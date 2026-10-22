<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ClientAccount;
use App\Models\ClientProviderProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ClientProviderProfileService
{
    public function claim(Client $client, string $provider, string $environment, string $profileId, ?string $name = null): ClientProviderProfile
    {
        if (DB::transactionLevel() === 0) {
            throw new RuntimeException('Profile claims require a caller-owned transaction.');
        }
        $snapshot = Client::query()->find($client->getKey());
        $owner = $snapshot ? User::query()->lockForUpdate()->find($snapshot->owner_id) : null;
        $client = Client::query()->lockForUpdate()->find($client->getKey());
        if (! $client?->active || ! $owner?->hasVerifiedEmail() || (string) $client->owner_id !== (string) $owner->id) {
            throw new RuntimeException('Profile claims require an active client with a verified owner.');
        }

        $identity = ['provider' => $provider, 'environment' => $environment, 'profile_id' => $profileId];
        $profile = ClientProviderProfile::query()->createOrFirst($identity, ['client_id' => $client->id, 'name' => $name, 'active' => true]);
        $created = $profile->wasRecentlyCreated;
        $profile = ClientProviderProfile::query()->whereKey($profile->id)->lockForUpdate()->firstOrFail();
        $profile->wasRecentlyCreated = $created;
        if ((string) $profile->client_id !== (string) $client->id || ! $profile->active
            || $profile->provider !== $provider || $profile->environment !== $environment || $profile->profile_id !== $profileId) {
            throw new RuntimeException('Provider profile is unavailable for this client.');
        }

        $accounts = ClientAccount::query()->where(function (Builder $query) use ($identity, $profile): void {
            $query->where($identity)->orWhere('client_provider_profile_id', $profile->id);
        })->lockForUpdate()->cursor();
        foreach ($accounts as $account) {
            if ((string) $account->client_id !== (string) $client->id
                || $account->provider !== $provider || $account->environment !== $environment || $account->profile_id !== $profileId
                || ($account->client_provider_profile_id !== null && (string) $account->client_provider_profile_id !== (string) $profile->id)) {
                throw new RuntimeException('Existing account mapping conflicts with profile ownership.');
            }
        }

        if ($name !== null && $profile->name !== $name) {
            $profile->forceFill(['name' => $name])->save();
        }

        return $profile;
    }
}
