<?php

namespace App\Zernio\Data;

use InvalidArgumentException;

final readonly class ZernioVerificationTarget
{
    public function __construct(
        public string $scopeId,
        public string $profileId,
        public string $accountId,
        public string $platform,
        public string $displayName,
        public string $environment,
    ) {
        foreach ([
            'scopeId' => $this->scopeId,
            'profileId' => $this->profileId,
            'accountId' => $this->accountId,
            'platform' => $this->platform,
            'displayName' => $this->displayName,
            'environment' => $this->environment,
        ] as $field => $value) {
            if (trim($value) === '') {
                throw new InvalidArgumentException("The {$field} is required.");
            }
        }
    }
}
