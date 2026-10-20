<?php

namespace Tests\Unit;

use App\Zernio\Data\ZernioAccount;
use App\Zernio\Services\ZernioReadService;
use App\Zernio\Support\FakeZernioProvider;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ZernioReadServiceTest extends TestCase
{
    public function test_it_reads_only_accounts_for_the_requested_scope(): void
    {
        $service = new ZernioReadService(new FakeZernioProvider([
            'client-1' => [new ZernioAccount('account-1', 'Primary', 'linkedin', 'connected', '2026-10-20T00:00:00+00:00')],
            'client-2' => [new ZernioAccount('account-2', 'Other', 'facebook', 'connected', '2026-10-20T00:00:00+00:00')],
        ]));

        $accounts = $service->accounts('client-1');

        self::assertCount(1, $accounts);
        self::assertSame('account-1', $accounts[0]->id);
        self::assertSame('linkedin', $accounts[0]->platform);
    }

    public function test_it_applies_a_bounded_limit(): void
    {
        $accounts = array_map(
            static fn (int $id): ZernioAccount => new ZernioAccount((string) $id, "Account {$id}", 'linkedin', 'connected', '2026-10-20T00:00:00+00:00'),
            range(1, 3),
        );
        $service = new ZernioReadService(new FakeZernioProvider(['client-1' => $accounts]));

        self::assertCount(2, $service->accounts('client-1', 2));
    }

    public function test_it_rejects_unbounded_limits(): void
    {
        $service = new ZernioReadService(new FakeZernioProvider);

        $this->expectException(InvalidArgumentException::class);
        $service->accounts('client-1', 101);
    }
}

