<?php

namespace App\Http\Controllers;

use App\Inference\InferenceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class WorkspaceController extends Controller
{
    public function __construct(private readonly InferenceService $inference) {}

    public function __invoke(Request $request): Response
    {
        return Inertia::render('dashboard', [
            'workspace' => [
                'status' => 'ready',
                'greeting' => 'Welcome back, '.$request->user()->name.'.',
                'correlationId' => (string) Str::uuid(),
                'conversation' => ['status' => 'empty', 'messages' => []],
                'sources' => [],
                'providers' => $this->inference->status(),
                'capabilities' => [
                    ['name' => 'AdKit read access', 'status' => 'deferred'],
                    ['name' => 'Zernio read access', 'status' => 'deferred'],
                    ['name' => 'Provider actions', 'status' => 'disabled'],
                ],
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
            $result = $this->inference->prompt($validated['provider'], $validated['prompt']);
        } catch (Throwable) {
            return response()->json(['message' => 'The selected provider is unavailable.'], 503);
        }

        return response()->json($result);
    }
}
