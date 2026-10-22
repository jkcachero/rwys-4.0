<?php

namespace App\Services;

use App\Inference\InferenceService;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class ChatConversationService
{
    public function __construct(private readonly InferenceService $inference) {}

    public function create(User $user, string $title = 'New chat'): ChatConversation
    {
        return $user->chatConversations()->create(['title' => $title]);
    }

    /** @return array{data: array, current_page: int, last_page: int} */
    public function listing(User $user): array
    {
        $page = $user->chatConversations()
            ->select(['id', 'title', 'provider', 'auth_mode', 'connection_id', 'model', 'thinking_level', 'updated_at'])
            ->orderByDesc('updated_at')->orderByDesc('id')->paginate(20);

        return [
            'data' => $page->getCollection()->map(fn (ChatConversation $conversation): array => $conversation->metadata())->all(),
            'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(),
        ];
    }

    /** @return array{conversation: array, messages: array, hasMore: bool, nextBeforeId?: int} */
    public function show(User $user, string $id, ?int $beforeId = null): array
    {
        $conversation = $user->chatConversations()->findOrFail($id);
        $messages = $conversation->messages()->select(['id', 'role', 'content', 'status', 'request_id', 'created_at'])
            ->when($beforeId !== null, fn ($query) => $query->where('id', '<', $beforeId))
            ->orderByDesc('id')->limit(51)->get();
        $hasMore = $messages->count() > 50;
        $messages = $messages->take(50)->reverse()->values();

        return [
            'conversation' => $conversation->metadata(),
            'messages' => $messages->map(fn (ChatMessage $message): array => $message->message())->all(),
            'hasMore' => $hasMore,
            ...($hasMore ? ['nextBeforeId' => $messages->first()->id] : []),
        ];
    }

    public function delete(User $user, string $id): void
    {
        $user->chatConversations()->findOrFail($id);
        Cache::lock('chat-conversation:'.$id, 180)->block(1, function () use ($user, $id): void {
            $user->chatConversations()->findOrFail($id)->delete();
        });
    }

    /**
     * @param  array{provider: string, prompt: string, connectionId?: ?int, model?: ?string, thinkingLevel?: ?string, conversationId?: ?string, requestId?: ?string}  $input
     * @return array{text: string, provider: string, model: string, correlationId: string, conversationId: string}
     */
    public function prompt(User $user, array $input): array
    {
        $conversation = isset($input['conversationId']) ? $user->chatConversations()->findOrFail($input['conversationId']) : null;
        $provider = $input['provider'] === 'anthropic' ? 'claude' : $input['provider'];
        $connection = isset($input['connectionId']) ? $user->aiConnections()->findOrFail($input['connectionId']) : null;
        $conversation ??= $this->create($user);
        $id = $conversation->id;

        return Cache::lock('chat-conversation:'.$id, 180)->block(1, function () use ($user, $input, $id, $connection, $provider): array {
            $conversation = $user->chatConversations()->findOrFail($id);
            $requestId = $input['requestId'] ?? (string) Str::uuid();
            $attempt = $conversation->messages()->where('request_id', $requestId)->where('role', 'user')->first();
            if ($attempt !== null) {
                $answer = $conversation->messages()->where('request_id', $requestId)->where('role', 'assistant')->where('status', 'completed')->first();
                if ($attempt->status !== 'completed' || $answer === null) {
                    throw new RuntimeException('This request has already been attempted.');
                }
                $latestAnswerId = $conversation->messages()->where('role', 'assistant')->where('status', 'completed')->max('id');
                if ($answer->id !== $latestAnswerId || $attempt->content !== $input['prompt']
                    || $conversation->provider !== $provider
                    || $conversation->auth_mode !== ($connection === null ? 'oauth' : 'api_key')
                    || $conversation->connection_id !== $connection?->id
                    || (isset($input['model']) && $conversation->model !== $input['model'])
                    || $conversation->thinking_level !== ($input['thinkingLevel'] ?? null)) {
                    throw ValidationException::withMessages(['requestId' => 'Already completed; reload history']);
                }

                return ['text' => $answer->content, 'provider' => $conversation->provider, 'model' => $conversation->model,
                    'correlationId' => $requestId, 'conversationId' => $id];
            }
            if ($connection !== null && ($connection->provider !== $provider || $connection->auth_mode !== 'api_key'
                || ! in_array($connection->status, ['active', 'configured'], true))) {
                throw ValidationException::withMessages(['connectionId' => 'Select a configured connection for this provider.']);
            }
            $history = $this->history($conversation);
            $attempt = DB::transaction(function () use ($conversation, $input, $requestId): ChatMessage {
                $attempt = $conversation->messages()->create([
                    'role' => 'user', 'status' => 'pending', 'content' => $input['prompt'], 'request_id' => $requestId,
                ]);
                if ($conversation->title === 'New chat') {
                    $conversation->title = Str::substr($input['prompt'], 0, 100);
                }
                $conversation->touch();

                return $attempt;
            });

            try {
                $result = $this->inference->prompt($user, $input['provider'], $input['prompt'],
                    $connection?->id, $input['model'] ?? null, $input['thinkingLevel'] ?? null, $history);

                DB::transaction(function () use ($conversation, $attempt, $result, $input, $requestId, $connection): void {
                    $conversation->messages()->create([
                        'role' => 'assistant', 'status' => 'completed', 'content' => $result->text, 'request_id' => $requestId,
                    ]);
                    $attempt->update(['status' => 'completed']);
                    $conversation->update([
                        'provider' => $result->provider, 'model' => $result->model,
                        'auth_mode' => $connection === null ? 'oauth' : 'api_key',
                        'connection_id' => $connection?->id, 'thinking_level' => $input['thinkingLevel'] ?? null,
                    ]);
                });
            } catch (Throwable $exception) {
                $attempt->update(['status' => 'failed']);
                throw $exception;
            }

            return [...(array) $result, 'conversationId' => $id];
        });
    }

    /** @return list<array{role: string, content: string}> */
    private function history(ChatConversation $conversation): array
    {
        $messages = $conversation->messages()->where('status', 'completed')
            ->select(['id', 'role', 'content', 'request_id'])->orderByDesc('id')->limit(20)->get()->reverse()->values();
        $pairs = [];
        $question = null;
        foreach ($messages as $message) {
            if ($message->role === 'user') {
                $question = $message;
            } elseif ($question !== null && $question->request_id === $message->request_id) {
                $pairs[] = [
                    ['role' => 'user', 'content' => $question->content],
                    ['role' => 'assistant', 'content' => $message->content],
                ];
                $question = null;
            }
        }
        $history = [];
        $characters = 0;
        foreach (array_reverse($pairs) as $pair) {
            $length = mb_strlen($pair[0]['content']) + mb_strlen($pair[1]['content']);
            if ($characters + $length > 16000) {
                break;
            }
            $characters += $length;
            $history = [...$pair, ...$history];
        }

        return $history;
    }
}
