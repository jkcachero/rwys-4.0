<?php

namespace App\AdKit\Data;

final readonly class AdKitAccount
{
    public function __construct(
        public string $id,
        public string $name,
        public string $status,
        public string $fetchedAt,
    ) {}
}
