<?php

namespace Tests\Feature;

use App\Enums\AgentAbility;
use App\Models\AgentOperation;
use App\Models\Client;
use App\Models\ClientAccountPermission;
use App\Models\ProviderAccount;
use App\Models\ProviderProfile;
use App\Models\User;
use App\Services\AgentTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AgentBrokerReadApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.zernio.enabled', true);
        config()->set('services.zernio.base_url', 'https://zernio.example/api');
        Http::preventStrayRequests();
    }

    public function test_capabilities_advertise_only_implemented_authorized_reads(): void
    {
        [$token] = $this->agentContext(AgentAbility::readOnly());

        $response = $this->withToken($token)->getJson('/api/v1/capabilities');

        $response->assertOk()
            ->assertJsonPath('capabilities', [
                AgentAbility::ContextRead->value,
                AgentAbility::AccountsRead->value,
                AgentAbility::PostsRead->value,
                AgentAbility::AnalyticsRead->value,
                AgentAbility::OperationsRead->value,
            ])
            ->assertJsonMissing([AgentAbility::PostsPublish->value]);

        Http::assertNothingSent();
    }

    public function test_zernio_reads_verify_provider_identity_and_return_only_local_ids(): void
    {
        [$token, , $account, $profile] = $this->agentContext([
            AgentAbility::AccountsRead->value,
            AgentAbility::PostsRead->value,
            AgentAbility::AnalyticsRead->value,
        ]);
        $providerPostId = '0123456789abcdef01234567';

        Http::fake(function (Request $request) use ($profile, $account, $providerPostId) {
            $path = parse_url($request->url(), PHP_URL_PATH);

            return match ($path) {
                '/api/v1/profiles/'.$profile->provider_id => Http::response(['profile' => ['_id' => $profile->provider_id]]),
                '/api/v1/accounts' => Http::response([
                    'accounts' => [[
                        '_id' => $account->provider_id,
                        'profileId' => ['_id' => $profile->provider_id],
                        'isActive' => true,
                        'status' => 'connected',
                    ]],
                    'pagination' => ['page' => 1, 'limit' => 100, 'total' => 1, 'pages' => 1],
                ]),
                '/api/v1/posts' => Http::response([
                    'posts' => [[
                        '_id' => $providerPostId,
                        'accountIds' => [['_id' => $account->provider_id]],
                        'status' => 'published',
                        'publishedAt' => '2026-10-22T12:00:00Z',
                    ]],
                    'pagination' => ['nextCursor' => 'next-page'],
                ]),
                '/api/v1/posts/'.$providerPostId => Http::response(['post' => [
                    '_id' => $providerPostId,
                    'accountId' => $account->provider_id,
                    'status' => 'published',
                    'publishedAt' => '2026-10-22T12:00:00Z',
                ]]),
                '/api/v1/analytics' => Http::response(['post' => [
                    'postId' => $providerPostId,
                    'accountId' => $account->provider_id,
                    'syncStatus' => 'synced',
                    'metrics' => ['likes' => 0, 'comments' => null],
                ]]),
                default => Http::response([], 500),
            };
        });

        $status = $this->withToken($token)->getJson("/api/v1/zernio/accounts/{$account->public_id}/status");
        $status->assertOk()
            ->assertJsonPath('data.id', $account->public_id)
            ->assertJsonPath('data.status', 'connected')
            ->assertJsonMissing(['id' => $account->provider_id]);

        $posts = $this->withToken($token)->getJson("/api/v1/zernio/accounts/{$account->public_id}/posts?limit=25");
        $posts->assertOk()
            ->assertJsonPath('data.0.account_id', $account->public_id)
            ->assertJsonPath('meta.next_cursor', 'next-page')
            ->assertJsonMissing(['id' => $providerPostId]);
        $localPostId = $posts->json('data.0.id');

        $this->withToken($token)->getJson("/api/v1/zernio/accounts/{$account->public_id}/posts/{$localPostId}")
            ->assertOk()
            ->assertJsonPath('data.id', $localPostId)
            ->assertJsonMissing(['id' => $providerPostId]);

        $this->withToken($token)->getJson("/api/v1/zernio/accounts/{$account->public_id}/posts/{$localPostId}/analytics")
            ->assertOk()
            ->assertJsonPath('data.state', 'synced')
            ->assertJsonPath('data.metrics.likes', 0)
            ->assertJsonPath('data.metrics.comments', null);

        Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer provider-secret'));
        Http::assertSent(fn (Request $request): bool => parse_url($request->url(), PHP_URL_PATH) === '/api/v1/accounts'
            && $request['profileId'] === $profile->provider_id
            && $request['limit'] === 100
            && $request['page'] === 1);
        Http::assertNotSent(fn (Request $request): bool => parse_url($request->url(), PHP_URL_PATH) === '/api/v1/accounts/'.$account->provider_id);
        $this->assertDatabaseCount('provider_posts', 1);
        $this->assertDatabaseCount('agent_request_audits', 4);
    }

    public function test_invalid_queries_and_cross_client_resources_are_rejected_and_audited(): void
    {
        [$token, , $account] = $this->agentContext([AgentAbility::PostsRead->value]);
        $otherAccount = ProviderAccount::factory()->create();

        $this->withToken($token)->getJson("/api/v1/zernio/accounts/{$account->public_id}/posts?unexpected=value")
            ->assertUnprocessable()
            ->assertJsonStructure(['message', 'errors', 'request_id']);

        $this->withToken($token)->getJson("/api/v1/zernio/accounts/{$otherAccount->public_id}/posts")
            ->assertNotFound()
            ->assertJsonStructure(['message', 'request_id']);

        $this->assertDatabaseCount('agent_request_audits', 2);
        $this->assertDatabaseHas('agent_request_audits', ['status' => 422]);
        $this->assertDatabaseHas('agent_request_audits', ['status' => 404]);
        Http::assertNothingSent();
    }

    public function test_operation_history_is_client_scoped_and_does_not_poll_the_provider(): void
    {
        [$token, $client] = $this->agentContext([AgentAbility::OperationsRead->value]);
        $visible = AgentOperation::factory()->create([
            'client_id' => $client,
            'capability' => AgentAbility::PostsPublish->value,
            'status' => 'succeeded',
            'outcome' => ['provider_status' => 'published'],
            'requested_at' => now()->subMinute(),
        ]);
        AgentOperation::factory()->create();

        $this->withToken($token)->getJson('/api/v1/operations?limit=25')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $visible->public_id)
            ->assertJsonPath('data.0.outcome.provider_status', 'published');

        $this->withToken($token)->getJson("/api/v1/operations/{$visible->public_id}")
            ->assertOk()
            ->assertJsonPath('data.id', $visible->public_id)
            ->assertJsonPath('data.outcome.provider_status', 'published');

        Http::assertNothingSent();
    }

    public function test_client_concurrency_admission_fails_closed_with_retry_metadata(): void
    {
        [$token, $client] = $this->agentContext([AgentAbility::ContextRead->value]);
        $first = Cache::lock("agent:in-flight:{$client->id}:1", 120);
        $second = Cache::lock("agent:in-flight:{$client->id}:2", 120);
        $this->assertTrue($first->get());
        $this->assertTrue($second->get());

        try {
            $this->withToken($token)->getJson('/api/v1/context')
                ->assertStatus(429)
                ->assertHeader('Retry-After', '1')
                ->assertJsonPath('error.code', 'too_many_in_flight')
                ->assertJsonStructure(['error', 'request_id']);
        } finally {
            $first->release();
            $second->release();
        }

        $this->assertDatabaseCount('agent_request_audits', 0);
    }

    public function test_client_and_token_read_admission_is_limited_to_sixty_requests_per_minute(): void
    {
        [$token] = $this->agentContext([AgentAbility::ContextRead->value]);

        for ($request = 1; $request <= 60; $request++) {
            $this->withToken($token)->getJson('/api/v1/context')->assertOk();
        }

        $this->withToken($token)->getJson('/api/v1/context')
            ->assertStatus(429)
            ->assertHeader('Retry-After')
            ->assertJsonPath('error.code', 'rate_limited')
            ->assertJsonStructure(['error', 'request_id']);

        $this->assertDatabaseCount('agent_request_audits', 60);
    }

    /**
     * @param  list<string>  $abilities
     * @return array{string, Client, ProviderAccount, ProviderProfile}
     */
    private function agentContext(array $abilities): array
    {
        $owner = User::factory()->create();
        $client = Client::factory()->create();
        $client->users()->attach($owner, ['role' => 'owner', 'is_active' => true]);
        $connection = $owner->providerConnections()->create([
            'provider' => 'zernio',
            'access_token' => 'provider-secret',
            'scopes' => ['read'],
        ]);
        $profile = ProviderProfile::factory()->create([
            'provider_connection_id' => $connection->id,
            'provider_id' => 'profile-123',
            'provider_id_hash' => hash('sha256', 'profile-123'),
        ]);
        $client->providerProfiles()->attach($profile, [
            'assigned_by' => $owner->id,
            'assigned_at' => now(),
        ]);
        $account = ProviderAccount::factory()->create([
            'provider_profile_id' => $profile,
            'provider_id' => 'account-123',
            'provider_id_hash' => hash('sha256', 'account-123'),
            'platform' => 'linkedin',
        ]);
        ClientAccountPermission::factory()->create([
            'client_id' => $client,
            'provider_account_id' => $account,
        ]);
        $token = app(AgentTokenService::class)->issue($owner, $client, 'Broker test');
        $token->accessToken->forceFill(['abilities' => $abilities])->save();

        return [$token->plainTextToken, $client, $account, $profile];
    }
}
