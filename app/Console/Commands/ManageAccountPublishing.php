<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\ClientAccount;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

#[Signature('agent:account-publishing {account} {--disable}')]
#[Description('Enable publishing for an eligible local account, or disable it for any existing mapping')]
class ManageAccountPublishing extends Command
{
    public function handle(): int
    {
        $id = (string) $this->argument('account');
        $disable = (bool) $this->option('disable');
        try {
            if (! preg_match('/\A[1-9][0-9]*\z/', $id)) {
                throw new RuntimeException('Invalid local account.');
            }
            $clientId = DB::transaction(function () use ($id, $disable): int {
                $snapshot = ClientAccount::query()->findOrFail($id);
                $clientSnapshot = Client::query()->findOrFail($snapshot->client_id);
                $owner = User::query()->lockForUpdate()->find($clientSnapshot->owner_id);
                $client = Client::query()->lockForUpdate()->findOrFail($snapshot->client_id);
                $account = ClientAccount::query()->lockForUpdate()->findOrFail($id);
                if ($account->client_id !== $client->id || $client->owner_id !== $clientSnapshot->owner_id) {
                    throw new RuntimeException('Mapping changed.');
                }
                if (! $disable && (! $client->active || ! $owner?->hasVerifiedEmail()
                    || ! $account->active || $account->provider !== 'zernio' || $account->environment !== 'production'
                    || ! $account->hasOwnedProfile() || config('agent-api.zernio.enabled', false) !== true
                    || config('agent-api.zernio.publishing_enabled', false) !== true
                    || (! in_array($account->platform, ['linkedin', 'facebook', 'threads', 'bluesky'], true)
                        && ! ($account->platform === 'instagram' && config('agent-api.zernio.instagram_scheduling_enabled', false) === true)))) {
                    throw new RuntimeException('Ineligible mapping.');
                }
                $account->publishing_enabled = ! $disable;
                $account->save();

                return $client->id;
            }, 3);
        } catch (Throwable) {
            $this->error('Publishing permission update failed; no changes were saved.');

            return self::FAILURE;
        }
        Log::info('Account publishing permission changed.', ['account_id' => (int) $id, 'client_id' => $clientId, 'action' => $disable ? 'disable' : 'enable']);
        $this->info($disable ? 'Account publishing disabled.' : 'Account publishing enabled.');

        return self::SUCCESS;
    }
}
