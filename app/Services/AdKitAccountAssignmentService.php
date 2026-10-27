<?php

namespace App\Services;

use App\AdKit\AdKitHttpClient;
use App\Exceptions\AdKit\AdKitRequestException;
use App\Models\Client;
use App\Models\ClientAccountPermission;
use App\Models\ProviderAccount;
use App\Models\ProviderConnection;
use App\Models\ProviderProfile;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AdKitAccountAssignmentService
{
    public function __construct(private AdKitHttpClient $adkit) {}

    /** @return array{accounts: list<array{id: string, name: string, currency: string, status: string|int, assigned_to_current_client: bool, assigned_elsewhere: bool}>} */
    public function discover(User $admin, Client $client): array
    {
        $this->assertAuthority($admin, $client);
        [$connection] = $this->mapping($admin);
        $accounts = $this->adkit->connectedAccounts($connection);
        $permissions = ClientAccountPermission::query()->where('is_active', true)
            ->whereHas('account', fn ($query) => $query
                ->whereIn('provider_id_hash', array_map(fn (array $account): string => hash('sha256', $account['id']), $accounts))
                ->whereHas('profile', fn ($query) => $query->where('provider', 'adkit')))
            ->with('account:id,provider_id_hash')->get()->groupBy('account.provider_id_hash');
        $this->assertAuthority($admin, $client);

        return ['accounts' => array_map(function (array $account) use ($permissions, $client): array {
            $claims = $permissions->get(hash('sha256', $account['id']), collect());

            return [
                'id' => $account['id'], 'name' => $account['name'], 'currency' => $account['currency'], 'status' => $account['status'],
                'assigned_to_current_client' => $claims->contains('client_id', $client->id),
                'assigned_elsewhere' => $claims->contains(fn (ClientAccountPermission $claim): bool => $claim->client_id !== $client->id),
            ];
        }, $accounts)];
    }

    public function assign(User $admin, Client $client, string $adAccountId): ProviderAccount
    {
        $this->assertAuthority($admin, $client);
        if (strlen($adAccountId) > 255 || preg_match('/\Aact_[0-9]+\z/', $adAccountId) !== 1) {
            throw new AdKitRequestException('adkit_invalid_request');
        }

        [$connection, $profile] = $this->mapping($admin);
        $existing = $this->target($client, $profile, $adAccountId);
        $connectionSnapshot = $this->connectionSnapshot($connection);
        $profileSnapshot = $profile?->getRawOriginal();
        $accountSnapshot = $existing?->getRawOriginal();
        $membership = collect($this->adkit->connectedAccounts($connection))->where('id', $adAccountId);
        $this->assertAuthority($admin, $client);
        [$connection, $profile] = $this->mapping($admin);
        if ($this->connectionSnapshot($connection) !== $connectionSnapshot || $profile?->getRawOriginal() !== $profileSnapshot) {
            throw new AdKitRequestException('adkit_mapping_invalid');
        }
        $existing = $this->target($client, $profile, $adAccountId);
        if ($existing?->getRawOriginal() !== $accountSnapshot) {
            throw new AdKitRequestException('adkit_mapping_invalid');
        }
        if ($membership->count() !== 1) {
            throw new AdKitRequestException('adkit_invalid_response');
        }

        $transientProfile = (new ProviderProfile(['provider' => 'adkit', 'provider_id' => $connection->client_id]))
            ->setRelation('providerConnection', $connection);
        $identity = $this->adkit->read($transientProfile, '/'.$adAccountId, [
            'fields' => 'id,name,account_id,account_status,disable_reason,currency,timezone_name',
        ], 'Verify account access');
        if (($identity['id'] ?? null) !== $adAccountId
            || ($identity['account_id'] ?? null) !== substr($adAccountId, 4)
            || ! is_string($identity['name'] ?? null) || trim($identity['name']) === '' || mb_strlen($identity['name']) > 255
            || (! is_int($identity['account_status'] ?? null) && ! is_string($identity['account_status'] ?? null))
            || (! is_int($identity['disable_reason'] ?? null) && ! is_string($identity['disable_reason'] ?? null))
            || ! is_string($identity['currency'] ?? null) || preg_match('/\A[A-Z]{3}\z/', $identity['currency']) !== 1
            || ! is_string($identity['timezone_name'] ?? null) || trim($identity['timezone_name']) === '') {
            throw new AdKitRequestException('adkit_invalid_response');
        }

        try {
            $account = DB::transaction(function () use ($admin, $client, $adAccountId, $identity, $connectionSnapshot, $profileSnapshot, $accountSnapshot): ProviderAccount {
                $database = DB::connection();
                if ($database->getDriverName() === 'pgsql') {
                    // Global identity, not project/Client: held through commit, including absent targets.
                    $lock = $database->selectOne(
                        "SELECT pg_advisory_xact_lock(CAST(? AS bigint)), current_setting('transaction_isolation') AS isolation_level",
                        [hexdec(substr(hash('sha256', 'rwys:adkit:account-assignment:'.$adAccountId), 0, 15))],
                        false,
                    );
                    if ($lock->isolation_level !== 'read committed') {
                        throw new AdKitRequestException('adkit_mapping_invalid');
                    }
                } elseif ($database->getDriverName() !== 'sqlite') {
                    throw new AdKitRequestException('adkit_mapping_invalid');
                }
                // SQLite serializes writers; stale read-to-write upgrades fail and roll back.
                $this->authorizedContext($admin, $client, lock: true);
                [$connection, $profile] = $this->mapping($admin, lock: true);
                if ($this->connectionSnapshot($connection) !== $connectionSnapshot || $profile?->getRawOriginal() !== $profileSnapshot) {
                    throw new AdKitRequestException('adkit_mapping_invalid');
                }
                $account = $this->target($client, $profile, $adAccountId, lock: true);
                if ($account?->getRawOriginal() !== $accountSnapshot) {
                    throw new AdKitRequestException('adkit_mapping_invalid');
                }

                if (! $connection->exists) {
                    $connection = $admin->providerConnections()->create([
                        'provider' => 'adkit', 'client_id' => $connection->client_id, 'subject' => $connection->subject,
                        'access_token' => $connection->access_token, 'scopes' => ['ads_read'],
                    ]);
                }
                if ($profile === null) {
                    $profile = ProviderProfile::query()->create([
                        'provider' => 'adkit', 'provider_connection_id' => $connection->id,
                        'provider_id' => $connection->client_id, 'provider_id_hash' => hash('sha256', $connection->client_id),
                        'name' => 'AdKit project', 'is_active' => true,
                    ]);
                }
                if ($account === null) {
                    $account = $profile->accounts()->create([
                        'platform' => 'meta', 'provider_id' => $adAccountId, 'provider_id_hash' => hash('sha256', $adAccountId),
                        'name' => $identity['name'], 'is_active' => true, 'synchronized_at' => now(),
                    ]);
                } else {
                    $account->update(['name' => $identity['name'], 'synchronized_at' => now()]);
                }
                $permission = ClientAccountPermission::query()->where('client_id', $client->id)
                    ->where('provider_account_id', $account->id)->lockForUpdate()->first();
                if ($permission === null) {
                    ClientAccountPermission::query()->create([
                        'client_id' => $client->id, 'provider_account_id' => $account->id, 'is_active' => true, 'can_schedule' => false,
                    ]);
                } else {
                    $permission->update(['is_active' => true]);
                }

                return $account;
            }, attempts: 3);
        } catch (QueryException) {
            // A concurrent insert must fail closed without exposing SQL bindings or credentials.
            throw new AdKitRequestException('adkit_mapping_invalid');
        }

        Log::info('AdKit account assigned.', ['actor_id' => $admin->id, 'client_id' => $client->public_id, 'account_id' => $account->public_id]);

        return $account;
    }

    public function remove(User $admin, Client $client, ProviderAccount $account): void
    {
        DB::transaction(function () use ($admin, $client, $account): void {
            $this->authorizedContext($admin, $client, lock: true);
            $freshAccount = ProviderAccount::query()->find($account->id);
            $profile = $freshAccount === null ? null : ProviderProfile::query()->lockForUpdate()->find($freshAccount->provider_profile_id);
            abort_unless($profile?->provider === 'adkit', 404);
            $freshAccount = ProviderAccount::query()->lockForUpdate()->find($account->id);
            abort_unless($freshAccount?->provider_profile_id === $profile->id, 404);
            $permission = ClientAccountPermission::query()->where('client_id', $client->id)
                ->where('provider_account_id', $freshAccount->id)->lockForUpdate()->first();
            abort_if($permission === null, 404);
            $permission->update(['is_active' => false]);
        }, attempts: 3);

        Log::info('AdKit account removed.', ['actor_id' => $admin->id, 'client_id' => $client->public_id, 'account_id' => $account->public_id]);
    }

    public function assertAuthority(User $admin, Client $client): void
    {
        $this->authorizedContext($admin, $client);
    }

    private function authorizedContext(User $admin, Client $client, bool $lock = false): void
    {
        $freshAdmin = User::query()->when($lock, fn ($query) => $query->lockForUpdate())->find($admin->id);
        $freshClient = Client::query()->when($lock, fn ($query) => $query->lockForUpdate())->find($client->id);
        if ($freshAdmin === null || ! $freshAdmin->isAdmin() || ! $freshAdmin->hasVerifiedEmail() || $freshClient === null || ! $freshClient->is_active) {
            throw new AuthorizationException;
        }
        $owners = DB::table('client_user')->where('client_id', $freshClient->id)->where('role', 'owner')->where('is_active', true)
            ->when($lock, fn ($query) => $query->lockForUpdate())->get(['user_id']);
        $owner = User::query()->whereIn('id', $owners->pluck('user_id'))->whereNotNull('email_verified_at')
            ->when($lock, fn ($query) => $query->lockForUpdate())->first();
        if ($owner === null) {
            throw new AuthorizationException;
        }
    }

    /** @return array{ProviderConnection, ProviderProfile|null} */
    private function mapping(User $admin, bool $lock = false): array
    {
        try {
            $connection = $admin->providerConnections()->where('provider', 'adkit')
                ->when($lock, fn ($query) => $query->lockForUpdate())->first();
            $project = $connection?->client_id ?? config('services.adkit.project_id');
            if (! is_string($project) || trim($project) === '' || strlen($project) > 255 || preg_match('/[\x00-\x1f\x7f]/', $project)) {
                throw new AdKitRequestException('adkit_configuration_error');
            }
            $profile = ProviderProfile::query()->where('provider', 'adkit')->where('provider_id_hash', hash('sha256', $project))
                ->when($lock, fn ($query) => $query->lockForUpdate())->first();
            if ($connection === null && $profile !== null) {
                $connection = ProviderConnection::query()->when($lock, fn ($query) => $query->lockForUpdate())->find($profile->provider_connection_id);
                if ($connection === null) {
                    throw new AdKitRequestException('adkit_mapping_invalid');
                }
            }
            if ($connection === null) {
                $subject = config('services.adkit.meta_account_id');
                $token = config('services.adkit.api_key');
                if (! is_string($subject) || strlen($subject) > 251 || preg_match('/\A(?:act_)?([0-9]+)\z/', $subject, $match) !== 1
                    || ! is_string($token) || trim($token) === '' || preg_match('/[\x00-\x1f\x7f]/', $token)) {
                    throw new AdKitRequestException('adkit_configuration_error');
                }
                $connection = new ProviderConnection(['provider' => 'adkit', 'client_id' => $project, 'subject' => 'act_'.$match[1], 'access_token' => $token, 'scopes' => ['ads_read']]);
            }
            if ($connection->provider !== 'adkit' || $connection->client_id !== $project
                || ! is_string($connection->subject) || strlen($connection->subject) > 255 || preg_match('/\Aact_[0-9]+\z/', $connection->subject) !== 1
                || ! is_string($connection->access_token) || trim($connection->access_token) === '' || preg_match('/[\x00-\x1f\x7f]/', $connection->access_token)
                || ($connection->access_token_expires_at !== null && $connection->access_token_expires_at->isPast())
                || ($profile !== null && ($profile->provider_connection_id !== $connection->id || $profile->provider_id !== $project || ! $profile->is_active))) {
                throw new AdKitRequestException('adkit_mapping_invalid');
            }
            if ($connection->exists && ProviderProfile::query()->where('provider_connection_id', $connection->id)
                ->when($profile !== null, fn ($query) => $query->whereKeyNot($profile->id))
                ->when($lock, fn ($query) => $query->lockForUpdate())->first(['id']) !== null) {
                throw new AdKitRequestException('adkit_mapping_invalid');
            }

            return [$connection, $profile];
        } catch (DecryptException) {
            throw new AdKitRequestException('adkit_mapping_invalid');
        }
    }

    private function target(Client $client, ?ProviderProfile $profile, string $adAccountId, bool $lock = false): ?ProviderAccount
    {
        $accounts = ProviderAccount::query()->where('provider_id_hash', hash('sha256', $adAccountId))
            ->whereHas('profile', fn ($query) => $query->where('provider', 'adkit'))
            ->when($lock, fn ($query) => $query->lockForUpdate())->get();
        if ($accounts->count() > 1) {
            throw new AdKitRequestException('adkit_mapping_invalid');
        }
        $account = $accounts->first();
        if ($account !== null) {
            try {
                if ($account->provider_profile_id !== $profile?->id || $account->provider_id !== $adAccountId || $account->platform !== 'meta' || ! $account->is_active) {
                    throw new AdKitRequestException('adkit_mapping_invalid');
                }
            } catch (DecryptException) {
                throw new AdKitRequestException('adkit_mapping_invalid');
            }
            $claims = $account->permissions()->where('is_active', true)->when($lock, fn ($query) => $query->lockForUpdate())->get();
            if ($claims->contains(fn (ClientAccountPermission $claim): bool => $claim->client_id !== $client->id)) {
                throw new AdKitRequestException('adkit_mapping_invalid');
            }
        }

        return $account;
    }

    /** @return array<string, mixed> */
    private function connectionSnapshot(ProviderConnection $connection): array
    {
        return [
            'id' => $connection->id, 'user_id' => $connection->user_id, 'provider' => $connection->provider,
            'project' => $connection->client_id, 'subject' => $connection->subject, 'token' => $connection->access_token,
            'refresh_token' => $connection->getRawOriginal('refresh_token'), 'expires_at' => $connection->access_token_expires_at?->toISOString(),
            'scopes' => $connection->scopes,
        ];
    }
}
