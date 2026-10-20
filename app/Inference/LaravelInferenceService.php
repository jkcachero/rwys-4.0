<?php

namespace App\Inference;

use App\Models\User;
use App\Providers\OpenAiOAuthService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;
use RuntimeException;

final class LaravelInferenceService implements InferenceService
{
    public function __construct(private readonly OpenAiOAuthService $oauth) {}

    public function status(User $user): array
    {
        return collect(config('workspace.providers'))
            ->map(function (array $provider, string $key) use ($user): array {
                $connection = $user->providerConnections()->where('provider', $key)->first();
                $hasApiKey = filled(config("ai.providers.{$key}.key"));

                if ($key === 'openai') {
                    return [
                        'name' => $key,
                        'label' => $provider['label'],
                        'model' => $provider['model'],
                        'status' => $connection ? 'available' : 'unavailable',
                        'message' => $connection
                            ? 'Connected for authenticated inference.'
                            : 'Connect an eligible OpenAI account to enable inference.',
                    ];
                }

                return [
                    'name' => $key,
                    'label' => $provider['label'],
                    'model' => $provider['model'],
                    'status' => $hasApiKey ? 'available' : 'unavailable',
                    'message' => $hasApiKey
                        ? 'Ready for authenticated inference.'
                        : ($provider['message'] ?? 'Provider credentials are not configured.'),
                ];
            })
            ->values()
            ->all();
    }

    public function prompt(User $user, string $provider, string $prompt): InferenceResult
    {
        $configuration = config("workspace.providers.{$provider}");

        if (! is_array($configuration)) {
            throw new RuntimeException('The selected provider is unavailable.');
        }

        if ($provider === 'openai') {
            return $this->promptOpenAi($user, $configuration, $prompt);
        }

        if (! filled(config("ai.providers.{$provider}.key"))) {
            throw new RuntimeException('The selected provider is unavailable.');
        }

        $agent = new class implements Agent
        {
            use Promptable;

            public function instructions(): string
            {
                return 'Answer the user request clearly and concisely. Do not perform external actions.';
            }
        };

        $response = $agent->prompt($prompt, provider: $provider, model: $configuration['model']);

        return new InferenceResult($provider, $configuration['model'], $response->text, (string) Str::uuid());
    }

    /** @param array{model: string} $configuration */
    private function promptOpenAi(User $user, array $configuration, string $prompt): InferenceResult
    {
        $connection = $user->providerConnections()->where('provider', 'openai')->first();

        if ($connection === null) {
            throw new RuntimeException('The selected provider is unavailable.');
        }

        $response = Http::withToken($this->oauth->accessToken($connection))
            ->acceptJson()
            ->timeout(60)
            ->post('https://api.openai.com/v1/responses', [
                'model' => $configuration['model'],
                'input' => $prompt,
                'store' => false,
                'stream' => true,
            ]);

        if ($response->failed()) {
            throw new RuntimeException('OpenAI inference was not completed.');
        }

        $text = $this->responseText($response->body());

        return new InferenceResult('openai', $configuration['model'], $text, (string) Str::uuid());
    }

    private function responseText(string $stream): string
    {
        $text = '';
        $completed = false;

        foreach (preg_split('/\r?\n/', $stream) ?: [] as $line) {
            if (! str_starts_with($line, 'data:')) {
                continue;
            }

            $payload = json_decode(trim(substr($line, 5)), true);

            if (! is_array($payload)) {
                continue;
            }

            $type = $payload['type'] ?? null;

            if ($type === 'response.output_text.delta') {
                $text .= (string) ($payload['delta'] ?? '');
            }

            if ($type === 'response.completed') {
                $completed = true;
            }

            if ($type === 'response.failed' || $type === 'response.incomplete') {
                throw new RuntimeException('OpenAI inference did not complete successfully.');
            }
        }

        if (! $completed) {
            throw new RuntimeException('OpenAI inference ended before completion.');
        }

        return $text;
    }
}
