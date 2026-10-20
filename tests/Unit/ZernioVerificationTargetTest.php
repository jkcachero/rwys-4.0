<?php

namespace Tests\Unit;

use App\Zernio\Data\ZernioAccount;
use App\Zernio\Data\ZernioVerificationTarget;
use App\Zernio\Services\ZernioReadService;
use App\Zernio\Support\FakeZernioProvider;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

final class ZernioVerificationTargetTest extends TestCase
{
    public function test_it_requires_all_target_identifiers(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ZernioVerificationTarget('', 'profile-1', 'account-1', 'linkedin', 'John Karlo Cachero', 'testing');
    }

    public function test_it_returns_only_the_exact_approved_account(): void
    {
        $service = new ZernioReadService(new FakeZernioProvider([
            'client-1' => [
                new ZernioAccount('account-1', 'John Karlo Cachero', 'linkedin', 'connected', '2026-10-20T00:00:00+00:00'),
                new ZernioAccount('account-2', 'John Karlo Cachero', 'facebook', 'connected', '2026-10-20T00:00:00+00:00'),
            ],
        ]));

        $account = $service->accountForTarget(new ZernioVerificationTarget(
            'client-1',
            'profile-1',
            'account-1',
            'linkedin',
            'John Karlo Cachero',
            'testing',
        ));

        self::assertSame('account-1', $account->id);
        self::assertSame('linkedin', $account->platform);
    }

    public function test_it_rejects_a_target_that_is_not_in_the_requested_scope(): void
    {
        $service = new ZernioReadService(new FakeZernioProvider([
            'client-1' => [new ZernioAccount('account-1', 'John Karlo Cachero', 'linkedin', 'connected', '2026-10-20T00:00:00+00:00')],
        ]));

        $this->expectException(UnexpectedValueException::class);

        $service->accountForTarget(new ZernioVerificationTarget(
            'client-2',
            'profile-1',
            'account-1',
            'linkedin',
            'John Karlo Cachero',
            'testing',
        ));
    }

    public function test_it_rejects_a_display_name_only_match(): void
    {
        $service = new ZernioReadService(new FakeZernioProvider([
            'client-1' => [new ZernioAccount('account-1', 'John Karlo Cachero', 'linkedin', 'connected', '2026-10-20T00:00:00+00:00')],
        ]));

        $this->expectException(UnexpectedValueException::class);

        $service->accountForTarget(new ZernioVerificationTarget(
            'client-1',
            'profile-1',
            'different-account',
            'linkedin',
            'John Karlo Cachero',
            'testing',
        ));
    }
}
