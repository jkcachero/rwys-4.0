<?php

namespace App\AdKit\Services;

use App\AdKit\Contracts\AdKitProvider;
use App\AdKit\Data\AdKitAccount;

final readonly class AdKitReadService
{
    public function __construct(private AdKitProvider $provider) {}

    /**
     * @return list<AdKitAccount>
     */
    public function accounts(string $scopeId, int $limit = 50): array
    {
        if ($limit < 1 || $limit > 100) {
            throw new \InvalidArgumentException('The account limit must be between 1 and 100.');
        }

        return array_slice($this->provider->accounts($scopeId), 0, $limit);
    }
}
