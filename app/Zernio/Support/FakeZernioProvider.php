<?php

namespace App\Zernio\Support;

use App\Zernio\Contracts\ZernioProvider;
use App\Zernio\Data\ZernioAccount;

final class FakeZernioProvider implements ZernioProvider
{
    /** @var array<string, list<ZernioAccount>> */
    private array $accountsByScope;

    /**
     * @param  array<string, list<ZernioAccount>>  $accountsByScope
     */
    public function __construct(array $accountsByScope = [])
    {
        $this->accountsByScope = $accountsByScope;
    }

    /**
     * @return list<ZernioAccount>
     */
    public function accounts(string $scopeId): array
    {
        return $this->accountsByScope[$scopeId] ?? [];
    }
}

