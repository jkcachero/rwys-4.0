<?php

namespace App\Services;

use App\Exceptions\Zernio\ZernioRequestException;
use App\Models\ProviderAccount;
use App\Models\ProviderProfile;
use App\Zernio\ZernioHttpClient;
use Illuminate\Support\Arr;

class ZernioAccountLookupService
{
    private const PAGE_LIMIT = 100;

    private const MAX_PAGES = 3;

    public function __construct(private readonly ZernioHttpClient $zernio) {}

    /** @return array<string, mixed> */
    public function find(ProviderProfile $profile, ProviderAccount $account): array
    {
        $total = null;
        $pages = null;

        for ($page = 1; $page <= self::MAX_PAGES; $page++) {
            $data = $this->zernio->accountPage($profile, self::PAGE_LIMIT, $page)->data;
            $accounts = Arr::get($data, 'accounts');
            $pagination = Arr::get($data, 'pagination');

            if (! is_array($accounts)
                || ! array_is_list($accounts)
                || ! is_array($pagination)
                || Arr::get($pagination, 'page') !== $page
                || Arr::get($pagination, 'limit') !== self::PAGE_LIMIT
                || ! is_int(Arr::get($pagination, 'total'))
                || ! is_int(Arr::get($pagination, 'pages'))) {
                throw $this->invalidResponse();
            }

            $pageTotal = $pagination['total'];
            $pageCount = $pagination['pages'];
            $calculatedPages = $pageTotal === 0 ? 0 : (int) ceil($pageTotal / self::PAGE_LIMIT);

            if ($pageTotal < 0
                || $pageTotal > self::PAGE_LIMIT * self::MAX_PAGES
                || $pageCount < 0
                || $pageCount > self::MAX_PAGES
                || $pageCount !== $calculatedPages) {
                throw $this->invalidResponse();
            }

            $total ??= $pageTotal;
            $pages ??= $pageCount;

            if ($total !== $pageTotal || $pages !== $pageCount) {
                throw $this->invalidResponse();
            }

            foreach ($accounts as $payload) {
                if (! is_array($payload)) {
                    throw $this->invalidResponse();
                }

                $providerAccountId = $this->referenceId(Arr::get($payload, '_id', Arr::get($payload, 'id')));

                if ($providerAccountId !== $account->provider_id) {
                    continue;
                }

                $providerProfileId = $this->referenceId(Arr::get($payload, 'profileId', Arr::get($payload, 'profile')));

                if ($providerProfileId !== $profile->provider_id) {
                    throw new ZernioRequestException('Zernio account ownership could not be verified.');
                }

                return $payload;
            }

            if ($page >= $pageCount) {
                break;
            }
        }

        throw new ZernioRequestException('Zernio account identity could not be verified.');
    }

    private function referenceId(mixed $value): ?string
    {
        if (is_string($value) && $value !== '') {
            return $value;
        }

        if (! is_array($value)) {
            return null;
        }

        $id = Arr::get($value, '_id', Arr::get($value, 'id'));

        return is_string($id) && $id !== '' ? $id : null;
    }

    private function invalidResponse(): ZernioRequestException
    {
        return new ZernioRequestException('Zernio accounts response was invalid.');
    }
}
