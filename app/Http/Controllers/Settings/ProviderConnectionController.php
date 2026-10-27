<?php

namespace App\Http\Controllers\Settings;

use App\Exceptions\Zernio\ZernioRequestException;
use App\Http\Requests\Settings\ConnectZernioRequest;
use App\Inference\InferenceService;
use App\Models\ProviderConnection;
use App\Providers\OpenAiOAuthService;
use App\Zernio\ZernioHttpClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Throwable;

class ProviderConnectionController
{
    public function __construct(
        private readonly OpenAiOAuthService $oauth,
        private readonly InferenceService $inference,
        private readonly ZernioHttpClient $zernio,
    ) {}

    public function connectOpenAi(Request $request): RedirectResponse
    {
        $this->authorizeAdmin($request);

        $authorization = $this->oauth->authorization($request->user());

        return redirect()->away($authorization['url']);
    }

    public function connectZernio(ConnectZernioRequest $request): RedirectResponse
    {
        $connection = new ProviderConnection([
            'provider' => 'zernio',
            'access_token' => $request->string('api_token')->toString(),
            'scopes' => ['profiles:read', 'accounts:read'],
        ]);

        try {
            $this->zernio->profiles($connection, 1, 0);
        } catch (ZernioRequestException) {
            throw ValidationException::withMessages([
                'api_token' => 'Zernio could not verify this API token.',
            ]);
        }

        $request->user()->providerConnections()->updateOrCreate(
            ['provider' => 'zernio'],
            [
                'client_id' => null,
                'subject' => null,
                'access_token' => $request->string('api_token')->toString(),
                'refresh_token' => null,
                'access_token_expires_at' => null,
                'scopes' => ['profiles:read', 'accounts:read'],
            ],
        );

        return to_route('settings.agent-access.edit')->with('status', 'Zernio is connected for profile assignment.');
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
            return redirect()->route('settings.agent-access.edit')->with('error', 'OpenAI authorization could not be completed.');
        }

        return redirect()->route('settings.agent-access.edit')->with('status', 'OpenAI is connected for inference.');
    }

    public function test(Request $request, string $provider): RedirectResponse
    {
        $this->authorizeAdmin($request);

        abort_unless(in_array($provider, ['openai', 'zernio'], true), 404);

        try {
            if ($provider === 'zernio') {
                $connection = $request->user()->providerConnections()
                    ->where('provider', 'zernio')
                    ->firstOrFail();
                $this->zernio->profiles($connection, 1, 0);
            } else {
                $this->inference->prompt(
                    $request->user(),
                    $provider,
                    'Reply with exactly: RWYS connection test passed.',
                );
            }
        } catch (Throwable) {
            return to_route('settings.agent-access.edit')->with('error', 'The provider connection test could not be completed.');
        }

        return to_route('settings.agent-access.edit')->with('status', 'Provider connection test completed.');
    }

    public function disconnect(Request $request, string $provider): RedirectResponse
    {
        $this->authorizeAdmin($request);
        abort_unless(in_array($provider, ['openai', 'zernio'], true), 404);

        if ($provider === 'openai') {
            $this->oauth->disconnect($request->user());
        } else {
            $request->user()->providerConnections()->where('provider', 'zernio')->delete();
        }

        return to_route('settings.agent-access.edit')->with('status', 'Provider disconnected.');
    }

    private function authorizeAdmin(Request $request): void
    {
        abort_unless($request->user()?->role === 'admin', 403);
    }
}
