<?php

namespace App\Zernio\Contracts;

use App\Zernio\Data\ZernioAccount;

interface ZernioProvider
{
    /**
     * @return list<ZernioAccount>
     */
    public function accounts(string $scopeId): array;
}

