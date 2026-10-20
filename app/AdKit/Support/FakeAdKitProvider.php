<?php

namespace App\AdKit\Support;

use App\AdKit\Contracts\AdKitProvider;
use App\AdKit\Data\AdKitAccount;

final class FakeAdKitProvider implements AdKitProvider
{
    /** @var array<string, list<AdKitAccount>> */
    private array $accountsByScope;

    /**
     * @param  array<string, list<AdKitAccount>>  $accountsByScope
     */
    public function __construct(array $accountsByScope = [])
    {
        $this->accountsByScope = $accountsByScope;
    }

    /**
     * @return list<AdKitAccount>
     */
    public function accounts(string $scopeId): array
    {
        return $this->accountsByScope[$scopeId] ?? [];
    }
}
