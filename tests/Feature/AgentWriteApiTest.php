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
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class AgentWriteApiTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.zernio.enabled', true);
        config()->set('services.zernio.publish_enabled', true);
        config()->set('services.zernio.schedule_enabled', true);
        config()->set('services.zernio.base_url', 'https://zernio.example/api');
        Http::preventStrayRequests();
    }

    public function test_immediate_publish_delegates_platform_support_and_is_durable_permanently_idempotent(): void
    {
        [$token, , $account, $profile] = $this->writeContext('instagram', AgentAbility::PostsPublish);
        $providerPostId = '0123456789abcdef01234567';

        Http::fake(function (Request $request) use ($account, $profile, $providerPostId) {
            $path = parse_url($request->url(), PHP_URL_PATH);

            return match ($path) {
                '/api/v1/profiles/'.$profile->provider_id => Http::response(['profile' => ['_id' => $profile->provider_id]]),
                '/api/v1/accounts' => Http::response([
                    'accounts' => [[
                        '_id' => $account->provider_id,
                        'profileId' => $profile->provider_id,
                        'isActive' => true,
                        'status' => 'connected',
                    ]],
                    'pagination' => ['page' => 1, 'limit' => 100, 'total' => 1, 'pages' => 1],
                ]),
                '/api/v1/posts' => Http::response(['post' => [
                    '_id' => $providerPostId,
                    'accountIds' => [$account->provider_id],
                    'content' => 'Ship the verified update.',
                    'platforms' => ['instagram'],
                    'status' => 'published',
                    'platformPosts' => [[
                        'platform' => 'instagram',
                        'status' => 'published',
                        'url' => 'https://www.instagram.com/p/example',
                    ]],
                ]], 201),
                default => Http::response([], 500),
            };
        });

        $first = $this->withToken($token)
            ->withHeader('Idempotency-Key', 'publish-key-123')
            ->postJson("/api/v1/zernio/accounts/{$account->public_id}/posts", [
                'content' => 'Ship the verified update.',
            ])
            ->assertAccepted()
            ->assertJsonPath('data.status', 'succeeded');

        $operationId = $first->json('data.id');

        $this->withToken($token)
            ->withHeader('Idempotency-Key', 'publish-key-123')
            ->postJson("/api/v1/zernio/accounts/{$account->public_id}/posts", [
                'content' => 'Ship the verified update.',
            ])
            ->assertAccepted()
            ->assertJsonPath('data.id', $operationId)
            ->assertJsonPath('data.status', 'succeeded');

        $this->withToken($token)
            ->withHeader('Idempotency-Key', 'publish-key-123')
            ->postJson("/api/v1/zernio/accounts/{$account->public_id}/posts", [
                'content' => 'Changed intent must conflict.',
            ])
            ->assertConflict()
            ->assertJsonPath('error.code', 'idempotency_conflict');

        $this->assertDatabaseCount('agent_operations', 1);
        $operation = AgentOperation::query()->sole();
        $this->assertSame('Ship the verified update.', $operation->intent['content']);
        $this->assertStringNotContainsString('Ship the verified update.', (string) $operation->getRawOriginal('intent'));

        Http::assertSentCount(3);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && str_ends_with($request->url(), '/v1/posts')
            && $request->hasHeader('Idempotency-Key', hash('sha256', 'publish-key-123')));
    }

    public function test_only_the_request_that_atomically_claims_an_idempotency_key_dispatches_the_provider_write(): void
    {
        [$token, , $account] = $this->writeContext('linkedin', AgentAbility::PostsPublish);
        $insertedCompetingOperation = false;

        AgentOperation::creating(function (AgentOperation $operation) use (&$insertedCompetingOperation): void {
            if ($insertedCompetingOperation) {
                return;
            }

            $insertedCompetingOperation = true;
            DB::table('agent_operations')->insert([
                ...$operation->getAttributes(),
                'public_id' => (string) Str::uuid(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
        Http::fake();

        $response = $this->withToken($token)
            ->withHeader('Idempotency-Key', 'racing-publish-key')
            ->postJson("/api/v1/zernio/accounts/{$account->public_id}/posts", [
                'content' => 'Only one request may dispatch.',
            ])
            ->assertAccepted()
            ->assertJsonPath('data.status', 'pending');

        $this->assertTrue($insertedCompetingOperation);
        $this->assertDatabaseCount('agent_operations', 1);
        $this->assertSame(
            AgentOperation::query()->sole()->public_id,
            $response->json('data.id'),
        );
        Http::assertNothingSent();
    }

    public function test_write_gate_fails_closed_before_creating_an_operation_or_calling_zernio(): void
    {
        [$token, , $account] = $this->writeContext('linkedin', AgentAbility::PostsPublish);
        config()->set('services.zernio.publish_enabled', false);
        Http::fake();

        $this->withToken($token)
            ->withHeader('Idempotency-Key', 'disabled-write-key')
            ->postJson("/api/v1/zernio/accounts/{$account->public_id}/posts", [
                'content' => 'This must not leave RWYS.',
            ])
            ->assertServiceUnavailable()
            ->assertJsonPath('error.code', 'publishing_disabled');

        $this->assertDatabaseCount('agent_operations', 0);
        Http::assertNothingSent();
    }

    public function test_immediate_instagram_single_image_is_uploaded_published_and_replayed_without_another_effect(): void
    {
        Storage::fake('local');
        [$token, , $account, $profile] = $this->writeContext('instagram', AgentAbility::PostsPublish);
        $providerPostId = 'abcdef0123456789abcdef01';
        $uploadUrl = 'https://cdn.zernio.example/immediate-image.jpg';

        Http::fake(function (Request $request) use ($account, $profile, $providerPostId, $uploadUrl) {
            $path = parse_url($request->url(), PHP_URL_PATH);

            return match ($path) {
                '/api/v1/profiles/'.$profile->provider_id => Http::response(['profile' => ['_id' => $profile->provider_id]]),
                '/api/v1/accounts' => Http::response([
                    'accounts' => [[
                        '_id' => $account->provider_id,
                        'profileId' => $profile->provider_id,
                        'isActive' => true,
                        'status' => 'connected',
                    ]],
                    'pagination' => ['page' => 1, 'limit' => 100, 'total' => 1, 'pages' => 1],
                ]),
                '/api/v1/media/upload-direct' => Http::response(['url' => $uploadUrl], 201),
                '/api/v1/posts' => Http::response(['post' => [
                    '_id' => $providerPostId,
                    'accountIds' => [$account->provider_id],
                    'content' => 'Immediate image proof.',
                    'platforms' => ['instagram'],
                    'status' => 'published',
                    'platformPosts' => [[
                        'platform' => 'instagram',
                        'status' => 'published',
                        'url' => 'https://www.instagram.com/p/single-image',
                    ]],
                ]], 201),
                default => Http::response([], 500),
            };
        });

        $first = $this->withToken($token)
            ->withHeader('Idempotency-Key', 'immediate-image-key')
            ->post("/api/v1/zernio/accounts/{$account->public_id}/posts", [
                'content' => 'Immediate image proof.',
                'images' => [$this->jpegUpload('single.jpg')],
            ], ['Accept' => 'application/json'])
            ->assertAccepted()
            ->assertJsonPath('data.status', 'succeeded')
            ->assertJsonPath('data.outcome.provider_status', 'published')
            ->assertJsonPath('data.outcome.platform_url', 'https://www.instagram.com/p/single-image');

        $operationId = $first->json('data.id');
        $operation = AgentOperation::query()->sole();
        $this->assertSame([hash('sha256', $this->jpegBytes())], $operation->intent['image_hashes']);
        Storage::disk('local')->assertExists($operation->intent['media_paths'][0]);

        $this->withToken($token)
            ->withHeader('Idempotency-Key', 'immediate-image-key')
            ->post("/api/v1/zernio/accounts/{$account->public_id}/posts", [
                'content' => 'Immediate image proof.',
                'images' => [$this->jpegUpload('single.jpg')],
            ], ['Accept' => 'application/json'])
            ->assertAccepted()
            ->assertJsonPath('data.id', $operationId);

        Http::assertSentCount(4);
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/v1/posts')
            && $request['publishNow'] === true
            && $request['isDraft'] === false
            && $request['mediaItems'] === [['type' => 'image', 'url' => $uploadUrl]]
            && ! isset($request['scheduledFor'], $request['queueId']));
    }

    public function test_immediate_instagram_carousel_preserves_image_order_in_uploads_payload_and_idempotency(): void
    {
        Storage::fake('local');
        [$token, , $account, $profile] = $this->writeContext('instagram', AgentAbility::PostsPublish);
        $firstBytes = $this->jpegBytes();
        $secondBytes = $this->jpegBytesWithTrailingMetadata();
        $uploadNumber = 0;

        Http::fake(function (Request $request) use ($account, $profile, &$uploadNumber) {
            $path = parse_url($request->url(), PHP_URL_PATH);

            return match ($path) {
                '/api/v1/profiles/'.$profile->provider_id => Http::response(['profile' => ['_id' => $profile->provider_id]]),
                '/api/v1/accounts' => Http::response([
                    'accounts' => [[
                        '_id' => $account->provider_id,
                        'profileId' => $profile->provider_id,
                        'isActive' => true,
                    ]],
                    'pagination' => ['page' => 1, 'limit' => 100, 'total' => 1, 'pages' => 1],
                ]),
                '/api/v1/media/upload-direct' => Http::response(['url' => 'https://cdn.zernio.example/carousel-'.(++$uploadNumber).'.jpg'], 201),
                '/api/v1/posts' => Http::response(['post' => [
                    '_id' => '0123456789abcdef01234567',
                    'accountIds' => [$account->provider_id],
                    'status' => 'published',
                    'platformPosts' => [['platform' => 'instagram', 'status' => 'published']],
                ]], 201),
                default => Http::response([], 500),
            };
        });

        $this->withToken($token)
            ->withHeader('Idempotency-Key', 'carousel-key-123')
            ->post("/api/v1/zernio/accounts/{$account->public_id}/posts", [
                'content' => 'Ordered carousel.',
                'images' => [
                    UploadedFile::fake()->createWithContent('first.jpg', $firstBytes),
                    UploadedFile::fake()->createWithContent('second.jpg', $secondBytes),
                ],
            ], ['Accept' => 'application/json'])
            ->assertAccepted()
            ->assertJsonPath('data.status', 'succeeded');

        $this->assertSame(
            [hash('sha256', $firstBytes), hash('sha256', $secondBytes)],
            AgentOperation::query()->sole()->intent['image_hashes'],
        );
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/v1/posts')
            && $request['mediaItems'] === [
                ['type' => 'image', 'url' => 'https://cdn.zernio.example/carousel-1.jpg'],
                ['type' => 'image', 'url' => 'https://cdn.zernio.example/carousel-2.jpg'],
            ]);

        $this->withToken($token)
            ->withHeader('Idempotency-Key', 'carousel-key-123')
            ->post("/api/v1/zernio/accounts/{$account->public_id}/posts", [
                'content' => 'Ordered carousel.',
                'images' => [
                    UploadedFile::fake()->createWithContent('second.jpg', $secondBytes),
                    UploadedFile::fake()->createWithContent('first.jpg', $firstBytes),
                ],
            ], ['Accept' => 'application/json'])
            ->assertConflict()
            ->assertJsonPath('error.code', 'idempotency_conflict');

        Http::assertSentCount(5);
    }

    public function test_immediate_media_rejects_invalid_shapes_platforms_and_caller_controlled_provider_fields(): void
    {
        [$token, , $account] = $this->writeContext('linkedin', AgentAbility::PostsPublish);
        Http::fake();

        $this->withToken($token)
            ->withHeader('Idempotency-Key', 'invalid-media-key')
            ->post("/api/v1/zernio/accounts/{$account->public_id}/posts", [
                'content' => 'Invalid provider input.',
                'images' => [$this->jpegUpload()],
                'scheduled_for' => '2026-10-24T12:00:00Z',
                'mediaItems' => [['url' => 'https://attacker.example/image.jpg']],
            ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.scheduled_for.0', 'The scheduled_for field is not allowed.')
            ->assertJsonPath('errors.mediaItems.0', 'The mediaItems field is not allowed.');

        $this->withToken($token)
            ->withHeader('Idempotency-Key', 'unsupported-platform-key')
            ->post("/api/v1/zernio/accounts/{$account->public_id}/posts", [
                'content' => 'LinkedIn image is not allowlisted.',
                'images' => [$this->jpegUpload()],
            ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'unsupported_platform');

        $this->assertDatabaseCount('agent_operations', 0);
        Http::assertNothingSent();
    }

    public function test_immediate_instagram_partial_provider_response_records_a_sanitized_failure(): void
    {
        Storage::fake('local');
        [$token, , $account, $profile] = $this->writeContext('instagram', AgentAbility::PostsPublish);

        Http::fake(function (Request $request) use ($account, $profile) {
            $path = parse_url($request->url(), PHP_URL_PATH);

            return match ($path) {
                '/api/v1/profiles/'.$profile->provider_id => Http::response(['profile' => ['_id' => $profile->provider_id]]),
                '/api/v1/accounts' => Http::response([
                    'accounts' => [[
                        '_id' => $account->provider_id,
                        'profileId' => $profile->provider_id,
                        'isActive' => true,
                    ]],
                    'pagination' => ['page' => 1, 'limit' => 100, 'total' => 1, 'pages' => 1],
                ]),
                '/api/v1/media/upload-direct' => Http::response(['url' => 'https://cdn.zernio.example/partial.jpg'], 201),
                '/api/v1/posts' => Http::response(['post' => [
                    '_id' => 'fedcba9876543210fedcba98',
                    'accountIds' => [$account->provider_id],
                    'status' => 'partial',
                    'platformResults' => [[
                        'platform' => 'instagram',
                        'status' => 'failed',
                        'url' => 'http://unsafe.example/post',
                        'error' => ['message' => 'provider secret details'],
                    ]],
                ]], 207),
                default => Http::response([], 500),
            };
        });

        $this->withToken($token)
            ->withHeader('Idempotency-Key', 'partial-image-key')
            ->post("/api/v1/zernio/accounts/{$account->public_id}/posts", [
                'content' => 'Partial result proof.',
                'images' => [$this->jpegUpload()],
            ], ['Accept' => 'application/json'])
            ->assertAccepted()
            ->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.outcome.provider_status', 'failed')
            ->assertJsonMissingPath('data.outcome.platform_url')
            ->assertJsonMissing(['provider secret details']);
    }

    public function test_scheduled_instagram_jpeg_is_validated_stored_and_replayed_without_another_effect(): void
    {
        Carbon::setTestNow('2026-10-23 12:00:00 UTC');
        Storage::fake('local');
        [$token, , $account, $profile] = $this->writeContext('instagram', AgentAbility::PostsSchedule);
        $scheduledFor = '2026-10-23T14:05:00Z';
        $providerPostId = 'abcdef0123456789abcdef01';
        $uploadUrl = 'https://cdn.zernio.example/private-upload-id.jpg';

        Http::fake(function (Request $request) use ($account, $profile, $providerPostId, $scheduledFor, $uploadUrl) {
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
                '/api/v1/media/upload-direct' => Http::response([
                    'url' => $uploadUrl,
                    'filename' => 'private-upload-id.jpg',
                    'contentType' => 'image/jpeg',
                    'size' => strlen($this->jpegBytes()),
                ], 201),
                '/api/v1/posts' => Http::response(['post' => [
                    '_id' => $providerPostId,
                    'accountIds' => [$account->provider_id],
                    'content' => 'Scheduled proof.',
                    'platforms' => ['instagram'],
                    'scheduledFor' => $scheduledFor,
                    'status' => 'scheduled',
                    'mediaItems' => [['url' => $uploadUrl]],
                ]], 201),
                default => Http::response([], 500),
            };
        });

        $first = $this->withToken($token)
            ->withHeader('Idempotency-Key', 'schedule-key-123')
            ->post("/api/v1/zernio/accounts/{$account->public_id}/scheduled-posts", [
                'content' => 'Scheduled proof.',
                'scheduled_for' => $scheduledFor,
                'image' => $this->jpegUpload(),
            ], ['Accept' => 'application/json'])
            ->assertAccepted()
            ->assertJsonPath('data.status', 'provider-pending');

        $operationId = $first->json('data.id');
        $operation = AgentOperation::query()->sole();
        Storage::disk('local')->assertExists($operation->intent['media_path']);
        $this->assertSame(hash('sha256', $this->jpegBytes()), $operation->intent['image_hash']);

        Carbon::setTestNow('2026-10-23 12:10:00 UTC');

        $this->withToken($token)
            ->withHeader('Idempotency-Key', 'schedule-key-123')
            ->post("/api/v1/zernio/accounts/{$account->public_id}/scheduled-posts", [
                'content' => 'Scheduled proof.',
                'scheduled_for' => $scheduledFor,
                'image' => $this->jpegUpload(),
            ], ['Accept' => 'application/json'])
            ->assertAccepted()
            ->assertJsonPath('data.id', $operationId)
            ->assertJsonPath('data.status', 'provider-pending');

        Http::assertSentCount(4);
        $this->assertDatabaseCount('agent_operations', 1);
    }

    public function test_scheduled_post_rejects_truncated_jpeg_and_unknown_fields_without_side_effects(): void
    {
        Carbon::setTestNow('2026-10-23 12:00:00 UTC');
        [$token, , $account] = $this->writeContext('instagram', AgentAbility::PostsSchedule);
        Http::fake();

        $this->withToken($token)
            ->withHeader('Idempotency-Key', 'invalid-image-key')
            ->post("/api/v1/zernio/accounts/{$account->public_id}/scheduled-posts", [
                'content' => 'Invalid image.',
                'scheduled_for' => '2026-10-23T12:05:00Z',
                'image' => UploadedFile::fake()->createWithContent('broken.jpg', "\xFF\xD8truncated"),
                'provider_url' => 'https://attacker.example/file.jpg',
            ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.provider_url.0', 'The provider_url field is not allowed.');

        $this->assertDatabaseCount('agent_operations', 0);
        Http::assertNothingSent();
    }

    /** @return array{string, Client, ProviderAccount, ProviderProfile} */
    private function writeContext(string $platform, AgentAbility $ability): array
    {
        $owner = User::factory()->create();
        $client = Client::factory()->create();
        $client->users()->attach($owner, ['role' => 'owner', 'is_active' => true]);
        $connection = $owner->providerConnections()->create([
            'provider' => 'zernio',
            'access_token' => 'provider-secret',
            'scopes' => ['read', 'write'],
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
            'platform' => $platform,
        ]);
        ClientAccountPermission::factory()->create([
            'client_id' => $client,
            'provider_account_id' => $account,
            'can_schedule' => $ability === AgentAbility::PostsSchedule,
        ]);
        $token = app(AgentTokenService::class)->issue($owner, $client, 'Write test', null, [$account->public_id]);
        $token->accessToken->forceFill(['abilities' => [$ability->value]])->save();

        return [$token->plainTextToken, $client, $account, $profile];
    }

    private function jpegUpload(string $filename = 'scheduled.jpg'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($filename, $this->jpegBytes());
    }

    private function jpegBytes(): string
    {
        return base64_decode('/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////2wBDAf//////////////////////////////////////////////////////////////////////////////////////wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAX/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIQAxAAAAEf/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABBQJ//8QAFBEBAAAAAAAAAAAAAAAAAAAAAP/aAAgBAwEBPwF//8QAFBEBAAAAAAAAAAAAAAAAAAAAAP/aAAgBAgEBPwF//8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQAGPwJ//8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPyF//9oADAMBAAIAAwAAABD/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oACAEDAQE/EH//xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oACAECAQE/EH//xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oACAEBAAE/EH//2Q==', true);
    }

    private function jpegBytesWithTrailingMetadata(): string
    {
        $bytes = $this->jpegBytes();

        return substr($bytes, 0, -2)."\xFF\xFE\x00\x06test"."\xFF\xD9";
    }
}
