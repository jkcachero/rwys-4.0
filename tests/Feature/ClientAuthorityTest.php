<?php

namespace Tests\Feature;

use App\Enums\AgentAbility;
use App\Models\Client;
use App\Models\ProviderAccount;
use App\Models\ProviderProfile;
use App\Models\User;
use App\Services\AgentTokenService;
use App\Services\ClientProfileAssignmentService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClientAuthorityTest extends TestCase
{
    use RefreshDatabase;

    public function test_clients_have_isolated_memberships_and_exact_roles(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $otherOwner = User::factory()->create();
        $client = Client::factory()->create();
        $otherClient = Client::factory()->create();

        $client->users()->attach($owner, ['role' => 'owner', 'is_active' => true]);
        $client->users()->attach($member, ['role' => 'member', 'is_active' => true]);
        $otherClient->users()->attach($otherOwner, ['role' => 'owner', 'is_active' => true]);

        $this->assertTrue($client->hasActiveOwner($owner));
        $this->assertFalse($client->hasActiveOwner($member));
        $this->assertFalse($client->hasActiveMember($otherOwner));
    }

    public function test_provider_profile_can_only_be_assigned_to_one_client(): void
    {
        $profile = ProviderProfile::factory()->create();
        $firstClient = Client::factory()->create();
        $secondClient = Client::factory()->create();

        $firstClient->providerProfiles()->attach($profile, ['assigned_at' => now()]);

        $this->expectException(QueryException::class);
        $secondClient->providerProfiles()->attach($profile, ['assigned_at' => now()]);
    }

    public function test_profile_import_is_atomic_bounded_and_preserves_local_account_ids(): void
    {
        $client = Client::factory()->create();
        $profile = ProviderProfile::factory()->create();
        $service = app(ClientProfileAssignmentService::class);

        $service->assign($client, $profile, [
            ['provider_id' => 'account-1', 'platform' => 'linkedin', 'name' => 'Primary'],
            ['provider_id' => 'account-2', 'platform' => 'facebook', 'name' => 'Secondary'],
        ]);

        $accountId = ProviderAccount::query()->where('provider_id_hash', hash('sha256', 'account-1'))->valueOrFail('id');

        $service->synchronize($client, $profile, [
            ['provider_id' => 'account-1', 'platform' => 'linkedin', 'name' => 'Renamed'],
            ['provider_id' => 'account-3', 'platform' => 'threads', 'name' => 'Third'],
        ]);

        $this->assertSame($accountId, ProviderAccount::query()->where('provider_id_hash', hash('sha256', 'account-1'))->valueOrFail('id'));
        $this->assertDatabaseHas('client_account_permissions', [
            'client_id' => $client->id,
            'provider_account_id' => $accountId,
            'can_schedule' => false,
        ]);
        $this->assertDatabaseMissing('client_account_permissions', [
            'client_id' => $client->id,
            'provider_account_id' => ProviderAccount::query()->where('provider_id_hash', hash('sha256', 'account-2'))->valueOrFail('id'),
            'is_active' => true,
        ]);
    }

    public function test_profile_import_rejects_more_than_three_hundred_accounts_without_partial_changes(): void
    {
        $client = Client::factory()->create();
        $profile = ProviderProfile::factory()->create();
        $accounts = collect(range(1, 301))->map(fn (int $id): array => [
            'provider_id' => "account-{$id}",
            'platform' => 'linkedin',
            'name' => "Account {$id}",
        ])->all();

        try {
            app(ClientProfileAssignmentService::class)->assign($client, $profile, $accounts);
            $this->fail('Expected an over-limit import to be rejected.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertSame('A provider profile may contain at most 300 accounts.', $exception->getMessage());
        }

        $this->assertDatabaseCount('client_provider_profiles', 0);
        $this->assertDatabaseCount('provider_accounts', 0);
    }

    public function test_verified_owner_can_issue_client_bound_token_with_fixed_capabilities(): void
    {
        $owner = User::factory()->create();
        $client = Client::factory()->create();
        $client->users()->attach($owner, ['role' => 'owner', 'is_active' => true]);

        $token = app(AgentTokenService::class)->issue($owner, $client, 'Codex');

        $this->assertSame($client->id, $token->accessToken->client_id);
        $this->assertSame(AgentAbility::clientTokenDefaults(), $token->accessToken->abilities);
        $this->assertNotEmpty($token->plainTextToken);
    }

    public function test_agent_token_rejects_non_owner_issuers(): void
    {
        $member = User::factory()->create();
        $client = Client::factory()->create();
        $client->users()->attach($member, ['role' => 'member', 'is_active' => true]);

        $this->expectException(\DomainException::class);
        app(AgentTokenService::class)->issue($member, $client, 'Denied');
    }
}
