<?php

namespace Tests\Feature;

use App\Models\AIConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class AIConnectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_users_can_create_and_list_a_redacted_connection(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);

        $this->actingAs($user)->postJson('/workspace/connections', [
            'provider' => 'openai',
            'label' => 'Personal OpenAI',
            'authMode' => 'api_key',
            'apiKey' => 'sk-test-secret-value',
            'model' => 'gpt-4o-mini',
        ])->assertCreated()
            ->assertJsonPath('connection.provider', 'openai')
            ->assertJsonPath('connection.status', 'configured')
            ->assertJsonPath('connection.lastValidatedAt', null)
            ->assertJsonMissing(['apiKey' => 'sk-test-secret-value']);

        $this->actingAs($user)->getJson('/workspace/connections')
            ->assertOk()
            ->assertJsonPath('connections.0.label', 'Personal OpenAI')
            ->assertJsonMissing(['credentials' => ['api_key' => 'sk-test-secret-value']]);

        $this->assertSame('sk-test-secret-value', AIConnection::firstOrFail()->credentials['api_key']);
        $this->assertArrayNotHasKey('credentials', AIConnection::firstOrFail()->toArray());
        $this->assertStringNotContainsString('sk-test-secret-value', AIConnection::firstOrFail()->getRawOriginal('credentials'));
        $this->assertSame(
            ['id', 'provider', 'label', 'authMode', 'model', 'status', 'lastValidatedAt'],
            array_keys($this->getJson('/workspace/connections')->json('connections.0')),
        );
        $this->actingAs(User::factory()->create())->getJson('/workspace/connections')
            ->assertExactJson(['connections' => []]);
    }

    public function test_users_cannot_revoke_another_users_connection(): void
    {
        $owner = User::factory()->create(['email_verified_at' => now()]);
        $other = User::factory()->create(['email_verified_at' => now()]);
        $connection = $owner->aiConnections()->create([
            'provider' => 'claude',
            'label' => 'Claude',
            'auth_mode' => 'api_key',
            'credentials' => ['api_key' => 'secret-value'],
            'status' => 'active',
        ]);

        $this->actingAs($other)->deleteJson('/workspace/connections/'.$connection->id)
            ->assertNotFound();

        $this->assertSame('active', $connection->fresh()->status);
    }

    public function test_connection_validation_rejects_unsupported_provider(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);

        $this->actingAs($user)->postJson('/workspace/connections', [
            'provider' => 'gemini',
            'label' => 'Unsupported',
            'authMode' => 'api_key',
            'apiKey' => 'long-enough-secret',
        ])->assertUnprocessable();
    }

    public function test_html_validation_is_json_and_never_flashes_secrets(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/workspace/connections', [
            'apiKey' => 'fictional-personal-secret',
            'access_token' => 'fictional-oauth-secret',
        ], ['Accept' => 'text/html'])->assertUnprocessable()->assertSessionMissing('_old_input');
    }

    public function test_owner_is_not_mass_assignable_and_revocation_erases_credentials(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $this->actingAs($user)->postJson('/workspace/connections', [
            'provider' => 'claude', 'label' => 'Personal', 'authMode' => 'api_key',
            'apiKey' => 'fictional-personal-secret', 'user_id' => $other->id,
        ])->assertCreated();

        $connection = $user->aiConnections()->firstOrFail();
        $connection->fill(['user_id' => $other->id])->save();
        $this->assertSame($user->id, $connection->fresh()->user_id);
        $this->actingAs($user)->delete('/workspace/connections/'.$connection->id, [], ['Accept' => 'text/html'])
            ->assertOk();
        $this->assertSame('revoked', $connection->fresh()->status);
        $this->assertEmpty($connection->fresh()->credentials);
    }

    public function test_redirect_validation_excludes_api_and_oauth_secrets_from_old_input(): void
    {
        Route::middleware('web')->post('/test-secret-validation', function (Request $request): void {
            $request->validate(['required_field' => ['required']]);
        });
        $this->post('/test-secret-validation', [
            'apiKey' => 'fictional-api-key', 'access_token' => 'fictional-access-token',
            'refresh_token' => 'fictional-refresh-token', 'client_secret' => 'fictional-client-secret',
            'label' => 'Safe label',
        ])->assertRedirect()->assertSessionHas('_old_input', ['label' => 'Safe label']);
    }

    public function test_connection_and_prompt_routes_return_json_for_unauthenticated_html_requests(): void
    {
        $this->get('/workspace/connections', ['Accept' => 'text/html'])
            ->assertUnauthorized()->assertHeader('Content-Type', 'application/json');
        $this->post('/dashboard/prompt', [], ['Accept' => 'text/html'])
            ->assertUnauthorized()->assertHeader('Content-Type', 'application/json');
    }
}
