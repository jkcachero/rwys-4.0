<?php

use App\Http\Middleware\AgentApiResponse;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Zernio\Exceptions\ZernioApiException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            require __DIR__.'/../routes/agent-webhooks.php';
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(AgentApiResponse::class);
        $middleware->trimStrings(except: [fn (Request $request): bool => $request->is('api/v1', 'api/v1/*', 'webhooks/zernio')]);
        $middleware->convertEmptyStringsToNull(except: [fn (Request $request): bool => $request->is('api/v1', 'api/v1/*', 'webhooks/zernio')]);
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        $middleware->web(append: [
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->report(function (Throwable $exception): ?bool {
            if (request()->is('api/v1', 'api/v1/*', 'webhooks/zernio')) {
                Log::warning('Agent API request failed.', ['request_id' => request()->attributes->get('agent_request_id')]);

                return false;
            }

            return null;
        });
        $exceptions->render(function (Throwable $exception, Request $request) {
            if (! $request->is('api/v1', 'api/v1/*', 'webhooks/zernio')) {
                return null;
            }
            $status = match (true) {
                $exception instanceof ValidationException => 422,
                $exception instanceof AuthenticationException => 401,
                $exception instanceof AuthorizationException => 403,
                $exception instanceof ModelNotFoundException => 404,
                $exception instanceof ZernioApiException => match ($exception->reason) {
                    'analytics_access_required' => 402,
                    'not_found' => 404,
                    default => 502,
                },
                $exception instanceof HttpExceptionInterface => $exception->getStatusCode(),
                default => 503,
            };
            $code = match ($status) {
                401 => 'unauthenticated', 402 => 'analytics_access_required', 403 => 'forbidden', 404 => 'not_found',
                409 => 'idempotency_conflict', 422 => 'invalid_input', 429 => 'rate_limited',
                502 => 'provider_unavailable', 503 => 'service_unavailable', default => 'request_failed',
            };

            return response()->json(['error' => ['code' => $code, 'message' => str_replace('_', ' ', ucfirst($code)).'.']], $status);
        });
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
