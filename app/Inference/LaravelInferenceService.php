<?php

namespace App\Inference;

use App\Models\AIConnection;
use App\Models\User;
use App\Providers\OpenAiOAuthService;
use App\Services\AIModelCatalog;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Ai;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;
use Laravel\Ai\Responses\Data\FinishReason;
use RuntimeException;

final class LaravelInferenceService implements InferenceService
{
    public function __construct(private readonly OpenAiOAuthService $oauth, private readonly AIModelCatalog $catalog) {}

    public function status(User $user): array
    {
        $apiConnections = $user->aiConnections()->where('auth_mode', 'api_key')
            ->whereIn('status', ['configured', 'active'])->get(['provider', 'status', 'last_validated_at']);
        $oauth = $user->providerConnections()->where('provider', 'openai')->first();

        return collect(config('workspace.providers'))->map(function (array $configuration, string $provider) use ($apiConnections, $oauth): array {
            $connections = $apiConnections->where('provider', $provider === 'anthropic' ? 'claude' : $provider);
            $tested = $connections->contains(fn (AIConnection $connection): bool => $connection->status === 'active' && $connection->last_validated_at !== null);
            $oauthUsable = $provider === 'openai' && $oauth !== null
                && array_diff(config('workspace.oauth.scopes'), $oauth->scopes ?? []) === []
                && ((filled($oauth->access_token) && $oauth->access_token_expires_at?->isAfter(now()->addMinute()))
                    || (filled($oauth->refresh_token) && filled($oauth->client_id)));
            $status = $tested || $oauthUsable ? 'available' : ($connections->isNotEmpty() ? 'configured' : 'unavailable');

            return [
                'name' => $provider,
                'label' => $configuration['label'],
                'model' => $configuration['model'],
                'status' => $status,
                'connected' => $oauthUsable,
                'expiresAt' => $provider === 'openai' ? $oauth?->access_token_expires_at?->toIso8601String() : null,
                'message' => match ($status) {
                    'available' => $tested ? 'Personal API connection tested successfully.' : 'OAuth connection available for inference; credentials may require refresh.',
                    'configured' => 'Personal API key saved; not yet tested successfully.',
                    default => 'Connect or re-authorize a personal provider to enable inference.',
                },
            ];
        })->values()->all();
    }

    public function models(User $user, string $provider, ?int $connectionId = null, bool $refresh = false): array
    {
        $provider = $provider === 'anthropic' ? 'claude' : $provider;
        if (! in_array($provider, ['openai', 'claude'], true)) {
            throw ValidationException::withMessages(['provider' => 'The selected provider is unavailable.']);
        }

        if ($connectionId !== null) {
            return $this->catalog->models($this->apiConnection($user, $provider, $connectionId), $refresh);
        }
        if ($provider !== 'openai') {
            throw new RuntimeException('Select a personal API connection.');
        }
        $connection = $user->providerConnections()->where('provider', 'openai')->first();
        if ($connection === null || array_diff(config('workspace.oauth.scopes'), $connection->scopes ?? []) !== []) {
            throw new RuntimeException('Connect an eligible personal OpenAI account.');
        }

        return $this->oauth->models($connection, $refresh);
    }

    private function apiConnection(User $user, string $provider, int $connectionId): AIConnection
    {
        $connection = $user->aiConnections()->findOrFail($connectionId);
        if ($connection->provider !== $provider || $connection->auth_mode !== 'api_key'
            || ! in_array($connection->status, ['configured', 'active'], true)
            || ! filled($connection->credentials['api_key'] ?? null)) {
            throw ValidationException::withMessages(['connectionId' => 'Select a configured personal connection for this provider.']);
        }

        return $connection;
    }

    public function prompt(User $user, string $provider, string $prompt, ?int $connectionId = null, ?string $model = null, ?string $thinkingLevel = null, array $history = []): InferenceResult
    {
        $provider = $provider === 'anthropic' ? 'claude' : $provider;
        $connection = $connectionId !== null ? $this->apiConnection($user, $provider, $connectionId) : null;
        $models = collect($this->models($user, $provider, $connectionId, true));
        $driver = $provider === 'claude' ? 'anthropic' : 'openai';
        $requestedModel = $model ?? $connection?->model;
        $selected = $requestedModel !== null ? $models->firstWhere('slug', $requestedModel)
            : ($models->firstWhere('slug', config("workspace.providers.{$driver}.model")) ?? $models->first());
        if ($selected === null) {
            throw ValidationException::withMessages(['model' => 'Select an available model for this connection.']);
        }
        if ($thinkingLevel !== null && ! in_array($thinkingLevel, $selected['thinkingLevels'], true)) {
            throw ValidationException::withMessages(['thinkingLevel' => 'Select a supported thinking level for this model.']);
        }
        $model = $selected['slug'];
        $history = $this->boundedHistory($history);

        if ($connection === null) {
            return $this->promptOpenAi($user, $prompt, $model, $thinkingLevel, $history);
        }

        $tokens = max(1, min(8192, (int) config('workspace.inference.'.($selected['thinkingLevels'] !== [] ? 'reasoning_max_tokens' : 'max_tokens'), 512)));
        $runtime = Ai::build([
            'name' => 'personal_'.Str::uuid(),
            'driver' => $driver,
            'key' => $connection->credentials['api_key'],
        ]);
        $agent = new class($driver, $thinkingLevel, $tokens) implements Agent, HasProviderOptions
        {
            use Promptable;

            public function __construct(private readonly string $driver, private readonly ?string $thinkingLevel, private readonly int $tokens) {}

            public function maxTokens(): int
            {
                return $this->tokens;
            }

            public function providerOptions(Lab|string $provider): array
            {
                $options = $this->driver === 'openai' ? ['store' => false] : [];
                if ($this->thinkingLevel !== null) {
                    $options[$this->driver === 'openai' ? 'reasoning' : 'output_config'] = ['effort' => $this->thinkingLevel];
                }

                return $options;
            }

            public function instructions(): string
            {
                return 'Answer clearly and concisely. No advertising tools are connected. Do not claim to access advertising data or perform external actions.';
            }
        };
        $response = $agent->withMessages($history)->prompt($prompt, provider: $runtime->name(), model: $model, timeout: 60);

        if ($response->raw === null || ! $response->raw->successful()
            || $response->steps->last()?->finishReason !== FinishReason::Stop
            || trim($response->text) === '') {
            throw new RuntimeException('Provider inference did not complete successfully.');
        }

        $user->aiConnections()->whereKey($connection->id)->whereIn('status', ['configured', 'active'])
            ->update(['status' => 'active', 'last_validated_at' => now()]);

        return new InferenceResult($provider, $model, $response->text, (string) Str::uuid());
    }

