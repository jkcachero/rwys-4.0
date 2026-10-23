<?php

namespace Tests\Feature;

use App\Enums\AgentAbility;
use App\Models\Client;
use App\Models\ClientAccountPermission;
use App\Models\PersonalAccessToken;
use App\Models\ProviderAccount;
use App\Models\ProviderProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AgentAccessManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_client_members_can_view_only_their_agent_access_clients(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $owner = User::factory()->create([
            'name' => 'Acme Owner',
            'email' => 'owner@example.com',
            'email_verified_at' => now(),
        ]);
        $client = Client::factory()->create(['name' => 'Acme']);
        $otherClient = Client::factory()->create(['name' => 'Other']);
        $client->users()->attach($owner, ['role' => 'owner', 'is_active' => true]);
        $client->users()->attach($user, ['role' => 'member', 'is_active' => true]);
        $otherClient->users()->attach(User::factory()->create(), ['role' => 'owner', 'is_active' => true]);

        $profile = ProviderProfile::factory()->create(['name' => 'Acme Social']);
        $account = ProviderAccount::factory()->for($profile, 'profile')->create([
            'name' => 'Acme LinkedIn',
            'platform' => 'linkedin',
        ]);
        $client->providerProfiles()->attach($profile, [
            'assigned_by' => $user->id,
            'assigned_at' => now(),
        ]);
        ClientAccountPermission::factory()->create([
            'client_id' => $client->id,
            'provider_account_id' => $account->id,
        ]);

        $this->actingAs($user)->get(route('settings.agent-access.edit', ['client' => $client->public_id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('settings/agent-access')
                ->has('clients', 1)
                ->where('clients.0.name', 'Acme')
                ->where('clients.0.owner.name', 'Acme Owner')
                ->where('clients.0.owner.email', 'owner@example.com')
                ->where('selectedClient.name', 'Acme')
                ->where('selectedClient.can_manage', false)
                ->where('selectedClient.profiles.0.name', 'Acme Social')
                ->where('selectedClient.profiles.0.accounts.0.name', 'Acme LinkedIn')
                ->where('selectedClient.profiles.0.accounts.0.available', true)
                ->missing('abilities')
                ->missing('selectedClient.approved_origins')
                ->missing('selectedClient.capabilities')
                ->missing('selectedClient.profiles.0.provider')
                ->missing('selectedClient.profiles.0.accounts.0.permission')
                ->missing('token_plaintext'));
    }

    public function test_only_verified_active_owners_can_update_account_permissions(): void
    {
        $owner = User::factory()->create(['email_verified_at' => now()]);
        $member = User::factory()->create(['email_verified_at' => now()]);
        $client = Client::factory()->create();
        $client->users()->attach($owner, ['role' => 'owner', 'is_active' => true]);
        $client->users()->attach($member, ['role' => 'member', 'is_active' => true]);
        $permission = ClientAccountPermission::factory()->create(['client_id' => $client->id]);

        $this->actingAs($member)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->patch(route('settings.agent-access.permissions.update', [
                'client' => $client->public_id,
                'permission' => $permission,
            ]), [
                'is_active' => true,
                'can_schedule' => false,
            ])->assertForbidden();

        $this->actingAs($owner)
            ->withSession(['auth.password_confirmed_at' => 0])
            ->patch(route('settings.agent-access.permissions.update', [
                'client' => $client->public_id,
                'permission' => $permission,
            ]), [
                'is_active' => true,
                'can_schedule' => false,
            ])->assertRedirect(route('password.confirm'));

        $this->actingAs($owner)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->patch(route('settings.agent-access.permissions.update', [
                'client' => $client->public_id,
                'permission' => $permission,
            ]), [
                'is_active' => true,
                'can_schedule' => true,
            ])->assertRedirect();

        $this->assertTrue($permission->fresh()->can_schedule);
    }

    public function test_owner_can_issue_rotate_and_revoke_a_finite_client_bound_token(): void
    {
        $owner = User::factory()->create(['email_verified_at' => now()]);
        $client = Client::factory()->create();
        $client->users()->attach($owner, ['role' => 'owner', 'is_active' => true]);
        $permission = ClientAccountPermission::factory()->create([
            'client_id' => $client->id,
        ]);
        $account = $permission->account;

        $issueResponse = $this->actingAs($owner)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->post(route('settings.agent-access.tokens.store', $client), [
                'name' => 'Codex',
                'allowed_account_ids' => [$account->public_id],
                'expires_at' => now()->addDays(30)->toDateTimeString(),
            ])->assertRedirect();

        $issuedToken = PersonalAccessToken::query()->sole();
        $issueResponse->assertSessionHas('agent_token_plaintext');
        $this->assertSame($client->id, $issuedToken->client_id);
        $this->assertSame($owner->id, $issuedToken->issued_by);
        $this->assertSame(AgentAbility::clientTokenDefaults(), $issuedToken->abilities);
        $this->assertSame([$account->public_id], $issuedToken->allowed_account_ids);
        $this->assertNotNull($issuedToken->expires_at);

        $this->actingAs($owner)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->post(route('settings.agent-access.tokens.rotate', [
                'client' => $client,
                'token' => $issuedToken,
            ]), [
                'name' => 'Codex broadened',
                'allowed_account_ids' => [],
            ])->assertSessionHasErrors('allowed_account_ids');

        $this->assertDatabaseCount('personal_access_tokens', 1);
        $this->assertNull($issuedToken->fresh()->revoked_at);

        $rotateResponse = $this->actingAs($owner)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->post(route('settings.agent-access.tokens.rotate', [
                'client' => $client,
                'token' => $issuedToken,
            ]), [
                'name' => 'Codex rotated',
                'allowed_account_ids' => [$account->public_id],
            ])->assertRedirect()->assertSessionHas('agent_token_plaintext');

        $rotatedToken = PersonalAccessToken::query()->whereKeyNot($issuedToken->id)->sole();
        $this->assertSame($issuedToken->id, $rotatedToken->rotated_from_id);
        $this->assertTrue($issuedToken->expires_at->equalTo($rotatedToken->expires_at));
        $this->assertNotNull($issuedToken->fresh()->revoked_at);

        $this->actingAs($owner)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->delete(route('settings.agent-access.tokens.destroy', [
                'client' => $client,
                'token' => $rotatedToken,
            ]))->assertRedirect();

        $this->assertNotNull($rotatedToken->fresh()->revoked_at);
        $this->assertDatabaseCount('personal_access_tokens', 2);
    }

    public function test_token_management_rejects_unapproved_accounts_and_ignores_legacy_ability_input(): void
    {
        $owner = User::factory()->create(['email_verified_at' => now()]);
        $client = Client::factory()->create();
        $client->users()->attach($owner, ['role' => 'owner', 'is_active' => true]);
        $otherAccount = ProviderAccount::factory()->create();

        $this->actingAs($owner)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->post(route('settings.agent-access.tokens.store', $client), [
                'name' => 'Unsafe',
                'abilities' => ['admin:*'],
                'allowed_account_ids' => [$otherAccount->public_id],
                'expires_at' => now()->addDays(30)->toDateTimeString(),
            ])->assertSessionHasErrors('allowed_account_ids');

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_public_password_token_issuance_is_not_available(): void
    {
        $this->postJson('/api/sanctum/token', [
            'email' => 'user@example.com',
            'password' => 'password',
            'device_name' => 'unsafe',
        ])->assertNotFound();
    }
}
