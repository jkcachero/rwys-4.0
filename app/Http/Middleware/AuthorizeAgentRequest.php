<?php

namespace App\Http\Middleware;

use App\Models\Client;
use App\Models\PersonalAccessToken;
use App\Models\User;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthorizeAgentRequest
{
    public function handle(Request $request, Closure $next, ?string $ability = null): Response
    {
        $token = $request->user()?->currentAccessToken();

        if (! $token instanceof PersonalAccessToken || $token->client_id === null || $token->revoked_at !== null
            || ($ability !== null && ! $token->can($ability))) {
            return $this->denied('The token is not authorized for this operation.', 403);
        }

        $client = Client::query()->find($token->client_id);
        $principal = $request->user();

        if (! $client?->is_active) {
            return $this->denied('The Client is inactive.', 403);
        }

        if (! $principal instanceof User
            || ! $principal->hasVerifiedEmail()
            || ! $client->hasActiveOwner($principal)
            || $token->issued_by !== $principal->id) {
            return $this->denied('The issuing principal is no longer authorized for this Client.', 403);
        }

        $origin = $request->headers->get('Origin');
        if ($origin !== null && ! in_array($origin, $client->approved_origins, true)) {
            return $this->denied('The request origin is not approved.', 403);
        }

        $request->attributes->set('agent_client', $client);
        $request->attributes->set('agent_token', $token);

        return $next($request);
    }

    private function denied(string $message, int $status): JsonResponse
    {
        return response()->json(['message' => $message], $status, ['Cache-Control' => 'no-store, private']);
    }
}
