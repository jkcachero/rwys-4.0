<?php

namespace Tests\Unit;

use App\Exceptions\Zernio\ZernioRequestException;
use App\Models\ProviderAccount;
use App\Models\ProviderProfile;
use App\Services\ZernioAccountLookupService;
use App\Zernio\ZernioHttpClient;
use App\Zernio\ZernioResponse;
use Mockery\MockInterface;
use Tests\TestCase;

final class ZernioAccountLookupServiceTest extends TestCase
{
    public function test_it_returns_an_exact_account_from_the_first_page(): void
    {
        [$profile, $account] = $this->providerModels();
        $client = $this->mock(ZernioHttpClient::class, function (MockInterface $mock) use ($profile, $account): void {
            $mock->shouldReceive('accountPage')
                ->once()
                ->with($profile, 100, 1)
                ->andReturn($this->page([
                    ['_id' => $account->provider_id, 'profileId' => ['_id' => $profile->provider_id]],
                ], 1, 1));
        });

        $payload = (new ZernioAccountLookupService($client))->find($profile, $account);

        self::assertSame($account->provider_id, $payload['_id']);
    }

    public function test_it_requests_pages_in_one_based_order_until_the_account_is_found(): void
    {
        [$profile, $account] = $this->providerModels();
        $client = $this->mock(ZernioHttpClient::class);
        $client->shouldReceive('accountPage')->once()->ordered()->with($profile, 100, 1)
            ->andReturn($this->page([['_id' => 'other', 'profileId' => $profile->provider_id]], 1, 2, 101));
        $client->shouldReceive('accountPage')->once()->ordered()->with($profile, 100, 2)
            ->andReturn($this->page([['id' => $account->provider_id, 'profile' => ['id' => $profile->provider_id]]], 2, 2, 101));

        $payload = (new ZernioAccountLookupService($client))->find($profile, $account);

        self::assertSame($account->provider_id, $payload['id']);
    }

    public function test_it_fails_when_the_account_is_missing_after_all_pages(): void
    {
        [$profile, $account] = $this->providerModels();
        $client = $this->mock(ZernioHttpClient::class, function (MockInterface $mock) use ($profile): void {
            $mock->shouldReceive('accountPage')->once()->with($profile, 100, 1)
                ->andReturn($this->page([['_id' => 'other', 'profileId' => $profile->provider_id]], 1, 1));
        });

        $this->expectException(ZernioRequestException::class);
        $this->expectExceptionMessage('Zernio account identity could not be verified.');

        (new ZernioAccountLookupService($client))->find($profile, $account);
    }

    public function test_it_fails_when_the_matching_account_belongs_to_another_profile(): void
    {
        [$profile, $account] = $this->providerModels();
        $client = $this->mock(ZernioHttpClient::class, function (MockInterface $mock) use ($profile, $account): void {
            $mock->shouldReceive('accountPage')->once()->with($profile, 100, 1)
                ->andReturn($this->page([['_id' => $account->provider_id, 'profileId' => 'different-profile']], 1, 1));
        });

        $this->expectException(ZernioRequestException::class);
        $this->expectExceptionMessage('Zernio account ownership could not be verified.');

        (new ZernioAccountLookupService($client))->find($profile, $account);
    }

    public function test_it_rejects_inconsistent_pagination(): void
    {
        [$profile, $account] = $this->providerModels();
        $client = $this->mock(ZernioHttpClient::class, function (MockInterface $mock) use ($profile): void {
            $mock->shouldReceive('accountPage')->once()->with($profile, 100, 1)
                ->andReturn(new ZernioResponse(200, [
                    'accounts' => [],
                    'pagination' => ['page' => 1, 'limit' => 100, 'total' => 101, 'pages' => 1],
                ]));
        });

        $this->expectException(ZernioRequestException::class);
        $this->expectExceptionMessage('Zernio accounts response was invalid.');

        (new ZernioAccountLookupService($client))->find($profile, $account);
    }

    /** @return array{ProviderProfile, ProviderAccount} */
    private function providerModels(): array
    {
        $profile = new ProviderProfile(['provider_id' => 'profile-1']);
        $account = new ProviderAccount(['provider_id' => 'account-1']);

        return [$profile, $account];
    }

    /** @param list<array<string, mixed>> $accounts */
    private function page(array $accounts, int $page, int $pages, ?int $total = null): ZernioResponse
    {
        return new ZernioResponse(200, [
            'accounts' => $accounts,
            'pagination' => [
                'page' => $page,
                'limit' => 100,
                'total' => $total ?? count($accounts),
                'pages' => $pages,
            ],
        ]);
    }
}
