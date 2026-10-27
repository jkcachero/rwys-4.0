<?php

namespace Tests\Feature;

use App\Enums\AgentAbility;
use App\Models\Client;
use App\Models\ClientAccountPermission;
use App\Models\ProviderAccount;
use App\Models\User;
use App\Services\AgentTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgentReadApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_context_returns_only_token_client_and_exact_capabilities(): void
    {
        [$token, $client] = $this->agentToken([AgentAbility::ContextRead->value]);

        $response = $this->withToken($token)->getJson('/api/v1/context');

        $response->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('X-Request-Id')
            ->assertJsonPath('client', ['id' => $client->public_id, 'name' => $client->name])
            ->assertJsonPath('capabilities', [AgentAbility::ContextRead->value])
            ->assertJson(fn ($json) => $json->whereType('request_id', 'string')->etc());
        $this->assertDatabaseCount('agent_request_audits', 1);
    }

    public function test_accounts_are_limited_to_active_client_permissions(): void
    {
        [$token, $client] = $this->agentToken([AgentAbility::AccountsRead->value]);
        $visible = ProviderAccount::factory()->create();
        $hidden = ProviderAccount::factory()->create();
        $client->providerProfiles()->attach([$visible->provider_profile_id, $hidden->provider_profile_id], ['assigned_at' => now()]);
        ClientAccountPermission::factory()->create(['client_id' => $client, 'provider_account_id' => $visible]);
        ClientAccountPermission::factory()->create([
            'client_id' => $client,
            'provider_account_id' => $hidden,
            'is_active' => false,
        ]);

        $this->withToken($token)->getJson('/api/v1/zernio/accounts?limit=25')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $visible->public_id)
            ->assertJsonMissing(['id' => $hidden->public_id]);
    }

    public function test_account_restrictions_are_enforced_on_every_request(): void
    {
        $owner = User::factory()->create();
        $client = Client::factory()->create();
        $client->users()->attach($owner, ['role' => 'owner', 'is_active' => true]);
        $allowed = ProviderAccount::factory()->create();
        $excluded = ProviderAccount::factory()->create();
        $client->providerProfiles()->attach([$allowed->provider_profile_id, $excluded->provider_profile_id], ['assigned_at' => now()]);
        ClientAccountPermission::factory()->create(['client_id' => $client, 'provider_account_id' => $allowed]);
        ClientAccountPermission::factory()->create(['client_id' => $client, 'provider_account_id' => $excluded]);
        $token = app(AgentTokenService::class)->issue(
            $owner,
            $client,
            'Restricted agent',
            null,
            [$allowed->public_id],
        );

        $this->withToken($token->plainTextToken)->getJson('/api/v1/zernio/accounts')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $allowed->public_id)
            ->assertJsonMissing(['id' => $excluded->public_id]);
    }

    public function test_zernio_account_listing_excludes_other_providers_and_unassigned_profiles(): void
    {
        [$token, $client] = $this->agentToken([AgentAbility::AccountsRead->value]);
        $visible = ProviderAccount::factory()->create();
        $adkit = ProviderAccount::factory()->create(['platform' => 'meta']);
        $adkit->profile->update(['provider' => 'adkit']);
        $inactive = ProviderAccount::factory()->create();
        $inactive->profile->update(['is_active' => false]);
        $unassigned = ProviderAccount::factory()->create();
        $client->providerProfiles()->attach([
            $visible->provider_profile_id,
            $adkit->provider_profile_id,
            $inactive->provider_profile_id,
        ], ['assigned_at' => now()]);
        foreach ([$visible, $adkit, $inactive, $unassigned] as $account) {
            ClientAccountPermission::factory()->create(['client_id' => $client, 'provider_account_id' => $account]);
        }

        $this->withToken($token)->getJson('/api/v1/zernio/accounts')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $visible->public_id);
    }

    public function test_request_is_denied_when_the_issuing_owner_loses_access(): void
    {
        [$token, $client] = $this->agentToken([AgentAbility::ContextRead->value]);

        $client->users()->updateExistingPivot($client->users()->firstOrFail(), ['is_active' => false]);

        $this->withToken($token)->getJson('/api/v1/context')->assertForbidden();
        $this->assertDatabaseCount('agent_request_audits', 0);
    }

    public function test_wrong_ability_and_unapproved_origin_are_denied_without_audit(): void
    {
        [$token] = $this->agentToken([AgentAbility::ContextRead->value]);

        $this->withToken($token)->getJson('/api/v1/zernio/accounts')->assertForbidden();
        $this->withToken($token)->withHeader('Origin', 'https://evil.example')->getJson('/api/v1/context')->assertForbidden();
        $this->assertDatabaseCount('agent_request_audits', 0);
    }

    /** @param list<string> $abilities
     * @return array{string, Client}
     */
    private function agentToken(array $abilities): array
    {
        $owner = User::factory()->create();
        $client = Client::factory()->create(['approved_origins' => ['https://agent.example']]);
        $client->users()->attach($owner, ['role' => 'owner', 'is_active' => true]);
        $token = app(AgentTokenService::class)->issue($owner, $client, 'Test agent');
        $token->accessToken->forceFill(['abilities' => $abilities])->save();

        return [$token->plainTextToken, $client];
    }
}
