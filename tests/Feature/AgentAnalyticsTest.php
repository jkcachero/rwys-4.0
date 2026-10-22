<?php

namespace Tests\Feature;

use App\Http\Middleware\AuthenticateAgentToken;
use App\Http\Middleware\GuardAgentRequest;
use App\Models\AgentAccessToken;
use App\Models\Client;
use App\Models\ClientAccount;
use App\Models\User;
use App\Services\AgentTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use stdClass;
use Tests\TestCase;

class AgentAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    private const PROFILE = 'aaaaaaaaaaaaaaaaaaaaaaaa';

    private const ACCOUNT = 'bbbbbbbbbbbbbbbbbbbbbbbb';

    private const POST = 'cccccccccccccccccccccccc';

    private const SECOND = 'dddddddddddddddddddddddd';

    private User $owner;

    private Client $client;

    private ClientAccount $account;

    protected function beforeRefreshingDatabase(): void
    {
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->assertEmpty(config('database.connections.sqlite.url'));
    }

    protected function setUp(): void
    {
        parent::setUp();
        config(['agent-api.zernio.enabled' => true, 'agent-api.zernio.api_key' => 'analytics-test-secret', 'agent-api.zernio.publishing_enabled' => false, 'agent-api.reads_per_minute' => 1000]);
        Http::preventStrayRequests();
        Http::fake([]);
        Route::prefix('api')->middleware('api')->group(base_path('routes/agent-api.php'));
        Route::getRoutes()->refreshNameLookups();
        $this->owner = User::factory()->create();
        $this->client = Client::factory()->create(['owner_id' => $this->owner->id]);
        $this->account = ClientAccount::factory()->create(['client_id' => $this->client->id, 'profile_id' => self::PROFILE, 'provider_account_id' => self::ACCOUNT]);
    }

    /** @param list<string> $abilities
     * @param  list<int>|null  $accounts
     */
    private function issue(array $abilities = ['zernio.analytics.read'], ?array $accounts = null): void
    {
        $issued = app(AgentTokenService::class)->issue($this->owner, $this->client, 'Analytics test', $abilities, $accounts);
        $this->withToken($issued->plainTextToken);
    }

    private function url(?ClientAccount $account = null, string $post = self::POST): string
    {
        return '/api/v1/zernio/accounts/'.($account ?? $this->account)->id.'/posts/'.$post.'/analytics';
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return [
            'postId' => self::POST, 'latePostId' => null, 'status' => 'published', 'syncStatus' => 'synced',
            'analytics' => ['impressions' => 0, 'follows' => null],
            'platformAnalytics' => [[
                'accountId' => self::ACCOUNT, 'platform' => 'linkedin', 'status' => 'published', 'syncStatus' => 'synced',
                'analytics' => ['impressions' => 0, 'follows' => null], 'errorCode' => null, 'isOwner' => true,
            ]],
        ];
    }

    /** @param array<string, mixed>|null $payload */
    private function fakeAnalytics(?array $payload = null, int $status = 200): void
    {
        Http::fake(function (Request $request) use ($payload, $status) {
            $this->assertSame('GET', $request->method());
            $this->assertSame('https://zernio.com/api/v1/analytics', strtok($request->url(), '?'));
            $this->assertSame(['profileId' => self::PROFILE, 'accountId' => self::ACCOUNT, 'postId' => self::POST], $request->data());

            return Http::response($payload ?? $this->payload(), $status);
        });
    }

    public function test_route_has_exact_read_ability_and_no_write_guard(): void
    {
        $route = Route::getRoutes()->getByName('agent-api.posts.analytics');
        $this->assertNotNull($route);
        $this->assertSame('api/v1/zernio/accounts/{account}/posts/{post}/analytics', $route->uri());
        $this->assertSame(['GET', 'HEAD'], $route->methods());
        $this->assertSame('zernio.analytics.read', $route->defaults['agent_ability']);
        $this->assertFalse($route->defaults['agent_write']);
        $this->assertContains(AuthenticateAgentToken::class, $route->gatherMiddleware());
        $this->assertContains(GuardAgentRequest::class, $route->gatherMiddleware());
        Http::assertNothingSent();
    }

    public function test_owned_analytics_preserves_zero_null_and_absent_metrics_without_internal_scope(): void
    {
        $this->issue();
        $payload = $this->payload();
        $payload['apiKey'] = 'private-upstream-secret';
        $payload['platformAnalytics'][0]['accessToken'] = 'private-upstream-secret';
        $this->fakeAnalytics($payload);
        $this->getJson($this->url())->assertOk()->assertJsonStructure(['request_id'])
            ->assertJsonPath('data.post_id', self::POST)->assertJsonPath('data.zernio_post_id', null)
            ->assertJsonPath('data.source', 'zernio')->assertJsonPath('data.upstream_status', 200)
            ->assertJsonPath('data.analytics', ['impressions' => 0, 'follows' => null])
            ->assertJsonPath('data.platform_analytics.0.analytics', ['impressions' => 0, 'follows' => null])
            ->assertJsonPath('data.platform_analytics.0.is_owner', true)
            ->assertJsonMissingPath('data.analytics.shares')->assertJsonMissingPath('data.profile_id')
            ->assertDontSee('private-upstream-secret');
        Http::assertSentCount(1);
    }

    #[DataProvider('adminOwners')]
    public function test_both_admin_owners_can_read_owned_but_not_foreign_selectors(string $email): void
    {
        $this->owner->update(['email' => $email, 'role' => 'admin']);
        $this->assertTrue($this->owner->isAdmin());
        $this->issue();
        $foreign = ClientAccount::factory()->create();
        $this->getJson($this->url($foreign))->assertNotFound();
        Http::assertNothingSent();
        $this->fakeAnalytics();
        $this->getJson($this->url())->assertOk();
        Http::assertSentCount(1);
    }

    public static function adminOwners(): array
    {
        return [['johnkarlo.315@gmail.com'], ['test@admin.com']];
    }

    public function test_narrow_account_grants_reject_other_local_selector_before_io(): void
    {
        $this->issue(accounts: [$this->account->id]);
        $other = ClientAccount::factory()->create(['client_id' => $this->client->id]);
        $this->getJson($this->url($other))->assertNotFound();
        Http::assertNothingSent();
    }

    #[DataProvider('oldAbilities')]
    public function test_existing_read_abilities_do_not_grant_analytics(array $abilities): void
    {
        $this->issue($abilities);
        $this->getJson($this->url())->assertForbidden();
        Http::assertNothingSent();
    }

    public static function oldAbilities(): array
    {
        return [[['zernio.posts.read']], [['zernio.accounts.read']], [['context.read']]];
    }

    #[DataProvider('unknownQueries')]
    public function test_all_query_overrides_are_rejected(string $query): void
    {
        $this->issue();
        $this->getJson($this->url().'?'.$query)->assertUnprocessable();
        Http::assertNothingSent();
    }

    public static function unknownQueries(): array
    {
        return array_map(static fn (string $field): array => [$field.'=override'], ['profileId', 'accountId', 'postId', 'profile_id', 'account_id', 'client_id', 'page', 'limit', 'unknown']);
    }

    public function test_get_bodies_and_files_are_rejected(): void
    {
        $this->issue();
        $this->json('GET', $this->url(), ['accountId' => self::SECOND])->assertUnprocessable();
        $this->call('GET', $this->url(), [], [], ['file' => UploadedFile::fake()->create('metrics.txt')], $this->transformHeadersToServerVars([...$this->defaultHeaders, 'Accept' => 'application/json']))->assertUnprocessable();
        Http::assertNothingSent();
    }

    #[DataProvider('invalidPosts')]
    public function test_invalid_post_selectors_are_hidden_without_io(string $post): void
    {
        $this->issue();
        $this->getJson($this->url(post: $post))->assertNotFound();
        Http::assertNothingSent();
    }

    public static function invalidPosts(): array
    {
        return [['invalid'], [str_repeat('C', 24)], [str_repeat('c', 23)], [str_repeat('c', 25)]];
    }

    public function test_disabled_provider_fails_closed_after_local_scope_check(): void
    {
        $this->issue();
        config(['agent-api.zernio.enabled' => false]);
        $this->getJson($this->url(ClientAccount::factory()->create()))->assertNotFound();
        $this->getJson($this->url())->assertServiceUnavailable();
        Http::assertNothingSent();
    }

    public function test_foreign_returned_target_withholds_the_entire_aggregate(): void
    {
        $this->issue();
        $foreign = ClientAccount::factory()->create(['profile_id' => self::PROFILE]);
        $payload = $this->payload();
        $payload['analytics'] = ['impressions' => 987654321];
        $payload['platformAnalytics'][] = array_replace($payload['platformAnalytics'][0], ['accountId' => $foreign->provider_account_id]);
        $this->fakeAnalytics($payload);
        $this->getJson($this->url())->assertNotFound()->assertJsonMissingPath('data')->assertDontSee('987654321')->assertDontSee($foreign->provider_account_id);
        Http::assertSentCount(1);
    }

    #[DataProvider('midReadRevocations')]
    public function test_analytics_rechecks_principal_abilities_and_all_target_scopes_after_read(string $change, int $status): void
    {
        $second = ClientAccount::factory()->create(['client_id' => $this->client->id, 'profile_id' => self::PROFILE, 'provider_account_id' => self::SECOND]);
        $issued = app(AgentTokenService::class)->issue($this->owner, $this->client, 'Mid-read analytics', ['zernio.analytics.read']);
        $this->withToken($issued->plainTextToken);
        $token = AgentAccessToken::findToken($issued->plainTextToken);
        Http::fake(function () use ($change, $second, $token) {
            match ($change) {
                'token' => $token->delete(),
                'client' => $this->client->update(['active' => false]),
                'owner' => $this->client->update(['owner_id' => User::factory()->create()->id]),
                'verified' => $this->owner->forceFill(['email_verified_at' => null])->save(),
                'ability' => $token->forceFill(['abilities' => ['context.read']])->save(),
                'grant' => $token->forceFill(['allowed_account_ids' => [$second->id]])->save(),
                'target grant' => $token->forceFill(['allowed_account_ids' => [$this->account->id]])->save(),
                'profile' => $this->account->providerProfile->update(['active' => false]),
                'mapping' => $this->account->update(['platform' => 'threads']),
                'target active' => $second->update(['active' => false]),
                'target owner' => $second->update(['client_id' => Client::factory()->create()->id]),
                'target platform' => $second->update(['platform' => 'facebook']),
            };
            $payload = $this->payload();
            $payload['analytics'] = ['impressions' => 987654321];
            $payload['platformAnalytics'][] = array_replace($payload['platformAnalytics'][0], ['accountId' => self::SECOND]);

            return Http::response($payload);
        });
        $this->getJson($this->url())->assertStatus($status)->assertJsonMissingPath('data')->assertDontSee('987654321');
        Http::assertSentCount(1);
        $this->assertDatabaseCount('agent_api_audits', 1);
    }

    public static function midReadRevocations(): array
    {
        return [
            ['token', 401], ['client', 401], ['owner', 401], ['verified', 401], ['ability', 403],
            ['grant', 404], ['target grant', 404], ['profile', 404], ['mapping', 404],
            ['target active', 404], ['target owner', 404], ['target platform', 404],
        ];
    }

    #[DataProvider('targetScopes')]
    public function test_every_returned_target_requires_current_mapping_and_grants(array $changes, bool $narrow, int $status): void
    {
        $second = ClientAccount::factory()->create(['client_id' => $this->client->id, 'profile_id' => self::PROFILE, 'provider_account_id' => self::SECOND]);
        $this->issue(accounts: $narrow ? [$this->account->id] : [$this->account->id, $second->id]);
        $second->update($changes);
        $payload = $this->payload();
        $payload['platformAnalytics'][] = array_replace($payload['platformAnalytics'][0], ['accountId' => self::SECOND]);
        $this->fakeAnalytics($payload);
        $response = $this->getJson($this->url())->assertStatus($status);
        if ($status === 200) {
            $response->assertJsonCount(2, 'data.platform_analytics');
        } else {
            $response->assertJsonMissingPath('data');
        }
        Http::assertSentCount(1);
    }

    public static function targetScopes(): array
    {
        return [
            'mapped' => [[], false, 200], 'narrow' => [[], true, 404],
            'inactive' => [['active' => false], false, 404], 'sandbox' => [['environment' => 'sandbox'], false, 404],
            'provider' => [['provider' => 'adkit'], false, 404], 'platform' => [['platform' => 'facebook'], false, 404],
            'profile' => [['profile_id' => 'eeeeeeeeeeeeeeeeeeeeeeee'], false, 404],
        ];
    }

    public function test_zernio_selector_can_resolve_to_external_post_alias(): void
    {
        $this->issue();
        $payload = $this->payload();
        $payload['postId'] = 'eeeeeeeeeeeeeeeeeeeeeeee';
        $payload['latePostId'] = self::POST;
        $this->fakeAnalytics($payload);
        $this->getJson($this->url())->assertOk()->assertJsonPath('data.post_id', 'eeeeeeeeeeeeeeeeeeeeeeee')->assertJsonPath('data.zernio_post_id', self::POST);
        Http::assertSentCount(1);
    }

    #[DataProvider('analyticsStates')]
    public function test_upstream_states_and_missing_metrics_are_not_fabricated(int $status, string $syncStatus): void
    {
        $this->issue();
        $payload = $this->payload();
        $payload['syncStatus'] = $syncStatus;
        $payload['analytics'] = null;
        $payload['platformAnalytics'][0]['syncStatus'] = $syncStatus;
        $payload['platformAnalytics'][0]['analytics'] = null;
        $this->fakeAnalytics($payload, $status);
        $this->getJson($this->url())->assertStatus($status)->assertJsonStructure(['request_id'])
            ->assertJsonPath('data.upstream_status', $status)->assertJsonPath('data.sync_status', $syncStatus)
            ->assertJsonPath('data.analytics', null)->assertJsonPath('data.platform_analytics.0.analytics', null);
        Http::assertSentCount(1);
    }

    public static function analyticsStates(): array
    {
        return [[200, 'synced'], [202, 'pending'], [424, 'unavailable']];
    }

    public function test_absent_analytics_remains_null_instead_of_zero_filled(): void
    {
        $this->issue();
        $payload = $this->payload();
        unset($payload['analytics'], $payload['platformAnalytics'][0]['analytics']);
        $this->fakeAnalytics($payload);
        $this->getJson($this->url())->assertOk()->assertJsonPath('data.analytics', null)
            ->assertJsonPath('data.platform_analytics.0.analytics', null);
        Http::assertSentCount(1);
    }

    #[DataProvider('invalidResponses')]
    public function test_unproven_upstream_identity_or_requested_target_never_discloses_metrics(string $variant): void
    {
        $this->issue();
        $payload = $this->payload();
        if ($variant === 'post') {
            $payload['postId'] = self::SECOND;
        } elseif ($variant === 'profile') {
            $payload['profileId'] = self::SECOND;
        } else {
            $payload['platformAnalytics'][0]['accountId'] = self::SECOND;
        }
        $this->fakeAnalytics($payload);
        $this->getJson($this->url())->assertStatus(502)->assertJsonMissingPath('data');
        Http::assertSentCount(1);
    }

    public static function invalidResponses(): array
    {
        return [['post'], ['profile'], ['target']];
    }

    #[DataProvider('upstreamErrors')]
    public function test_upstream_errors_are_sanitized_without_mutating_or_retrying(int $upstream, int $status, string $code): void
    {
        $this->issue();
        $this->fakeAnalytics(['error' => 'private-upstream-secret', 'analytics' => ['impressions' => 0]], $upstream);
        $this->getJson($this->url())->assertStatus($status)->assertJsonPath('error.code', $code)
            ->assertJsonMissingPath('data')->assertJsonStructure(['request_id'])->assertDontSee('private-upstream-secret');
        Http::assertSentCount(1);
    }

    public static function upstreamErrors(): array
    {
        return [[402, 402, 'analytics_access_required'], [404, 404, 'not_found'], [500, 502, 'provider_unavailable'], [401, 502, 'provider_unavailable']];
    }

    #[DataProvider('wireMetrics')]
    public function test_aggregate_and_target_metrics_keep_json_object_shapes_on_the_wire(stdClass $upstream, stdClass $expected): void
    {
        $this->issue();
        $payload = $this->payload();
        $payload['analytics'] = $upstream;
        $payload['platformAnalytics'][0]['analytics'] = $upstream;
        $this->fakeAnalytics($payload);
        $response = $this->getJson($this->url())->assertOk()->assertDontSee('private-upstream-secret');
        $wire = json_decode($response->getContent(), false, 512, JSON_THROW_ON_ERROR);
        $this->assertInstanceOf(stdClass::class, $wire);
        $this->assertSame($response->headers->get('X-Request-ID'), $wire->request_id);
        foreach ([$wire->data->analytics, $wire->data->platform_analytics[0]->analytics] as $metrics) {
            $this->assertInstanceOf(stdClass::class, $metrics);
            $this->assertSame(array_keys(get_object_vars($expected)), array_keys(get_object_vars($metrics)));
            foreach (get_object_vars($expected) as $name => $value) {
                if ($value instanceof stdClass) {
                    $this->assertInstanceOf(stdClass::class, $metrics->{$name});
                    $this->assertSame(get_object_vars($value), get_object_vars($metrics->{$name}));
                    foreach (get_object_vars($value) as $key => $number) {
                        $this->assertSame($number, $metrics->{$name}->{(string) $key});
                    }
                } else {
                    $this->assertSame($value, $metrics->{$name});
                }
            }
        }
        Http::assertSentCount(1);
    }

    public static function wireMetrics(): array
    {
        $emptyMaps = (object) ['impressionSources' => new stdClass, 'audienceTypes' => new stdClass, 'audienceCountries' => new stdClass, 'retentionCurve' => new stdClass];
        $sequential = (object) ['retentionCurve' => (object) ['0' => 100, '1' => 75, '2' => 50]];
        $sparse = (object) ['retentionCurve' => (object) ['0' => 100, '4' => 25]];
        $named = (object) ['impressionSources' => (object) ['feed' => 12], 'audienceTypes' => (object) ['followers' => 10], 'audienceCountries' => (object) ['US' => 8]];

        return [
            'empty metrics' => [new stdClass, new stdClass],
            'unknown metrics sanitized to object' => [(object) ['unknownMetric' => 'private-upstream-secret'], new stdClass],
            'empty maps' => [$emptyMaps, $emptyMaps],
            'sequential retention keys' => [$sequential, $sequential],
            'sparse retention keys' => [$sparse, $sparse],
            'named map properties' => [$named, $named],
        ];
    }

    public function test_repeated_reads_return_current_upstream_metrics_even_when_values_decrease(): void
    {
        $this->issue();
        $first = $this->payload();
        $first['analytics'] = $first['platformAnalytics'][0]['analytics'] = ['likes' => 12];
        $second = $this->payload();
        $second['analytics'] = $second['platformAnalytics'][0]['analytics'] = ['likes' => 10];
        Http::fakeSequence('https://zernio.com/api/v1/analytics*')->push($first)->push($second);
        foreach ([12, 10] as $likes) {
            $this->getJson($this->url())->assertOk()->assertHeader('Cache-Control', 'no-store, private')
                ->assertJsonPath('data.analytics.likes', $likes)->assertJsonPath('data.platform_analytics.0.analytics.likes', $likes);
        }
        Http::assertSentCount(2);
        foreach (Http::recorded() as [$request]) {
            $this->assertSame('GET', $request->method());
            $this->assertSame('https://zernio.com/api/v1/analytics', strtok($request->url(), '?'));
            $this->assertSame(['profileId' => self::PROFILE, 'accountId' => self::ACCOUNT, 'postId' => self::POST], $request->data());
        }
    }

    #[DataProvider('readOnlyAbilities')]
    public function test_read_tokens_cannot_publish_even_when_server_and_account_allow_publishing(array $abilities): void
    {
        config(['agent-api.zernio.publishing_enabled' => true]);
        $this->account->update(['publishing_enabled' => true]);
        $this->issue($abilities);
        $this->withHeader('Idempotency-Key', 'read-only-publish-denied')
            ->postJson('/api/v1/zernio/accounts/'.$this->account->id.'/posts', ['content' => 'Never dispatch'])
            ->assertForbidden()->assertJsonPath('error.code', 'forbidden');
        Http::assertNothingSent();
    }

    public static function readOnlyAbilities(): array
    {
        return [[['zernio.posts.read']], [['zernio.accounts.read']], [['context.read']], [['zernio.analytics.read']], [AgentTokenService::READ_ABILITIES]];
    }

    public function test_analytics_only_capability_is_truthful_and_disappears_when_disabled_or_unmapped(): void
    {
        $this->issue(['context.read', 'zernio.analytics.read']);
        $this->getJson('/api/v1/capabilities')->assertOk()->assertJsonPath('data.abilities', ['context.read', 'zernio.analytics.read'])
            ->assertJsonPath('data.zernio.read_enabled', true)->assertJsonPath('data.zernio.write_enabled', false)->assertJsonMissingPath('data.zernio.analytics_enabled');
        config(['agent-api.zernio.enabled' => false]);
        $this->getJson('/api/v1/capabilities')->assertOk()->assertJsonPath('data.abilities', ['context.read'])->assertJsonPath('data.zernio.read_enabled', false);
        config(['agent-api.zernio.enabled' => true]);
        $this->account->update(['active' => false]);
        $this->getJson('/api/v1/capabilities')->assertOk()->assertJsonPath('data.abilities', ['context.read'])->assertJsonPath('data.zernio.read_enabled', false);
        Http::assertNothingSent();
    }
}
