<?php

namespace App\Zernio\Services;

use App\Zernio\Contracts\ZernioProvider;
use App\Zernio\Data\ZernioAccount;
use InvalidArgumentException;

final readonly class ZernioReadService
{
    public function __construct(private ZernioProvider $provider) {}

    /**
     * @return list<ZernioAccount>
     */
    public function accounts(string $scopeId, int $limit = 50): array
    {
        if ($limit < 1 || $limit > 100) {
            throw new InvalidArgumentException('The account limit must be between 1 and 100.');
        }

        return array_slice($this->provider->accounts($scopeId), 0, $limit);
    }
}

