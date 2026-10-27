<?php

namespace Tests\Feature;

use App\Models\ProviderConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProviderConnectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_provider_connections_page_is_no_longer_available(): void
    {
        $user = User::factory()->create(['role' => 'admin', 'email_verified_at' => now()]);
        $connection = $user->providerConnections()->create([
            'provider' => 'zernio',
            'access_token' => 'existing-token',
            'scopes' => ['profiles:read', 'accounts:read'],
        ]);

        $this->actingAs($user)
            ->get('/settings/connections')
            ->assertNotFound();

        $this->assertSame('existing-token', $connection->fresh()->access_token);
    }

    public function test_admin_authorization_uses_pkce_and_the_exact_loopback_redirect(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'email_verified_at' => now(),
        ]);

        $response = $this->actingAs($admin)->get('/settings/connections/openai');
        $location = $response->headers->get('Location');
        parse_str((string) parse_url((string) $location, PHP_URL_QUERY), $query);

        $response->assertRedirect();
        $this->assertSame('dynamic_agent_client', $query['client_id']);
        $this->assertSame('http://127.0.0.1:1455/auth/callback', $query['redirect_uri']);
        $this->assertSame('S256', $query['code_challenge_method']);
        $this->assertNotSame('', $query['state']);
        $this->assertNotSame('', $query['nonce']);
        $this->assertNotSame('', $query['code_challenge']);
        $this->assertStringContainsString('chatgpt.tokens.use.direct', $query['scope']);
    }

    public function test_verified_admin_can_connect_zernio_with_a_valid_api_token(): void
    {
        config([
            'services.zernio.enabled' => true,
            'services.zernio.base_url' => 'https://zernio.test',
        ]);

        Http::fake([
            'https://zernio.test/v1/profiles*' => Http::response([
                'data' => [],
                'total' => 0,
                'skip' => 0,
                'limit' => 1,
            ]),
        ]);

        $admin = User::factory()->create([
            'role' => 'admin',
            'email_verified_at' => now(),
        ]);

        $this->actingAs($admin)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->put('/settings/connections/zernio', [
                'api_token' => 'zernio-secret-token',
            ])
            ->assertRedirect('/settings/agent-access')
            ->assertSessionHas('status', 'Zernio is connected for profile assignment.');

        $connection = ProviderConnection::query()
            ->whereBelongsTo($admin)
            ->where('provider', 'zernio')
            ->sole();

        $this->assertSame('zernio-secret-token', $connection->access_token);
        $this->assertNotSame('zernio-secret-token', $connection->getRawOriginal('access_token'));
        $this->assertSame(['profiles:read', 'accounts:read'], $connection->scopes);

        Http::assertSent(fn ($request): bool => $request->url() === 'https://zernio.test/v1/profiles?limit=1&skip=0'
            && $request->hasHeader('Authorization', 'Bearer zernio-secret-token'));
    }

    public function test_invalid_zernio_token_is_not_persisted_or_allowed_to_replace_a_connection(): void
    {
        config([
            'services.zernio.enabled' => true,
            'services.zernio.base_url' => 'https://zernio.test',
        ]);

        Http::fake([
            'https://zernio.test/v1/profiles*' => Http::response(['message' => 'Unauthorized'], 401),
        ]);

        $admin = User::factory()->create([
            'role' => 'admin',
            'email_verified_at' => now(),
        ]);
        $admin->providerConnections()->create([
            'provider' => 'zernio',
            'access_token' => 'existing-token',
            'scopes' => ['profiles:read', 'accounts:read'],
        ]);

        $this->actingAs($admin)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->put('/settings/connections/zernio', [
                'api_token' => 'invalid-token',
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('api_token')
            ->assertSessionMissing('_old_input.api_token');

        $this->assertSame(
            'existing-token',
            $admin->providerConnections()->where('provider', 'zernio')->sole()->access_token,
        );
    }

    public function test_zernio_connection_requires_password_confirmation(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'email_verified_at' => now(),
        ]);

        $this->actingAs($admin)
            ->put('/settings/connections/zernio', [
                'api_token' => 'zernio-secret-token',
            ])
            ->assertRedirect('/user/confirm-password');

        $this->assertDatabaseCount('provider_connections', 0);
    }

    public function test_loopback_listener_rejects_non_loopback_bindings(): void
    {
        $this->artisan('openai:oauth-listen', ['--host' => '0.0.0.0'])
            ->assertExitCode(1);
    }

    public function test_oauth_callback_requires_a_single_use_state_and_code(): void
    {
        $this->get('/auth/callback')->assertSessionHasErrors(['code', 'state']);
    }
}
