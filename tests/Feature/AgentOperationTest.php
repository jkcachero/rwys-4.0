<?php

namespace Tests\Feature;

use App\Http\Resources\AgentOperationResource;
use App\Models\AgentAccessToken;
use App\Models\AgentOperation;
use App\Models\Client;
use App\Models\ClientAccount;
use App\Models\User;
use App\Services\AgentOperationService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class AgentOperationTest extends TestCase
{
    use RefreshDatabase;

    private const PROFILE = 'aaaaaaaaaaaaaaaaaaaaaaaa';

    private const ACCOUNT = 'bbbbbbbbbbbbbbbbbbbbbbbb';

    private const POST = 'cccccccccccccccccccccccc';

    private ClientAccount $account;

    private AgentAccessToken $token;

    /** @var array<string, mixed> */
    private array $publishResponse;

    /** @var array<string, mixed> */
    private array $getResponse;

    private int $publishStatus = 201;

    private int $getStatus = 200;

    private int $writes = 0;

    private int $reads = 0;

    private int $accountReads = 0;

    private int $accountPages = 1;

    private int $listingPages = 1;

    private bool $candidateInListing = true;

    private ?int $candidatePage = null;

    /** @var list<int> */
    private array $listingReads = [];

    private ?\Closure $duringPostRead = null;

    private bool $exactAccount = true;

    private bool $revokeDuringPreflight = false;

    private bool $competingClaim = false;

    protected function beforeRefreshingDatabase(): void
    {
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->assertEmpty(config('database.connections.sqlite.url'));
    }

    protected function setUp(): void
    {
        parent::setUp();
        config(['agent-api.zernio.enabled' => true, 'agent-api.zernio.publishing_enabled' => true, 'agent-api.zernio.api_key' => 'test-provider-secret']);
        $owner = User::factory()->create();
        $client = Client::factory()->create(['owner_id' => $owner->id]);
        $this->account = ClientAccount::factory()->create([
            'client_id' => $client->id, 'provider' => 'zernio', 'environment' => 'production',
            'profile_id' => self::PROFILE, 'provider_account_id' => self::ACCOUNT, 'platform' => 'linkedin',
            'active' => true, 'publishing_enabled' => true,
        ]);
        $this->token = $this->issueToken($owner, $client);
        $this->publishResponse = $this->rawPost();
        $this->getResponse = $this->rawPost();
        Http::preventStrayRequests();
        Http::fake(function (Request $request) {
            if ($request->method() === 'POST') {
                $this->writes++;
                $operation = AgentOperation::sole();
                $this->assertSame('executing', $operation->state);
                $this->assertSame([$operation->id], $request->header('Idempotency-Key'));
                $this->assertSame(1, DB::transactionLevel());

                return Http::response($this->publishResponse, $this->publishStatus);
            }
            if (str_contains($request->url(), '/accounts')) {
                $this->accountReads++;
                if ($this->revokeDuringPreflight) {
                    $this->token->delete();
                }
                if ($this->competingClaim) {
                    AgentOperation::query()->update(['state' => 'executing']);
                }

                return Http::response([
                    'accounts' => [['_id' => $this->exactAccount ? self::ACCOUNT : 'dddddddddddddddddddddddd', 'profileId' => self::PROFILE, 'platform' => 'linkedin', 'isActive' => true]],
                    'hasAnalyticsAccess' => false, 'pagination' => ['page' => (int) $request['page'], 'limit' => 25, 'total' => $this->accountPages === 1 ? 1 : $this->accountPages * 25, 'pages' => $this->accountPages],
                ]);
            }
            $this->reads++;

            if (parse_url($request->url(), PHP_URL_PATH) === '/api/v1/posts') {
                $page = (int) $request['page'];
                $this->listingReads[] = $page;

                return Http::response([
                    'posts' => $this->candidateInListing && ($this->candidatePage === null || $this->candidatePage === $page) ? [$this->getResponse['post']] : [],
                    'pagination' => ['page' => (int) $request['page'], 'limit' => 25, 'total' => $this->listingPages === 1 ? 1 : $this->listingPages * 25, 'pages' => $this->listingPages],
                ], $this->getStatus);
            }

            if ($this->duringPostRead !== null) {
                ($this->duringPostRead)();
            }

            return Http::response($this->getResponse, $this->getStatus);
        });
    }

    private function issueToken(User $owner, Client $client): AgentAccessToken
    {
        return AgentAccessToken::forceCreate([
            'tokenable_type' => $owner->getMorphClass(), 'tokenable_id' => $owner->id,
            'name' => 'agent', 'token' => hash('sha256', fake()->uuid()),
            'abilities' => ['zernio.posts.publish'], 'expires_at' => now()->addHour(),
            'client_id' => $client->id, 'allowed_account_ids' => [$this->account->id],
        ]);
    }

    /** @return array<string, mixed> */
    private function rawPost(string $status = 'published', string $targetStatus = 'published'): array
    {
        return ['post' => ['_id' => self::POST, 'profileId' => self::PROFILE, 'status' => $status,
            'content' => 'private text', 'platforms' => [['accountId' => ['_id' => self::ACCOUNT, 'profileId' => self::PROFILE], 'platform' => 'linkedin', 'status' => $targetStatus, 'platformPostUrl' => null]]]];
    }

    private function publish(string $content = 'private text', string $key = 'key'): AgentOperation
    {
        return app(AgentOperationService::class)->publish($this->token, $this->account, $content, $key);
    }

    public function test_publish_is_durable_encrypted_and_deduplicated_across_token_rotation(): void
    {
        $operation = $this->publish();
        $this->assertSame('succeeded', $operation->state);
        $replacement = $this->issueToken($this->token->tokenable, Client::findOrFail($this->account->client_id));
        $this->token->delete();
        $this->token = $replacement;
        $this->assertSame($operation->id, $this->publish()->id);
        $this->assertSame(1, $this->writes);
        $this->assertStringNotContainsString('private text', DB::table('agent_operations')->value('intent'));
        $this->assertStringNotContainsString(self::POST, DB::table('agent_operations')->value('result'));
        $resource = (new AgentOperationResource($operation))->resolve();
        $this->assertSame(['id', 'client_id', 'client_account_id', 'capability', 'state', 'provider_post_id', 'created_at', 'updated_at', 'last_reconciled_at'], array_keys($resource));
        $this->assertStringNotContainsString('private text', json_encode($resource));
        foreach (['intent', 'result', 'key_hash', 'payload_hash', 'recovery_post_id'] as $secret) {
            $this->assertArrayNotHasKey($secret, $operation->toArray());
        }
    }

    public function test_conflicting_payload_is_rejected_without_resending(): void
    {
        $this->publish();
        try {
            $this->publish('changed');
            $this->fail('Conflict was accepted.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }
        $this->assertSame(1, $this->writes);
    }

    public function test_revoked_token_is_checked_before_replay(): void
    {
        $this->publish();
        $this->token->delete();
        $this->expectException(HttpException::class);
        $this->publish();
    }

    #[DataProvider('invalidTokens')]
    public function test_invalid_persisted_scope_is_rejected_without_provider_calls(array $attributes): void
    {
        if (array_key_exists('client_id', $attributes)) {
            $attributes['client_id'] = Client::factory()->create(['owner_id' => $this->token->tokenable_id])->id;
        }
        $this->token->forceFill($attributes)->save();
        try {
            $this->publish();
            $this->fail('Invalid scope was accepted.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        Http::assertNothingSent();
    }

    public static function invalidTokens(): array
    {
        return [
            'wildcard' => [['abilities' => ['*', 'zernio.posts.publish']]],
            'prefix wildcard' => [['abilities' => ['zernio.*', 'zernio.posts.publish']]],
            'unknown ability' => [['abilities' => ['zernio.posts.publish', 'arbitrary.admin']]],
            'non-string ability' => [['abilities' => ['zernio.posts.publish', 123]]],
            'no ability' => [['abilities' => ['operations.read']]],
            'no finite expiry' => [['expires_at' => null]],
            'expired' => [['expires_at' => '2000-01-01']],
            'account outside scope' => [['allowed_account_ids' => [999999]]],
            'client outside scope' => [['client_id' => 999999]],
        ];
    }

    public function test_ambiguous_candidate_is_private_until_verified_and_never_resent(): void
    {
        $this->publishStatus = 207;
        $operation = $this->publish();
        $this->assertSame('unknown', $operation->state);
        $this->assertNull($operation->provider_post_id);
        $this->assertStringNotContainsString(self::POST, json_encode((new AgentOperationResource($operation))->resolve()));
        $this->assertSame($operation->id, $this->publish()->id);
        $this->assertSame(1, $this->writes);
        $this->token->delete();
        $this->assertSame('succeeded', app(AgentOperationService::class)->reconcile($operation)->state);
        $this->assertSame(self::POST, $operation->fresh()->provider_post_id);
        $this->assertNotNull($operation->fresh()->last_reconciled_at);
        $this->assertSame(1, $this->writes);
    }

    public function test_malformed_target_is_not_exposed_or_marked_successful(): void
    {
        $this->publishResponse['post']['platforms'][] = ['accountId' => 'dddddddddddddddddddddddd', 'platform' => 'linkedin', 'status' => 'published'];
        $operation = $this->publish();
        $this->assertSame('unknown', $operation->state);
        $this->assertNull($operation->provider_post_id);
    }

    public function test_mapping_change_blocks_reconciliation_and_replay(): void
    {
        $this->publishStatus = 207;
        $operation = $this->publish();
        $this->account->forceFill(['profile_id' => 'dddddddddddddddddddddddd'])->save();
        $this->assertSame('unknown', app(AgentOperationService::class)->reconcile($operation)->state);
        $this->assertSame(0, $this->reads);
        $this->expectException(HttpException::class);
        $this->publish();
    }

    public function test_reconciliation_is_fair_even_when_reads_fail(): void
    {
        $this->publishStatus = 207;
        $this->getStatus = 503;
        $operation = $this->publish();
        $second = $operation->replicate();
        $second->key_hash = hash('sha256', 'second');
        $second->save();
        $this->artisan('agent:reconcile-operations', ['--limit' => 1])->assertSuccessful();
        $this->assertSame(1, AgentOperation::whereNotNull('last_reconciled_at')->count());
        $this->artisan('agent:reconcile-operations', ['--limit' => 1])->assertSuccessful();
        $this->assertSame(2, AgentOperation::whereNotNull('last_reconciled_at')->count());
        $this->assertSame(2, $this->reads);
        $this->assertSame(1, $this->writes);
    }

    public function test_cross_client_account_is_rejected_even_with_unrestricted_account_scope(): void
    {
        $other = Client::factory()->create(['owner_id' => $this->token->tokenable_id]);
        $this->token->forceFill(['client_id' => $other->id, 'allowed_account_ids' => null])->save();
        try {
            $this->publish();
            $this->fail('Cross-client account was accepted.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        Http::assertNothingSent();
    }

    #[DataProvider('persistedStates')]
    public function test_no_persisted_state_can_be_dispatched_again(string $state): void
    {
        $operation = $this->publish();
        AgentOperation::whereKey($operation->id)->update(['state' => $state]);
        $this->assertSame($state, $this->publish()->state);
        $this->assertSame(1, $this->writes);
    }

    public static function persistedStates(): array
    {
        return array_map(static fn (string $state): array => [$state], ['pending', 'executing', 'provider_pending', 'partial', 'succeeded', 'failed', 'cancelled', 'unknown']);
    }

    public function test_terminal_operation_cannot_regress_during_reconciliation(): void
    {
        $operation = $this->publish();
        $this->getResponse = $this->rawPost('publishing', 'pending');
        $this->assertSame('succeeded', app(AgentOperationService::class)->reconcile($operation)->state);
        $this->assertSame(0, $this->reads);
    }

    public function test_partial_post_can_be_reconciled_without_resending(): void
    {
        $this->publishStatus = 207;
        $operation = $this->publish();
        $this->getResponse = $this->rawPost('partial', 'published');
        $this->assertSame('partial', app(AgentOperationService::class)->reconcile($operation)->state);
        $this->getResponse = $this->rawPost();
        $this->assertSame('succeeded', app(AgentOperationService::class)->reconcile($operation)->state);
        $this->assertSame(1, $this->writes);
    }

    public function test_candidate_with_wrong_profile_is_not_exposed(): void
    {
        $this->publishStatus = 207;
        $operation = $this->publish();
        $this->getResponse['post']['profileId'] = 'dddddddddddddddddddddddd';
        $this->getResponse['post']['platforms'][0]['accountId']['profileId'] = 'dddddddddddddddddddddddd';
        $this->assertSame('unknown', app(AgentOperationService::class)->reconcile($operation)->state);
        $this->assertNull($operation->fresh()->provider_post_id);
        $this->assertSame(1, $this->writes);
    }

    public function test_persistence_failure_before_dispatch_fails_closed(): void
    {
        DB::listen(function (QueryExecuted $query): void {
            if (str_starts_with($query->sql, 'insert into "agent_operations"')) {
                throw new \RuntimeException('Simulated persistence failure');
            }
        });
        $this->expectException(\RuntimeException::class);
        try {
            $this->publish();
        } finally {
            $this->assertSame(0, $this->writes);
        }
    }

    public function test_persistence_failure_after_dispatch_does_not_allow_resending(): void
    {
        $failed = false;
        DB::listen(function (QueryExecuted $query) use (&$failed): void {
            if (! $failed && $this->writes === 1 && str_starts_with($query->sql, 'update "agent_operations"')) {
                $failed = true;
                throw new \RuntimeException('Simulated post-dispatch persistence failure');
            }
        });
        try {
            $this->publish();
            $this->fail('Persistence failure was ignored.');
        } catch (\RuntimeException) {
            $this->assertTrue($failed);
        }
        $this->publish();
        $this->assertSame(1, $this->writes);
    }

    public function test_command_rejects_unbounded_limits(): void
    {
        foreach (['0', '101', 'no', '-1'] as $limit) {
            $this->artisan('agent:reconcile-operations', ['--limit' => $limit])->assertExitCode(2);
        }
        Http::assertNothingSent();
    }

    public function test_exact_account_preflight_is_bounded_to_three_pages(): void
    {
        $this->exactAccount = false;
        $this->accountPages = 100;
        $this->assertSame('failed', $this->publish()->state);
        $this->assertSame(3, $this->accountReads);
        $this->assertSame(0, $this->writes);
    }

    public function test_token_is_refreshed_again_after_preflight_before_dispatch(): void
    {
        $this->revokeDuringPreflight = true;
        try {
            $this->publish();
            $this->fail('Revoked token was dispatched.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertSame(0, $this->writes);
        $this->assertSame('pending', AgentOperation::sole()->state);
    }

    public function test_compare_and_swap_loser_does_not_dispatch(): void
    {
        $this->competingClaim = true;
        $this->assertSame('executing', $this->publish()->state);
        $this->assertSame(0, $this->writes);
    }

    public function test_model_rejects_mutating_immutable_intent(): void
    {
        $operation = $this->publish();
        $this->expectException(\LogicException::class);
        $operation->forceFill(['intent' => ['content' => 'changed']])->save();
    }

    public function test_token_expiry_beyond_thirty_days_is_rejected_before_replay(): void
    {
        $this->publish();
        $this->token->forceFill(['expires_at' => now()->addDays(31)])->save();
        try {
            $this->publish();
            $this->fail('Overlong expiry was accepted.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertSame(1, $this->writes);
    }

    public function test_token_with_original_lifetime_over_thirty_days_is_rejected(): void
    {
        $this->token->forceFill(['created_at' => now()->subDays(29), 'expires_at' => now()->addDays(2)])->save();
        try {
            $this->publish();
            $this->fail('Overlong token lifetime was accepted.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        Http::assertNothingSent();
    }

    public function test_publish_has_no_provider_preflight_after_final_authorization(): void
    {
        $this->publish();
        $this->assertSame(1, $this->accountReads, 'The adapter must not perform additional account I/O after the service authorizes dispatch.');
        $this->assertSame(1, $this->writes);
    }

    #[DataProvider('contradictoryStatuses')]
    public function test_contradictory_post_statuses_remain_unknown(string $aggregate, string $target): void
    {
        $this->publishStatus = 207;
        $operation = $this->publish();
        $this->getResponse = $this->rawPost($aggregate, $target);
        $this->assertSame('unknown', app(AgentOperationService::class)->reconcile($operation)->state);
        $this->assertNull($operation->fresh()->provider_post_id);
        $this->assertStringNotContainsString(self::POST, json_encode((new AgentOperationResource($operation->fresh()))->resolve()));
        $this->assertSame(1, $this->writes);
    }

    public static function contradictoryStatuses(): array
    {
        return [
            ['failed', 'published'], ['cancelled', 'published'], ['publishing', 'published'],
            ['published', 'failed'], ['published', 'pending'], ['published', 'cancelled'],
            ['failed', 'pending'], ['cancelled', 'failed'], ['scheduled', 'failed'],
        ];
    }

    #[DataProvider('consistentStatuses')]
    public function test_consistent_reconciliation_statuses(string $aggregate, string $target, string $expected): void
    {
        $this->publishStatus = 207;
        $operation = $this->publish();
        $this->getResponse = $this->rawPost($aggregate, $target);
        $this->assertSame($expected, app(AgentOperationService::class)->reconcile($operation)->state);
        $this->assertSame(self::POST, $operation->fresh()->provider_post_id);
        $this->assertSame(1, $this->writes);
    }

    public static function consistentStatuses(): array
    {
        return [
            ['published', 'published', 'succeeded'], ['failed', 'failed', 'failed'],
            ['cancelled', 'cancelled', 'cancelled'], ['publishing', 'processing', 'provider_pending'],
            ['scheduled', 'pending', 'provider_pending'], ['publishing', 'uploading', 'provider_pending'],
            ['partial', 'published', 'partial'],
        ];
    }

    public function test_missing_post_detail_profile_requires_scoped_listing_evidence(): void
    {
        $this->publishStatus = 207;
        $operation = $this->publish();
        unset($this->getResponse['post']['profileId']);
        $this->getResponse['post']['platforms'][0]['accountId'] = self::ACCOUNT;
        $this->assertSame('succeeded', app(AgentOperationService::class)->reconcile($operation)->state);
        $this->assertSame(2, $this->reads);
        $this->assertSame(1, $this->writes);
    }

    public function test_candidate_missing_from_bounded_scoped_listing_stays_private(): void
    {
        $this->publishStatus = 207;
        $operation = $this->publish();
        $this->candidateInListing = false;
        $this->listingPages = 100;
        unset($this->getResponse['post']['profileId']);
        $this->getResponse['post']['platforms'][0]['accountId'] = self::ACCOUNT;
        $this->assertSame('unknown', app(AgentOperationService::class)->reconcile($operation)->state);
        $this->assertNull($operation->fresh()->provider_post_id);
        $this->assertSame(4, $this->reads);
        $this->assertSame(1, $this->writes);
    }

    public function test_operation_mapping_and_private_recovery_hashes_support_direct_lookup(): void
    {
        $this->publishStatus = 207;
        $operation = $this->publish();
        $expectedMapping = hash('sha256', json_encode($operation->intent['mapping'], JSON_THROW_ON_ERROR));
        $this->assertSame($expectedMapping, $operation->mapping_hash);
        $this->assertSame($this->account->mappingHash(), $operation->mapping_hash);
        $this->assertSame($operation->id, AgentOperation::where('recovery_post_hash', hash('sha256', 'zernio-post:'.self::POST))->sole()->id);
        $this->assertStringNotContainsString(self::POST, $operation->getRawOriginal('recovery_post_id'));
        foreach (['mapping_hash', 'recovery_post_hash', 'reconcile_lease_token', 'reconcile_lease_until'] as $hidden) {
            $this->assertArrayNotHasKey($hidden, $operation->toArray());
        }
        app(AgentOperationService::class)->reconcile($operation);
        $this->assertNull($operation->fresh()->recovery_post_hash);
    }

    public function test_model_saving_derives_recovery_hash_on_create_replace_and_clear(): void
    {
        $operation = AgentOperation::factory()->create(['recovery_post_id' => self::POST, 'recovery_post_hash' => 'untrusted']);
        $this->assertSame(hash('sha256', 'zernio-post:'.self::POST), $operation->fresh()->recovery_post_hash);
        $operation->forceFill(['recovery_post_id' => 'dddddddddddddddddddddddd'])->save();
        $this->assertSame(hash('sha256', 'zernio-post:dddddddddddddddddddddddd'), $operation->fresh()->recovery_post_hash);
        $operation->forceFill(['recovery_post_id' => null])->save();
        $this->assertNull($operation->fresh()->recovery_post_hash);
    }

    public function test_maximum_multibyte_facebook_intent_and_result_are_encrypted_without_truncation(): void
    {
        $content = str_repeat("\u{1F600}", 63206);
        $operation = AgentOperation::factory()->create(['intent' => ['content' => $content], 'result' => ['content' => $content]]);
        $this->assertSame($content, $operation->fresh()->intent['content']);
        $this->assertSame($content, $operation->fresh()->result['content']);
        $this->assertGreaterThan(65535, strlen($operation->getRawOriginal('intent')));
        $this->assertGreaterThan(65535, strlen($operation->getRawOriginal('result')));
    }

    public function test_reconciliation_cursor_reaches_older_scoped_posts_across_attempts(): void
    {
        $this->publishStatus = 207;
        $operation = $this->publish();
        unset($this->getResponse['post']['profileId']);
        $this->getResponse['post']['platforms'][0]['accountId'] = self::ACCOUNT;
        $this->listingPages = 10;
        $this->candidatePage = 5;
        $service = app(AgentOperationService::class);
        $this->assertSame('unknown', $service->reconcile($operation)->state);
        $this->assertSame([1, 2, 3], $this->listingReads);
        $this->assertSame(4, $operation->fresh()->reconcile_page);
        $this->assertSame('succeeded', $service->reconcile($operation)->state);
        $this->assertSame([1, 2, 3, 4, 5], $this->listingReads);
    }

    public function test_reconciliation_cursor_resets_at_end_and_never_exceeds_page_one_thousand(): void
    {
        $this->publishStatus = 207;
        $operation = $this->publish();
        $operation->forceFill(['reconcile_page' => 999])->save();
        unset($this->getResponse['post']['profileId']);
        $this->getResponse['post']['platforms'][0]['accountId'] = self::ACCOUNT;
        $this->listingPages = 1000;
        $this->candidateInListing = false;
        app(AgentOperationService::class)->reconcile($operation);
        $this->assertSame([999, 1000], $this->listingReads);
        $this->assertSame(1, $operation->fresh()->reconcile_page);
    }

    public function test_reconciliation_lease_blocks_another_worker_and_is_released_on_read_failure(): void
    {
        $this->publishStatus = 207;
        $operation = $this->publish();
        $this->getStatus = 503;
        $claimedToken = null;
        $claimedUntil = null;
        $attemptedAt = null;
        $nestedReadCount = null;
        $this->duringPostRead = function () use ($operation, &$claimedToken, &$claimedUntil, &$attemptedAt, &$nestedReadCount): void {
            $this->duringPostRead = null;
            $claimed = $operation->fresh();
            $claimedToken = $claimed->reconcile_lease_token;
            $claimedUntil = $claimed->reconcile_lease_until;
            $attemptedAt = $claimed->last_reconciled_at;
            app(AgentOperationService::class)->reconcile($claimed);
            $nestedReadCount = $this->reads;
        };
        app(AgentOperationService::class)->reconcile($operation);
        $this->assertNotNull($claimedToken);
        $this->assertTrue($claimedUntil?->isFuture());
        $this->assertNotNull($attemptedAt);
        $this->assertSame(1, $nestedReadCount);
        $this->assertSame(1, $this->reads);
        $this->assertNull($operation->fresh()->reconcile_lease_token);
        $this->assertNull($operation->fresh()->reconcile_lease_until);
    }

    public function test_stale_lease_holder_cannot_record_results_or_release_a_new_claim(): void
    {
        $this->publishStatus = 207;
        $operation = $this->publish();
        $replacement = (string) Str::uuid();
        $this->duringPostRead = function () use ($operation, $replacement): void {
            AgentOperation::whereKey($operation->id)->update([
                'reconcile_lease_token' => $replacement,
                'reconcile_lease_until' => now()->addSeconds(90)->format($operation->getDateFormat()),
            ]);
        };
        $this->assertSame('unknown', app(AgentOperationService::class)->reconcile($operation)->state);
        $this->assertNull($operation->fresh()->provider_post_id);
        $this->assertSame($replacement, $operation->fresh()->reconcile_lease_token);
    }

    public function test_expired_lease_can_be_reclaimed(): void
    {
        $this->publishStatus = 207;
        $operation = $this->publish();
        $operation->forceFill(['reconcile_lease_token' => (string) Str::uuid(), 'reconcile_lease_until' => now()->subSecond()])->save();
        $this->assertSame('succeeded', app(AgentOperationService::class)->reconcile($operation)->state);
        $this->assertNull($operation->fresh()->reconcile_lease_token);
    }

    public function test_new_operations_do_not_starve_older_attempted_operations(): void
    {
        $this->publishStatus = 207;
        $this->getStatus = 503;
        $older = $this->publish();
        $older->forceFill(['last_reconciled_at' => now()->subHour(), 'created_at' => now()->subDay()])->save();
        $newer = $older->replicate();
        $newer->forceFill(['key_hash' => hash('sha256', 'newer'), 'last_reconciled_at' => null, 'created_at' => now()])->save();
        $this->artisan('agent:reconcile-operations', ['--limit' => 1])->assertSuccessful();
        $this->assertTrue($older->fresh()->last_reconciled_at->gt(now()->subMinute()));
        $this->assertNull($newer->fresh()->last_reconciled_at);
    }

    public function test_command_skips_active_leases_without_consuming_its_limit(): void
    {
        $this->publishStatus = 207;
        $this->getStatus = 503;
        $leased = $this->publish();
        $leased->forceFill([
            'reconcile_lease_token' => (string) Str::uuid(),
            'reconcile_lease_until' => now()->addSeconds(90),
            'created_at' => now()->subDay(),
        ])->save();
        $eligible = $leased->replicate();
        $eligible->forceFill(['key_hash' => hash('sha256', 'eligible'), 'reconcile_lease_token' => null, 'reconcile_lease_until' => null])->save();
        $this->artisan('agent:reconcile-operations', ['--limit' => 1])->assertSuccessful();
        $this->assertNull($leased->fresh()->last_reconciled_at);
        $this->assertNotNull($eligible->fresh()->last_reconciled_at);
        $this->assertSame(1, $this->reads);
    }

    public function test_lease_expiring_during_read_prevents_result_persistence(): void
    {
        $this->publishStatus = 207;
        $operation = $this->publish();
        $this->duringPostRead = function (): void {
            $this->travel(91)->seconds();
        };
        try {
            $this->assertSame('unknown', app(AgentOperationService::class)->reconcile($operation)->state);
            $this->assertNull($operation->fresh()->provider_post_id);
            $this->assertNull($operation->fresh()->result);
            $this->assertNull($operation->fresh()->reconcile_lease_token);
        } finally {
            $this->travelBack();
        }
    }

    public function test_command_rejects_invalid_operation_ids_without_provider_calls(): void
    {
        foreach ([null, '', 'not-a-uuid', '1', str_repeat('c', 24), '*'] as $id) {
            $this->artisan('agent:reconcile-operations', ['--operation' => $id])->assertExitCode(2);
        }
        Http::assertNothingSent();
    }

    public function test_command_targets_only_the_exact_operation_instead_of_older_pending_work(): void
    {
        $this->publishStatus = 207;
        $older = $this->publish();
        $older->forceFill(['created_at' => now()->subDay()])->save();
        $approved = $older->replicate();
        $approved->forceFill(['key_hash' => hash('sha256', 'approved'), 'created_at' => now()])->save();
        $this->artisan('agent:reconcile-operations', ['--operation' => $approved->id, '--limit' => 1])->assertSuccessful();
        $this->assertSame('succeeded', $approved->fresh()->state);
        $this->assertNotNull($approved->fresh()->last_reconciled_at);
        $this->assertSame('unknown', $older->fresh()->state);
        $this->assertNull($older->fresh()->last_reconciled_at);
        $this->assertSame(1, $this->reads);
        $this->assertSame(1, $this->writes);
    }

    public function test_targeted_command_still_validates_limit_and_does_not_fall_back_to_other_operations(): void
    {
        $this->publishStatus = 207;
        $operation = $this->publish();
        foreach (['0', '101', 'no', '-1'] as $limit) {
            $this->artisan('agent:reconcile-operations', ['--operation' => $operation->id, '--limit' => $limit])->assertExitCode(2);
        }
        $this->artisan('agent:reconcile-operations', ['--operation' => (string) Str::uuid()])->assertSuccessful();
        $this->assertNull($operation->fresh()->last_reconciled_at);
        $this->assertSame(0, $this->reads);
        $this->assertSame(1, $this->writes);
    }

    public function test_targeted_command_does_not_override_an_active_reconciliation_lease(): void
    {
        $this->publishStatus = 207;
        $operation = $this->publish();
        $lease = (string) Str::uuid();
        $operation->forceFill(['reconcile_lease_token' => $lease, 'reconcile_lease_until' => now()->addSeconds(90)])->save();
        $this->artisan('agent:reconcile-operations', ['--operation' => $operation->id])->assertSuccessful();
        $this->assertNull($operation->fresh()->last_reconciled_at);
        $this->assertSame($lease, $operation->fresh()->reconcile_lease_token);
        $this->assertSame(0, $this->reads);
        $this->assertSame(1, $this->writes);
    }

    public function test_targeted_command_still_requires_current_owned_profile_before_provider_reads(): void
    {
        $this->publishStatus = 207;
        $operation = $this->publish();
        $this->account->forceFill(['profile_id' => str_repeat('d', 24)])->save();
        $this->artisan('agent:reconcile-operations', ['--operation' => $operation->id])->assertSuccessful();
        $this->assertSame('unknown', $operation->fresh()->state);
        $this->assertNull($operation->fresh()->provider_post_id);
        $this->assertSame(0, $this->reads);
        $this->assertSame(1, $this->writes);
    }
}
