<?php

namespace Tests\Feature;

use App\Jobs\ReconcileAgentWebhook;
use App\Models\AgentApiAudit;
use App\Models\AgentOperation;
use App\Models\AgentWebhookReceipt;
use App\Models\ClientAccount;
use App\Services\AgentOperationService;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class AgentWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const POST = 'cccccccccccccccccccccccc';

    protected function beforeRefreshingDatabase(): void
    {
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->assertEmpty(config('database.connections.sqlite.url'));
    }

    protected function setUp(): void
    {
        parent::setUp();
        config(['agent-api.zernio.webhook_secret' => 'test-webhook-secret', 'agent-api.zernio.enabled' => true,
            'agent-api.zernio.api_key' => 'test-api-key', 'queue.default' => 'sync']);
        Queue::fake();
        Http::preventStrayRequests();
        Http::fake([]);
        if (! Route::has('agent-webhooks.zernio')) {
            require base_path('routes/agent-webhooks.php');
        }
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return ['id' => (string) Str::uuid(), 'event' => 'post.published', 'timestamp' => now()->toIso8601String(),
            'post' => ['id' => self::POST, 'content' => 'private text', 'status' => 'published',
                'scheduledFor' => now()->toIso8601String(), 'platforms' => [['platform' => 'linkedin', 'status' => 'published']]]];
    }

    /** @param array<string, mixed> $payload */
    private function deliver(array $payload, ?string $signature = null): TestResponse
    {
        $raw = json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);

        return $this->call('POST', '/webhooks/zernio', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_ZERNIO_SIGNATURE' => $signature ?? hash_hmac('sha256', $raw, 'test-webhook-secret'),
        ], $raw);
    }

    private function process(AgentWebhookReceipt $receipt): void
    {
        (new ReconcileAgentWebhook($receipt->id))->handle(app(AgentOperationService::class));
    }

    private function operation(string $state = 'unknown', bool $verified = false): AgentOperation
    {
        $account = ClientAccount::factory()->create(['provider' => 'zernio', 'environment' => 'production',
            'platform' => 'linkedin', 'active' => true, 'publishing_enabled' => true]);

        return AgentOperation::factory()->create(['client_id' => $account->client_id, 'client_account_id' => $account->id,
            'state' => $state, 'provider_post_id' => $verified ? self::POST : null,
            'recovery_post_id' => $verified ? null : self::POST,
            'intent' => ['content' => 'private text', 'mapping' => ['local_id' => $account->id, 'client_id' => $account->client_id,
                'provider' => $account->provider, 'environment' => $account->environment, 'profile_id' => $account->profile_id,
                'account_id' => $account->provider_account_id, 'platform' => $account->platform]]]);
    }

    private function fakePost(AgentOperation $operation, ?string $profile = null): void
    {
        $mapping = $operation->intent['mapping'];
        $post = [
            '_id' => self::POST, 'profileId' => $profile ?? $mapping['profile_id'], 'content' => 'private text', 'status' => 'published',
            'platforms' => [['accountId' => ['_id' => $mapping['account_id'], 'profileId' => $profile ?? $mapping['profile_id']],
                'platform' => 'linkedin', 'status' => 'published']],
        ];
        Http::fake([
            'https://zernio.com/api/v1/posts/'.self::POST => Http::response(['post' => $post]),
        ]);
    }

    public function test_raw_signature_receipt_encryption_replay_and_sync_are_safe(): void
    {
        $payload = $this->payload();
        $this->deliver($payload)->assertNoContent();
        $this->deliver($payload)->assertNoContent();
        $receipt = AgentWebhookReceipt::sole();
        $this->assertSame($payload['id'], $receipt->event_id);
        $this->assertSame('pending', $receipt->status);
        $stored = DB::table('agent_webhook_receipts')->value('raw_body');
        $this->assertStringNotContainsString('private text', $stored);
        $this->assertStringNotContainsString(self::POST, $stored);
        $this->assertArrayNotHasKey('raw_body', $receipt->toArray());
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_missing_secret_missing_signature_tampering_and_noncanonical_signatures_fail_closed(): void
    {
        $payload = $this->payload();
        $this->call('POST', '/webhooks/zernio', [], [], [], ['HTTP_ACCEPT' => 'application/json'], '{}')->assertUnauthorized();
        foreach (['bad', str_repeat('a', 64), str_repeat('A', 64), 'sha256='.str_repeat('a', 64)] as $signature) {
            $this->deliver($payload, $signature)->assertUnauthorized();
        }
        $signature = hash_hmac('sha256', json_encode($payload, JSON_THROW_ON_ERROR), 'test-webhook-secret');
        $this->deliver($payload, $signature)->assertUnauthorized();
        config(['agent-api.zernio.webhook_secret' => null]);
        $this->deliver($payload)->assertStatus(503);
        $this->assertDatabaseCount('agent_webhook_receipts', 0);
        Http::assertNothingSent();
    }

    public function test_body_limit_and_required_canonical_event_and_post_ids(): void
    {
        $payload = $this->payload();
        $payload['post']['content'] = str_repeat('x', 1048576);
        $this->deliver($payload)->assertStatus(413);
        $payload = $this->payload();
        unset($payload['post']['id']);
        $payload['data']['postId'] = self::POST;
        $this->deliver($payload)->assertUnprocessable();
        $payload = $this->payload();
        $payload['id'] = self::POST;
        $this->deliver($payload)->assertUnprocessable();
        $this->assertDatabaseCount('agent_webhook_receipts', 0);
    }

    public function test_unsupported_and_sample_events_are_durable_ignored(): void
    {
        $payload = $this->payload();
        $payload['event'] = 'account.connected';
        unset($payload['post']);
        $this->deliver($payload)->assertNoContent();
        $payload = $this->payload();
        $payload['test'] = true;
        $this->deliver($payload)->assertNoContent();
        $this->assertSame(2, AgentWebhookReceipt::where('status', 'ignored')->count());
        Queue::assertNothingPushed();
    }

    public function test_persistent_queue_dispatches_only_receipt_id_once(): void
    {
        config(['queue.default' => 'database']);
        $payload = $this->payload();
        $this->deliver($payload)->assertNoContent();
        $this->deliver($payload)->assertNoContent();
        Queue::assertPushed(ReconcileAgentWebhook::class, 1);
        Queue::assertPushed(ReconcileAgentWebhook::class, fn ($job): bool => $job->receiptId === AgentWebhookReceipt::sole()->id);
        Http::assertNothingSent();
    }

    public function test_early_delivery_remains_pending_and_later_matches_encrypted_candidate(): void
    {
        $this->deliver($this->payload())->assertNoContent();
        $receipt = AgentWebhookReceipt::sole();
        $this->process($receipt);
        $this->assertSame('pending', $receipt->fresh()->status);
        $this->assertNotNull($receipt->fresh()->last_attempted_at);
        $this->assertNull($receipt->fresh()->processing_until);
        Http::assertNothingSent();
        $operation = $this->operation();
        $this->fakePost($operation);
        $this->process($receipt);
        $this->assertNull($receipt->fresh()->last_error);
        Http::assertSentCount(1);
        $this->assertSame('succeeded', $operation->fresh()->state);
        $this->assertSame('processed', $receipt->fresh()->status);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => $request->method() === 'GET' && str_ends_with($request->url(), '/'.self::POST));
        $this->process($receipt);
        Http::assertSentCount(1);
    }

    public function test_event_claims_never_select_other_operations_or_supply_status(): void
    {
        $operation = $this->operation();
        $operation->forceFill(['recovery_post_id' => 'dddddddddddddddddddddddd'])->save();
        $payload = $this->payload();
        $payload['post']['metadata']['operation_id'] = $operation->id;
        $payload['profiles'] = [['id' => $operation->intent['mapping']['profile_id'], 'externalId' => (string) $operation->client_id]];
        $this->deliver($payload)->assertNoContent();
        $this->process(AgentWebhookReceipt::sole());
        $this->assertSame('unknown', $operation->fresh()->state);
        Http::assertNothingSent();
    }

    public function test_wrong_provider_profile_and_changed_local_mapping_cannot_succeed(): void
    {
        $operation = $this->operation();
        $this->fakePost($operation, 'eeeeeeeeeeeeeeeeeeeeeeee');
        $this->deliver($this->payload())->assertNoContent();
        $this->process(AgentWebhookReceipt::sole());
        $this->assertSame('unknown', $operation->fresh()->state);
        $this->assertNull($operation->fresh()->provider_post_id);
        $this->assertSame('pending', AgentWebhookReceipt::sole()->status);
        $operation->account->forceFill(['profile_id' => 'ffffffffffffffffffffffff'])->save();
        $this->process(AgentWebhookReceipt::sole());
        Http::assertSentCount(1);
    }

    public function test_terminal_operations_never_regress(): void
    {
        $operation = $this->operation('succeeded', true);
        $payload = $this->payload();
        $payload['event'] = 'post.failed';
        $payload['post']['status'] = 'failed';
        $this->deliver($payload)->assertNoContent();
        $this->process(AgentWebhookReceipt::sole());
        $this->assertSame('succeeded', $operation->fresh()->state);
        $this->assertSame('processed', AgentWebhookReceipt::sole()->status);
        Http::assertNothingSent();
    }

    public function test_cli_fairness_expiry_and_limit_validation(): void
    {
        $this->deliver($this->payload())->assertNoContent();
        $first = AgentWebhookReceipt::sole();
        $this->deliver($this->payload())->assertNoContent();
        $this->artisan('agent:process-webhooks', ['--limit' => 1])->assertSuccessful();
        $this->assertSame(1, AgentWebhookReceipt::whereNotNull('last_attempted_at')->count());
        $this->artisan('agent:process-webhooks', ['--limit' => 1])->assertSuccessful();
        $this->assertSame(2, AgentWebhookReceipt::whereNotNull('last_attempted_at')->count());
        $this->travel(24)->hours();
        $this->artisan('agent:process-webhooks')->assertSuccessful();
        $this->assertSame('expired', $first->fresh()->status);
        $this->assertSame(2, AgentWebhookReceipt::where('status', 'expired')->count());
        foreach (['0', '101', '-1', 'no'] as $limit) {
            $this->artisan('agent:process-webhooks', ['--limit' => $limit])->assertExitCode(2);
        }
        Http::assertNothingSent();
    }

    public function test_only_persistent_drivers_are_allowed_even_with_custom_connection_names(): void
    {
        foreach (['sync', 'null', 'deferred', 'background', 'failover', 'database', 'redis', 'sqs', 'beanstalkd'] as $driver) {
            Queue::fake();
            config(['queue.default' => 'webhook-test', 'queue.connections.webhook-test.driver' => $driver]);
            $this->deliver($this->payload())->assertNoContent();
            if (in_array($driver, ['database', 'redis', 'sqs', 'beanstalkd'], true)) {
                Queue::assertPushed(ReconcileAgentWebhook::class, 1);
            } else {
                Queue::assertNothingPushed();
            }
        }
        Http::assertNothingSent();
    }

    public function test_enqueue_failure_still_acknowledges_a_durable_pending_receipt(): void
    {
        config(['queue.default' => 'database']);
        Queue::shouldReceive('connection')->once()->with('database')->andThrow(new \RuntimeException('private queue failure'));
        $this->deliver($this->payload())->assertNoContent();
        $this->assertSame('pending', AgentWebhookReceipt::sole()->status);
        Http::assertNothingSent();
    }

    public function test_receipt_persistence_failure_never_returns_success_or_enqueues(): void
    {
        DB::listen(function (QueryExecuted $query): void {
            if (str_starts_with($query->sql, 'insert into "agent_webhook_receipts"')) {
                throw new \RuntimeException('private persistence failure');
            }
        });
        $this->deliver($this->payload())->assertStatus(503)->assertJson(['message' => 'Webhook persistence is unavailable.']);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_an_active_receipt_claim_prevents_duplicate_processing_and_expired_claim_recovers(): void
    {
        $operation = $this->operation();
        $this->fakePost($operation);
        $this->deliver($this->payload())->assertNoContent();
        $receipt = AgentWebhookReceipt::sole();
        $receipt->forceFill(['processing_until' => now()->addMinute()])->save();
        $this->process($receipt);
        $this->assertNull($receipt->fresh()->last_attempted_at);
        Http::assertNothingSent();
        $this->travel(2)->minutes();
        $this->process($receipt);
        $this->assertSame('succeeded', $operation->fresh()->state);
        $this->assertSame('processed', $receipt->fresh()->status);
    }

    public function test_indexed_candidate_lookup_never_hydrates_unrelated_client_history(): void
    {
        $operation = $this->operation();
        $operation->forceFill(['recovery_post_id' => 'dddddddddddddddddddddddd'])->save();
        for ($index = 0; $index < 99; $index++) {
            $copy = $operation->replicate();
            $copy->key_hash = hash('sha256', (string) $index);
            $copy->save();
        }
        $target = $this->operation();
        $this->assertNotSame($operation->client_id, $target->client_id);
        $this->fakePost($target);
        $this->deliver($this->payload())->assertNoContent();
        $receipt = AgentWebhookReceipt::sole();
        $hydrated = [];
        AgentOperation::retrieved(function (AgentOperation $found) use (&$hydrated): void {
            $hydrated[] = $found->id;
        });
        $this->process($receipt);
        $this->assertSame('processed', $receipt->fresh()->status);
        $this->assertNotEmpty($hydrated);
        $this->assertSame([$target->id], array_values(array_unique($hydrated)));
        $this->assertSame('succeeded', $target->fresh()->state);
        $this->assertSame(100, AgentOperation::where('state', 'unknown')->count());
        Http::assertSentCount(1);
    }

    public function test_candidate_lookup_limits_each_page_to_five_and_does_not_lose_unresolved_prior_pages(): void
    {
        $operation = $this->operation();
        for ($index = 0; $index < 5; $index++) {
            $copy = $operation->replicate();
            $copy->key_hash = hash('sha256', (string) $index);
            $copy->save();
        }
        $this->deliver($this->payload())->assertNoContent();
        $receipt = AgentWebhookReceipt::sole();
        $ids = AgentOperation::orderBy('id')->pluck('id');
        $service = $this->mock(AgentOperationService::class);
        $calls = [];
        $service->shouldReceive('reconcile')->andReturnUsing(function (AgentOperation $candidate) use (&$calls, $ids): AgentOperation {
            $calls[] = $candidate->id;
            if ($candidate->id === $ids->first()) {
                throw new \RuntimeException('private candidate failure');
            }
            $candidate->state = 'succeeded';

            return $candidate;
        });
        $this->process($receipt);
        $this->assertCount(5, $calls);
        $this->assertSame($ids[4], $receipt->fresh()->operation_cursor);
        $this->assertSame('reconciliation_failed', $receipt->fresh()->last_error);
        $this->assertSame('pending', $receipt->fresh()->status);
        $this->process($receipt);
        $this->assertCount(6, $calls);
        $this->assertSame($ids->all(), $calls);
        $this->assertNull($receipt->fresh()->operation_cursor);
        $this->assertSame('pending', $receipt->fresh()->status);
        $this->process($receipt);
        $this->assertCount(11, $calls);
        $this->assertSame($ids->first(), $calls[6]);
        Http::assertNothingSent();
    }

    public function test_recovery_hash_is_only_a_lookup_hint_and_exact_decrypted_candidate_is_required(): void
    {
        $operation = $this->operation();
        $operation->forceFill(['recovery_post_id' => 'dddddddddddddddddddddddd'])->save();
        DB::table('agent_operations')->where('id', $operation->id)
            ->update(['recovery_post_hash' => hash('sha256', 'zernio-post:'.self::POST)]);
        $this->deliver($this->payload())->assertNoContent();
        $service = $this->mock(AgentOperationService::class);
        $service->shouldNotReceive('reconcile');
        $this->process(AgentWebhookReceipt::sole());
        $this->assertSame('pending', AgentWebhookReceipt::sole()->status);
        Http::assertNothingSent();
    }

    public function test_unmatched_event_does_not_hydrate_or_decrypt_unrelated_history(): void
    {
        $operation = $this->operation();
        $operation->forceFill(['recovery_post_id' => 'dddddddddddddddddddddddd'])->save();
        DB::table('agent_operations')->where('id', $operation->id)->update(['recovery_post_id' => 'invalid ciphertext']);
        $this->deliver($this->payload())->assertNoContent();
        $hydrated = [];
        AgentOperation::retrieved(function (AgentOperation $found) use (&$hydrated): void {
            $hydrated[] = $found->id;
        });
        $this->process(AgentWebhookReceipt::sole());
        $this->assertSame([], $hydrated);
        $this->assertSame('pending', AgentWebhookReceipt::sole()->status);
        $this->assertNull(AgentWebhookReceipt::sole()->last_error);
        Http::assertNothingSent();
    }

    public function test_all_terminal_candidate_pages_finish_without_an_extra_empty_pass(): void
    {
        $operation = $this->operation('succeeded', true);
        for ($index = 0; $index < 5; $index++) {
            $copy = $operation->replicate();
            $copy->key_hash = hash('sha256', (string) $index);
            $copy->save();
        }
        $this->deliver($this->payload())->assertNoContent();
        $receipt = AgentWebhookReceipt::sole();
        $this->process($receipt);
        $this->assertSame('pending', $receipt->fresh()->status);
        $this->assertNotNull($receipt->fresh()->operation_cursor);
        $this->process($receipt);
        $this->assertSame('processed', $receipt->fresh()->status);
        $this->assertNull($receipt->fresh()->operation_cursor);
        Http::assertNothingSent();
    }

    public function test_failure_summary_is_safe_and_failed_receipts_do_not_starve_new_receipts(): void
    {
        $operation = $this->operation();
        $this->deliver($this->payload())->assertNoContent();
        $receipt = AgentWebhookReceipt::sole();
        $payload = $this->payload();
        $payload['post']['id'] = 'dddddddddddddddddddddddd';
        $this->deliver($payload)->assertNoContent();
        $service = $this->mock(AgentOperationService::class);
        $service->shouldReceive('reconcile')->once()->andThrow(new \RuntimeException('private text secret'));
        $this->artisan('agent:process-webhooks', ['--limit' => 1])
            ->expectsOutput('Inspected 1 receipt(s); 1 failure(s). Pending receipts remain retryable.')->assertSuccessful();
        $this->assertSame('reconciliation_failed', $receipt->fresh()->last_error);
        $this->assertNotNull($receipt->fresh()->last_attempted_at);
        $this->artisan('agent:process-webhooks', ['--limit' => 1])->assertSuccessful();
        $this->assertSame(2, AgentWebhookReceipt::whereNotNull('last_attempted_at')->count());
        $this->assertSame('unknown', $operation->fresh()->state);
        Http::assertNothingSent();
    }

    public function test_direct_post_read_uses_only_the_exact_candidate_and_ignores_claimed_profile(): void
    {
        $operation = $this->operation();
        $other = $this->operation();
        $other->forceFill(['recovery_post_id' => 'dddddddddddddddddddddddd'])->save();
        $this->fakePost($operation);
        $payload = $this->payload();
        $payload['profiles'] = [['id' => $other->intent['mapping']['profile_id'], 'externalId' => (string) $other->client_id]];
        $payload['post']['metadata'] = ['client_id' => $other->client_id, 'operation_id' => $other->id];
        $payload['post']['status'] = 'failed';
        $this->deliver($payload)->assertNoContent();
        $this->process(AgentWebhookReceipt::sole());
        $this->assertSame('succeeded', $operation->fresh()->state);
        $this->assertSame('unknown', $other->fresh()->state);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => $request->method() === 'GET'
            && $request->url() === 'https://zernio.com/api/v1/posts/'.self::POST);
        Http::assertNotSent(fn ($request): bool => $request->method() !== 'GET'
            || ($request->data()['profileId'] ?? null) === $other->intent['mapping']['profile_id']);
    }

    public function test_platform_events_require_the_official_connected_account_id(): void
    {
        $payload = $this->payload();
        $payload['event'] = 'post.platform.published';
        $this->deliver($payload)->assertUnprocessable();
        $payload['account'] = ['accountId' => 'bbbbbbbbbbbbbbbbbbbbbbbb', 'platform' => 'linkedin', 'username' => 'example'];
        $payload['platform'] = ['name' => 'linkedin', 'status' => 'published'];
        $this->deliver($payload)->assertNoContent();
        Http::assertNothingSent();
    }

    public function test_cli_preserves_subsecond_attempt_ordering_for_fair_retries(): void
    {
        $this->travelTo(now()->startOfSecond()->addMilliseconds(100));
        $this->deliver($this->payload())->assertNoContent();
        $first = AgentWebhookReceipt::sole();
        $this->deliver($this->payload())->assertNoContent();
        $second = AgentWebhookReceipt::whereKeyNot($first->id)->sole();
        $this->travel(100)->milliseconds();
        $this->artisan('agent:process-webhooks', ['--limit' => 1])->assertSuccessful();
        $this->travel(100)->milliseconds();
        $this->artisan('agent:process-webhooks', ['--limit' => 1])->assertSuccessful();
        $this->assertTrue($first->fresh()->last_attempted_at->lt($second->fresh()->last_attempted_at));
        $this->travel(100)->milliseconds();
        $this->artisan('agent:process-webhooks', ['--limit' => 1])->assertSuccessful();
        $this->assertTrue($second->fresh()->last_attempted_at->lt($first->fresh()->last_attempted_at));
    }

    public function test_continuous_new_receipts_do_not_starve_an_older_retry(): void
    {
        $this->travelTo(now()->startOfSecond());
        $this->deliver($this->payload())->assertNoContent();
        $older = AgentWebhookReceipt::sole();
        $this->artisan('agent:process-webhooks', ['--limit' => 1])->assertSuccessful();
        $firstAttempt = $older->fresh()->last_attempted_at;
        for ($index = 0; $index < 3; $index++) {
            $this->travel(1)->seconds();
            $this->deliver($this->payload())->assertNoContent();
        }
        $this->artisan('agent:process-webhooks', ['--limit' => 1])->assertSuccessful();
        $this->assertTrue($older->fresh()->last_attempted_at->gt($firstAttempt));
        $this->assertSame(3, AgentWebhookReceipt::whereNull('last_attempted_at')->count());
        Http::assertNothingSent();
    }

    public function test_registered_scheduled_retry_processes_an_early_event_after_operation_creation(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->filter(fn (Event $event): bool => str_ends_with($event->command ?? '', 'agent:process-webhooks --limit=25'))->sole();
        $this->assertSame('* * * * *', $event->expression);
        config(['agent-api.zernio.enabled' => false]);
        $this->assertFalse($event->filtersPass($this->app));
        config(['agent-api.zernio.enabled' => true]);
        $this->assertTrue($event->filtersPass($this->app));
        $payload = $this->payload();
        $this->deliver($payload)->assertNoContent();
        $receipt = AgentWebhookReceipt::sole();
        $this->artisan('agent:process-webhooks', ['--limit' => 25])->assertSuccessful();
        $this->assertSame('pending', $receipt->fresh()->status);
        $this->assertNotNull($receipt->fresh()->last_attempted_at);
        Http::assertNothingSent();
        $operation = $this->operation();
        $this->fakePost($operation);
        $this->travel(1)->minutes();
        $this->assertTrue($event->filtersPass($this->app));
        $this->artisan('agent:process-webhooks', ['--limit' => 25])->assertSuccessful();
        $this->assertSame('processed', $receipt->fresh()->status);
        $this->assertSame($payload['id'], $receipt->fresh()->event_id);
        $this->assertSame('succeeded', $operation->fresh()->state);
        Http::assertSentCount(1);
        Http::assertNotSent(fn ($request): bool => $request->method() !== 'GET');
    }

    public function test_sync_request_never_calls_provider_and_cli_can_drain_a_verified_candidate(): void
    {
        $operation = $this->operation('provider_pending', true);
        $this->fakePost($operation);
        $this->deliver($this->payload())->assertNoContent();
        Http::assertNothingSent();
        Queue::assertNothingPushed();
        $this->artisan('agent:process-webhooks')->assertSuccessful();
        $this->assertSame('succeeded', $operation->fresh()->state);
        $this->assertSame('processed', AgentWebhookReceipt::sole()->status);
        Http::assertSentCount(1);
        Http::assertNotSent(fn ($request): bool => $request->method() !== 'GET');
    }

    public function test_disabled_provider_stays_pending_without_provider_reads(): void
    {
        $operation = $this->operation();
        config(['agent-api.zernio.enabled' => false]);
        $this->deliver($this->payload())->assertNoContent();
        $this->artisan('agent:process-webhooks')->assertSuccessful();
        $this->assertSame('pending', AgentWebhookReceipt::sole()->status);
        $this->assertNotNull(AgentWebhookReceipt::sole()->last_attempted_at);
        $this->assertSame('unknown', $operation->fresh()->state);
        Http::assertNothingSent();
    }

    public function test_prune_redacts_old_payloads_but_preserves_tombstones_and_duplicate_replays(): void
    {
        Log::spy();
        $payload = $this->payload();
        $this->deliver($payload)->assertNoContent();
        $original = AgentWebhookReceipt::sole();
        $original->forceFill(['status' => 'processed', 'created_at' => now()->subDays(8)])->save();
        foreach (['ignored', 'expired', 'pending'] as $status) {
            $this->deliver($this->payload())->assertNoContent();
            AgentWebhookReceipt::latest('id')->firstOrFail()->forceFill([
                'status' => $status, 'created_at' => now()->subDays(8), 'expires_at' => now()->subDays(7),
            ])->save();
        }
        $this->deliver($this->payload())->assertNoContent();
        $recent = AgentWebhookReceipt::latest('id')->firstOrFail();
        $this->artisan('agent:prune-evidence')->assertSuccessful();
        $this->assertDatabaseCount('agent_webhook_receipts', 5);
        $this->assertSame(4, AgentWebhookReceipt::whereNull('raw_body')->count());
        $this->assertSame(2, AgentWebhookReceipt::where('status', 'ignored')->count());
        $this->assertNotNull($recent->fresh()->raw_body);
        $this->assertSame('pending', $recent->fresh()->status);
        $this->assertSame($payload['id'], $original->fresh()->event_id);
        $this->assertNull($original->fresh()->raw_body);
        $this->assertNotNull($original->fresh()->payload_redacted_at);
        config(['queue.default' => 'database']);
        $this->deliver($payload)->assertNoContent();
        $this->assertDatabaseCount('agent_webhook_receipts', 5);
        $this->assertNull($original->fresh()->raw_body);
        $this->assertSame('processed', $original->fresh()->status);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
        foreach (['log', 'emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug'] as $method) {
            Log::shouldNotHaveReceived($method);
        }
    }

    public function test_prune_respects_retention_leases_and_pending_expiration(): void
    {
        config(['agent-api.webhook_payload_retention_days' => 2]);
        $this->deliver($this->payload())->assertNoContent();
        $old = AgentWebhookReceipt::sole();
        $old->forceFill(['status' => 'ignored', 'created_at' => now()->subDays(3)])->save();
        $this->deliver($this->payload())->assertNoContent();
        $unexpired = AgentWebhookReceipt::latest('id')->firstOrFail();
        $unexpired->forceFill(['created_at' => now()->subDays(3), 'expires_at' => now()->addHour()])->save();
        $this->deliver($this->payload())->assertNoContent();
        $claimed = AgentWebhookReceipt::latest('id')->firstOrFail();
        $claimed->forceFill(['created_at' => now()->subDays(3), 'expires_at' => now()->subDay(), 'processing_until' => now()->addMinute()])->save();
        $this->artisan('agent:prune-evidence')->assertSuccessful();
        $this->assertNull($old->fresh()->raw_body);
        $this->assertNotNull($unexpired->fresh()->raw_body);
        $this->assertNotNull($claimed->fresh()->raw_body);
        $this->travel(2)->minutes();
        $this->artisan('agent:prune-evidence')->assertSuccessful();
        $this->assertNull($claimed->fresh()->raw_body);
        $this->assertSame('ignored', $claimed->fresh()->status);
        $this->assertNull($claimed->fresh()->processing_until);
    }

    public function test_prune_is_bounded_deletes_only_finished_old_audits_and_never_operations(): void
    {
        $operation = $this->operation('succeeded', true);
        $operation->forceFill(['created_at' => now()->subYear()])->save();
        $audits = [];
        foreach ([now()->subDays(91), now()->subDays(92), now()->subDays(89), null] as $finishedAt) {
            $audits[] = AgentApiAudit::forceCreate([
                'request_id' => (string) Str::uuid(), 'client_id' => $operation->client_id, 'token_id' => 1,
                'capability' => 'operations.read', 'is_write' => false,
                'started_at' => now()->subDays(100), 'finished_at' => $finishedAt,
            ]);
        }
        for ($index = 0; $index < 2; $index++) {
            $this->deliver($this->payload())->assertNoContent();
            AgentWebhookReceipt::latest('id')->firstOrFail()->forceFill(['status' => 'ignored', 'created_at' => now()->subDays(8)])->save();
        }
        $this->artisan('agent:prune-evidence', ['--limit' => 1])->assertSuccessful();
        $this->assertSame(1, AgentWebhookReceipt::whereNull('raw_body')->count());
        $this->assertDatabaseCount('agent_api_audits', 3);
        $this->artisan('agent:prune-evidence', ['--limit' => 1])->assertSuccessful();
        $this->assertSame(2, AgentWebhookReceipt::whereNull('raw_body')->count());
        $this->assertDatabaseCount('agent_api_audits', 2);
        $this->assertNotNull($audits[2]->fresh());
        $this->assertNotNull($audits[3]->fresh());
        $this->assertNotNull($operation->fresh());
        $this->assertSame('succeeded', $operation->fresh()->state);
        foreach (['0', '1001', '-1', 'no'] as $limit) {
            $this->artisan('agent:prune-evidence', ['--limit' => $limit])->assertExitCode(2);
        }
        Http::assertNothingSent();
    }

    public function test_prune_rejects_invalid_retention_and_honors_audit_retention_override(): void
    {
        $audit = AgentApiAudit::forceCreate([
            'request_id' => (string) Str::uuid(), 'client_id' => 1, 'token_id' => 1,
            'capability' => 'operations.read', 'is_write' => false,
            'started_at' => now()->subDays(10), 'finished_at' => now()->subDays(9),
        ]);
        config(['agent-api.audit_retention_days' => 0]);
        $this->artisan('agent:prune-evidence')->assertExitCode(2);
        $this->assertNotNull($audit->fresh());
        config(['agent-api.audit_retention_days' => 8]);
        $this->artisan('agent:prune-evidence')->assertSuccessful();
        $this->assertNull($audit->fresh());
    }

    public function test_prune_drains_more_than_two_batches_and_retains_tombstones_and_operations(): void
    {
        $operation = $this->operation('succeeded', true);
        $operation->forceFill(['created_at' => now()->subYear()])->save();
        for ($index = 0; $index < 5; $index++) {
            $this->deliver($this->payload())->assertNoContent();
            AgentWebhookReceipt::latest('id')->firstOrFail()->forceFill(['status' => 'ignored', 'created_at' => now()->subDays(8)])->save();
        }
        $eventIds = AgentWebhookReceipt::orderBy('id')->pluck('event_id')->all();
        for ($index = 0; $index < 9; $index++) {
            AgentApiAudit::forceCreate([
                'request_id' => (string) Str::uuid(), 'client_id' => $operation->client_id, 'token_id' => 1,
                'capability' => 'operations.read', 'is_write' => false, 'started_at' => now()->subDays(100),
                'finished_at' => $index < 7 ? now()->subDays(91) : ($index === 7 ? now()->subDay() : null),
            ]);
        }
        $this->artisan('agent:prune-evidence', ['--limit' => 2, '--batches' => 3])
            ->expectsOutput('Redacted 5 webhook payload(s); deleted 6 completed audit(s). Deduplication records retained.')
            ->assertSuccessful();
        $this->assertSame(5, AgentWebhookReceipt::whereNull('raw_body')->count());
        $this->assertSame($eventIds, AgentWebhookReceipt::orderBy('id')->pluck('event_id')->all());
        $this->assertDatabaseCount('agent_api_audits', 3);
        $this->assertSame('succeeded', $operation->fresh()->state);
        $this->artisan('agent:prune-evidence')
            ->expectsOutput('Redacted 0 webhook payload(s); deleted 1 completed audit(s). Deduplication records retained.')
            ->assertSuccessful();
        $this->assertDatabaseCount('agent_api_audits', 2);
        $this->assertDatabaseCount('agent_operations', 1);
        $this->assertDatabaseCount('agent_webhook_receipts', 5);
        Http::assertNothingSent();
    }

    public function test_prune_rejects_invalid_batch_bounds_without_mutating_evidence(): void
    {
        $this->deliver($this->payload())->assertNoContent();
        $receipt = AgentWebhookReceipt::sole();
        $receipt->forceFill(['status' => 'ignored', 'created_at' => now()->subDays(8)])->save();
        foreach (['0', '11', '-1', 'no', '1.5'] as $batches) {
            $this->artisan('agent:prune-evidence', ['--batches' => $batches])->assertExitCode(2);
        }
        $this->assertNotNull($receipt->fresh()->raw_body);
        $this->assertNull($receipt->fresh()->payload_redacted_at);
    }

    public function test_prune_stops_when_both_evidence_batches_are_under_limit(): void
    {
        $this->deliver($this->payload())->assertNoContent();
        AgentWebhookReceipt::sole()->forceFill(['status' => 'ignored', 'created_at' => now()->subDays(8)])->save();
        $receiptSelections = 0;
        DB::listen(function (QueryExecuted $query) use (&$receiptSelections): void {
            if (str_starts_with($query->sql, 'select "id" from "agent_webhook_receipts"')) {
                $receiptSelections++;
            }
        });
        $this->artisan('agent:prune-evidence', ['--limit' => 2, '--batches' => 10])->assertSuccessful();
        $this->assertSame(1, $receiptSelections);
        $this->assertNull(AgentWebhookReceipt::sole()->raw_body);
    }

    public function test_prune_checks_the_ten_second_budget_between_batches(): void
    {
        for ($index = 0; $index < 3; $index++) {
            $this->deliver($this->payload())->assertNoContent();
            AgentWebhookReceipt::latest('id')->firstOrFail()->forceFill(['status' => 'ignored', 'created_at' => now()->subDays(8)])->save();
            AgentApiAudit::forceCreate([
                'request_id' => (string) Str::uuid(), 'client_id' => 1, 'token_id' => 1,
                'capability' => 'operations.read', 'is_write' => false,
                'started_at' => now()->subDays(100), 'finished_at' => now()->subDays(91),
            ]);
        }
        $elapsed = false;
        DB::listen(function (QueryExecuted $query) use (&$elapsed): void {
            if (! $elapsed && str_starts_with($query->sql, 'delete from "agent_api_audits"')) {
                $elapsed = true;
                $this->travel(11)->seconds();
            }
        });
        $this->artisan('agent:prune-evidence', ['--limit' => 1, '--batches' => 10])
            ->expectsOutput('Redacted 1 webhook payload(s); deleted 1 completed audit(s). Deduplication records retained.')
            ->assertSuccessful();
        $this->assertTrue($elapsed);
        $this->assertSame(1, AgentWebhookReceipt::whereNull('raw_body')->count());
        $this->assertDatabaseCount('agent_api_audits', 2);
        $this->assertDatabaseCount('agent_webhook_receipts', 3);
        Http::assertNothingSent();
    }
}
