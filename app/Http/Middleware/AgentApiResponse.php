<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class AgentApiResponse
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->is('api/v1', 'api/v1/*', 'webhooks/zernio')) {
            return $next($request);
        }
        $id = (string) Str::uuid();
        $request->attributes->set('agent_request_id', $id);
        $response = $next($request);
        if ($response instanceof JsonResponse) {
            $data = $response->getData();
            $data->request_id = $id;
            $response->setData($data);
        }
        $response->headers->set('X-Request-ID', $id);
        $response->headers->set('Cache-Control', 'no-store, private');
        if ($response->getStatusCode() === 429) {
            $response->headers->set('Retry-After', '60');
        }

        return $response;
    }
}
