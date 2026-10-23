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
            'can_read' => true,
            'can_publish' => $ability === AgentAbility::PostsPublish,
            'can_schedule' => $ability === AgentAbility::PostsSchedule,
        ]);
        $token = app(AgentTokenService::class)->issue($owner, $client, 'Write test', null, [$account->public_id]);
        $token->accessToken->forceFill(['abilities' => [$ability->value]])->save();

        return [$token->plainTextToken, $client, $account, $profile];
    }

    private function jpegUpload(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('scheduled.jpg', $this->jpegBytes());
    }

    private function jpegBytes(): string
    {
        return base64_decode('/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////2wBDAf//////////////////////////////////////////////////////////////////////////////////////wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAX/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIQAxAAAAEf/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABBQJ//8QAFBEBAAAAAAAAAAAAAAAAAAAAAP/aAAgBAwEBPwF//8QAFBEBAAAAAAAAAAAAAAAAAAAAAP/aAAgBAgEBPwF//8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQAGPwJ//8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPyF//9oADAMBAAIAAwAAABD/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oACAEDAQE/EH//xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oACAECAQE/EH//xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oACAEBAAE/EH//2Q==', true);
    }
}
