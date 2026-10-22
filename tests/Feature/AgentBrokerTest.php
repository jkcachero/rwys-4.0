<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgentBrokerTest extends TestCase
{
    use RefreshDatabase;

    public function test_client_scoped_token_can_read_provider_accounts(): void
    {
        $user = User::factory()->create();
        $client = Client::factory()->for($user)->create();
        $token = $user->createToken('agent', ['clients:read'])->plainTextToken;

        $this->withToken($token)->getJson("/api/v1/agents/clients/{$client->id}/accounts?provider=adkit&scope_id=default")
            ->assertOk()
            ->assertJsonPath('meta.client', $client->slug)
            ->assertJsonPath('data.0.name', 'Demo campaign account');
    }

    public function test_client_accounts_are_isolated_between_users(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $client = Client::factory()->for($owner)->create();
        $token = $otherUser->createToken('agent', ['clients:read'])->plainTextToken;

        $this->withToken($token)->getJson("/api/v1/agents/clients/{$client->id}/accounts?provider=adkit&scope_id=default")
            ->assertForbidden();
    }

    public function test_client_accounts_require_the_read_ability(): void
    {
        $user = User::factory()->create();
        $client = Client::factory()->for($user)->create();
        $token = $user->createToken('agent')->plainTextToken;

        $this->withToken($token)->getJson("/api/v1/agents/clients/{$client->id}/accounts?provider=adkit&scope_id=default")
            ->assertForbidden();
    }
}
