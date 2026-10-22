<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ClientAccountPermission;
use App\Models\ProviderAccount;
use App\Models\ProviderProfile;
use App\Models\User;
use Illuminate\Support\Facades\DB;

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
        });
    }

    /** @param list<array{provider_id: string, platform: string, name: string}> $accounts */
    public function synchronize(Client $client, ProviderProfile $profile, array $accounts): void
    {
        $this->validateAccounts($accounts);

        if (! $client->providerProfiles()->whereKey($profile->id)->exists()) {
            throw new \LogicException('The provider profile is not assigned to this client.');
        }

        DB::transaction(fn () => $this->synchronizeAccounts($client, $profile, $accounts));
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
            $account = ProviderAccount::query()->updateOrCreate(
                ['provider_profile_id' => $profile->id, 'provider_id_hash' => $hash],
                [
                    'platform' => $accountData['platform'],
                    'provider_id' => $accountData['provider_id'],
                    'name' => $accountData['name'],
                    'synchronized_at' => now(),
                    'is_active' => true,
                ],
            );
            $activeAccountIds[] = $account->id;

            ClientAccountPermission::query()->updateOrCreate(
                ['client_id' => $client->id, 'provider_account_id' => $account->id],
                ['is_active' => true, 'can_read' => true],
            );
        }

        $profileAccountIds = $profile->accounts()->pluck('id');
        ProviderAccount::query()->whereIn('id', $profileAccountIds)->whereNotIn('id', $activeAccountIds)->update(['is_active' => false]);
        ClientAccountPermission::query()->where('client_id', $client->id)->whereIn('provider_account_id', $profileAccountIds)->whereNotIn('provider_account_id', $activeAccountIds)->update(['is_active' => false]);
        $profile->update(['synchronized_at' => now()]);
    }
}
