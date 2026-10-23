<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ClientAccountPermission;
use App\Models\ClientOperatorGrant;
use App\Models\ProviderAccount;
use App\Models\ProviderConnection;
use App\Models\ProviderProfile;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ClientProfileAssignmentService
{
    /** @param list<array{provider_id: string, platform: string, name: string}> $accounts */
    public function assign(Client $client, ProviderProfile $profile, array $accounts, ?User $actor = null): void
    {
        $this->validateAccounts($accounts);

        DB::transaction(function () use ($client, $profile, $accounts, $actor): void {
            $client->providerProfiles()->attach($profile, [
                'assigned_by' => $actor?->id,
                'assigned_at' => now(),
            ]);

            $this->synchronizeAccounts($client, $profile, $accounts);
            $profile->increment('sync_generation');
        });
    }

    /** @param list<array{provider_id: string, platform: string, name: string}> $accounts */
    public function synchronize(Client $client, ProviderProfile $profile, array $accounts): void
    {
        $this->validateAccounts($accounts);

        if (! $client->providerProfiles()->whereKey($profile->id)->exists()) {
            throw new \LogicException('The provider profile is not assigned to this client.');
        }

        DB::transaction(function () use ($client, $profile, $accounts): void {
            $this->synchronizeAccounts($client, $profile, $accounts);
            $profile->increment('sync_generation');
        });
    }

    /**
     * @param  array{provider_id: string, name: string}  $profileData
     * @param  list<array{provider_id: string, platform: string, name: string}>  $accounts
     */
    public function assignSnapshot(
        User $operator,
        Client $client,
        ProviderConnection $connection,
        array $profileData,
        array $accounts,
        ?int $expectedGeneration,
    ): ProviderProfile {
        $this->validateAccounts($accounts);

        return DB::transaction(function () use ($operator, $client, $connection, $profileData, $accounts, $expectedGeneration): ProviderProfile {
            $lockedClient = Client::query()->lockForUpdate()->findOrFail($client->id);
            $this->assertAssignmentAuthority($operator, $lockedClient);

            if ($connection->user_id !== $operator->id || $connection->provider !== 'zernio') {
                throw ValidationException::withMessages([
                    'provider_profile_id' => 'The provider profile is not available through your Zernio connection.',
                ]);
            }

            $profileHash = hash('sha256', $profileData['provider_id']);
            $profile = ProviderProfile::query()
                ->where('provider', 'zernio')
                ->where('provider_id_hash', $profileHash)
                ->lockForUpdate()
                ->first();

            if ($profile === null) {
                if ($expectedGeneration !== null) {
                    throw $this->staleSnapshot();
                }

                $profile = ProviderProfile::query()->createOrFirst(
                    [
                        'provider' => 'zernio',
                        'provider_id_hash' => $profileHash,
                    ],
                    [
                        'provider_connection_id' => $connection->id,
                        'provider_id' => $profileData['provider_id'],
                        'name' => $profileData['name'],
                        'is_active' => true,
                    ],
                );

                if (! $profile->wasRecentlyCreated) {
                    throw $this->staleSnapshot();
                }
            } elseif ($expectedGeneration === null || $profile->sync_generation !== $expectedGeneration) {
                throw $this->staleSnapshot();
            }

            if ($profile->provider_connection_id !== $connection->id) {
                throw ValidationException::withMessages([
                    'provider_profile_id' => 'This Zernio profile belongs to a different provider connection.',
                ]);
            }

            $assignment = DB::table('client_provider_profiles')
                ->where('provider_profile_id', $profile->id)
                ->lockForUpdate()
                ->first();

            if ($assignment !== null) {
                throw ValidationException::withMessages([
                    'provider_profile_id' => $assignment->client_id === $lockedClient->id
                        ? 'This Zernio profile is already assigned. Use Sync accounts instead.'
                        : 'This Zernio profile is already assigned to another Client.',
                ]);
            }

            $lockedClient->providerProfiles()->attach($profile, [
                'assigned_by' => $operator->id,
                'assigned_at' => now(),
            ]);

            $profile->update([
                'name' => $profileData['name'],
                'is_active' => true,
            ]);
            $this->synchronizeAccounts($lockedClient, $profile, $accounts);
            $profile->increment('sync_generation');

            return $profile->fresh();
        }, attempts: 3);
    }

    /**
     * @param  array{provider_id: string, name: string}  $profileData
     * @param  list<array{provider_id: string, platform: string, name: string}>  $accounts
     */
    public function synchronizeSnapshot(
        User $operator,
        Client $client,
        ProviderProfile $profile,
        array $profileData,
        array $accounts,
        int $expectedGeneration,
    ): void {
        $this->validateAccounts($accounts);

        DB::transaction(function () use ($operator, $client, $profile, $profileData, $accounts, $expectedGeneration): void {
            $lockedClient = Client::query()->lockForUpdate()->findOrFail($client->id);
            $this->assertAssignmentAuthority($operator, $lockedClient);
            $lockedProfile = ProviderProfile::query()->lockForUpdate()->findOrFail($profile->id);

            if ($lockedProfile->sync_generation !== $expectedGeneration) {
                throw $this->staleSnapshot();
            }

            if (! $lockedClient->providerProfiles()->whereKey($lockedProfile->id)->exists()) {
                throw ValidationException::withMessages([
                    'provider_profile_id' => 'This Zernio profile is not assigned to the selected Client.',
                ]);
            }

            if ($lockedProfile->provider_id !== $profileData['provider_id']) {
                throw ValidationException::withMessages([
                    'provider_profile_id' => 'Zernio returned a different profile than the assigned profile.',
                ]);
            }

            $lockedProfile->update(['name' => $profileData['name']]);
            $this->synchronizeAccounts($lockedClient, $lockedProfile, $accounts);
            $lockedProfile->increment('sync_generation');
        }, attempts: 3);
    }

    public function assertAssignmentAuthority(User $operator, Client $client): void
    {
        $authorized = $operator->hasVerifiedEmail()
            && $operator->isAdmin()
            && $client->is_active
            && $client->users()->wherePivot('role', 'owner')->wherePivot('is_active', true)->exists()
            && ClientOperatorGrant::query()
                ->where('client_id', $client->id)
                ->where('user_id', $operator->id)
                ->where('is_active', true)
                ->exists();

        abort_unless($authorized, 403);
    }

    /** @param list<array{provider_id: string, platform: string, name: string}> $accounts */
    private function validateAccounts(array $accounts): void
    {
        if (count($accounts) > 300) {
            throw new \InvalidArgumentException('A provider profile may contain at most 300 accounts.');
        }
    }

    /** @param list<array{provider_id: string, platform: string, name: string}> $accounts */
    private function synchronizeAccounts(Client $client, ProviderProfile $profile, array $accounts): void
    {
        $activeAccountIds = [];

        foreach ($accounts as $accountData) {
            $hash = hash('sha256', $accountData['provider_id']);
            $account = ProviderAccount::query()
                ->where('provider_profile_id', $profile->id)
                ->where('provider_id_hash', $hash)
                ->first();

            if ($account === null) {
                $account = ProviderAccount::query()->create([
                    'provider_profile_id' => $profile->id,
                    'platform' => $accountData['platform'],
                    'provider_id' => $accountData['provider_id'],
                    'provider_id_hash' => $hash,
                    'name' => $accountData['name'],
                    'synchronized_at' => now(),
                    'is_active' => true,
                ]);
            } else {
                $account->update([
                    'platform' => $accountData['platform'],
                    'provider_id' => $accountData['provider_id'],
                    'name' => $accountData['name'],
                    'synchronized_at' => now(),
                ]);
            }

            $activeAccountIds[] = $account->id;

            ClientAccountPermission::query()->firstOrCreate(
                ['client_id' => $client->id, 'provider_account_id' => $account->id],
                ['is_active' => true, 'can_schedule' => false],
            );
        }

        $profileAccountIds = $profile->accounts()->pluck('id');
        ProviderAccount::query()->whereIn('id', $profileAccountIds)->whereNotIn('id', $activeAccountIds)->update(['is_active' => false]);
        ClientAccountPermission::query()->where('client_id', $client->id)->whereIn('provider_account_id', $profileAccountIds)->whereNotIn('provider_account_id', $activeAccountIds)->update(['is_active' => false]);
        $profile->update(['synchronized_at' => now()]);
    }

    private function staleSnapshot(): ValidationException
    {
        return ValidationException::withMessages([
            'provider_profile_id' => 'The profile changed while it was loading. Refresh and try again.',
        ]);
    }
}
