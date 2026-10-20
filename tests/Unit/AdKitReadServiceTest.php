<?php

namespace Tests\Unit;

use App\AdKit\Data\AdKitAccount;
use App\AdKit\Services\AdKitReadService;
use App\AdKit\Support\FakeAdKitProvider;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class AdKitReadServiceTest extends TestCase
{
    public function test_it_reads_only_accounts_for_the_requested_scope(): void
    {
        $service = new AdKitReadService(new FakeAdKitProvider([
            'team-1' => [new AdKitAccount('account-1', 'Primary', 'active', '2026-10-21T00:00:00+00:00')],
            'team-2' => [new AdKitAccount('account-2', 'Other', 'active', '2026-10-21T00:00:00+00:00')],
        ]));

        $accounts = $service->accounts('team-1');

        self::assertCount(1, $accounts);
        self::assertSame('account-1', $accounts[0]->id);
    }

    public function test_it_applies_a_bounded_limit(): void
    {
        $accounts = array_map(
            static fn (int $id): AdKitAccount => new AdKitAccount((string) $id, "Account {$id}", 'active', '2026-10-21T00:00:00+00:00'),
            range(1, 3),
        );
        $service = new AdKitReadService(new FakeAdKitProvider(['team-1' => $accounts]));

        self::assertCount(2, $service->accounts('team-1', 2));
    }

    public function test_it_rejects_unbounded_limits(): void
    {
        $service = new AdKitReadService(new FakeAdKitProvider);

        $this->expectException(InvalidArgumentException::class);
        $service->accounts('team-1', 101);
    }
}
