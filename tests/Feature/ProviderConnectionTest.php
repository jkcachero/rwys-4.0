<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProviderConnectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_provider_connections_are_limited_to_configured_admins(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);

        $this->actingAs($user)
            ->get('/settings/connections')
            ->assertForbidden();
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

    public function test_loopback_listener_rejects_non_loopback_bindings(): void
    {
        $this->artisan('openai:oauth-listen', ['--host' => '0.0.0.0'])
            ->assertExitCode(1);
    }

    public function test_oauth_callback_requires_a_single_use_state_and_code(): void
    {
        $this->get('/auth/callback')->assertSessionHasErrors(['code', 'state']);
    }

    public function test_oauth_callback_reports_an_expired_authorization_state(): void
    {
        $this->get('/auth/callback?code=code&state='.str_repeat('a', 64))
            ->assertRedirect('/settings/connections')
            ->assertSessionHas('error', 'The OAuth authorization has expired or was already used.');
    }
}