    /** @param list<array{role: string, content: string}> $history */
    private function promptOpenAi(User $user, string $prompt, string $model, ?string $thinkingLevel, array $history): InferenceResult
    {
        $connection = $user->providerConnections()->where('provider', 'openai')->first();
        if ($connection === null || array_diff(config('workspace.oauth.scopes'), $connection->scopes ?? []) !== []) {
            throw new RuntimeException('Connect an eligible personal OpenAI account.');
        }

        $input = array_map(fn (array $message): array => [
            'role' => $message['role'],
            'content' => [['type' => $message['role'] === 'assistant' ? 'output_text' : 'input_text', 'text' => $message['content']]],
        ], [...$history, ['role' => 'user', 'content' => $prompt]]);

        $response = Http::withToken($this->oauth->accessToken($connection))
            ->accept('text/event-stream')->connectTimeout(10)->timeout(60)
            ->post('https://api.openai.com/v1/responses', [
                'model' => $model,
                'input' => $input,
                'store' => false,
                'stream' => true,
                ...($thinkingLevel !== null ? ['reasoning' => ['effort' => $thinkingLevel]] : []),
            ]);

        if (! $response->successful()) {
            throw new RuntimeException('OpenAI inference was not completed.');
        }

        return new InferenceResult('openai', $model, $this->responseText($response->body()), (string) Str::uuid());
    }

    /**
     * @param  list<array{role: string, content: string}>  $history
     * @return list<array{role: string, content: string}>
     */
    private function boundedHistory(array $history): array
    {
        $messages = [];
        $characters = 0;
        $limit = max(0, min(20, (int) config('workspace.inference.history_max_messages', 20)));
        $budget = max(0, min(24000, (int) config('workspace.inference.history_max_characters', 24000)));
        if ($limit === 0 || $budget === 0) {
            return [];
        }
        foreach (array_reverse(array_slice($history, -$limit)) as $message) {
            if (! is_array($message) || ! in_array($message['role'] ?? null, ['user', 'assistant'], true)
                || ! is_string($message['content'] ?? null)) {
                throw ValidationException::withMessages(['history' => 'Conversation context must contain only trusted user and assistant text.']);
            }
            $characters += mb_strlen($message['content']);
            if ($characters > $budget) {
                break;
            }
            $messages[] = ['role' => $message['role'], 'content' => $message['content']];
        }
        $messages = array_reverse($messages);
        while ($messages !== [] && $messages[0]['role'] !== 'user') {
            array_shift($messages);
        }

        return $messages;
    }

    private function responseText(string $stream): string
    {
        $text = '';
        $completed = false;
        $stream = str_replace(["\r\n", "\r"], "\n", $stream);

        foreach (explode("\n\n", $stream) as $event) {
            $data = [];
            foreach (explode("\n", $event) as $line) {
                if (str_starts_with($line, 'data:')) {
                    $data[] = ltrim(substr($line, 5), ' ');
                }
            }
            if ($data === [] || implode('', $data) === '[DONE]') {
                continue;
            }
            $payload = json_decode(implode("\n", $data), true);
            if (! is_array($payload)) {
                throw new RuntimeException('OpenAI returned an invalid inference event.');
            }
            $type = $payload['type'] ?? '';
            if (in_array($type, ['error', 'response.failed', 'response.incomplete', 'response.refusal.delta', 'response.refusal.done'], true)) {
                throw new RuntimeException('OpenAI refused or did not complete the request.');
            }
            if ($type === 'response.output_text.delta') {
                $text .= (string) ($payload['delta'] ?? '');
            }
            if ($type === 'response.completed') {
                if (($payload['response']['status'] ?? 'completed') !== 'completed' || isset($payload['response']['error'])) {
                    throw new RuntimeException('OpenAI inference did not complete successfully.');
                }
                $finalText = '';
                foreach ($payload['response']['output'] ?? [] as $output) {
                    foreach ($output['content'] ?? [] as $content) {
                        if (($content['type'] ?? '') === 'refusal') {
                            throw new RuntimeException('OpenAI refused the request.');
                        }
                        if (($content['type'] ?? '') === 'output_text') {
                            $finalText .= (string) ($content['text'] ?? '');
                        }
                    }
                }
                $text = $finalText !== '' ? $finalText : $text;
                $completed = true;
            }
        }

        if (! $completed || trim($text) === '') {
            throw new RuntimeException('OpenAI inference ended without a completed text response.');
        }

        return $text;
    }
}
