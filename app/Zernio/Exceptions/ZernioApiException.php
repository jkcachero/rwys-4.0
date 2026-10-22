<?php

namespace App\Zernio\Exceptions;

use Exception;

final class ZernioApiException extends Exception
{
    public function __construct(public string $reason, public bool $ambiguous = false, public ?string $recoveryPostId = null)
    {
        parent::__construct('The Zernio request could not be completed safely.');
    }
}
