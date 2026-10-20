<?php

namespace App\Zernio\Data;

final readonly class ZernioAccount
{
    public function __construct(
        public string $id,
        public string $name,
        public string $platform,
        public string $status,
        public string $fetchedAt,
    ) {}
}

