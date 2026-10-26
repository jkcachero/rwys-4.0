<?php

namespace App\Services;

use App\Enums\AgentAbility;
use App\Models\Client;
use App\Models\PersonalAccessToken;
use App\Models\User;
use DateTimeInterface;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\NewAccessToken;

class AgentTokenService
{
    /** @param list<string> $allowedAccountIds */
    public function issue(
        User $owner,
        Client $client,
        string $name,
        ?DateTimeInterface $expiresAt = null,
        array $allowedAccountIds = [],
        ?PersonalAccessToken $rotatedFrom = null,
    ): NewAccessToken {
        return DB::transaction(function () use (
            $owner,
            $client,
            $name,
            $expiresAt,
            $allowedAccountIds,
            $rotatedFrom,
        ): NewAccessToken {
            $owner = User::query()->lockForUpdate()->find($owner->getKey());
            $client = Client::query()->lockForUpdate()->find($client->getKey());

            if ($owner === null || $client === null || ! $client->is_active || ! $owner->hasVerifiedEmail()
                || $client->users()->whereKey($owner->id)->wherePivot('is_active', true)
                    ->wherePivot('role', 'owner')->lockForUpdate()->first() === null) {
                throw new DomainException('Only a verified active Client owner may issue an agent token.');
            }

            if ($rotatedFrom !== null) {
                $rotatedFrom = PersonalAccessToken::query()->lockForUpdate()->find($rotatedFrom->getKey());

                if ($rotatedFrom === null || $rotatedFrom->client_id !== $client->id || $rotatedFrom->revoked_at !== null) {
                    throw new DomainException('Only an active token belonging to this Client may be rotated.');
                }

                if ($rotatedFrom->expires_at === null || ! $rotatedFrom->expires_at->isFuture()) {
                    throw new DomainException('Only unexpired finite-expiry agent tokens may be rotated.');
                }

                $currentAccountIds = $rotatedFrom->allowed_account_ids;

                if (! is_array($currentAccountIds) || ($currentAccountIds !== []
                    && ($allowedAccountIds === [] || array_diff($allowedAccountIds, $currentAccountIds) !== []))) {
                    throw new DomainException('Token rotation cannot add account access.');
                }

                $expiresAt = $rotatedFrom->expires_at;
            }

            $expiration = Carbon::instance($expiresAt ?? now()->addDays(30));

            if (! $expiration->isFuture() || $expiration->greaterThan(now()->addDays(90))) {
                throw new DomainException('Agent tokens must expire within 90 days.');
            }

            $validatedAccountIds = $this->validateAllowedAccounts($client, $allowedAccountIds);
            $abilities = $rotatedFrom !== null
                ? array_values(array_intersect($rotatedFrom->abilities, AgentAbility::implemented()))
                : AgentAbility::clientTokenDefaults();
            $token = $owner->createToken($name, $abilities, $expiration);
            $token->accessToken->forceFill([
                'client_id' => $client->id,
                'issued_by' => $owner->id,
                'rotated_from_id' => $rotatedFrom?->id,
                'allowed_account_ids' => $validatedAccountIds,
            ])->save();

            if ($rotatedFrom !== null) {
                $rotatedFrom->forceFill(['revoked_at' => now()])->save();
            }

            return $token;
        });
    }

    /** @param list<string> $allowedAccountIds */
    public function rotate(
        User $owner,
        Client $client,
        PersonalAccessToken $token,
        string $name,
        array $allowedAccountIds = [],
    ): NewAccessToken {
        return $this->issue(
            $owner,
            $client,
            $name,
            allowedAccountIds: $allowedAccountIds,
            rotatedFrom: $token,
        );
    }

    public function revoke(User $owner, Client $client, PersonalAccessToken $token): void
    {
        if (! $client->hasActiveOwner($owner) || $token->client_id !== $client->id) {
            throw new DomainException('The token does not belong to this Client.');
        }

        $token->forceFill(['revoked_at' => now()])->save();
    }

    /**
     * @param  list<string>  $allowedAccountIds
     * @return list<string>
     */
    private function validateAllowedAccounts(Client $client, array $allowedAccountIds): array
    {
        $uniqueIds = array_values(array_unique($allowedAccountIds));

        if ($uniqueIds === []) {
            return [];
        }

        $availableIds = $client->accountPermissions()
            ->where('is_active', true)
            ->whereHas('account', fn ($query) => $query
                ->where('is_active', true)
                ->whereIn('public_id', $uniqueIds))
            ->with('account:id,public_id')
            ->get()
            ->pluck('account.public_id')
            ->all();

        sort($uniqueIds);
        sort($availableIds);

        if ($uniqueIds !== $availableIds) {
            throw new DomainException('Every allowed account must be active for this Client.');
        }

        return $uniqueIds;
    }
}
