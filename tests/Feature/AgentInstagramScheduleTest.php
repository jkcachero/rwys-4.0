<?php

namespace Tests\Feature;

use App\Models\AgentAccessToken;
use App\Models\AgentOperation;
use App\Models\Client;
use App\Models\ClientAccount;
use App\Models\ClientProviderProfile;
use App\Models\User;
use App\Services\AgentOperationService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class AgentInstagramScheduleTest extends TestCase
{
    use RefreshDatabase;

    private AgentAccessToken $token;

    private ClientAccount $account;

    private UploadedFile $image;

    private string $scheduledFor;

    private int $uploads = 0;

    private int $creates = 0;

    private string $uploadedMediaUrl = 'https://cdn.zernio.com/image.jpg';

    private ?\Closure $duringPreflight = null;

    private ?\Closure $duringUpload = null;

    private bool $uploadTimeout = false;

    private bool $postTimeout = false;

    private bool $missingProfile = false;

    private int $listingReads = 0;

    private int $postReads = 0;

    private int $postStatus = 201;

    /** @var array<string, mixed> */
    private array $post;

    protected function beforeRefreshingDatabase(): void
    {
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->assertEmpty(config('database.connections.sqlite.url'));
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();
        config(['agent-api.zernio.enabled' => true, 'agent-api.zernio.publishing_enabled' => true, 'agent-api.zernio.instagram_scheduling_enabled' => true, 'agent-api.zernio.api_key' => 'test-secret']);
        Http::preventStrayRequests();
        Http::fake([]);
        Route::prefix('api')->middleware('api')->group(base_path('routes/agent-api.php'));
        Route::getRoutes()->refreshNameLookups();
        $owner = User::factory()->create();
        $client = Client::factory()->create(['owner_id' => $owner->id]);
        $this->account = ClientAccount::factory()->create(['client_id' => $client->id, 'platform' => 'instagram', 'publishing_enabled' => true]);
        $this->token = AgentAccessToken::forceCreate([
            'tokenable_type' => $owner->getMorphClass(), 'tokenable_id' => $owner->id,
            'name' => 'schedule test', 'token' => hash('sha256', str_repeat('r', 40)),
            'abilities' => ['zernio.posts.schedule'], 'expires_at' => now()->addHour(),
            'client_id' => $client->id, 'allowed_account_ids' => [$this->account->id],
        ]);
        $this->image = $this->jpeg();
        $this->scheduledFor = now()->addMinutes(10)->utc()->format('Y-m-d\TH:i:s\Z');
        $this->post = ['_id' => str_repeat('c', 24), 'profileId' => $this->account->profile_id,
            'content' => 'caption', 'status' => 'scheduled', 'scheduledFor' => $this->scheduledFor, 'timezone' => 'UTC',
            'mediaItems' => [['type' => 'image', 'url' => 'https://cdn.zernio.com/image.jpg']],
            'platforms' => [['accountId' => $this->account->provider_account_id, 'platform' => 'instagram', 'status' => 'pending']]];
        Http::fake(function (Request $request) {
            $this->assertSame(['Bearer test-secret'], $request->header('Authorization'));
            if ($request->method() === 'POST') {
                $operation = AgentOperation::sole();
                $this->assertSame('executing', $operation->state);
                $this->assertSame(1, DB::transactionLevel(), 'Only the test transaction may span HTTP.');
                if (str_contains($request->url(), '/posts')) {
                    $this->creates++;
                    $this->assertSame($this->uploadedMediaUrl, $operation->result['media_url']);
                    $this->assertSame([$operation->id], $request->header('Idempotency-Key'));
                    $this->assertFalse($request['publishNow']);
                    $this->assertSame('UTC', $request['timezone']);
                    if ($this->postTimeout) {
                        throw new ConnectionException('timeout');
                    }

                    return Http::response(['post' => $this->post], $this->postStatus);
                }
                $this->uploads++;
                $this->assertStringContainsString($operation->id.'.jpg', $request->body());
                if ($this->uploadTimeout) {
                    throw new ConnectionException('timeout');
                }
                if ($this->duringUpload) {
                    ($this->duringUpload)();
                }

                return Http::response(['url' => $this->uploadedMediaUrl, 'mediaUrl' => $this->uploadedMediaUrl]);
            }
            if (str_contains($request->url(), '/accounts')) {
                $this->assertSame('pending', AgentOperation::sole()->state);
                if ($this->duringPreflight) {
                    ($this->duringPreflight)();
                }

                return Http::response(['accounts' => [['_id' => $this->account->provider_account_id, 'profileId' => $this->account->profile_id, 'platform' => 'instagram', 'isActive' => true]],
                    'hasAnalyticsAccess' => false, 'pagination' => ['page' => 1, 'limit' => 25, 'total' => 1, 'pages' => 1]]);
            }

            if (parse_url($request->url(), PHP_URL_PATH) === '/api/v1/posts') {
                $this->listingReads++;
                $this->assertSame($this->account->profile_id, $request['profileId']);
                $this->assertSame($this->account->provider_account_id, $request['accountId']);

                return Http::response(['posts' => [$this->post], 'pagination' => ['page' => 1, 'limit' => 25, 'total' => 1, 'pages' => 1]]);
            }
            $post = $this->post;
            $this->postReads++;
            if ($this->missingProfile) {
                unset($post['profileId']);
            }

            return Http::response(['post' => $post]);
        });
    }

    private function schedule(string $key = 'key', string $content = 'caption'): AgentOperation
    {
        return app(AgentOperationService::class)->scheduleInstagramImage($this->token, $this->account, $content, $this->image, $this->scheduledFor, $key);
    }

    private function jpeg(int $width = 1, int $height = 1): UploadedFile
    {
        $bytes = base64_decode('/9j/4AAQSkZJRgABAQEAYABgAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////2wBDAf//////////////////////////////////////////////////////////////////////////////////////wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAX/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIQAxAAAAF//8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABBQJ//8QAFBEBAAAAAAAAAAAAAAAAAAAAAP/aAAgBAwEBPwF//8QAFBEBAAAAAAAAAAAAAAAAAAAAAP/aAAgBAgEBPwF//8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQAGPwJ//8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPyF//9oADAMBAAIAAwAAABD/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oACAEDAQE/EB//xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oACAECAQE/EB//xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oACAEBAAE/EB//2Q==');
        // SOF dimensions let boundary tests avoid allocating enormous decoded images.
        $offset = strpos($bytes, "\xff\xc0");
        $bytes = substr_replace($bytes, pack('nn', $height, $width), $offset + 5, 4);

        return UploadedFile::fake()->createWithContent('image.jpg', $bytes);
    }

    public function test_original_read_token_cannot_schedule_or_create_an_intent(): void
    {
        $this->token->forceFill(['abilities' => ['zernio.posts.read']])->save();
        try {
            $this->schedule();
            $this->fail('Read token gained write access.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        Http::assertNothingSent();
        $this->assertSame(0, AgentOperation::count());
    }

    public function test_schedule_is_pending_encrypted_and_replays_after_its_deadline_without_resending(): void
    {
        $operation = $this->schedule();
        $this->assertSame('provider_pending', $operation->state);
        $this->assertSame(str_repeat('c', 24), $operation->provider_post_id);
        $this->assertSame(['mapping', 'content', 'scheduled_for', 'image_sha256', 'image_size', 'image_mime'], array_keys($operation->intent));
        $this->assertSame(hash_file('sha256', $this->image->getPathname()), $operation->intent['image_sha256']);
        $this->assertStringNotContainsString('caption', $operation->getRawOriginal('intent'));
        $this->assertStringNotContainsString('https://cdn', $operation->getRawOriginal('result'));
        $this->travel(20)->minutes();
        $this->assertSame($operation->id, $this->schedule()->id);
        $this->assertSame(1, $this->uploads);
        $this->assertSame(1, $this->creates);
    }

    #[DataProvider('invalidInputs')]
    public function test_invalid_inputs_never_create_a_ledger_or_send_http(string $kind): void
    {
        $content = 'caption';
        if ($kind === 'caption') {
            $content = str_repeat('a', 2201);
        } elseif ($kind === 'control') {
            $content = "bad\ncaption";
        } elseif ($kind === 'utf8') {
            $content = "\xff";
        } elseif ($kind === 'png') {
            $this->image = UploadedFile::fake()->createWithContent('fake.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII='));
        } elseif ($kind === 'aspect') {
            $this->image = $this->jpeg(100, 10);
        } elseif ($kind === 'dimensions') {
            $this->image = $this->jpeg(4097, 4097);
        } elseif ($kind === 'pixels') {
            $this->image = $this->jpeg(4096, 4096);
        } elseif ($kind === 'bytes') {
            $this->image = UploadedFile::fake()->createWithContent('fake.jpg', str_repeat('x', 2097153));
        } elseif ($kind === 'past') {
            $this->scheduledFor = now()->subMinute()->utc()->format('Y-m-d\TH:i:s\Z');
        } elseif ($kind === 'near') {
            $this->scheduledFor = now()->addSeconds(119)->utc()->format('Y-m-d\TH:i:s\Z');
        } elseif ($kind === 'far') {
            $this->scheduledFor = now()->addDay()->addSecond()->utc()->format('Y-m-d\TH:i:s\Z');
        } else {
            $this->scheduledFor = $kind;
        }
        try {
            $this->schedule(content: $content);
            $this->fail('Invalid input accepted.');
        } catch (ValidationException) {
            Http::assertNothingSent();
            $this->assertSame(0, AgentOperation::count());
        }
    }

    public static function invalidInputs(): array
    {
        return array_map(static fn (string $value): array => [$value], ['caption', 'control', 'utf8', 'png', 'aspect', 'dimensions', 'pixels', 'bytes', 'past', 'near', 'far', '2026-02-30T12:00:00Z', '2026-10-23T12:00:00+00:00', '2026-10-23T12:00:00.000Z', "2026-10-23T12:00:00Z\0"]);
    }

    public function test_revocation_after_upload_prevents_post_creation(): void
    {
        $this->duringUpload = fn () => $this->token->delete();
        try {
            $this->schedule();
            $this->fail('Revoked token created a post.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertSame(1, $this->uploads);
        $this->assertSame(0, $this->creates);
        $this->assertSame('https://cdn.zernio.com/image.jpg', AgentOperation::sole()->result['media_url']);
    }

    public function test_revocation_during_preflight_prevents_upload(): void
    {
        $this->duringPreflight = fn () => $this->token->delete();
        try {
            $this->schedule();
            $this->fail('Revoked token uploaded media.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertSame(0, $this->uploads);
        $this->assertSame(0, $this->creates);
    }

    public function test_forward_clock_during_upload_never_causes_immediate_publishing(): void
    {
        $this->duringUpload = fn () => $this->travel(10)->minutes();
        $this->assertSame('failed', $this->schedule()->state);
        $this->assertSame(0, $this->creates);
    }

    public function test_upload_metadata_persistence_failure_prevents_create_and_replay(): void
    {
        $failed = false;
        DB::listen(function (QueryExecuted $query) use (&$failed): void {
            if (! $failed && $this->uploads === 1 && str_starts_with($query->sql, 'update "agent_operations"')) {
                $failed = true;
                throw new \RuntimeException('Simulated metadata persistence failure');
            }
        });
        try {
            $this->schedule();
            $this->fail('Persistence error ignored.');
        } catch (\RuntimeException) {
            $this->assertTrue($failed);
        }
        $this->schedule();
        $this->assertSame(1, $this->uploads);
        $this->assertSame(0, $this->creates);
    }

    public function test_reconciliation_requires_exact_media_time_and_real_published_url_even_after_write_revocation(): void
    {
        $operation = $this->schedule();
        config(['agent-api.zernio.publishing_enabled' => false, 'agent-api.zernio.instagram_scheduling_enabled' => false]);
        $this->account->forceFill(['publishing_enabled' => false])->save();
        $this->post['mediaItems'][0]['url'] = 'https://cdn.zernio.com/other.jpg';
        $service = app(AgentOperationService::class);
        $this->assertSame('unknown', $service->reconcile($operation)->state);
        $this->post['mediaItems'][0]['url'] = 'https://cdn.zernio.com/image.jpg';
        $this->post['status'] = 'published';
        $this->post['platforms'][0]['status'] = 'published';
        $this->post['platforms'][0]['platformPostUrl'] = 'https://instagram.example/p/fake/';
        $this->assertSame('unknown', $service->reconcile($operation)->state);
        $this->post['platforms'][0]['platformPostUrl'] = 'https://www.instagram.com/p/RealPost123/';
        $this->assertSame('succeeded', $service->reconcile($operation)->state);
        $this->assertSame('https://cdn.zernio.com/image.jpg', $operation->result['media_url']);
        $this->assertSame(1, $this->creates);
    }

    public function test_real_bearer_multipart_route_returns_only_safe_pending_operation_fields(): void
    {
        $response = $this->withToken($this->token->id.'|'.str_repeat('r', 40))->post('/api/v1/zernio/accounts/'.$this->account->id.'/scheduled-posts', [
            'content' => 'caption', 'image' => $this->image, 'scheduled_for' => $this->scheduledFor,
        ], ['Accept' => 'application/json', 'Content-Type' => 'multipart/form-data', 'Idempotency-Key' => 'route-key']);
        $response->assertSuccessful()->assertJsonPath('data.state', 'provider_pending')
            ->assertJsonPath('data.provider_post_id', str_repeat('c', 24))->assertJsonMissingPath('data.intent')->assertJsonMissingPath('data.result');
        $this->assertSame(1, $this->creates);
    }

    public function test_real_bearer_original_read_token_route_is_forbidden_without_io(): void
    {
        $this->token->forceFill(['abilities' => ['zernio.posts.read']])->save();
        $this->withToken($this->token->id.'|'.str_repeat('r', 40))->post('/api/v1/zernio/accounts/'.$this->account->id.'/scheduled-posts', [
            'content' => 'caption', 'image' => $this->image, 'scheduled_for' => $this->scheduledFor,
        ], ['Accept' => 'application/json', 'Idempotency-Key' => 'route-key'])->assertForbidden();
        Http::assertNothingSent();
    }

    #[DataProvider('authorizationFailures')]
    public function test_current_permission_failures_have_zero_provider_io(string $kind): void
    {
        if (in_array($kind, ['enabled', 'publishing_enabled', 'instagram_scheduling_enabled'], true)) {
            config(['agent-api.zernio.'.$kind => false]);
        } elseif ($kind === 'account') {
            $this->account->forceFill(['publishing_enabled' => false])->save();
        } elseif ($kind === 'profile') {
            ClientProviderProfile::whereKey($this->account->client_provider_profile_id)->update(['active' => false]);
        } elseif ($kind === 'scope') {
            $this->token->forceFill(['allowed_account_ids' => [999999]])->save();
        } elseif ($kind === 'cross-client') {
            $this->token->forceFill(['client_id' => Client::factory()->create()->id])->save();
        } elseif ($kind === 'owner') {
            Client::whereKey($this->account->client_id)->update(['owner_id' => User::factory()->create()->id]);
        } elseif ($kind === 'expiry') {
            $this->token->forceFill(['expires_at' => now()->addDays(31)])->save();
        } else {
            $this->token->forceFill(['abilities' => ['zernio.posts.schedule', '*']])->save();
        }
        try {
            $this->schedule();
            $this->fail('Unauthorized schedule accepted.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        Http::assertNothingSent();
        $this->assertSame(0, AgentOperation::count());
    }

    public static function authorizationFailures(): array
    {
        return array_map(static fn (string $value): array => [$value], ['enabled', 'publishing_enabled', 'instagram_scheduling_enabled', 'account', 'profile', 'scope', 'cross-client', 'owner', 'expiry', 'wildcard']);
    }

    #[DataProvider('conflicts')]
    public function test_permanent_key_rejects_different_image_schedule_and_caption(string $kind): void
    {
        $this->schedule();
        if ($kind === 'image') {
            $this->image = $this->jpeg(2, 2);
        } elseif ($kind === 'time') {
            $this->scheduledFor = now()->addMinutes(11)->utc()->format('Y-m-d\TH:i:s\Z');
        }
        try {
            $this->schedule(content: $kind === 'caption' ? 'changed' : 'caption');
            $this->fail('Conflicting intent accepted.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }
        $this->assertSame(1, $this->uploads);
        $this->assertSame(1, $this->creates);
    }

    public static function conflicts(): array
    {
        return [['image'], ['time'], ['caption']];
    }

    #[DataProvider('ambiguousEffects')]
    public function test_ambiguous_writes_are_private_and_never_resent(string $kind): void
    {
        $this->uploadTimeout = $kind === 'upload';
        $this->postTimeout = $kind === 'post';
        $this->postStatus = $kind === '207' ? 207 : 201;
        $operation = $this->schedule();
        $this->assertSame('unknown', $operation->state);
        $this->assertNull($operation->provider_post_id);
        $this->assertSame($kind === '207' ? str_repeat('c', 24) : null, $operation->recovery_post_id);
        $this->assertArrayNotHasKey('recovery_post_id', $operation->toArray());
        $this->assertSame($operation->id, $this->schedule()->id);
        $this->assertSame(1, $this->uploads);
        $this->assertSame($kind === 'upload' ? 0 : 1, $this->creates);
    }

    public static function ambiguousEffects(): array
    {
        return [['upload'], ['post'], ['207']];
    }

    public function test_ledger_failure_prevents_even_account_preflight(): void
    {
        DB::listen(function (QueryExecuted $query): void {
            if (str_starts_with($query->sql, 'insert into "agent_operations"')) {
                throw new \RuntimeException('Ledger unavailable');
            }
        });
        try {
            $this->schedule();
            $this->fail('Ledger error ignored.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Ledger unavailable', $exception->getMessage());
        }
        Http::assertNothingSent();
    }

    public function test_private_candidate_requires_scoped_membership_and_exact_schedule_for_recovery(): void
    {
        $this->postStatus = 207;
        $operation = $this->schedule();
        $this->missingProfile = true;
        $this->post['scheduledFor'] = now()->addMinutes(11)->utc()->format('Y-m-d\TH:i:s\Z');
        $service = app(AgentOperationService::class);
        $this->assertSame('unknown', $service->reconcile($operation)->state);
        $this->assertNull($operation->provider_post_id);
        $this->post['scheduledFor'] = $this->scheduledFor;
        $this->assertSame('provider_pending', $service->reconcile($operation)->state);
        $this->assertSame(2, $this->listingReads);
        $this->assertSame(1, $this->creates);
    }

    public function test_subsecond_initial_delay_is_strict(): void
    {
        $this->travelTo(now()->startOfSecond()->addMicroseconds(500000));
        $this->scheduledFor = now()->addSeconds(120)->utc()->format('Y-m-d\TH:i:s\Z');
        $this->expectException(ValidationException::class);
        try {
            $this->schedule();
        } finally {
            Http::assertNothingSent();
        }
    }

    #[DataProvider('persistedStates')]
    public function test_every_existing_state_is_replay_only(string $state): void
    {
        $operation = $this->schedule();
        AgentOperation::whereKey($operation->id)->update(['state' => $state]);
        $this->assertSame($state, $this->schedule()->state);
        $this->assertSame(1, $this->uploads);
        $this->assertSame(1, $this->creates);
    }

    public static function persistedStates(): array
    {
        return array_map(static fn (string $state): array => [$state], ['pending', 'executing', 'provider_pending', 'partial', 'unknown', 'failed', 'cancelled', 'succeeded']);
    }

    public function test_competing_execution_claim_prevents_upload(): void
    {
        $this->duringPreflight = fn () => AgentOperation::query()->update(['state' => 'executing']);
        $this->assertSame('executing', $this->schedule()->state);
        $this->assertSame(0, $this->uploads);
        $this->assertSame(0, $this->creates);
    }

    public function test_audit_admission_failure_blocks_the_real_route_before_provider_io(): void
    {
        DB::listen(function (QueryExecuted $query): void {
            if (str_starts_with($query->sql, 'insert into "agent_api_audits"')) {
                throw new \RuntimeException('Audit unavailable');
            }
        });
        $this->withoutExceptionHandling();
        try {
            $this->withToken($this->token->id.'|'.str_repeat('r', 40))->post('/api/v1/zernio/accounts/'.$this->account->id.'/scheduled-posts', [
                'content' => 'caption', 'image' => $this->image, 'scheduled_for' => $this->scheduledFor,
            ], ['Accept' => 'application/json', 'Content-Type' => 'multipart/form-data', 'Idempotency-Key' => 'audit-key']);
            $this->fail('Audit error ignored.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Audit unavailable', $exception->getMessage());
        }
        Http::assertNothingSent();
        $this->assertSame(0, AgentOperation::count());
    }

    #[DataProvider('terminalStatuses')]
    public function test_failure_and_cancellation_require_same_intent_evidence(string $status): void
    {
        $operation = $this->schedule();
        $this->post['status'] = $status;
        $this->post['platforms'][0]['status'] = $status;
        $this->post['content'] = 'different caption';
        $service = app(AgentOperationService::class);
        $this->assertSame('unknown', $service->reconcile($operation)->state);
        $this->post['content'] = 'caption';
        $this->assertSame($status, $service->reconcile($operation)->state);
        $this->assertSame('https://cdn.zernio.com/image.jpg', $operation->result['media_url']);
        $this->assertSame(1, $this->creates);
    }

    public static function terminalStatuses(): array
    {
        return [['failed'], ['cancelled']];
    }

    public function test_stale_profile_blocks_evidence_reads_even_after_write_permission_revocation(): void
    {
        $operation = $this->schedule();
        ClientProviderProfile::whereKey($this->account->client_provider_profile_id)->update(['active' => false]);
        $sent = count(Http::recorded());
        $this->assertSame('provider_pending', app(AgentOperationService::class)->reconcile($operation)->state);
        Http::assertSentCount($sent);
    }

    public function test_missing_creation_profile_requires_read_only_scoped_recovery_without_resending(): void
    {
        unset($this->post['profileId']);
        $operation = $this->schedule();
        $this->assertSame('unknown', $operation->state);
        $this->assertNull($operation->provider_post_id);
        $this->assertSame(str_repeat('c', 24), $operation->recovery_post_id);
        $this->assertSame($operation->id, $this->schedule()->id);
        $this->assertSame(1, $this->uploads);
        $this->assertSame(1, $this->creates);

        config(['agent-api.zernio.publishing_enabled' => false, 'agent-api.zernio.instagram_scheduling_enabled' => false]);
        $this->account->forceFill(['publishing_enabled' => false])->save();
        $operation = app(AgentOperationService::class)->reconcile($operation);
        $this->assertSame('provider_pending', $operation->state);
        $this->assertSame(str_repeat('c', 24), $operation->provider_post_id);
        $this->assertNull($operation->recovery_post_id);
        $this->assertSame('https://cdn.zernio.com/image.jpg', $operation->result['media_url']);
        $this->assertSame(1, $this->postReads);
        $this->assertSame(1, $this->listingReads);
        $this->assertSame(1, $this->uploads);
        $this->assertSame(1, $this->creates);
        Http::assertSentCount(5);
    }

    public function test_missing_profile_candidate_with_extra_scoped_targets_remains_private(): void
    {
        unset($this->post['profileId']);
        $operation = $this->schedule();
        $this->post['platforms'][] = ['accountId' => str_repeat('d', 24), 'platform' => 'instagram', 'status' => 'pending'];
        $operation = app(AgentOperationService::class)->reconcile($operation);
        $this->assertSame('unknown', $operation->state);
        $this->assertNull($operation->provider_post_id);
        $this->assertSame(str_repeat('c', 24), $operation->recovery_post_id);
        $this->assertSame(1, $this->postReads);
        $this->assertSame(1, $this->listingReads);
        $this->assertSame(1, $this->uploads);
        $this->assertSame(1, $this->creates);
    }

    #[DataProvider('truncatedJpegs')]
    public function test_incomplete_jpeg_is_rejected_before_ledger_and_network(string $kind): void
    {
        $bytes = $this->image->getContent();
        $bytes = match ($kind) {
            'header-only' => substr($bytes, 0, strpos($bytes, "\xff\xda")),
            'missing-soi' => substr($bytes, 2),
            default => substr($bytes, 0, -2),
        };
        $this->image = UploadedFile::fake()->createWithContent('truncated.jpg', $bytes);
        $this->withToken($this->token->id.'|'.str_repeat('r', 40))->post('/api/v1/zernio/accounts/'.$this->account->id.'/scheduled-posts', [
            'content' => 'caption', 'image' => $this->image, 'scheduled_for' => $this->scheduledFor,
        ], ['Accept' => 'application/json', 'Content-Type' => 'multipart/form-data', 'Idempotency-Key' => 'truncated-key'])
            ->assertUnprocessable();
        $this->assertSame(0, AgentOperation::count());
        Http::assertNothingSent();
    }

    public static function truncatedJpegs(): array
    {
        return [['header-only'], ['missing-soi'], ['missing-eoi']];
    }

    #[DataProvider('observedMembership')]
    public function test_promoted_creation_media_still_requires_observed_profile_membership(bool $hasProfile): void
    {
        $this->uploadedMediaUrl = 'https://media.zernio.com/temp/approved-image.jpg';
        $this->post['mediaItems'][0]['url'] = 'https://media.zernio.com/media/approved-image.jpg';
        if (! $hasProfile) {
            unset($this->post['profileId']);
        }
        $operation = $this->schedule();
        $this->assertSame($hasProfile ? 'provider_pending' : 'unknown', $operation->state);
        $this->assertSame($hasProfile ? str_repeat('c', 24) : null, $operation->provider_post_id);
        $this->assertSame($hasProfile ? null : str_repeat('c', 24), $operation->recovery_post_id);
        $this->assertSame($this->uploadedMediaUrl, $operation->result['media_url']);
        $this->assertSame($operation->id, $this->schedule()->id);
        $this->assertSame(1, $this->uploads);
        $this->assertSame(1, $this->creates);
        $this->assertSame(0, $this->postReads);
        $this->assertSame(0, $this->listingReads);
    }

    public static function observedMembership(): array
    {
        return [[true], [false]];
    }

    public function test_promoted_media_recovers_through_scoped_reads_then_real_published_url_without_writes(): void
    {
        $this->uploadedMediaUrl = 'https://media.zernio.com/temp/approved-image.jpg';
        $this->post['mediaItems'][0]['url'] = 'https://media.zernio.com/media/approved-image.jpg';
        unset($this->post['profileId']);
        $operation = $this->schedule();
        $this->assertSame('unknown', $operation->state);
        $intent = $operation->intent;
        config(['agent-api.zernio.publishing_enabled' => false, 'agent-api.zernio.instagram_scheduling_enabled' => false]);
        $this->account->forceFill(['publishing_enabled' => false])->save();
        $service = app(AgentOperationService::class);
        $this->assertSame('provider_pending', $service->reconcile($operation)->state);
        $this->assertSame(str_repeat('c', 24), $operation->provider_post_id);
        $this->assertSame($this->uploadedMediaUrl, $operation->result['media_url']);
        $this->post['status'] = 'published';
        $this->post['platforms'][0]['status'] = 'published';
        $this->assertSame('unknown', $service->reconcile($operation)->state);
        $this->post['platforms'][0]['platformPostUrl'] = 'https://www.instagram.com/p/ApprovedImage123/';
        $this->assertSame('succeeded', $service->reconcile($operation)->state);
        $this->assertSame($this->uploadedMediaUrl, $operation->result['media_url']);
        $this->assertSame($intent, $operation->intent);
        $this->assertSame(3, $this->postReads);
        $this->assertSame(3, $this->listingReads);
        $this->assertSame(1, $this->uploads);
        $this->assertSame(1, $this->creates);
    }

    #[DataProvider('untrustedMediaPromotions')]
    public function test_untrusted_media_changes_remain_private_during_creation_and_reconciliation(string $observed): void
    {
        $this->uploadedMediaUrl = 'https://media.zernio.com/temp/approved-image.jpg';
        $this->post['mediaItems'][0]['url'] = $observed;
        $operation = $this->schedule();
        $this->assertSame('unknown', $operation->state);
        $this->assertNull($operation->provider_post_id);
        $this->assertSame($operation->id, $this->schedule()->id);
        $this->assertSame('unknown', app(AgentOperationService::class)->reconcile($operation)->state);
        $this->assertNull($operation->provider_post_id);
        $this->assertSame(str_repeat('c', 24), $operation->recovery_post_id);
        $this->assertSame($this->uploadedMediaUrl, $operation->result['media_url']);
        $this->assertSame(1, $this->uploads);
        $this->assertSame(1, $this->creates);
        $this->assertSame(1, $this->postReads);
    }

    public static function untrustedMediaPromotions(): array
    {
        return [
            ['https://untrusted.example.com/media/approved-image.jpg'],
            ['https://media.zernio.com/other/approved-image.jpg'],
            ['https://media.zernio.com/media/unrelated-image.jpg'],
        ];
    }
}
