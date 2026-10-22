<?php

namespace App\Http\Controllers;

use App\Inference\InferenceService;
use App\Services\AIConnectionService;
use App\Services\ChatConversationService;
use App\Workspace\Services\WorkspaceReadService;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class WorkspaceController extends Controller
{
    public function __construct(
        private readonly InferenceService $inference,
        private readonly WorkspaceReadService $reads,
        private readonly AIConnectionService $connections,
        private readonly ChatConversationService $conversations,
    ) {}

    public function __invoke(Request $request): Response
    {
        return Inertia::render('dashboard', [
            'workspace' => $this->workspace($request),
        ]);
    }

    public function chat(Request $request): Response
    {
        $validated = $request->validate(['conversation' => ['nullable', 'uuid']]);
        $conversation = isset($validated['conversation'])
            ? $this->conversations->show($request->user(), $validated['conversation']) : null;

        return Inertia::render('chat', [
            'workspace' => [
                ...$this->workspace($request),
                ...($conversation === null ? [] : ['conversation' => [
                    ...$conversation['conversation'],
                    'status' => $conversation['messages'] === [] ? 'empty' : 'active',
                    'messages' => $conversation['messages'], 'hasMore' => $conversation['hasMore'],
                    ...(isset($conversation['nextBeforeId']) ? ['nextBeforeId' => $conversation['nextBeforeId']] : []),
                ]]),
            ],
        ]);
    }

    public function conversationIndex(Request $request): JsonResponse
    {
        $request->validate(['page' => ['sometimes', 'integer', 'min:1']]);

        return response()->json($this->conversations->listing($request->user()));
    }

    public function conversationStore(Request $request): JsonResponse
    {
        $validated = $request->validate(['title' => ['sometimes', 'string', 'max:100']]);

        return response()->json(['conversation' => $this->conversations->create($request->user(), $validated['title'] ?? 'New chat')->metadata()], 201);
    }

    public function conversationShow(Request $request, string $conversation): JsonResponse
    {
        $validated = $request->validate(['beforeId' => ['sometimes', 'integer', 'min:1']]);

        return response()->json($this->conversations->show($request->user(), $conversation, isset($validated['beforeId']) ? (int) $validated['beforeId'] : null));
    }

    public function conversationDestroy(Request $request, string $conversation): JsonResponse
    {
        try {
            $this->conversations->delete($request->user(), $conversation);
        } catch (LockTimeoutException) {
            return response()->json(['message' => 'The conversation is busy.'], 503);
        }

        return response()->json(['status' => 'deleted']);
    }

    public function models(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'provider' => ['required', 'string', 'in:openai,claude,anthropic'],
            'connectionId' => ['nullable', 'integer'],
            'refresh' => ['sometimes', 'boolean'],
        ]);

        try {
            $models = $this->inference->models($request->user(), $validated['provider'],
                isset($validated['connectionId']) ? (int) $validated['connectionId'] : null, $request->boolean('refresh'));
        } catch (ModelNotFoundException|ValidationException $exception) {
            throw $exception;
        } catch (HttpExceptionInterface $exception) {
            if (in_array($exception->getStatusCode(), [404, 422], true)) {
                throw $exception;
            }

            return response()->json(['message' => 'The selected provider is unavailable.'], 503);
        } catch (Throwable) {
            return response()->json(['message' => 'The selected provider is unavailable.'], 503);
        }

        return response()->json(['models' => $models, 'cacheSeconds' => 60]);
    }

    public function read(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'provider' => ['required', 'string', 'in:adkit,zernio'],
            'question' => ['required', 'string', 'max:500'],
        ]);

        if (! app()->environment('testing') || config('services.workspace.ai_driver') !== 'fake') {
            return response()->json(['message' => 'No advertising tools are connected.'], 503);
        }

        $result = $this->reads->answer($validated['provider'], 'default', trim($validated['question']));

        return response()->json([
            'message' => [
                'role' => 'assistant',
                'content' => $result->summary,
                'sources' => $result->sources,
            ],
        ]);
    }

    public function prompt(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'provider' => ['required', 'string', 'in:openai,claude,anthropic'],
            'prompt' => ['required', 'string', 'max:4000'],
            'connectionId' => ['nullable', 'integer'],
            'model' => ['nullable', 'string', 'max:100'],
            'thinkingLevel' => ['nullable', 'string', 'max:20'],
            'conversationId' => ['nullable', 'uuid'],
            'requestId' => ['nullable', 'uuid'],
        ]);

        try {
            $result = $this->conversations->prompt($request->user(), $validated);
        } catch (ModelNotFoundException|ValidationException $exception) {
            throw $exception;
        } catch (HttpExceptionInterface $exception) {
            if (in_array($exception->getStatusCode(), [404, 422], true)) {
                throw $exception;
            }

            return response()->json(['message' => 'The selected provider is unavailable.'], 503);
        } catch (Throwable) {
            return response()->json(['message' => 'The selected provider is unavailable.'], 503);
        }

        return response()->json($result);
    }

    /** @return array<string, mixed> */
    private function workspace(Request $request): array
    {
        $providers = $this->inference->status($request->user());
        $hasAvailableProvider = collect($providers)->contains('status', 'available');

        return [
            'status' => $hasAvailableProvider ? 'ready' : 'unavailable',
            'greeting' => 'Welcome back, '.$request->user()->name.'.',
            'correlationId' => (string) Str::uuid(),
            'conversation' => ['id' => null, 'title' => 'New chat', 'status' => 'empty', 'messages' => [], 'hasMore' => false],
            'sources' => [],
            'provider' => [
                'status' => $hasAvailableProvider ? 'available' : 'unavailable',
                'message' => $hasAvailableProvider
                    ? 'Provider status is available for configured providers.'
                    : 'Connect a personal AI provider to chat. No advertising tools are connected.',
            ],
            'connections' => $this->connections->listFor($request->user()),
            'providers' => $providers,
            'capabilities' => [
                ['name' => 'AdKit read access', 'status' => 'unavailable'],
                ['name' => 'Zernio read access', 'status' => 'unavailable'],
                ['name' => 'Personal OpenAI / Claude connections', 'status' => $hasAvailableProvider ? 'available' : 'unavailable'],
                ['name' => 'Provider actions', 'status' => 'approval_required'],
            ],
        ];
    }
}
