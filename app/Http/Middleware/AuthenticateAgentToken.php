<?php

namespace App\Http\Middleware;

use App\Models\AgentAccessToken;
use App\Models\User;
use App\Services\AgentTokenService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateAgentToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $this->authenticate($request);

        return $next($request);
    }

    public function authenticate(Request $request): void
    {
        $bearer = $request->bearerToken();
        abort_unless(is_string($bearer) && preg_match('/\A[1-9][0-9]*\|[A-Za-z0-9]{40,128}\z/', $bearer), 401);
        $token = AgentAccessToken::findToken($bearer);
        $client = $token?->client;
        $owner = $token?->tokenable;
        $abilities = $token?->abilities;
        $allowed = $token?->allowed_account_ids;
        abort_unless($token && $client?->active && $owner instanceof User && $owner->hasVerifiedEmail()
            && $client->owner_id === $owner->id && $token->expires_at?->isFuture()
            && $token->created_at && $token->expires_at->lte($token->created_at->copy()->addDays(30))
            && (! config('sanctum.expiration') || $token->created_at->gt(now()->subMinutes(config('sanctum.expiration'))))
            && is_array($abilities) && array_is_list($abilities) && count($abilities) > 0
            && count(array_filter($abilities, fn (mixed $ability): bool => is_string($ability) && in_array($ability, AgentTokenService::ABILITIES, true))) === count($abilities)
            && ($allowed === null || (is_array($allowed) && array_is_list($allowed) && count($allowed) > 0
                && count(array_filter($allowed, fn (mixed $id): bool => is_int($id) && $id > 0)) === count($allowed))), 401);
        $request->attributes->set('agent_token', $token);
        $request->attributes->set('agent_client', $client);
        $request->setUserResolver(fn () => $owner->withAccessToken($token));
    }
}
