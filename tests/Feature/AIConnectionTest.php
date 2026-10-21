<?php

namespace Tests\Feature;

use App\Models\AIConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
            ->assertJsonMissing(['apiKey' => 'sk-test-secret-value']);

        $this->actingAs($user)->getJson('/workspace/connections')
            ->assertOk()
            ->assertJsonPath('connections.0.label', 'Personal OpenAI')
            ->assertJsonMissing(['credentials' => ['api_key' => 'sk-test-secret-value']]);

        $this->assertSame('sk-test-secret-value', AIConnection::firstOrFail()->credentials['api_key']);
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
}
