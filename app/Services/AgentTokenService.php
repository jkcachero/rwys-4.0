<?php

namespace App\Services;

use App\Models\AgentAccessToken;
use App\Models\Client;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\NewAccessToken;

class AgentTokenService
{
    public const array READ_ABILITIES = ['context.read', 'zernio.accounts.read', 'zernio.posts.read', 'zernio.analytics.read', 'operations.read'];

    public const array ABILITIES = [...self::READ_ABILITIES, 'zernio.posts.publish', 'zernio.posts.schedule'];

    /**
     * @param  list<string>  $abilities
     * @param  list<int>|null  $accountIds
     */
    public function issue(User $user, Client $client, string $name, array $abilities, ?array $accountIds = null): NewAccessToken
    {
        return DB::transaction(function () use ($user, $client, $name, $abilities, $accountIds): NewAccessToken {
            $user = User::query()->lockForUpdate()->find($user->getKey());
            abort_unless($user?->hasVerifiedEmail(), 403);
            $client = Client::query()->lockForUpdate()->findOrFail($client->getKey());
            $this->authorizeOwner($user, $client);
            $this->validateScope($client, $name, $abilities, $accountIds);

            $issued = $user->createToken($name, $abilities, now()->addDays(30));
            $issued->accessToken->forceFill(['client_id' => $client->id, 'allowed_account_ids' => $accountIds])->save();

            return $issued;
        }, 3);
    }

    public function rotate(User $user, AgentAccessToken $token): NewAccessToken
    {
        return DB::transaction(function () use ($user, $token): NewAccessToken {
            $user = User::query()->lockForUpdate()->find($user->getKey());
            abort_unless($user?->hasVerifiedEmail(), 403);
            $snapshot = AgentAccessToken::query()->findOrFail($token->getKey());
            $client = Client::query()->lockForUpdate()->find($snapshot->client_id);
            $token = AgentAccessToken::query()->lockForUpdate()->findOrFail($token->getKey());
            abort_unless($token->client_id === $snapshot->client_id
                && $token->tokenable_type === $snapshot->tokenable_type && $token->tokenable_id === $snapshot->tokenable_id, 403);
            $this->authorizeOwner($user, $client);
            abort_unless($token->tokenable_type === $user->getMorphClass() && (int) $token->tokenable_id === $user->id, 403);
            $this->validateScope($client, $token->name, $token->abilities, $token->allowed_account_ids);
            if ($token->expires_at === null || $token->created_at === null || $token->expires_at->isPast()
                || $token->expires_at->gt(now()->addDays(30)) || $token->expires_at->gt($token->created_at->copy()->addDays(30))) {
                throw ValidationException::withMessages(['token' => 'Only unexpired tokens with a maximum 30-day lifetime can be rotated.']);
            }

            $issued = $user->createToken($token->name, $token->abilities, $token->expires_at);
            $issued->accessToken->forceFill(['client_id' => $client->id, 'allowed_account_ids' => $token->allowed_account_ids])->save();
            $token->delete();

            return $issued;
        }, 3);
    }

    public function revoke(User $user, AgentAccessToken $token): void
    {
        DB::transaction(function () use ($user, $token): void {
            $user = User::query()->lockForUpdate()->find($user->getKey());
            abort_unless($user?->hasVerifiedEmail(), 403);
            $snapshot = AgentAccessToken::query()->findOrFail($token->getKey());
            $client = Client::query()->lockForUpdate()->find($snapshot->client_id);
            $token = AgentAccessToken::query()->lockForUpdate()->findOrFail($token->getKey());
            abort_unless($token->client_id === $snapshot->client_id
                && $token->tokenable_type === $snapshot->tokenable_type && $token->tokenable_id === $snapshot->tokenable_id, 403);
            $this->authorizeOwner($user, $client, requireActive: false);
            abort_unless($token->tokenable_type === $user->getMorphClass() && (int) $token->tokenable_id === $user->id, 403);
            $token->delete();
        }, 3);
    }

    private function authorizeOwner(User $user, ?Client $client, bool $requireActive = true): void
    {
        abort_unless($user->hasVerifiedEmail() && $client !== null && (! $requireActive || $client->active) && $client->owner_id !== null && (int) $client->owner_id === $user->id, 403);
    }

    /**
     * @param  list<string>  $abilities
     * @param  list<int>|null  $accountIds
     */
    private function validateScope(Client $client, string $name, array $abilities, ?array $accountIds): void
    {
        Validator::make(['name' => $name, 'abilities' => $abilities, 'allowed_account_ids' => $accountIds], [
            'name' => ['required', 'string', 'max:100'],
            'abilities' => ['required', 'array', 'list', 'min:1'],
            'abilities.*' => ['required', 'string', 'distinct:strict', Rule::in(self::ABILITIES)],
            'allowed_account_ids' => ['nullable', 'array', 'list', 'min:1'],
            'allowed_account_ids.*' => ['required', 'integer:strict', 'min:1', 'distinct'],
        ])->validate();

        $accounts = $client->accounts()->withOwnedProfile()->where('active', true)->where('provider', 'zernio')->where('environment', 'production');
        if ($accountIds !== null) {
            $accounts->whereIn('id', $accountIds);
        }
        $mapped = $accounts->get(['id', 'platform', 'publishing_enabled']);
        if ($accountIds !== null && $mapped->count() !== count($accountIds)) {
            throw ValidationException::withMessages(['allowed_account_ids' => 'Select only active accounts mapped to this client.']);
        }
        if (in_array('zernio.posts.publish', $abilities, true) && (config('agent-api.zernio.publishing_enabled', false) !== true || ! $mapped->contains(fn ($account): bool => $account->publishing_enabled && in_array($account->platform, ['linkedin', 'facebook', 'threads', 'bluesky'], true)))) {
            throw ValidationException::withMessages(['abilities' => 'Publishing requires a supported production account with publishing enabled in the selected scope and server publishing permission.']);
        }
        if (in_array('zernio.posts.schedule', $abilities, true)
            && (config('agent-api.zernio.enabled', false) !== true
                || config('agent-api.zernio.publishing_enabled', false) !== true
                || config('agent-api.zernio.instagram_scheduling_enabled', false) !== true
                || ! $mapped->contains(fn ($account): bool => $account->publishing_enabled && $account->platform === 'instagram'))) {
            throw ValidationException::withMessages(['abilities' => 'Scheduling requires an owned active Instagram account with publishing enabled in the selected scope and all server scheduling permissions.']);
        }
    }
}
