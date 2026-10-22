<?php

namespace Tests\Feature;

use App\Models\AgentOperation;
use App\Models\Client;
use App\Models\ClientAccount;
use App\Models\User;
use App\Services\AgentOperationService;
use App\Services\AgentTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AccountPublishingPermissionTest extends TestCase
{
    use RefreshDatabase;

    protected function beforeRefreshingDatabase(): void
    {
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->assertEmpty(config('database.connections.sqlite.url'));
    }

    public function test_cli_defaults_to_deny_and_requires_platform_and_server_permission(): void
    {
        Http::preventStrayRequests();
        $account = ClientAccount::factory()->create(['platform' => 'instagram']);
        $this->assertFalse($account->publishing_enabled);
        $this->artisan('agent:account-publishing', ['account' => '0'])->assertFailed();
        foreach (['enabled', 'publishing_enabled', 'instagram_scheduling_enabled'] as $gate) {
            config(['agent-api.zernio.enabled' => true, 'agent-api.zernio.publishing_enabled' => true, 'agent-api.zernio.instagram_scheduling_enabled' => true]);
            config(['agent-api.zernio.'.$gate => false]);
            $this->artisan('agent:account-publishing', ['account' => $account->id])->assertFailed();
            $this->assertFalse($account->fresh()->publishing_enabled);
        }
        config(['agent-api.zernio.enabled' => true, 'agent-api.zernio.publishing_enabled' => true, 'agent-api.zernio.instagram_scheduling_enabled' => true]);
        $this->artisan('agent:account-publishing', ['account' => $account->id])->assertSuccessful();
        $this->assertTrue($account->fresh()->publishing_enabled);
        foreach (['linkedin', 'facebook', 'threads', 'bluesky'] as $platform) {
            $account->update(['platform' => $platform]);
            config(['agent-api.zernio.instagram_scheduling_enabled' => false]);
            $this->artisan('agent:account-publishing', ['account' => $account->id])->assertSuccessful();
        }
        $account->update(['platform' => 'tiktok']);
        $this->artisan('agent:account-publishing', ['account' => $account->id])->assertFailed();
        Http::assertNothingSent();
    }

    public function test_cli_requires_current_verified_owner_active_mapping_and_owned_profile(): void
    {
        config(['agent-api.zernio.enabled' => true, 'agent-api.zernio.publishing_enabled' => true]);
        $account = ClientAccount::factory()->create();
        $client = $account->client;
        $owner = $client->owner;
        foreach ([['active' => false], ['environment' => 'staging'], ['provider' => 'other'], ['client_provider_profile_id' => null]] as $change) {
            $original = $account->getAttributes();
            $account->forceFill($change)->save();
            $this->artisan('agent:account-publishing', ['account' => $account->id])->assertFailed();
            $account->forceFill($original)->save();
        }
        $profile = $account->providerProfile;
        $profile->update(['client_id' => Client::factory()->create()->id]);
        $this->artisan('agent:account-publishing', ['account' => $account->id])->assertFailed();
        $profile->update(['client_id' => $client->id]);
        $owner->forceFill(['email_verified_at' => null])->save();
        $this->artisan('agent:account-publishing', ['account' => $account->id])->assertFailed();
        $owner->forceFill(['email_verified_at' => now()])->save();
        $client->update(['active' => false]);
        $this->artisan('agent:account-publishing', ['account' => $account->id])->assertFailed();
        $client->update(['active' => true, 'owner_id' => null]);
        $this->artisan('agent:account-publishing', ['account' => $account->id])->assertFailed();
    }

    public function test_disable_always_revokes_without_changing_mapping_owner_or_tokens(): void
    {
        $account = ClientAccount::factory()->create(['publishing_enabled' => true]);
        $owner = $account->client->owner;
        $token = $owner->createToken('Existing read token', ['context.read']);
        $beforeToken = $token->accessToken->fresh()->getAttributes();
        $account->update(['active' => false]);
        $account->client->update(['active' => false, 'owner_id' => null]);
        $owner->forceFill(['email_verified_at' => null])->save();
        $beforeAccount = $account->fresh()->getAttributes();
        $beforeOwner = $owner->fresh()->getAttributes();
        $this->artisan('agent:account-publishing', ['account' => $account->id, '--disable' => true])->assertSuccessful();
        $this->assertFalse($account->fresh()->publishing_enabled);
        $this->assertSame(collect($beforeAccount)->except(['publishing_enabled', 'updated_at'])->all(), collect($account->fresh()->getAttributes())->except(['publishing_enabled', 'updated_at'])->all());
        $this->assertSame($beforeOwner, User::find($owner->id)->getAttributes());
        $this->assertSame($beforeToken, $token->accessToken->fresh()->getAttributes());
    }

    public function test_schedule_route_requires_bearer_ability_and_rejects_invalid_input_without_provider_io(): void
    {
        Http::preventStrayRequests();
        Http::fake([]);
        config(['agent-api.writes_per_minute' => 100, 'agent-api.zernio.enabled' => true, 'agent-api.zernio.publishing_enabled' => true, 'agent-api.zernio.instagram_scheduling_enabled' => true]);
        $account = ClientAccount::factory()->create(['platform' => 'instagram', 'publishing_enabled' => true]);
        $owner = $account->client->owner;
        $service = app(AgentTokenService::class);
        $read = $service->issue($owner, $account->client, 'Existing read', AgentTokenService::READ_ABILITIES, [$account->id]);
        $beforeRead = $read->accessToken->fresh()->getAttributes();
        $url = route('agent-api.posts.schedule', $account->id);
        $this->postJson($url)->assertUnauthorized();
        $this->withToken($read->plainTextToken)->postJson($url)->assertForbidden();
        $this->assertSame($beforeRead, $read->accessToken->fresh()->getAttributes());
        $write = $service->issue($owner, $account->client, 'Explicit schedule', ['context.read', 'zernio.posts.schedule'], [$account->id]);
        $this->withToken($write->plainTextToken);
        $jpeg = base64_decode('/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////2wBDAf//////////////////////////////////////////////////////////////////////////////////////wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAX/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/8QAFQEBAQAAAAAAAAAAAAAAAAAABQb/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIRAxEAPwCKAAB//9k=');
        $valid = ['content' => 'Test', 'scheduled_for' => '2026-12-01T12:00:00Z', 'image' => UploadedFile::fake()->createWithContent('image.jpg', $jpeg)];
        $headers = ['Accept' => 'application/json', 'Content-Type' => 'multipart/form-data', 'Idempotency-Key' => 'schedule-test'];
        foreach ([['unknown' => 'value'], ['content' => str_repeat('a', 2201)], ['scheduled_for' => '2026-12-01T12:00:00+00:00'], ['scheduled_for' => '2026-02-30T12:00:00Z'], ['image' => UploadedFile::fake()->createWithContent('image.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII='))], ['image' => UploadedFile::fake()->createWithContent('large.jpg', $jpeg)->size(2049)], ['extra' => UploadedFile::fake()->createWithContent('extra.jpg', $jpeg)]] as $change) {
            $this->post($url, array_replace($valid, $change), $headers)->assertUnprocessable();
        }
        foreach (['', 'has space', str_repeat('a', 256), "bad\tkey"] as $key) {
            $this->post($url, $valid, array_replace($headers, ['Idempotency-Key' => $key]))->assertUnprocessable();
        }
        $this->post($url.'?content=override', $valid, $headers)->assertUnprocessable();
        $this->postJson($url, ['content' => 'Test', 'scheduled_for' => $valid['scheduled_for']], ['Idempotency-Key' => 'json'])->assertUnprocessable();
        $this->assertDatabaseCount('agent_operations', 0);
        Http::assertNothingSent();

        // Past timestamps must reach the ledger so permanent idempotent replays can succeed.
        $valid['scheduled_for'] = '2020-01-01T12:00:00Z';
        $operations = $this->mock(AgentOperationService::class);
        foreach (['pending' => 202, 'succeeded' => 200] as $state => $status) {
            $operation = new AgentOperation(['id' => '019a0000-0000-7000-8000-000000000001', 'client_id' => $account->client_id, 'client_account_id' => $account->id, 'capability' => 'zernio.posts.schedule', 'state' => $state]);
            $operations->shouldReceive('scheduleInstagramImage')->once()
                ->withArgs(fn ($token, $mapped, $content, $image, $scheduledFor, $key): bool => $token->id === $write->accessToken->id
                    && $mapped->id === $account->id && $content === 'Test' && $image instanceof UploadedFile
                    && $scheduledFor === $valid['scheduled_for'] && $key === 'schedule-test')
                ->andReturn($operation);
            $this->post($url, $valid, $headers)->assertStatus($status)->assertJsonPath('data.state', $state);
        }
        $this->assertDatabaseCount('agent_operations', 0);
        Http::assertNothingSent();
    }

    public function test_capabilities_advertise_scheduling_only_with_current_scope_token_and_gates(): void
    {
        config(['agent-api.zernio.enabled' => true, 'agent-api.zernio.api_key' => 'test-key', 'agent-api.zernio.publishing_enabled' => true, 'agent-api.zernio.instagram_scheduling_enabled' => true]);
        $account = ClientAccount::factory()->create(['platform' => 'instagram', 'publishing_enabled' => true]);
        $service = app(AgentTokenService::class);
        $token = $service->issue($account->client->owner, $account->client, 'Schedule', ['context.read', 'zernio.posts.schedule'], [$account->id]);
        $this->withToken($token->plainTextToken)->getJson(route('agent-api.capabilities'))->assertOk()
            ->assertJsonPath('data.abilities', ['context.read', 'zernio.posts.schedule'])->assertJsonPath('data.zernio.write_enabled', true);
        foreach (['enabled', 'publishing_enabled', 'instagram_scheduling_enabled'] as $gate) {
            config(['agent-api.zernio.'.$gate => false]);
            $this->getJson(route('agent-api.capabilities'))->assertOk()->assertJsonPath('data.abilities', ['context.read'])->assertJsonPath('data.zernio.write_enabled', false);
            config(['agent-api.zernio.'.$gate => true]);
        }
        $account->update(['publishing_enabled' => false]);
        $this->getJson(route('agent-api.capabilities'))->assertOk()->assertJsonPath('data.zernio.write_enabled', false);
        $account->update(['publishing_enabled' => true]);
        $account->providerProfile->update(['active' => false]);
        $this->getJson(route('agent-api.capabilities'))->assertOk()->assertJsonPath('data.zernio.write_enabled', false);
    }

    public function test_account_write_flags_require_platform_specific_abilities_current_gates_and_scope(): void
    {
        Http::preventStrayRequests();
        Http::fake([]);
        config(['agent-api.zernio.enabled' => true, 'agent-api.zernio.api_key' => 'test-key', 'agent-api.zernio.publishing_enabled' => true, 'agent-api.zernio.instagram_scheduling_enabled' => true]);
        $instagram = ClientAccount::factory()->create(['platform' => 'instagram', 'publishing_enabled' => true]);
        $client = $instagram->client;
        $text = ClientAccount::factory()->create(['client_id' => $client->id, 'platform' => 'linkedin', 'publishing_enabled' => true]);
        $service = app(AgentTokenService::class);
        $schedule = $service->issue($client->owner, $client, 'Scheduler', ['zernio.accounts.read', 'zernio.posts.schedule'], [$instagram->id]);
        $url = route('agent-api.accounts.index');
        $this->withToken($schedule->plainTextToken)->getJson($url)->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $instagram->id)->assertJsonPath('data.0.write_enabled', true);
        foreach (['enabled', 'publishing_enabled', 'instagram_scheduling_enabled'] as $gate) {
            config(['agent-api.zernio.'.$gate => false]);
            if ($gate === 'enabled') {
                $this->getJson($url)->assertServiceUnavailable();
            } else {
                $this->getJson($url)->assertOk()->assertJsonPath('data.0.write_enabled', false);
            }
            config(['agent-api.zernio.'.$gate => true]);
        }
        $instagram->update(['publishing_enabled' => false]);
        $this->getJson($url)->assertOk()->assertJsonPath('data.0.write_enabled', false);
        $instagram->update(['publishing_enabled' => true]);
        $publish = $service->issue($client->owner, $client, 'Text publisher', ['zernio.accounts.read', 'zernio.posts.publish']);
        $this->withToken($publish->plainTextToken)->getJson($url)->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $instagram->id)->assertJsonPath('data.0.write_enabled', false)
            ->assertJsonPath('data.1.id', $text->id)->assertJsonPath('data.1.write_enabled', true);
        config(['agent-api.zernio.instagram_scheduling_enabled' => false]);
        $this->getJson($url)->assertOk()->assertJsonPath('data.1.write_enabled', true);
        $read = $service->issue($client->owner, $client, 'Read only', AgentTokenService::READ_ABILITIES);
        $this->withToken($read->plainTextToken)->getJson($url)->assertOk()
            ->assertJsonPath('data.0.write_enabled', false)->assertJsonPath('data.1.write_enabled', false);
        Http::assertNothingSent();
    }
}
