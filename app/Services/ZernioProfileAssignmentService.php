<?php

namespace App\Services;

use App\Exceptions\Zernio\ZernioRequestException;
use App\Models\Client;
use App\Models\ProviderConnection;
use App\Models\ProviderProfile;
use App\Models\User;
use App\Zernio\ZernioHttpClient;
use Illuminate\Validation\ValidationException;

class ZernioProfileAssignmentService
{
    public function __construct(
        private ZernioHttpClient $zernio,
        private ClientProfileAssignmentService $assignments,
    ) {}

    /** @return array{profiles: list<array{id: string, name: string, account_count: int|null}>, page: int, has_more: bool} */
    public function discover(User $operator, Client $client, int $page): array
    {
        $this->assignments->assertAssignmentAuthority($operator, $client);
        $connection = $this->connectionFor($operator);
        $limit = 25;
        $skip = ($page - 1) * $limit;
        $response = $this->zernio->profiles($connection, $limit, $skip)->data;
        $profiles = $response['profiles'] ?? null;

        if (! is_array($profiles) || ! array_is_list($profiles)) {
            throw $this->invalidProviderResponse();
        }

        $normalized = array_map(function (mixed $profile): array {
            if (! is_array($profile)) {
                throw $this->invalidProviderResponse();
            }

            $id = $this->nonEmptyString($profile['_id'] ?? $profile['id'] ?? null);
            $name = $this->nonEmptyString($profile['name'] ?? null);
            $accountCount = $profile['accountCount'] ?? null;

            if ($id === null || $name === null || ($accountCount !== null && (! is_int($accountCount) || $accountCount < 0))) {
                throw $this->invalidProviderResponse();
            }

            return ['id' => $id, 'name' => $name, 'account_count' => $accountCount];
        }, $profiles);

        $total = $response['total'] ?? null;
        $hasMore = is_int($total)
            ? $skip + count($normalized) < $total
            : count($normalized) === $limit;

        return ['profiles' => $normalized, 'page' => $page, 'has_more' => $hasMore];
    }

    public function assign(User $operator, Client $client, string $providerProfileId): ProviderProfile
    {
        $this->assignments->assertAssignmentAuthority($operator, $client);
        $connection = $this->connectionFor($operator);
        $existing = ProviderProfile::query()
            ->where('provider', 'zernio')
            ->where('provider_id_hash', hash('sha256', $providerProfileId))
            ->first();
        $expectedGeneration = $existing?->sync_generation;
        [$profileData, $accounts] = $this->completeSnapshot($connection, $providerProfileId);

        return $this->assignments->assignSnapshot(
            $operator,
            $client,
            $connection,
            $profileData,
            $accounts,
            $expectedGeneration,
        );
    }

    public function synchronize(User $operator, Client $client, ProviderProfile $profile): void
    {
        $this->assignments->assertAssignmentAuthority($operator, $client);
        $profile->loadMissing('providerConnection');
        $connection = $profile->providerConnection;

        if ($profile->provider !== 'zernio' || $connection === null || $connection->provider !== 'zernio') {
            throw ValidationException::withMessages([
                'provider_profile_id' => 'The assigned Zernio profile has no usable provider connection.',
            ]);
        }

        $expectedGeneration = $profile->sync_generation;
        [$profileData, $accounts] = $this->completeSnapshot($connection, $profile->provider_id);
        $this->assignments->synchronizeSnapshot(
            $operator,
            $client,
            $profile,
            $profileData,
            $accounts,
            $expectedGeneration,
        );
    }

    private function connectionFor(User $operator): ProviderConnection
    {
        $connection = $operator->providerConnections()
            ->where('provider', 'zernio')
            ->first();

        if ($connection === null) {
            throw ValidationException::withMessages([
                'provider_profile_id' => 'Connect an active Zernio account before assigning profiles.',
            ]);
        }

        return $connection;
    }

