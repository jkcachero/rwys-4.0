<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class PrepareAgentResponse
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $request->attributes->set('agent_request_id', (string) Str::uuid());
        $response = $next($request);

        return $this->prepare($request, $response);
    }

    public function prepare(Request $request, Response $response): Response
    {
        $requestId = $request->attributes->get('agent_request_id');

        if (! is_string($requestId)) {
            $requestId = (string) Str::uuid();
            $request->attributes->set('agent_request_id', $requestId);
        }

        if ($response instanceof JsonResponse && $response->getStatusCode() !== 204) {
            $data = $response->getData(true);

            if (is_array($data)) {
                $data['request_id'] = $requestId;
                $response->setData($data);
            }
        }

        $response->headers->set('X-Request-Id', $requestId);
        $operationId = $request->attributes->get('agent_operation_id');
        if (is_string($operationId)) {
            $response->headers->set('X-Operation-Id', $operationId);
        }
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
