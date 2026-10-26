<?php

namespace App\Http\Controllers;

use App\Inference\InferenceService;
use App\Workspace\Services\WorkspaceReadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class WorkspaceController extends Controller
{
    public function __construct(
        private readonly InferenceService $inference,
        private readonly WorkspaceReadService $reads,
    ) {}

    public function __invoke(): Response
    {
        return Inertia::render('dashboard');
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
}
