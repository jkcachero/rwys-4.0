<?php

namespace App\AdKit\Contracts;

use App\AdKit\Data\AdKitAccount;

interface AdKitProvider
{
    /**
     * @return list<AdKitAccount>
     */
    public function accounts(string $scopeId): array;
}
