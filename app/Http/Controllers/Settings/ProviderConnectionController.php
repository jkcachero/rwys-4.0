<?php

namespace App\Http\Controllers\Settings;

use App\Inference\InferenceService;
use App\Providers\OpenAiOAuthService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class ProviderConnectionController
{
    public function __construct(
        private readonly OpenAiOAuthService $oauth,
        private readonly InferenceService $inference,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorizeAdmin($request);

        $connections = $request->user()->providerConnections()
            ->get(['provider', 'subject', 'access_token_expires_at', 'scopes'])
            ->keyBy('provider');

        return Inertia::render('settings/connections', [
            'providers' => collect(config('workspace.providers'))->map(
                fn (array $provider, string $name): array => [
                    'name' => $name,
                    'label' => $provider['label'],
                    'model' => $provider['model'],
                    'authentication' => $provider['authentication'],
                    'capabilities' => $provider['capabilities'],
                    'message' => $provider['message'] ?? null,
                    'connected' => $connections->has($name),
                    'expiresAt' => $connections->get($name)?->access_token_expires_at?->toIso8601String(),
                ],
            )->values()->all(),
        ]);
    }

    public function connectOpenAi(Request $request): RedirectResponse
    {
        $this->authorizeAdmin($request);

        $authorization = $this->oauth->authorization($request->user());

        return redirect()->away($authorization['url']);
    }

    public function callback(Request $request): RedirectResponse
    {
        $request->validate([
            'code' => ['required', 'string'],
            'state' => ['required', 'string', 'size:64'],
        ]);

        try {
            $this->oauth->complete($request->string('code')->toString(), $request->string('state')->toString());
        } catch (Throwable) {
            return redirect()->route('connections.edit')->with('error', 'OpenAI authorization could not be completed.');
        }

        return redirect()->route('connections.edit')->with('status', 'OpenAI is connected for inference.');
    }

    public function test(Request $request, string $provider): RedirectResponse
    {
        $this->authorizeAdmin($request);

        abort_unless($provider === 'openai', 404);

        try {
            $this->inference->prompt(
                $request->user(),
                $provider,
                'Reply with exactly: RWYS connection test passed.',
            );
        } catch (Throwable) {
            return to_route('connections.edit')->with('error', 'The provider connection test could not be completed.');
        }

        return to_route('connections.edit')->with('status', 'Provider connection test completed.');
    }

    public function disconnect(Request $request, string $provider): RedirectResponse
    {
        $this->authorizeAdmin($request);
        abort_unless($provider === 'openai', 404);

        $this->oauth->disconnect($request->user());

        return to_route('connections.edit')->with('status', 'Provider disconnected.');
    }

    private function authorizeAdmin(Request $request): void
    {
        abort_unless($request->user()?->role === 'admin', 403);
    }
}
