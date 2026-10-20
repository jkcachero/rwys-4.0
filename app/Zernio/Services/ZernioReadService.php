<?php

namespace App\Zernio\Services;

use App\Zernio\Contracts\ZernioProvider;
use App\Zernio\Data\ZernioAccount;
use App\Zernio\Data\ZernioVerificationTarget;
use InvalidArgumentException;
use UnexpectedValueException;

final readonly class ZernioReadService
{
    public function __construct(private ZernioProvider $provider) {}

    /**
     * @return list<ZernioAccount>
     */
    public function accounts(string $scopeId, int $limit = 50): array
    {
        $this->validateLimit($limit);

        return array_slice($this->provider->accounts($scopeId), 0, $limit);
    }

    public function accountForTarget(ZernioVerificationTarget $target): ZernioAccount
    {
        $matches = array_values(array_filter(
            $this->accounts($target->scopeId),
            static fn (ZernioAccount $account): bool => $account->id === $target->accountId
                && $account->platform === $target->platform
                && $account->name === $target->displayName,
        ));

        if ($matches === []) {
            throw new UnexpectedValueException('The approved Zernio verification target was not found.');
        }

        if (count($matches) > 1) {
            throw new UnexpectedValueException('The approved Zernio verification target was ambiguous.');
        }

        return $matches[0];
    }

    private function validateLimit(int $limit): void
    {
        if ($limit < 1 || $limit > 100) {
            throw new InvalidArgumentException('The account limit must be between 1 and 100.');
        }
    }
}
