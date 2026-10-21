<?php

namespace App\Http\Controllers;

use App\Inference\InferenceService;
use App\Workspace\Services\WorkspaceReadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class WorkspaceController extends Controller
{
    public function __construct(
        private readonly InferenceService $inference,
        private readonly WorkspaceReadService $reads,
    ) {}

    public function __invoke(Request $request): Response
    {
        return Inertia::render('dashboard', [
            'workspace' => $this->workspace($request),
        ]);
    }

    public function read(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'provider' => ['required', 'string', 'in:adkit,zernio'],
            'scopeId' => ['required', 'string', 'max:100'],
            'question' => ['required', 'string', 'max:500'],
        ]);

        $result = $this->reads->answer($validated['provider'], $validated['scopeId'], trim($validated['question']));

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
            'provider' => ['required', 'string', 'in:openai,anthropic'],
            'prompt' => ['required', 'string', 'max:4000'],
        ]);

        try {
            $result = $this->inference->prompt($request->user(), $validated['provider'], $validated['prompt']);
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
            'status' => 'ready',
            'greeting' => 'Welcome back, '.$request->user()->name.'.',
            'correlationId' => (string) Str::uuid(),
            'conversation' => ['status' => 'empty', 'messages' => []],
            'sources' => [],
            'provider' => [
                'status' => $hasAvailableProvider ? 'available' : 'unavailable',
                'message' => $hasAvailableProvider
                    ? 'Provider status is available for configured providers.'
                    : 'Connect an eligible provider before requesting inference.',
            ],
            'providers' => $providers,
            'capabilities' => [
                ['name' => 'AdKit read access', 'status' => 'ready'],
                ['name' => 'Zernio read access', 'status' => 'ready'],
                ['name' => 'Provider actions', 'status' => 'approval_required'],
            ],
        ];
    }
}
