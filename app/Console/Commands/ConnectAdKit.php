<?php

namespace App\Console\Commands;

use App\AdKit\AdKitHttpClient;
use App\Models\Client;
use App\Models\ClientAccountPermission;
use App\Models\ClientOperatorGrant;
use App\Models\ProviderAccount;
use App\Models\ProviderConnection;
use App\Models\ProviderProfile;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

#[Signature('adkit:connect {client : Existing Client public UUID} {--operator= : Verified admin user ID} {--account= : Target Meta account ID; defaults to the configured connection account}')]
#[Description('Verify and connect the configured AdKit project and selected Meta account for read-only access')]
class ConnectAdKit extends Command
{
    public function handle(AdKitHttpClient $adkit): int
    {
        try {
            $apiKey = config('services.adkit.api_key');
            $projectId = config('services.adkit.project_id');
            $configuredAccount = config('services.adkit.meta_account_id');
            $requestedAccount = $this->option('account') ?? $configuredAccount;
            $clientUuid = $this->argument('client');
            $operatorId = $this->option('operator');

            if (! is_string($apiKey) || trim($apiKey) === '' || preg_match('/[\x00-\x1f\x7f]/', $apiKey)
                || ! is_string($projectId) || trim($projectId) === '' || strlen($projectId) > 255 || preg_match('/[\x00-\x1f\x7f]/', $projectId)
                || ! is_string($configuredAccount) || strlen($configuredAccount) > 255
                || preg_match('/\A(?:act_)?([0-9]+)\z/', $configuredAccount, $accountMatch) !== 1
                || ! is_string($requestedAccount) || strlen($requestedAccount) > 255
                || preg_match('/\A(?:act_)?([0-9]+)\z/', $requestedAccount, $targetMatch) !== 1
                || ! is_string($clientUuid) || ! Str::isUuid($clientUuid)
                || ! is_string($operatorId) || preg_match('/\A[1-9][0-9]*\z/', $operatorId) !== 1
                || filter_var($operatorId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
                throw new RuntimeException;
            }

            $connectionAccountId = 'act_'.$accountMatch[1];
            $accountId = 'act_'.$targetMatch[1];
            if (strlen($connectionAccountId) > 255 || strlen($accountId) > 255) {
                throw new RuntimeException;
            }

            [$client, $operator] = $this->authorizedContext($clientUuid, (int) $operatorId);
            $this->existingMapping($client, $operator, $projectId, $connectionAccountId);

            $credentials = [
                'provider' => 'adkit', 'client_id' => $projectId, 'subject' => $connectionAccountId,
                'access_token' => $apiKey, 'refresh_token' => null, 'access_token_expires_at' => null,
                'scopes' => ['ads_read'],
            ];
            $transientConnection = new ProviderConnection($credentials);
            $transientProfile = (new ProviderProfile(['provider' => 'adkit', 'provider_id' => $projectId]))
                ->setRelation('providerConnection', $transientConnection);

            $connectedAccount = collect($adkit->connectedAccounts($transientConnection))->firstWhere('id', $accountId);
            if ($connectedAccount === null) {
                throw new RuntimeException;
            }

            $identity = $adkit->read($transientProfile, '/'.$accountId, [
                'fields' => 'id,name,account_id,account_status,disable_reason,currency,timezone_name',
            ], 'Verify account access');

            if (($identity['id'] ?? null) !== $accountId
                || ($identity['account_id'] ?? null) !== $targetMatch[1]
                || ! is_string($identity['name'] ?? null) || trim($identity['name']) === '' || mb_strlen($identity['name']) > 255
                || ! is_int($identity['account_status'] ?? null)
                || ! is_int($identity['disable_reason'] ?? null)
                || ! is_string($identity['currency'] ?? null) || preg_match('/\A[A-Z]{3}\z/', $identity['currency']) !== 1
                || ! is_string($identity['timezone_name'] ?? null) || trim($identity['timezone_name']) === '') {
                throw new RuntimeException;
            }

            $rotated = DB::transaction(function () use ($clientUuid, $operatorId, $projectId, $connectionAccountId, $accountId, $credentials, $identity): bool {
                [$client, $operator] = $this->authorizedContext($clientUuid, (int) $operatorId, lock: true);
                [$connection, $profile] = $this->existingMapping($client, $operator, $projectId, $connectionAccountId, lock: true);
                $rotated = $connection !== null;

                if ($connection === null) {
                    $connection = $operator->providerConnections()->create($credentials);
                } else {
                    $connection->update($credentials);
                }

                if ($profile === null) {
                    $profile = ProviderProfile::query()->create([
                        'provider' => 'adkit', 'provider_connection_id' => $connection->id,
                        'provider_id' => $projectId, 'provider_id_hash' => hash('sha256', $projectId),
                        'name' => 'AdKit project', 'is_active' => true,
                    ]);
                }

                if (! $client->providerProfiles()->whereKey($profile->id)->exists()) {
                    $client->providerProfiles()->attach($profile, ['assigned_by' => $operator->id, 'assigned_at' => now()]);
                }

                $account = $profile->accounts()->where('provider_id_hash', hash('sha256', $accountId))->lockForUpdate()->first();
                if ($account === null) {
                    $account = $profile->accounts()->create([
                        'platform' => 'meta', 'provider_id' => $accountId, 'provider_id_hash' => hash('sha256', $accountId),
                        'name' => $identity['name'], 'is_active' => true, 'synchronized_at' => now(),
                    ]);
                } else {
                    if ($account->provider_id !== $accountId || $account->platform !== 'meta') {
                        throw new RuntimeException;
                    }
                    $account->update(['name' => $identity['name'], 'synchronized_at' => now()]);
                }

                ClientAccountPermission::query()->firstOrCreate(
                    ['client_id' => $client->id, 'provider_account_id' => $account->id],
                    ['is_active' => true, 'can_schedule' => false],
                );
                $profile->update(['name' => 'AdKit project', 'synchronized_at' => now()]);
                $profile->increment('sync_generation');

                return $rotated;
            });
        } catch (Throwable) {
            // Never render exception messages, SQL bindings, or provider response bodies here.
            $this->components->error('AdKit connection refused. Check configuration, authority, existing mappings, and provider access.');

            return self::FAILURE;
        }

        $this->components->info($rotated
            ? 'AdKit reconnected: 1 selected account; credentials rotated for the same mapping. Existing flags preserved.'
            : 'AdKit connected: 1 selected account. Existing flags preserved.');

        return self::SUCCESS;
    }

    /** @return array{Client, User} */
    private function authorizedContext(string $clientUuid, int $operatorId, bool $lock = false): array
    {
        $client = Client::query()->where('public_id', $clientUuid)->when($lock, fn ($query) => $query->lockForUpdate())->first();
        $operator = User::query()->when($lock, fn ($query) => $query->lockForUpdate())->find($operatorId);
        if ($client === null || ! $client->is_active || $operator === null || ! $operator->isAdmin() || ! $operator->hasVerifiedEmail()) {
            throw new RuntimeException;
        }

        $owners = DB::table('client_user')->where('client_id', $client->id)->where('role', 'owner')->where('is_active', true)
            ->when($lock, fn ($query) => $query->lockForUpdate())->get(['user_id']);
        $verifiedOwner = User::query()->whereIn('id', $owners->pluck('user_id'))->whereNotNull('email_verified_at')
            ->when($lock, fn ($query) => $query->lockForUpdate())->first();
        $grant = ClientOperatorGrant::query()->where('client_id', $client->id)->where('user_id', $operator->id)
            ->where('is_active', true)->when($lock, fn ($query) => $query->lockForUpdate())->first();
        if ($verifiedOwner === null || $grant === null) {
            throw new RuntimeException;
        }

        return [$client, $operator];
    }

    /** @return array{ProviderConnection|null, ProviderProfile|null} */
    private function existingMapping(Client $client, User $operator, string $projectId, string $connectionAccountId, bool $lock = false): array
    {
        $connection = $operator->providerConnections()->where('provider', 'adkit')
            ->when($lock, fn ($query) => $query->lockForUpdate())->first();
        $profile = ProviderProfile::query()->where('provider', 'adkit')->where('provider_id_hash', hash('sha256', $projectId))
            ->when($lock, fn ($query) => $query->lockForUpdate())->first();

        if ($connection !== null) {
            if ($connection->client_id !== $projectId || $connection->subject !== $connectionAccountId) {
                throw new RuntimeException;
            }
            $mappedProfiles = ProviderProfile::query()->where('provider_connection_id', $connection->id)
                ->when($lock, fn ($query) => $query->lockForUpdate())->get();
            if ($mappedProfiles->contains(fn (ProviderProfile $mapped): bool => $mapped->id !== $profile?->id)) {
                throw new RuntimeException;
            }
        }

        if ($profile !== null) {
            if ($connection === null || $profile->provider_connection_id !== $connection->id || $profile->provider_id !== $projectId) {
                throw new RuntimeException;
            }
            $assignment = DB::table('client_provider_profiles')->where('provider_profile_id', $profile->id)
                ->when($lock, fn ($query) => $query->lockForUpdate())->first();
            if ($assignment !== null && $assignment->client_id !== $client->id) {
                throw new RuntimeException;
            }

            $accountIds = ProviderAccount::query()->where('provider_profile_id', $profile->id)
                ->when($lock, fn ($query) => $query->lockForUpdate())->get(['id'])->modelKeys();
            $foreignPermission = ClientAccountPermission::query()->whereIn('provider_account_id', $accountIds)
                ->where('client_id', '!=', $client->id)->when($lock, fn ($query) => $query->lockForUpdate())->first();
            if ($foreignPermission !== null) {
                throw new RuntimeException;
            }

            if ($lock) {
                ClientAccountPermission::query()->whereIn('provider_account_id', $accountIds)->lockForUpdate()->get(['id']);
            }
        }

        return [$connection, $profile];
    }
}
