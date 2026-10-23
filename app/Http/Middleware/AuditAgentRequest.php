<?php

namespace App\Http\Middleware;

use App\Models\AgentRequestAudit;
use Closure;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class AuditAgentRequest
{
    public function __construct(private readonly ExceptionHandler $exceptions) {}

    public function handle(Request $request, Closure $next): Response
    {
        $startedAt = hrtime(true);
        $requestId = $request->attributes->get('agent_request_id');
        abort_unless(is_string($requestId), 500);

        try {
            $response = $next($request);
        } catch (Throwable $exception) {
            $this->exceptions->report($exception);
            $response = $this->exceptions->render($request, $exception);
        }

        $client = $request->attributes->get('agent_client');
        $token = $request->attributes->get('agent_token');

        AgentRequestAudit::query()->create([
            'request_id' => $requestId,
            'client_id' => $client->id,
            'personal_access_token_id' => $token->id,
            'method' => $request->method(),
            'route_name' => $request->route()?->getName() ?? 'unknown',
            'status' => $response->getStatusCode(),
            'duration_ms' => max(0, (int) ((hrtime(true) - $startedAt) / 1_000_000)),
        ]);

        return $response;
    }
}