    /**
     * @return array{array{provider_id: string, name: string}, list<array{provider_id: string, platform: string, name: string}>}
     */
    private function completeSnapshot(ProviderConnection $connection, string $providerProfileId): array
    {
        $detail = $this->zernio->profileById($connection, $providerProfileId)->data;
        $profile = $detail['profile'] ?? $detail;

        if (! is_array($profile)) {
            throw $this->invalidProviderResponse();
        }

        $profileId = $this->nonEmptyString($profile['_id'] ?? $profile['id'] ?? null);
        $profileName = $this->nonEmptyString($profile['name'] ?? null);

        if ($profileId !== $providerProfileId || $profileName === null) {
            throw ValidationException::withMessages([
                'provider_profile_id' => 'Zernio returned a different or incomplete profile.',
            ]);
        }

        $accounts = [];
        $seenAccountIds = [];
        $total = null;

        for ($page = 0; $page < 3; $page++) {
            $skip = $page * 100;
            $response = $this->zernio->profileAccounts($connection, $providerProfileId, 100, $skip)->data;
            $pageAccounts = $response['accounts'] ?? null;

            if (! is_array($pageAccounts) || ! array_is_list($pageAccounts)) {
                throw $this->invalidProviderResponse();
            }

            if (isset($response['skip']) && $response['skip'] !== $skip) {
                throw $this->invalidProviderResponse();
            }

            if (isset($response['limit']) && $response['limit'] !== 100) {
                throw $this->invalidProviderResponse();
            }

            if (isset($response['total'])) {
                if (! is_int($response['total']) || $response['total'] < 0 || $response['total'] > 300) {
                    throw ValidationException::withMessages([
                        'provider_profile_id' => 'This Zernio profile exceeds the 300-account import limit.',
                    ]);
                }

                $total ??= $response['total'];

                if ($total !== $response['total']) {
                    throw $this->invalidProviderResponse();
                }
            }

            foreach ($pageAccounts as $account) {
                $normalized = $this->normalizeAccount($account, $providerProfileId);

                if (isset($seenAccountIds[$normalized['provider_id']])) {
                    throw ValidationException::withMessages([
                        'provider_profile_id' => 'Zernio returned duplicate accounts for this profile.',
                    ]);
                }

                $seenAccountIds[$normalized['provider_id']] = true;
                $accounts[] = $normalized;
            }

            if (count($accounts) > 300) {
                throw ValidationException::withMessages([
                    'provider_profile_id' => 'This Zernio profile exceeds the 300-account import limit.',
                ]);
            }

            if ($total !== null && count($accounts) >= $total) {
                break;
            }

            if (count($pageAccounts) < 100) {
                break;
            }

            if ($page === 2) {
                throw ValidationException::withMessages([
                    'provider_profile_id' => 'Zernio did not return a complete profile within three pages.',
                ]);
            }
        }

        if ($total !== null && count($accounts) !== $total) {
            throw ValidationException::withMessages([
                'provider_profile_id' => 'Zernio returned an incomplete account snapshot.',
            ]);
        }

        return [
            ['provider_id' => $profileId, 'name' => $profileName],
            $accounts,
        ];
    }

    /** @return array{provider_id: string, platform: string, name: string} */
    private function normalizeAccount(mixed $account, string $providerProfileId): array
    {
        if (! is_array($account)) {
            throw $this->invalidProviderResponse();
        }

        $accountId = $this->nonEmptyString($account['_id'] ?? $account['id'] ?? null);
        $profileReference = $account['profileId'] ?? $account['profile'] ?? null;

        if (is_array($profileReference)) {
            $profileReference = $profileReference['_id'] ?? $profileReference['id'] ?? null;
        }

        $profileReference = $this->nonEmptyString($profileReference);
        $platform = $this->nonEmptyString($account['platform'] ?? null);
        $name = $this->nonEmptyString($account['displayName'] ?? $account['name'] ?? null);

        if ($accountId === null || $profileReference !== $providerProfileId || $platform === null || $name === null) {
            throw ValidationException::withMessages([
                'provider_profile_id' => 'Zernio returned an account outside the selected profile or with incomplete identity data.',
            ]);
        }

        return [
            'provider_id' => $accountId,
            'platform' => strtolower($platform),
            'name' => $name,
        ];
    }

    private function nonEmptyString(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    private function invalidProviderResponse(): ZernioRequestException
    {
        return new ZernioRequestException('Zernio returned an invalid profile response.', 502, 'provider_error');
    }
}
