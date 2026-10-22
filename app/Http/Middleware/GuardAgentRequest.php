<?php

namespace App\Http\Middleware;

use App\Models\AgentApiAudit;
use App\Models\Client;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class GuardAgentRequest
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->attributes->get('agent_token');
        $ability = $request->route()->defaults['agent_ability'] ?? null;
        abort_unless(is_string($ability) && in_array($ability, $token->abilities, true), 403);
        $write = ($request->route()->defaults['agent_write'] ?? false) === true;
        $started = hrtime(true);
        // The Client row serializes admission across tokens and application workers.
        $audit = DB::transaction(function () use ($request, $token, $ability, $write): AgentApiAudit {
            Client::query()->lockForUpdate()->findOrFail($token->client_id);
            $budget = max(1, (int) config($write ? 'agent-api.writes_per_minute' : 'agent-api.reads_per_minute'));
            $recent = AgentApiAudit::query()->where('started_at', '>', now()->subMinute())->where('is_write', $write);
            abort_if((clone $recent)->where('client_id', $token->client_id)->count() >= $budget
                || (clone $recent)->where('token_id', $token->id)->count() >= $budget, 429);
            // Maximum route budget is five 15-second HTTP calls; stale leases expire after 120 seconds.
            abort_if(AgentApiAudit::where('client_id', $token->client_id)->whereNull('finished_at')->where('started_at', '>', now()->subSeconds(120))->count() >= 2, 429);

            return AgentApiAudit::create([
                'request_id' => $request->attributes->get('agent_request_id'), 'client_id' => $token->client_id,
                'token_id' => $token->id, 'capability' => $ability, 'is_write' => $write, 'started_at' => now(),
            ]);
        }, 3);
        $status = 503;
        try {
            $response = $next($request);
            $status = $response->getStatusCode();

            return $response;
        } finally {
            $audit->forceFill(['finished_at' => now(), 'status' => $status, 'duration_ms' => min(PHP_INT_MAX, (int) ((hrtime(true) - $started) / 1e6))])->save();
            $token->forceFill(['last_used_at' => now()])->save();
        }
    }
}
