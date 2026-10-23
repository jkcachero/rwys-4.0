<?php

namespace App\Zernio;

final readonly class ZernioResponse
{
    /** @param array<string, mixed> $data */
    public function __construct(
        public int $status,
        public array $data,
        public ?int $retryAfter = null,
    ) {}
}
