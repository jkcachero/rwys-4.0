<?php

namespace Tests\Feature;

use App\Http\Middleware\AuthenticateAgentToken;
use App\Http\Middleware\GuardAgentRequest;
use App\Models\AgentAccessToken;
use App\Models\AgentOperation;
use App\Models\Client;
use App\Models\ClientAccount;
use App\Models\User;
use App\Services\AgentTokenService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AgentApiTest extends TestCase
{
    use RefreshDatabase;

    private const PROFILE = 'aaaaaaaaaaaaaaaaaaaaaaaa';

    private const ACCOUNT = 'bbbbbbbbbbbbbbbbbbbbbbbb';

    private const POST = 'cccccccccccccccccccccccc';

    private User $owner;

    private Client $client;

    private ClientAccount $account;

    private string $bearer;

    private AgentAccessToken $token;

    protected function beforeRefreshingDatabase(): void
    {
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->assertEmpty(config('database.connections.sqlite.url'));
    }

    protected function setUp(): void
    {
        parent::setUp();
        config(['agent-api.zernio.enabled' => true, 'agent-api.zernio.publishing_enabled' => true, 'agent-api.zernio.api_key' => 'test-secret', 'agent-api.reads_per_minute' => 1000, 'agent-api.writes_per_minute' => 1000]);
        Http::preventStrayRequests();
        Http::fake([]);
        Route::prefix('api')->middleware('api')->group(base_path('routes/agent-api.php'));
        Route::getRoutes()->refreshNameLookups();
        $this->owner = User::factory()->create();
        $this->client = Client::factory()->create(['owner_id' => $this->owner->id]);
        $this->account = ClientAccount::factory()->create(['client_id' => $this->client->id, 'profile_id' => self::PROFILE, 'provider_account_id' => self::ACCOUNT, 'publishing_enabled' => true]);
        $this->issue();
    }

    private function issue(?array $abilities = null, ?array $accounts = null): void
    {
        $issued = app(AgentTokenService::class)->issue($this->owner, $this->client, 'API test', $abilities ?? [...AgentTokenService::READ_ABILITIES, 'zernio.posts.publish'], $accounts);
        $this->token = $issued->accessToken;
        $this->bearer = $issued->plainTextToken;
        $this->withToken($this->bearer);
    }

    public function test_accounts_are_safe_paginated_local_mappings_with_current_grants(): void
    {
        ClientAccount::factory()->create(['client_id' => $this->client->id]);
        ClientAccount::factory()->create();
        $this->issue(accounts: [$this->account->id]);
        $response = $this->getJson('/api/v1/zernio/accounts?limit=1')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $this->account->id)->assertJsonPath('pagination.total', 1)->assertJsonPath('pagination.complete', true);
        $response->assertJsonMissingPath('data.0.profile_id')->assertJsonMissingPath('data.0.provider_account_id')->assertJsonStructure(['request_id']);
        Http::assertNothingSent();
    }

    public function test_context_and_capabilities_are_truthful_and_adkit_is_blocked(): void
    {
        $this->issue(['context.read']);
        $this->getJson('/api/v1/context')->assertOk()->assertJsonPath('data.client.id', $this->client->id);
        $this->getJson('/api/v1/capabilities')->assertOk()->assertJsonPath('data.adkit.status', 'blocked')
            ->assertJsonPath('data.abilities', ['context.read']);
        config(['agent-api.zernio.enabled' => false]);
        $this->getJson('/api/v1/capabilities')->assertOk()->assertJsonPath('data.zernio.read_enabled', false);
        Http::assertNothingSent();
    }

    #[DataProvider('invalidQueries')]
    public function test_unknown_fields_and_unbounded_pagination_are_rejected(string $query): void
    {
        $this->getJson('/api/v1/zernio/accounts?'.$query)->assertUnprocessable();
        Http::assertNothingSent();
    }

    public static function invalidQueries(): array
    {
        return [['profileId=evil'], ['accountId=evil'], ['page=1001'], ['limit=101'], ['page=0'], ['limit[]=1'], ['unknown=']];
    }

    public function test_missing_revoked_expired_and_wildcard_tokens_are_denied_without_io(): void
    {
        $this->withHeader('Authorization', '')->getJson('/api/v1/context')->assertUnauthorized();
        $this->withToken($this->bearer);
        $this->token->forceFill(['abilities' => ['*']])->save();
        $this->getJson('/api/v1/context')->assertUnauthorized();
        $this->token->forceFill(['abilities' => ['context.read'], 'expires_at' => now()->subSecond()])->save();
        $this->getJson('/api/v1/context')->assertUnauthorized();
        $this->token->delete();
        $this->getJson('/api/v1/context')->assertUnauthorized();
        Http::assertNothingSent();
    }

    public function test_unverified_deleted_and_non_owner_admin_tokens_are_denied(): void
    {
        $this->owner->forceFill(['email_verified_at' => null])->save();
        $this->getJson('/api/v1/context')->assertUnauthorized();
        $this->owner->forceFill(['email_verified_at' => now()])->save();
        $this->owner->update(['role' => 'admin']);
        $other = User::factory()->create();
        $this->client->update(['owner_id' => $other->id]);
        $this->getJson('/api/v1/context')->assertUnauthorized();
        $this->client->update(['owner_id' => $this->owner->id]);
        $this->owner->delete();
        $this->getJson('/api/v1/context')->assertUnauthorized();
        Http::assertNothingSent();
    }

    public function test_two_admin_owners_remain_isolated_by_their_client_bound_tokens(): void
    {
        $this->owner->update(['email' => 'johnkarlo.315@gmail.com', 'role' => 'admin']);
        $platformAdmin = User::factory()->create(['email' => 'test@admin.com', 'role' => 'admin']);
        $platformClient = Client::factory()->create(['owner_id' => $platformAdmin->id]);
        $platformAccount = ClientAccount::factory()->create(['client_id' => $platformClient->id, 'publishing_enabled' => true]);
        $johnOperation = AgentOperation::factory()->create(['client_account_id' => $this->account->id]);
        $platformOperation = AgentOperation::factory()->create(['client_account_id' => $platformAccount->id]);

        foreach ([
            [$this->owner, $this->client, $this->account, $johnOperation, $platformAccount, $platformOperation],
            [$platformAdmin, $platformClient, $platformAccount, $platformOperation, $this->account, $johnOperation],
        ] as [$owner, $client, $ownAccount, $ownOperation, $foreignAccount, $foreignOperation]) {
            $this->assertTrue($owner->isAdmin());
            $issued = app(AgentTokenService::class)->issue($owner, $client, 'Admin isolation test', [...AgentTokenService::READ_ABILITIES, 'zernio.posts.publish']);
            $this->withToken($issued->plainTextToken);

            $this->getJson('/api/v1/context')->assertOk()->assertJsonPath('data.client.id', $client->id);
            $this->getJson('/api/v1/zernio/accounts')->assertOk()->assertJsonCount(1, 'data')
                ->assertJsonPath('data.0.id', $ownAccount->id)->assertJsonPath('pagination.total', 1);
            $this->getJson('/api/v1/operations')->assertOk()->assertJsonCount(1, 'data')
                ->assertJsonPath('data.0.id', $ownOperation->id);
            $this->getJson('/api/v1/operations/'.$ownOperation->id)->assertOk();

            $foreignUrl = '/api/v1/zernio/accounts/'.$foreignAccount->id;
            $this->getJson($foreignUrl.'/status')->assertNotFound();
            $this->getJson($foreignUrl.'/posts')->assertNotFound();
            $this->getJson($foreignUrl.'/posts/'.self::POST)->assertNotFound();
            $this->withHeader('Idempotency-Key', 'admin-isolation-'.$owner->id)
                ->postJson($foreignUrl.'/posts', ['content' => 'Must never be dispatched'])->assertNotFound();
            $this->getJson('/api/v1/operations/'.$foreignOperation->id)->assertNotFound()->assertDontSee($foreignOperation->id);
            Http::assertNothingSent();
        }

        $this->assertDatabaseCount('agent_operations', 2);
    }

    public function test_ability_denials_and_foreign_inactive_and_ungranted_selectors_do_not_call_provider(): void
    {
        $foreign = ClientAccount::factory()->create();
        $this->getJson('/api/v1/zernio/accounts/'.$foreign->id.'/posts')->assertNotFound();
        $this->account->update(['active' => false]);
        $this->getJson('/api/v1/zernio/accounts/'.$this->account->id.'/status')->assertNotFound();
        $this->account->update(['active' => true]);
        $this->issue(['context.read']);
        $this->getJson('/api/v1/zernio/accounts')->assertForbidden();
        $this->issue(accounts: [$this->account->id]);
        $other = ClientAccount::factory()->create(['client_id' => $this->client->id]);
        $this->getJson('/api/v1/zernio/accounts/'.$other->id.'/posts')->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_disabled_reads_fail_closed_without_provider_io(): void
    {
        config(['agent-api.zernio.enabled' => false]);
        $this->getJson('/api/v1/zernio/accounts')->assertServiceUnavailable();
        $this->getJson('/api/v1/zernio/accounts/'.$this->account->id.'/posts')->assertServiceUnavailable();
        Http::assertNothingSent();
    }

    public function test_route_module_declares_exact_abilities_and_write_guard(): void
    {
        $routes = ['context' => 'context.read', 'capabilities' => 'context.read', 'accounts.index' => 'zernio.accounts.read', 'accounts.status' => 'zernio.accounts.read', 'posts.index' => 'zernio.posts.read', 'posts.show' => 'zernio.posts.read', 'posts.store' => 'zernio.posts.publish', 'operations.index' => 'operations.read', 'operations.show' => 'operations.read'];
        foreach ($routes as $name => $ability) {
            $route = Route::getRoutes()->getByName('agent-api.'.$name);
            $this->assertNotNull($route);
            $this->assertSame($ability, $route->defaults['agent_ability']);
            $this->assertSame($name === 'posts.store', $route->defaults['agent_write'] ?? false);
            $this->assertContains(AuthenticateAgentToken::class, $route->gatherMiddleware());
            $this->assertContains(GuardAgentRequest::class, $route->gatherMiddleware());
        }
        Http::assertNothingSent();
    }

    public function test_account_visibility_and_publishing_follow_current_environment_and_permissions(): void
    {
        $this->account->update(['environment' => 'sandbox']);
        $this->getJson('/api/v1/zernio/accounts')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/zernio/accounts/'.$this->account->id.'/posts')->assertNotFound();
        $this->account->update(['environment' => 'production', 'publishing_enabled' => false]);
        $this->getJson('/api/v1/capabilities')->assertOk()->assertJsonPath('data.zernio.write_enabled', false);
        $this->withHeader('Idempotency-Key', 'disabled')->postJson('/api/v1/zernio/accounts/'.$this->account->id.'/posts', ['content' => 'Text'])->assertForbidden();
        $this->account->update(['publishing_enabled' => true]);
        $this->issue(['zernio.posts.read']);
        $this->postJson('/api/v1/zernio/accounts/'.$this->account->id.'/posts', ['content' => 'Text'])->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_operation_lists_and_selectors_do_not_disclose_foreign_or_ungranted_operations(): void
    {
        $mapping = ['local_id' => $this->account->id, 'client_id' => $this->client->id, 'provider' => 'zernio', 'environment' => 'production', 'profile_id' => self::PROFILE, 'account_id' => self::ACCOUNT, 'platform' => 'linkedin'];
        $own = AgentOperation::factory()->create(['client_account_id' => $this->account->id, 'intent' => ['mapping' => $mapping, 'content' => 'Secret']]);
        $foreign = AgentOperation::factory()->create();
        $this->getJson('/api/v1/operations/'.$foreign->id)->assertNotFound()->assertDontSee($foreign->id);
        $this->getJson('/api/v1/operations?limit=1')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('pagination.has_more', false)->assertJsonMissingPath('pagination.total')->assertJsonPath('data.0.id', $own->id)->assertDontSee('Secret');
        $other = ClientAccount::factory()->create(['client_id' => $this->client->id]);
        $this->issue(['operations.read'], [$other->id]);
        $this->getJson('/api/v1/operations/'.$own->id)->assertNotFound()->assertDontSee($own->id);
        $this->getJson('/api/v1/operations')->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('pagination.has_more', false)->assertJsonPath('pagination.next_cursor', null);
        Http::assertNothingSent();
    }

    public function test_operation_history_uses_bounded_sql_cursor_reads_without_decrypting_snapshots(): void
    {
        $history = AgentOperation::factory()->count(250)->create(['client_account_id' => $this->account->id, 'mapping_hash' => $this->account->mapping_hash]);
        $staleAccount = ClientAccount::factory()->create(['client_id' => $this->client->id]);
        $stale = AgentOperation::factory()->count(99)->create(['client_account_id' => $staleAccount->id, 'mapping_hash' => $staleAccount->mapping_hash]);
        $staleAccount->update(['profile_id' => 'eeeeeeeeeeeeeeeeeeeeeeee']);
        DB::table('agent_operations')->where('client_id', $this->client->id)->update(['intent' => 'not-ciphertext', 'result' => 'not-ciphertext']);
        $hydrated = 0;
        $queries = [];
        Event::listen('eloquent.retrieved: '.AgentOperation::class, function (AgentOperation $operation) use (&$hydrated): void {
            $hydrated++;
            $this->assertArrayNotHasKey('intent', $operation->getAttributes());
            $this->assertArrayNotHasKey('result', $operation->getAttributes());
        });
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            if (str_contains($query->sql, 'agent_operations')) {
                $queries[] = $query->sql;
            }
        });
        $expected = $history->pluck('id')->sort()->values();
        $first = $this->getJson('/api/v1/operations?limit=5')->assertOk()->assertJsonCount(5, 'data')
            ->assertJsonPath('pagination', ['limit' => 5, 'has_more' => true, 'next_cursor' => $expected[4], 'complete' => false]);
        $this->assertSame($expected->take(5)->all(), array_column($first->json('data'), 'id'));
        $this->assertSame(6, $hydrated);
        $this->assertCount(1, $queries);
        $this->assertStringContainsString('exists', strtolower($queries[0]));
        $this->assertStringContainsString('limit 6', strtolower($queries[0]));
        $this->assertStringNotContainsString('count(', strtolower($queries[0]));
        $hydrated = 0;
        $second = $this->getJson('/api/v1/operations?limit=5&after='.$expected[4])->assertOk()->assertJsonCount(5, 'data')->assertJsonPath('pagination.complete', false);
        $this->assertSame($expected->slice(5, 5)->values()->all(), array_column($second->json('data'), 'id'));
        $this->assertSame(6, $hydrated);
        $hydrated = 0;
        $this->getJson('/api/v1/operations?limit=5&after='.$expected[248])->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('pagination.has_more', false)->assertJsonPath('pagination.next_cursor', null)->assertJsonPath('pagination.complete', false);
        $this->assertSame(1, $hydrated);
        $hydrated = 0;
        $this->getJson('/api/v1/operations/'.$expected[0])->assertOk();
        $this->assertSame(1, $hydrated);
        $hydrated = 0;
        $this->getJson('/api/v1/operations/'.$stale->first()->id)->assertNotFound();
        $this->assertSame(0, $hydrated);
        $this->account->update(['platform' => 'threads']);
        $this->getJson('/api/v1/operations?limit=5')->assertOk()->assertJsonCount(0, 'data')
            ->assertJsonPath('pagination', ['limit' => 5, 'has_more' => false, 'next_cursor' => null, 'complete' => true]);
        $this->assertSame(0, $hydrated);
        Http::assertNothingSent();
    }

    #[DataProvider('invalidOperationQueries')]
    public function test_operation_cursor_rejects_unknown_and_unbounded_inputs(string $query): void
    {
        $this->getJson('/api/v1/operations?'.$query)->assertUnprocessable();
        Http::assertNothingSent();
    }

    public static function invalidOperationQueries(): array
    {
        return [['page=1'], ['total=1'], ['limit=101'], ['limit=0'], ['after=not-a-uuid'], ['after[]=value'], ['after='], ['client_id=1']];
    }

    #[DataProvider('unavailableOperationMappings')]
    public function test_operation_reads_require_current_active_production_mapping(array $changes): void
    {
        $operation = AgentOperation::factory()->create(['client_account_id' => $this->account->id]);
        $this->account->update($changes);
        $this->getJson('/api/v1/operations/'.$operation->id)->assertNotFound()->assertDontSee($operation->id);
        $this->getJson('/api/v1/operations?limit=1')->assertOk()->assertJsonCount(0, 'data')
            ->assertJsonPath('pagination', ['limit' => 1, 'has_more' => false, 'next_cursor' => null, 'complete' => true]);
        Http::assertNothingSent();
    }

    public static function unavailableOperationMappings(): array
    {
        return [[['active' => false]], [['environment' => 'sandbox']], [['provider' => 'adkit']], [['provider_account_id' => 'eeeeeeeeeeeeeeeeeeeeeeee']], [['platform' => 'threads']]];
    }

    public function test_session_unbound_bearer_and_unknown_abilities_cannot_authenticate(): void
    {
        $this->withHeader('Authorization', '')->actingAs($this->owner)->getJson('/api/v1/context')->assertUnauthorized();
        $unbound = $this->owner->createToken('Unbound', ['context.read'], now()->addDay());
        $this->withToken($unbound->plainTextToken)->getJson('/api/v1/context')->assertUnauthorized();
        $this->token->forceFill(['abilities' => ['context.read', 'unknown.scope']])->save();
        $this->withToken($this->bearer)->getJson('/api/v1/context')->assertUnauthorized();
        Http::assertNothingSent();
    }

    private function fakeProvider(array $targets = [], int $pages = 1, bool $exact = true, int $publishStatus = 201, ?string $postProfile = null): void
    {
        Http::fake(function (Request $request) use ($targets, $pages, $exact, $publishStatus, $postProfile) {
            if (str_contains($request->url(), '/accounts')) {
                $this->assertSame(self::PROFILE, $request['profileId']);
                $this->assertSame(self::ACCOUNT, $request['search']);

                return Http::response(['accounts' => [['_id' => $exact ? self::ACCOUNT : 'dddddddddddddddddddddddd', 'profileId' => self::PROFILE, 'platform' => 'linkedin', 'isActive' => true]], 'hasAnalyticsAccess' => false, 'pagination' => ['page' => (int) $request['page'], 'limit' => (int) $request['limit'], 'total' => $pages, 'pages' => $pages]]);
            }
            $post = ['_id' => self::POST, 'content' => 'Private text', 'status' => 'published', 'platforms' => $targets ?: [['accountId' => self::ACCOUNT, 'platform' => 'linkedin', 'status' => 'published']]];
            if ($postProfile !== null) {
                $post['profileId'] = $postProfile;
            }
            if ($request->method() === 'POST') {
                return Http::response(['post' => $post], $publishStatus);
            }
            if (str_starts_with(parse_url($request->url(), PHP_URL_PATH), '/api/v1/posts/')) {
                $post['_id'] = basename(parse_url($request->url(), PHP_URL_PATH));

                return Http::response(['post' => $post]);
            }
            $this->assertSame('/api/v1/posts', parse_url($request->url(), PHP_URL_PATH));
            $this->assertSame(self::PROFILE, $request['profileId']);
            $this->assertSame(self::ACCOUNT, $request['accountId']);

            return Http::response(['posts' => [$post], 'pagination' => ['page' => (int) $request['page'], 'limit' => (int) $request['limit'], 'total' => $pages, 'pages' => $pages]]);
        });
    }

    public function test_exact_status_search_and_missing_post_are_bounded_to_three_pages(): void
    {
        $this->fakeProvider(pages: 100, exact: false);
        $this->getJson('/api/v1/zernio/accounts/'.$this->account->id.'/status')->assertNotFound();
        Http::assertSentCount(3);
        $this->getJson('/api/v1/zernio/accounts/'.$this->account->id.'/posts/eeeeeeeeeeeeeeeeeeeeeeee')->assertNotFound();
        Http::assertSentCount(7);
    }

    public function test_status_and_posts_use_only_server_owned_scope(): void
    {
        $this->fakeProvider();
        $this->getJson('/api/v1/zernio/accounts/'.$this->account->id.'/status')->assertOk()->assertJsonPath('data.status', 'connected');
        $this->getJson('/api/v1/zernio/accounts/'.$this->account->id.'/posts?limit=10')->assertOk()->assertJsonPath('data.0.content', 'Private text')->assertJsonPath('pagination.complete', true);
        $this->getJson('/api/v1/zernio/accounts/'.$this->account->id.'/posts/'.self::POST)->assertOk()->assertJsonPath('data.id', self::POST);
        Http::assertSentCount(4);
    }

    public function test_mixed_target_private_posts_are_not_returned_even_when_requested_account_matches(): void
    {
        $this->fakeProvider([['accountId' => self::ACCOUNT, 'platform' => 'linkedin', 'status' => 'published'], ['accountId' => 'dddddddddddddddddddddddd', 'platform' => 'facebook', 'status' => 'published']]);
        $this->getJson('/api/v1/zernio/accounts/'.$this->account->id.'/posts')->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('pagination.complete', false)->assertDontSee('Private text');
        $this->getJson('/api/v1/zernio/accounts/'.$this->account->id.'/posts/'.self::POST)->assertNotFound()->assertDontSee('Private text');
    }

    public function test_exact_post_with_observed_membership_does_not_require_recent_history_search(): void
    {
        $this->fakeProvider(postProfile: self::PROFILE);
        $this->getJson('/api/v1/zernio/accounts/'.$this->account->id.'/posts/'.self::POST)->assertOk()->assertJsonPath('data.id', self::POST);
        Http::assertSentCount(1);
    }

    #[DataProvider('midReadRevocations')]
    public function test_provider_reads_recheck_authorization_before_disclosing_or_continuing(string $read, string $change, int $status): void
    {
        $other = ClientAccount::factory()->create(['client_id' => $this->client->id, 'profile_id' => self::PROFILE]);
        $calls = 0;
        Http::fake(function (Request $request) use ($read, $change, $other, &$calls) {
            $calls++;
            if ($calls === ($read === 'fallback' ? 2 : 1)) {
                match ($change) {
                    'token' => $this->token->delete(),
                    'client' => $this->client->update(['active' => false]),
                    'owner' => $this->client->update(['owner_id' => User::factory()->create()->id]),
                    'verified' => $this->owner->forceFill(['email_verified_at' => null])->save(),
                    'grant' => $this->token->forceFill(['allowed_account_ids' => [$other->id]])->save(),
                    'ability' => $this->token->forceFill(['abilities' => ['context.read']])->save(),
                    'profile' => $this->account->providerProfile->update(['active' => false]),
                    'mapping' => $this->account->update(['platform' => 'threads']),
                };
            }
            $post = ['_id' => self::POST, 'content' => 'Private mid-read marker', 'status' => 'published', 'platforms' => [['accountId' => self::ACCOUNT, 'platform' => 'linkedin', 'status' => 'published']]];
            if ($read === 'exact') {
                $post['profileId'] = self::PROFILE;
            }
            $pagination = ['page' => 1, 'limit' => 25, 'total' => 100, 'pages' => 4];

            return Http::response(['post' => $post, 'posts' => [$post], 'accounts' => [['_id' => self::ACCOUNT, 'profileId' => self::PROFILE, 'platform' => 'linkedin', 'isActive' => true]], 'hasAnalyticsAccess' => false, 'pagination' => $pagination]);
        });
        $suffix = $read === 'status' ? '/status' : '/posts'.($read === 'list' ? '' : '/'.self::POST);
        $this->getJson('/api/v1/zernio/accounts/'.$this->account->id.$suffix)->assertStatus($status)
            ->assertJsonMissingPath('data')->assertDontSee('Private mid-read marker');
        Http::assertSentCount($read === 'fallback' ? 2 : 1);
        $this->assertDatabaseCount('agent_api_audits', 1);
    }

    public static function midReadRevocations(): array
    {
        $cases = [];
        foreach (['status', 'list', 'exact', 'unscoped', 'fallback'] as $read) {
            foreach (['token' => 401, 'client' => 401, 'owner' => 401, 'verified' => 401, 'grant' => 404, 'ability' => 403, 'profile' => 404, 'mapping' => 404] as $change => $status) {
                $cases[$read.' '.$change] = [$read, $change, $status];
            }
        }

        return $cases;
    }

    #[DataProvider('midReadTargetScopes')]
    public function test_post_targets_use_fresh_grants_and_profile_claims(string $read, bool $narrow): void
    {
        $second = ClientAccount::factory()->create(['client_id' => $this->client->id, 'profile_id' => self::PROFILE]);
        Http::fake(function () use ($second, $narrow) {
            if ($narrow) {
                $this->token->forceFill(['allowed_account_ids' => [$this->account->id]])->save();
            } else {
                $second->update(['active' => false]);
            }
            $post = ['_id' => self::POST, 'profileId' => self::PROFILE, 'content' => 'Private narrowed-target marker', 'status' => 'published', 'platforms' => [
                ['accountId' => self::ACCOUNT, 'platform' => 'linkedin', 'status' => 'published'],
                ['accountId' => $second->provider_account_id, 'platform' => 'linkedin', 'status' => 'published'],
            ]];

            return Http::response(['post' => $post, 'posts' => [$post], 'pagination' => ['page' => 1, 'limit' => 25, 'total' => 1, 'pages' => 1]]);
        });
        $response = $this->getJson('/api/v1/zernio/accounts/'.$this->account->id.'/posts'.($read === 'list' ? '' : '/'.self::POST));
        if ($read === 'list') {
            $response->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('pagination.filtered', true);
        } else {
            $response->assertNotFound()->assertJsonMissingPath('data');
        }
        $response->assertDontSee('Private narrowed-target marker');
        Http::assertSentCount(1);
    }

    public static function midReadTargetScopes(): array
    {
        return [['list', true], ['exact', true], ['list', false], ['exact', false]];
    }

    public function test_exact_post_with_foreign_membership_is_hidden(): void
    {
        $this->fakeProvider(postProfile: 'eeeeeeeeeeeeeeeeeeeeeeee');
        $this->getJson('/api/v1/zernio/accounts/'.$this->account->id.'/posts/'.self::POST)->assertNotFound()->assertDontSee('Private text');
        Http::assertSentCount(1);
    }

    public function test_exact_post_without_requested_target_is_hidden_even_if_other_targets_are_authorized(): void
    {
        $other = ClientAccount::factory()->create(['client_id' => $this->client->id, 'profile_id' => self::PROFILE]);
        $this->fakeProvider([['accountId' => $other->provider_account_id, 'platform' => $other->platform, 'status' => 'published']], postProfile: self::PROFILE);
        $this->getJson('/api/v1/zernio/accounts/'.$this->account->id.'/posts/'.self::POST)->assertNotFound()->assertDontSee('Private text');
        Http::assertSentCount(1);
    }

    public function test_post_reads_hydrate_only_mappings_for_returned_targets(): void
    {
        ClientAccount::factory()->count(99)->create(['client_id' => $this->client->id, 'profile_id' => self::PROFILE]);
        $hydrated = 0;
        Event::listen('eloquent.retrieved: '.ClientAccount::class, function () use (&$hydrated): void {
            $hydrated++;
        });
        $this->fakeProvider();
        $this->getJson('/api/v1/zernio/accounts/'.$this->account->id.'/posts')->assertOk()->assertJsonCount(1, 'data');
        $this->assertLessThanOrEqual(3, $hydrated);
        $hydrated = 0;
        $this->getJson('/api/v1/zernio/accounts/'.$this->account->id.'/posts/'.self::POST)->assertOk();
        $this->assertLessThanOrEqual(4, $hydrated);
    }

    public function test_all_post_targets_require_current_profile_platform_activity_and_token_grants(): void
    {
        $second = ClientAccount::factory()->create(['client_id' => $this->client->id, 'profile_id' => self::PROFILE, 'provider_account_id' => 'dddddddddddddddddddddddd', 'platform' => 'facebook']);
        $this->fakeProvider([['accountId' => self::ACCOUNT, 'platform' => 'linkedin', 'status' => 'published'], ['accountId' => $second->provider_account_id, 'platform' => 'facebook', 'status' => 'published']]);
        $url = '/api/v1/zernio/accounts/'.$this->account->id.'/posts';
        $this->getJson($url)->assertOk()->assertJsonCount(1, 'data');
        foreach ([['active' => false], ['active' => true, 'profile_id' => 'eeeeeeeeeeeeeeeeeeeeeeee'], ['profile_id' => self::PROFILE, 'platform' => 'threads']] as $change) {
            $second->update($change);
            $this->getJson($url)->assertOk()->assertJsonCount(0, 'data')->assertDontSee('Private text');
        }
        $second->update(['platform' => 'facebook']);
        $this->issue(['zernio.posts.read'], [$this->account->id]);
        $this->getJson($url)->assertOk()->assertJsonCount(0, 'data')->assertDontSee('Private text');
    }

    public function test_publish_is_idempotent_and_polling_is_local_and_mapping_scoped(): void
    {
        $this->fakeProvider();
        $url = '/api/v1/zernio/accounts/'.$this->account->id.'/posts';
        $first = $this->withHeader('Idempotency-Key', 'same-key')->postJson($url, ['content' => 'Private text'])->assertOk()->assertJsonPath('data.state', 'succeeded');
        $id = $first->json('data.id');
        $this->postJson($url, ['content' => 'Private text'])->assertOk()->assertJsonPath('data.id', $id);
        Http::assertSentCount(2);
        $this->postJson($url, ['content' => 'Changed'])->assertConflict();
        $this->getJson('/api/v1/operations/'.$id)->assertOk()->assertJsonMissingPath('data.intent')->assertJsonMissingPath('data.result');
        $this->getJson('/api/v1/operations')->assertOk()->assertJsonCount(1, 'data');
        $this->account->update(['profile_id' => 'eeeeeeeeeeeeeeeeeeeeeeee']);
        $this->getJson('/api/v1/operations/'.$id)->assertNotFound();
        $this->getJson('/api/v1/operations')->assertOk()->assertJsonCount(0, 'data');
        Http::assertSentCount(2);
    }

    public function test_publish_requires_header_and_rejects_body_and_query_overrides(): void
    {
        $url = '/api/v1/zernio/accounts/'.$this->account->id.'/posts';
        $this->postJson($url, ['content' => 'Text'])->assertUnprocessable();
        $this->withHeader('Idempotency-Key', 'key')->postJson($url, ['content' => 'Text', 'profileId' => self::PROFILE])->assertUnprocessable();
        $this->postJson($url.'?content=override', ['content' => 'Text'])->assertUnprocessable();
        $this->postJson($url, ['content' => ['Text']])->assertUnprocessable();
        Http::assertNothingSent();
    }

    public function test_nonterminal_publish_returns_accepted_and_polling_never_reconciles(): void
    {
        $this->fakeProvider(publishStatus: 503);
        $response = $this->withHeader('Idempotency-Key', 'ambiguous')->postJson('/api/v1/zernio/accounts/'.$this->account->id.'/posts', ['content' => 'Private text'])->assertAccepted()->assertJsonPath('data.state', 'unknown');
        $count = count(Http::recorded());
        $this->getJson('/api/v1/operations/'.$response->json('data.id'))->assertOk()->assertJsonPath('data.state', 'unknown');
        Http::assertSentCount($count);
        $this->assertSame(1, AgentOperation::count());
    }
}
