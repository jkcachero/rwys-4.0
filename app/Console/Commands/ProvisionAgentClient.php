<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\ClientAccount;
use App\Models\User;
use App\Services\ClientProviderProfileService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

#[Signature('agent:provision-client {owner_email} {name} {--profile=} {--account=} {--platform=} {--environment=production} {--confirmed-mapping} {--allow-publishing}')]
#[Description('Provision a local client and optionally an operator-confirmed Zernio mapping; only production mappings are enabled at runtime')]
class ProvisionAgentClient extends Command
{
    public function handle(): int
    {
        $email = (string) $this->argument('owner_email');
        $name = (string) $this->argument('name');
        $profile = $this->option('profile');
        $providerAccount = $this->option('account');
        $platform = $this->option('platform');
        $environment = (string) $this->option('environment');
        $confirmed = (bool) $this->option('confirmed-mapping');
        $allowPublishing = (bool) $this->option('allow-publishing');
        $hasMapping = $profile !== null || $providerAccount !== null || $platform !== null || $confirmed || $allowPublishing;

        if (trim($email) === '' || trim($name) === '' || mb_strlen($name) > 255
            || preg_match('/\A[a-zA-Z0-9_-]{1,100}\z/', $environment) !== 1) {
            $this->error('A nonempty exact owner email, client name (up to 255 characters), and valid environment are required.');

            return self::FAILURE;
        }

        if ($hasMapping && (! $confirmed
            || ! is_string($profile) || preg_match('/\A[a-f0-9]{24}\z/', $profile) !== 1
            || ! is_string($providerAccount) || preg_match('/\A[a-f0-9]{24}\z/', $providerAccount) !== 1
            || ! in_array($platform, ['linkedin', 'facebook', 'threads', 'bluesky'], true))) {
            $this->error('Mapping requires --confirmed-mapping, exact --profile and --account IDs (24 lowercase hexadecimal characters), and --platform=linkedin|facebook|threads|bluesky.');

            return self::FAILURE;
        }

        try {
            [$clientId, $accountId] = DB::transaction(function () use ($email, $name, $profile, $providerAccount, $platform, $environment, $hasMapping, $allowPublishing): array {
                $owner = User::query()->where('email', $email)->lockForUpdate()->first();

                if ($owner === null || $owner->email !== $email || $owner->email_verified_at === null) {
                    throw new RuntimeException('Owner must already exist and be verified.');
                }

                // Compare in PHP as well so case-insensitive database collations cannot alias identities.
                $clients = Client::query()->where('owner_id', $owner->id)->where('name', $name)
                    ->lockForUpdate()->get()->filter(fn (Client $client): bool => $client->name === $name);

                if ($clients->count() > 1 || ($clients->isNotEmpty() && ! $clients->first()->active)) {
                    throw new RuntimeException('Client is ambiguous or inactive.');
                }

                $client = $clients->first();

                if ($client === null) {
                    $client = new Client;
                    $client->owner_id = $owner->id;
                    $client->name = $name;
                    $client->active = true;
                    $client->save();
                }

                if (! $hasMapping) {
                    return [$client->id, null];
                }

                $claim = app(ClientProviderProfileService::class)->claim($client, 'zernio', $environment, $profile);
                $account = ClientAccount::query()->where('provider', 'zernio')
                    ->where('environment', $environment)->where('provider_account_id', $providerAccount)
                    ->lockForUpdate()->first();

                if ($account !== null) {
                    if ((string) $account->client_id !== (string) $client->id
                        || $account->provider !== 'zernio' || $account->environment !== $environment
                        || $account->provider_account_id !== $providerAccount
                        || $account->profile_id !== $profile || $account->platform !== $platform
                        || ! $account->hasOwnedProfile()
                        || ! $account->active || ($allowPublishing && ! $account->publishing_enabled)) {
                        throw new RuntimeException('Existing mapping conflicts with the requested mapping or permissions.');
                    }
                } else {
                    $account = new ClientAccount;
                    $account->client_id = $client->id;
                    $account->client_provider_profile_id = $claim->id;
                    $account->provider = 'zernio';
                    $account->environment = $environment;
                    $account->profile_id = $profile;
                    $account->provider_account_id = $providerAccount;
                    $account->platform = $platform;
                    $account->active = true;
                    $account->publishing_enabled = $allowPublishing;
                    $account->save();
                }

                return [$client->id, $account->id];
            }, 3);
        } catch (Throwable) {
            $this->error('Provisioning failed; no changes were saved.');

            return self::FAILURE;
        }

        $this->line("Client ID: {$clientId}");

        if ($accountId !== null) {
            $this->line("Local account ID: {$accountId}");
        }

        return self::SUCCESS;
    }
}
