<?php

namespace Tests\Feature;

use App\Inference\InferenceResult;
use App\Inference\InferenceService;
use App\Models\User;
use App\Providers\OpenAiOAuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use RuntimeException;
use Tests\TestCase;

class ProviderConnectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['workspace.oauth.redirect_uri' => 'http://127.0.0.1:1455/auth/callback']);
    }

    public function test_verified_users_can_view_only_their_redacted_connections(): void
    {
        $user = User::factory()->create();
        $user->aiConnections()->create(['provider' => 'openai', 'label' => 'Mine', 'auth_mode' => 'api_key', 'credentials' => ['api_key' => 'secret'], 'status' => 'configured']);
        User::factory()->create()->aiConnections()->create(['provider' => 'openai', 'label' => 'Other', 'auth_mode' => 'api_key', 'credentials' => ['api_key' => 'other-secret'], 'status' => 'configured']);
        $this->actingAs($user)->get('/settings/connections')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('settings/connections')
                ->has('connections', 1)->where('connections.0.label', 'Mine')
                ->missing('connections.0.credentials')->has('providers'));
    }

    public function test_start_and_poll_are_owner_bound_and_expire_after_ten_minutes(): void
    {
        $user = User::factory()->create();
        $start = $this->actingAs($user)->postJson('/settings/connections/openai')->assertOk()
            ->assertJsonStructure(['url', 'state', 'expiresAt'])->json();
        parse_str(parse_url($start['url'], PHP_URL_QUERY), $query);
        $this->assertSame('dynamic_agent_client', $query['client_id']);
        $this->assertSame('S256', $query['code_challenge_method']);
        $this->assertSame('http://127.0.0.1:1455/auth/callback', $query['redirect_uri']);
        $this->getJson('/settings/connections/openai/attempts/'.$start['state'])->assertOk()->assertJsonPath('status', 'pending');
        $this->actingAs(User::factory()->create())->getJson('/settings/connections/openai/attempts/'.$start['state'])->assertNotFound();
        $this->travel(601)->seconds();
        $this->actingAs($user)->getJson('/settings/connections/openai/attempts/'.$start['state'])->assertNotFound();
    }

    public function test_callback_denial_needs_no_cookie_and_links_to_fixed_app_origin(): void
    {
        config(['app.url' => 'https://workspace.example']);
        $user = User::factory()->create();
        $attempt = app(OpenAiOAuthService::class)->authorization($user);
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1', 'SERVER_PORT' => 1455])
            ->get('http://127.0.0.1:1455/auth/callback?state='.$attempt['state'].'&error=access_denied&code=never-echo-this')
            ->assertOk()->assertSee('https://workspace.example/settings/connections', false)
            ->assertDontSee('never-echo-this')->assertSessionMissing('error');
        $this->actingAs($user)->getJson('/settings/connections/openai/attempts/'.$attempt['state'])
            ->assertOk()->assertJsonPath('status', 'error');
        Http::assertNothingSent();
    }

    public function test_callback_rejects_wrong_address_or_port_and_malformed_inputs_without_flashing(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.1', 'SERVER_PORT' => 1455])->get('http://127.0.0.1:1455/auth/callback')->assertForbidden();
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1', 'SERVER_PORT' => 8000])->get('http://127.0.0.1:8000/auth/callback')->assertForbidden();
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1', 'SERVER_PORT' => 1455])->get('http://127.0.0.1:1455/auth/callback')->assertStatus(400)->assertSessionMissing('_old_input');
    }

    public function test_start_requires_authentication_verification_and_is_throttled(): void
    {
        $this->postJson('/settings/connections/openai')->assertUnauthorized();
        $this->actingAs(User::factory()->unverified()->create())->postJson('/settings/connections/openai')->assertForbidden();
        $this->actingAs(User::factory()->create());
        for ($i = 0; $i < 6; $i++) {
            $this->postJson('/settings/connections/openai')->assertOk();
        }
        $this->postJson('/settings/connections/openai')->assertStatus(429);
    }

    public function test_selected_api_provider_test_uses_owner_connection_and_selected_model(): void
    {
        $user = User::factory()->create();
        $connection = $user->aiConnections()->create(['provider' => 'claude', 'label' => 'Mine', 'auth_mode' => 'api_key', 'credentials' => ['api_key' => 'secret'], 'status' => 'configured']);
        $this->mock(InferenceService::class)->shouldReceive('prompt')->once()
            ->withArgs(fn (User $owner, string $provider, string $prompt, ?int $id, ?string $model): bool => $owner->is($user) && $provider === 'claude' && $id === $connection->id && $model === 'selected-model')
            ->andReturn(new InferenceResult('claude', 'selected-model', 'passed', 'test'));
        $this->actingAs($user)->post('/settings/connections/claude/test', ['connectionId' => $connection->id, 'model' => 'selected-model'])
            ->assertRedirect('/settings/connections')->assertSessionHas('status');
        $this->actingAs(User::factory()->create())->post('/settings/connections/claude/test', ['connectionId' => $connection->id])->assertNotFound();
    }

    public function test_json_selected_api_provider_test_returns_message_without_a_redirect(): void
    {
        $user = User::factory()->create();
        $connection = $user->aiConnections()->create(['provider' => 'claude', 'label' => 'Mine', 'auth_mode' => 'api_key', 'credentials' => ['api_key' => 'secret'], 'status' => 'configured']);
        $this->mock(InferenceService::class)->shouldReceive('prompt')->once()
            ->withArgs(fn (User $owner, string $provider, string $prompt, ?int $id, ?string $model): bool => $owner->is($user) && $provider === 'claude' && $id === $connection->id && $model === 'selected-model')
            ->andReturn(new InferenceResult('claude', 'selected-model', 'passed', 'test'));
        $this->actingAs($user)->postJson('/settings/connections/claude/test', ['connectionId' => $connection->id, 'model' => 'selected-model'])
            ->assertOk()->assertExactJson(['message' => 'Provider connection test completed.'])
            ->assertHeaderMissing('Location')->assertSessionMissing('status');
        $this->postJson('/settings/connections/openai/test', ['connectionId' => $connection->id])->assertNotFound();
        $this->actingAs(User::factory()->create())->postJson('/settings/connections/claude/test', ['connectionId' => $connection->id])->assertNotFound();
    }

    public function test_json_inference_failure_is_a_safe_service_unavailable_response(): void
    {
        $this->mock(InferenceService::class)->shouldReceive('prompt')->once()
            ->andThrow(new RuntimeException('secret upstream response with access-token'));
        $this->actingAs(User::factory()->create())->postJson('/settings/connections/openai/test')
            ->assertStatus(503)->assertExactJson(['message' => 'The provider connection test could not be completed.'])
            ->assertHeaderMissing('Location')->assertSessionMissing('error');
    }

    public function test_json_disconnect_revokes_latest_refresh_token_and_only_deletes_owner_connection(): void
    {
        $user = User::factory()->create();
        $connection = $user->providerConnections()->create(['provider' => 'openai', 'client_id' => 'oaiapp_test', 'subject' => 'subject', 'access_token' => 'expired-access', 'refresh_token' => 'old-refresh', 'scopes' => config('workspace.oauth.scopes'), 'access_token_expires_at' => now()->subMinute()]);
        $connection->update(['refresh_token' => 'rotated-refresh']);
        $other = User::factory()->create()->providerConnections()->create(['provider' => 'openai', 'access_token' => 'other-access', 'scopes' => []]);
        Http::fake([
            'https://auth.openai.com/.well-known/openid-configuration' => Http::response(['revocation_endpoint' => 'https://auth.openai.com/oauth/revoke']),
            'https://auth.openai.com/oauth/revoke' => Http::response('', 200),
        ]);
        $this->actingAs($user)->deleteJson('/settings/connections/openai')->assertOk()
            ->assertExactJson(['message' => 'Provider session revoked and disconnected.'])
            ->assertHeaderMissing('Location')->assertSessionMissing('status');
        $this->assertNull($connection->fresh());
        $this->assertNotNull($other->fresh());
        Http::assertSentCount(2);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://auth.openai.com/oauth/revoke'
            && $request['token'] === 'rotated-refresh' && $request['client_id'] === 'oaiapp_test');
    }

    public function test_disconnect_failure_returns_safe_json_503_or_legacy_flash_and_retains_credentials(): void
    {
        $user = User::factory()->create();
        $connection = $user->providerConnections()->create(['provider' => 'openai', 'client_id' => 'oaiapp_test', 'access_token' => 'access-secret', 'refresh_token' => 'refresh-secret', 'scopes' => []]);
        Http::fake([
            'https://auth.openai.com/.well-known/openid-configuration' => Http::response(['revocation_endpoint' => 'https://auth.openai.com/oauth/revoke']),
            'https://auth.openai.com/oauth/revoke' => Http::response(['secret' => 'refresh-secret'], 503),
        ]);
        $message = 'Remote revocation was not confirmed. The connection was retained; retry or disconnect the app in ChatGPT Settings.';
        $this->actingAs($user)->deleteJson('/settings/connections/openai')->assertStatus(503)
            ->assertExactJson(['message' => $message])->assertHeaderMissing('Location')->assertSessionMissing('error');
        $this->assertSame('refresh-secret', $connection->fresh()->refresh_token);
        $this->delete('/settings/connections/openai')->assertRedirect('/settings/connections')->assertSessionHas('error', $message);
    }

    public function test_index_uses_explicit_inference_connection_status_and_preserves_configuration_metadata(): void
    {
        $user = User::factory()->create();
        $user->providerConnections()->create(['provider' => 'openai', 'access_token' => 'expired', 'scopes' => [], 'access_token_expires_at' => now()->subHour()]);
        $this->mock(InferenceService::class)->shouldReceive('status')->once()->withArgs(fn (User $owner): bool => $owner->is($user))
            ->andReturn([['name' => 'openai', 'connected' => false, 'status' => 'unavailable', 'message' => 'Re-authorize this account.', 'expiresAt' => null]]);
        $this->actingAs($user)->get('/settings/connections')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('settings/connections')
                ->where('providers.0.connected', false)->where('providers.0.status', 'unavailable')
                ->where('providers.0.message', 'Re-authorize this account.')->where('providers.0.expiresAt', null)
                ->where('providers.0.authentication', config('workspace.providers.openai.authentication'))
                ->where('providers.0.capabilities', config('workspace.providers.openai.capabilities'))
                ->where('providers.0.label', config('workspace.providers.openai.label')));
    }

    public function test_connection_tests_are_throttled_for_ordinary_verified_users(): void
    {
        $this->mock(InferenceService::class)->shouldReceive('prompt')->times(6)
            ->andReturn(new InferenceResult('openai', 'model', 'passed', 'test'));
        $this->actingAs(User::factory()->create());
        for ($i = 0; $i < 6; $i++) {
            $this->postJson('/settings/connections/openai/test')->assertOk();
        }
        $this->postJson('/settings/connections/openai/test')->assertStatus(429);
    }
}
