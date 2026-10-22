<?php

namespace App\Http\Controllers\Settings;

use App\Inference\InferenceService;
use App\Providers\OpenAiOAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Validator;
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
        $statuses = collect($this->inference->status($request->user()))->keyBy('name');

        return Inertia::render('settings/connections', [
            'connections' => $request->user()->aiConnections()
                ->get(['id', 'provider', 'label', 'auth_mode', 'model', 'status', 'last_validated_at'])
                ->map->redacted()->values()->all(),
            'providers' => collect(config('workspace.providers'))->map(
                fn (array $provider, string $name): array => array_replace([
                    'name' => $name,
                    'label' => $provider['label'],
                    'model' => $provider['model'],
                    'authentication' => $provider['authentication'],
                    'capabilities' => $provider['capabilities'],
                    'message' => $provider['message'] ?? null,
                    'status' => 'unavailable',
                    'connected' => false,
                    'expiresAt' => null,
                ], array_intersect_key($statuses->get($name, []), array_flip(['status', 'message', 'connected', 'expiresAt']))),
            )->values()->all(),
        ]);
    }

    public function connectOpenAi(Request $request): JsonResponse
    {
        return response()->json($this->oauth->authorization($request->user()));
    }

    public function attempt(Request $request, string $state): JsonResponse
    {
        $attempt = $this->oauth->attempt($request->user(), $state);
        abort_if($attempt === null, 404);

        return response()->json($attempt)->header('Cache-Control', 'no-store');
    }

    public function callback(Request $request): HttpResponse
    {
        $redirect = parse_url(config('workspace.oauth.redirect_uri'));
        abort_unless(($redirect['scheme'] ?? null) === 'http' && ($redirect['host'] ?? null) === '127.0.0.1'
            && ($redirect['path'] ?? null) === '/auth/callback'
            && $request->server('REMOTE_ADDR') === '127.0.0.1'
            && (int) $request->server('SERVER_PORT') === (int) ($redirect['port'] ?? 80), 403);

        $validator = Validator::make($request->query(), [
            'code' => ['required_without:error', 'nullable', 'string', 'max:8192'],
            'state' => ['required', 'string', 'size:64', 'regex:/^[A-Za-z0-9]+$/D'],
            'client_id' => ['nullable', 'string', 'max:255'],
            'error' => ['nullable', 'string', 'max:255'],
        ]);
        $status = 200;
        $message = 'ChatGPT is connected for inference. You can return to your workspace.';

        try {
            if ($validator->fails()) {
                throw new \RuntimeException('Invalid callback.');
            }
            if ($request->filled('error')) {
                $this->oauth->deny($request->query('state'), $request->query('error'));
                $message = 'ChatGPT authorization was not completed. Return to your workspace to try again.';
            } else {
                $this->oauth->complete($request->query('code'), $request->query('state'), $request->query('client_id'));
            }
        } catch (Throwable) {
            $status = 400;
            $message = 'ChatGPT authorization could not be completed. Return to your workspace to start a new authorization.';
        }

        $url = e(rtrim(config('app.url'), '/').'/settings/connections');

        return response('<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>ChatGPT connection</title><body><main><h1>ChatGPT connection</h1><p>'.e($message).'</p><a href="'.$url.'">Return to connections</a></main></body></html>', $status)
            ->header('Content-Type', 'text/html; charset=UTF-8')
            ->header('Cache-Control', 'no-store')
            ->header('Referrer-Policy', 'no-referrer')
            ->header('Content-Security-Policy', "default-src 'none'; base-uri 'none'; frame-ancestors 'none'");
    }

    public function test(Request $request, string $provider): JsonResponse|RedirectResponse
    {
        $input = $request->validate([
            'connectionId' => ['nullable', 'integer', 'min:1'],
            'model' => ['nullable', 'string', 'max:255'],
        ]);
        $connectionId = isset($input['connectionId']) ? (int) $input['connectionId'] : null;
        if ($connectionId !== null) {
            $connection = $request->user()->aiConnections()->findOrFail($connectionId);
            abort_unless($connection->provider === $provider, 404);
        } else {
            abort_unless($provider === 'openai', 404);
        }

        $message = 'Provider connection test completed.';
        $status = 200;
        try {
            $this->inference->prompt(
                $request->user(),
                $provider,
                'Reply with exactly: RWYS connection test passed.',
                $connectionId,
                $input['model'] ?? null,
            );
        } catch (Throwable) {
            $message = 'The provider connection test could not be completed.';
            $status = 503;
        }

        return $request->expectsJson()
            ? response()->json(['message' => $message], $status)
            : to_route('connections.edit')->with($status === 200 ? 'status' : 'error', $message);
    }

    public function disconnect(Request $request, string $provider): JsonResponse|RedirectResponse
    {
        abort_unless($provider === 'openai', 404);

        $message = 'Provider session revoked and disconnected.';
        $status = 200;
        try {
            $this->oauth->disconnect($request->user());
        } catch (Throwable) {
            $message = 'Remote revocation was not confirmed. The connection was retained; retry or disconnect the app in ChatGPT Settings.';
            $status = 503;
        }

        return $request->expectsJson()
            ? response()->json(['message' => $message], $status)
            : to_route('connections.edit')->with($status === 200 ? 'status' : 'error', $message);
    }
}
