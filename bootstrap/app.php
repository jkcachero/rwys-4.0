<?php

use App\Http\Middleware\AdmitAgentRequest;
use App\Http\Middleware\AuditAgentRequest;
use App\Http\Middleware\AuthorizeAgentRequest;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\PrepareAgentResponse;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);
        $middleware->alias([
            'agent.response' => PrepareAgentResponse::class,
            'agent.ability' => AuthorizeAgentRequest::class,
            'agent.audit' => AuditAgentRequest::class,
            'agent.admit' => AdmitAgentRequest::class,
        ]);

        $middleware->web(append: [
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
        $exceptions->respond(function (Response $response, Throwable $_exception, Request $request): Response {
            if ($request->is('api/v1/*')) {
                return app(PrepareAgentResponse::class)->prepare($request, $response);
            }

            return $response;
        });
    })->create();
