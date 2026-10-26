<?php

namespace App\Http\Middleware;

use App\Models\Client;
use App\Models\PersonalAccessToken;
use App\Services\AgentAdmissionService;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AdmitAgentRequest
{
    public function __construct(private readonly AgentAdmissionService $admission) {}

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next, string $mode = 'auto'): Response
    {
        $client = $request->attributes->get('agent_client');
        $token = $request->attributes->get('agent_token');

        if (! $client instanceof Client || ! $token instanceof PersonalAccessToken) {
            return $this->denied('forbidden', 'Agent authorization context is unavailable.', 403);
        }

        $retryAfter = $this->admission->rateLimit($client, $token, $mode !== 'read' && ! $request->isMethodSafe());

        if ($retryAfter !== null) {
            return $this->denied('rate_limited', 'The agent request limit was exceeded.', 429, $retryAfter);
        }

        $lease = $this->admission->acquire($client);

        if ($lease === null) {
            return $this->denied('too_many_in_flight', 'The Client has too many requests in progress.', 429, 1);
        }

        try {
            return $next($request);
        } finally {
            $lease->release();
        }
    }

    private function denied(string $code, string $message, int $status, ?int $retryAfter = null): JsonResponse
    {
        $response = response()->json(['error' => ['code' => $code, 'message' => $message]], $status);

        if ($retryAfter !== null) {
            $response->headers->set('Retry-After', (string) $retryAfter);
        }

        return $response;
    }
}
