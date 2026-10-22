<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ClientAccount;
use App\Models\ClientProviderProfile;
use App\Models\User;
use App\Zernio\Exceptions\ZernioApiException;
use App\Zernio\Services\ZernioApiClient;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class ProviderAccountAssignmentService
{
    public const array MAPPING_FIELDS = ['id', 'client_id', 'platform', 'profile_id', 'provider_account_id', 'active', 'publishing_enabled'];

    public const array PROFILE_FIELDS = ['id', 'client_id', 'profile_id', 'name', 'active', 'last_synced_at'];

    public function __construct(private readonly ZernioApiClient $provider) {}

    public function authorizeOperator(User $operator): void
    {
        $operator = $operator->fresh();
        abort_unless($operator !== null && $operator->canAssignProviderAccounts(), 403);
        Gate::forUser($operator)->authorize('assign-provider-accounts');
    }

    public function eligibleClient(int $clientId): Client
    {
        $client = Client::query()->with('owner:id,name,email,email_verified_at')->find($clientId);
        if ($client === null || ! $client->active || ! $client->owner?->hasVerifiedEmail()) {
            throw ValidationException::withMessages(['client_id' => 'Select an active client with a verified owner.']);
        }

        return $client;
    }

    public function ready(): bool
    {
        return Schema::hasTable('client_provider_profiles') && Schema::hasTable('client_accounts')
            && Schema::hasColumn('client_accounts', 'client_provider_profile_id')
            && Schema::hasColumn('client_provider_profiles', 'sync_version');
    }

    private function requireReady(): void
    {
        if (! $this->ready()) {
            throw new HttpResponseException(response()->json(['error' => [
                'code' => 'profile_assignments_not_ready', 'message' => 'Provider profile assignments are not available yet.',
            ]], 503)->header('Cache-Control', 'no-store, private'));
        }
    }

    private function recheck(User $operator, Client $client): void
    {
        $this->authorizeOperator($operator);
        $freshClient = Client::query()->with('owner:id,email_verified_at')->find($client->id);
        abort_unless($freshClient?->active && $freshClient->owner?->hasVerifiedEmail()
            && (int) $freshClient->owner_id === (int) $client->owner_id, 403);
    }

    private function providerError(ZernioApiException $exception): never
    {
        [$status, $code, $message] = match ($exception->reason) {
            'not_configured' => [503, 'provider_not_configured', 'Provider profile discovery is not configured.'],
            'not_found' => [404, 'provider_profile_not_found', 'The provider profile was not found.'],
            default => [502, 'provider_unavailable', 'Provider profile discovery is unavailable.'],
        };
        throw new HttpResponseException(response()->json(['error' => compact('code', 'message')], $status)
            ->header('Cache-Control', 'no-store, private'));
    }

    /** @return array{profiles: list<array<string, mixed>>, pagination: array<string, int>} */
    public function discover(User $operator, Client $client, int $page = 1, int $limit = 25, ?string $search = null): array
    {
        $this->authorizeOperator($operator);
        $client = $this->eligibleClient($client->id);
        $this->requireReady();
        try {
            $result = $this->provider->profiles($page, $limit, $search);
        } catch (ZernioApiException $exception) {
            $this->providerError($exception);
        }
        $this->recheck($operator, $client);

        return $result;
    }

    public function assign(User $operator, Client $client, string $profileId): ClientProviderProfile
    {
        $this->authorizeOperator($operator);
        Validator::make(['profile_id' => $profileId], [
            'profile_id' => ['required', 'string', 'regex:/\A[a-f0-9]{24}\z/'],
        ])->validate();
        $client = $this->eligibleClient($client->id);
        $this->requireReady();
        $baseline = ClientProviderProfile::query()->where('provider', 'zernio')->where('environment', 'production')
            ->where('profile_id', $profileId)->first(['id', 'sync_version']);
        $accounts = [];
        $started = hrtime(true);
        try {
            $profile = $this->provider->profile($profileId);
            $this->recheck($operator, $client);
            if ($profile['id'] !== $profileId) {
                throw new ZernioApiException('scope_mismatch');
            }
            $pagination = null;
            for ($page = 1; $page <= ($pagination['pages'] ?? 1); $page++) {
                if ((hrtime(true) - $started) / 1e9 >= 60) {
                    throw new ZernioApiException('transport_error');
                }
                $result = $this->provider->accounts($profileId, $page, 100);
                $this->recheck($operator, $client);
                $current = $result['pagination'];
                if ($current['total'] > 300 || $current['pages'] > 3) {
                    throw ValidationException::withMessages(['profile_id' => 'This profile exceeds the 300 account sync limit.']);
                }
                if ($current['page'] !== $page || $current['limit'] !== 100 || $current['total'] < 0
                    || ($current['total'] === 0 ? ! in_array($current['pages'], [0, 1], true)
                        : $current['pages'] !== (int) ceil($current['total'] / 100))
                    || ($pagination !== null && ($current['total'] !== $pagination['total'] || $current['pages'] !== $pagination['pages']))
                    || count($result['accounts']) !== min(100, max(0, $current['total'] - ($page - 1) * 100))) {
                    throw new ZernioApiException('malformed_response');
                }
                $pagination ??= $current;
                foreach ($result['accounts'] as $account) {
                    if ($account['profile_id'] !== $profileId || isset($accounts[$account['id']])) {
                        throw new ZernioApiException('scope_mismatch');
                    }
                    $accounts[$account['id']] = $account;
                }
            }
            if (count($accounts) !== $pagination['total'] || (hrtime(true) - $started) / 1e9 >= 60) {
                throw new ZernioApiException('malformed_response');
            }
        } catch (ZernioApiException $exception) {
            $this->providerError($exception);
        }

        try {
            $claim = DB::transaction(function () use ($operator, $client, $accounts, $profile, $profileId, $baseline): ClientProviderProfile {
                $users = User::query()->whereIn('id', array_unique([$operator->id, $client->owner_id]))
                    ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                $freshOperator = $users->get($operator->id);
                abort_unless($freshOperator?->canAssignProviderAccounts(), 403);
                Gate::forUser($freshOperator)->authorize('assign-provider-accounts');
                $freshClient = Client::query()->lockForUpdate()->find($client->id);
                abort_unless($freshClient?->active && (int) $freshClient->owner_id === (int) $client->owner_id, 403);
                $owner = $users->get($client->owner_id);
                abort_unless($owner?->hasVerifiedEmail(), 403);

                try {
                    $claim = app(ClientProviderProfileService::class)->claim($freshClient, 'zernio', 'production', $profileId, $profile['name']);
                } catch (RuntimeException) {
                    abort(409, 'Provider profile conflicts with an existing assignment.');
                }
                abort_unless($claim->active, 409, 'Provider profile conflicts with an existing assignment.');
                abort_unless($baseline === null ? $claim->wasRecentlyCreated
                    : $claim->id === $baseline->id && $claim->sync_version === $baseline->sync_version,
                    409, 'Provider profile changed during sync. Refresh and try again.');
                $existing = ClientAccount::query()->where('provider', 'zernio')->where('environment', 'production')
                    ->whereIn('provider_account_id', array_keys($accounts))->lockForUpdate()->get()->keyBy('provider_account_id');
                foreach ($existing as $mapping) {
                    abort_unless((int) $mapping->client_id === $freshClient->id && $mapping->profile_id === $profileId
                        && $mapping->platform === $accounts[$mapping->provider_account_id]['platform']
                        && ($mapping->client_provider_profile_id === null || (int) $mapping->client_provider_profile_id === $claim->id),
                        409, 'Provider account mapping conflicts with an existing assignment.');
                }
                $mappings = [];
                foreach ($accounts as $account) {
                    $mapping = $existing->get($account['id']);
                    if ($mapping === null) {
                        $mapping = ClientAccount::query()->create([
                            'client_id' => $freshClient->id, 'client_provider_profile_id' => $claim->id,
                            'provider' => 'zernio', 'environment' => 'production', 'provider_account_id' => $account['id'],
                            'profile_id' => $profileId, 'platform' => $account['platform'],
                            'active' => $account['is_active'], 'publishing_enabled' => false,
                        ]);
                    } else {
                        $mapping->forceFill(['client_provider_profile_id' => $claim->id, 'active' => $mapping->active && $account['is_active']]);
                        if ($mapping->isDirty()) {
                            $mapping->save();
                        }
                    }
                    $mappings[] = $mapping;
                }
                ClientAccount::query()->where('client_id', $freshClient->id)->where('provider', 'zernio')
                    ->where('environment', 'production')->where('profile_id', $profileId)
                    ->whereNotIn('provider_account_id', array_keys($accounts))->where('active', true)->update(['active' => false]);
                $created = $claim->wasRecentlyCreated;
                $claim->forceFill(['name' => $profile['name'], 'last_synced_at' => now(), 'sync_version' => $claim->sync_version + 1])->save();
                $claim->wasRecentlyCreated = $created;
                $claim->setRelation('accounts', collect($mappings));
                $claim->setAttribute('account_count', count($mappings));

                return $claim;
            }, 3);
        } catch (UniqueConstraintViolationException) {
            abort(409, 'Provider account mapping conflicts with an existing assignment.');
        }

        Log::info('provider_profile_assignment', [
            'operator_id' => $operator->id, 'client_id' => $client->id, 'profile_claim_id' => $claim->id, 'account_count' => count($accounts),
        ]);

        return $claim;
    }
}
