<?php

namespace Tests\Feature;

use App\Enums\AgentAbility;
use App\Models\Client;
use App\Models\ClientAccountPermission;
use App\Models\PersonalAccessToken;
use App\Models\ProviderAccount;
use App\Models\ProviderProfile;
use App\Models\User;
use App\Services\AgentTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AgentAccessManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_roster_lists_assigned_users_and_provider_account_counts(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $owner = User::factory()->create(['name' => 'John Karlo']);
        $client = Client::factory()->create(['name' => 'John Client']);
        $unassigned = Client::factory()->create(['name' => 'Unassigned']);
        $client->users()->attach($owner, ['role' => 'owner', 'is_active' => true]);
        $unassigned->users()->attach(User::factory()->create(), ['role' => 'owner', 'is_active' => true]);
        $profile = ProviderProfile::factory()->create(['provider' => 'zernio']);
        $client->providerProfiles()->attach($profile, ['assigned_by' => $admin->id, 'assigned_at' => now()]);
        ClientAccountPermission::factory()->create([
            'client_id' => $client,
            'provider_account_id' => ProviderAccount::factory()->create(['provider_profile_id' => $profile]),
        ]);
        $adProfile = ProviderProfile::factory()->create(['provider' => 'adkit']);
        foreach ([true, false] as $active) {
            ClientAccountPermission::factory()->create([
                'client_id' => $client,
                'provider_account_id' => ProviderAccount::factory()->create(['provider_profile_id' => $adProfile, 'platform' => 'meta']),
                'is_active' => $active,
            ]);
        }

        $this->actingAs($admin)->get(route('settings.agent-access.edit'))
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('clients', 2)
            ->has('assigned_users', 1)
            ->where('assigned_users.0.id', $client->public_id)
            ->where('assigned_users.0.owner.name', 'John Karlo')
            ->where('assigned_users.0.social_account_count', 1)
            ->where('assigned_users.0.ad_account_count', 1)
            ->where('selectedClient.id', $client->public_id)
            ->where('can_assign_accounts', true)
            ->missing('assigned_users.0.tokens')
            ->missing('assigned_users.0.provider_connection'));
    }

    public function test_assigned_users_roster_does_not_expose_other_clients_to_a_regular_owner(): void
    {
        $owner = User::factory()->create();
        $own = Client::factory()->create();
        $foreign = Client::factory()->create();
        $own->users()->attach($owner, ['role' => 'owner', 'is_active' => true]);
        $foreign->users()->attach(User::factory()->create(), ['role' => 'owner', 'is_active' => true]);
        foreach ([$own, $foreign] as $client) {
            $profile = ProviderProfile::factory()->create(['provider' => 'adkit']);
            ClientAccountPermission::factory()->create([
                'client_id' => $client,
                'provider_account_id' => ProviderAccount::factory()->create(['provider_profile_id' => $profile, 'platform' => 'meta']),
            ]);
        }

        $this->actingAs($owner)->get(route('settings.agent-access.edit'))
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('clients', 1)
            ->has('assigned_users', 1)
            ->where('assigned_users.0.id', $own->public_id)
            ->where('can_assign_accounts', false));
    }

    public function test_assigned_users_are_paginated_independently_from_the_user_directory(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $clients = Client::factory()->count(26)->create();
        foreach ($clients as $client) {
            $client->users()->attach($admin, ['role' => 'owner', 'is_active' => true]);
            $profile = ProviderProfile::factory()->create(['provider' => 'zernio']);
            $client->providerProfiles()->attach($profile, ['assigned_at' => now()]);
        }

        $this->actingAs($admin)->get(route('settings.agent-access.edit'))
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('assigned_users', 25)
            ->where('assigned_users_pagination.page', 1)
            ->where('assigned_users_pagination.has_more', true));
        $this->get(route('settings.agent-access.edit', ['assigned_page' => 2]))
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('assigned_users', 1)
            ->where('assigned_users_pagination.page', 2)
            ->where('assigned_users_pagination.has_more', false));
    }

    public function test_key_management_shows_only_current_keys_and_retains_hidden_records(): void
    {
        $owner = User::factory()->create(['email_verified_at' => now()]);
        $client = Client::factory()->create();
        $otherClient = Client::factory()->create();
        foreach ([$client, $otherClient] as $ownedClient) {
            $ownedClient->users()->attach($owner, ['role' => 'owner', 'is_active' => true]);
        }
        $tokens = app(AgentTokenService::class);
        $active = $tokens->issue($owner, $client, 'Current key')->accessToken;
        $revoked = $tokens->issue($owner, $client, 'Revoked key')->accessToken;
        $tokens->revoke($owner, $client, $revoked);
        $expired = $tokens->issue($owner, $client, 'Expired key')->accessToken;
        $expired->forceFill(['expires_at' => now()->subMinute()])->save();
        $tokens->issue($owner, $otherClient, 'Other client key');

        $this->actingAs($owner)
            ->get(route('settings.agent-access.edit', ['client' => $client->public_id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('selectedClient.tokens', 1)
                ->where('selectedClient.tokens.0.id', $active->id));

        $this->withSession(['auth.password_confirmed_at' => time()])
            ->delete(route('settings.agent-access.tokens.destroy', [
                'client' => $client,
                'token' => $active,
            ]))->assertRedirect();

        $this->get(route('settings.agent-access.edit', ['client' => $client->public_id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('selectedClient.tokens', 0));

        $this->assertDatabaseCount('personal_access_tokens', 4);
        $this->assertNotNull($active->fresh()->revoked_at);
    }

    public function test_one_time_plaintext_key_is_only_shown_for_its_selected_client(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $own = Client::factory()->create();
        $other = Client::factory()->create();
        $own->users()->attach($admin, ['role' => 'owner', 'is_active' => true]);
        $other->users()->attach(User::factory()->create(), ['role' => 'owner', 'is_active' => true]);
        $flash = ['agent_token_plaintext' => 'synthetic-one-time-key', 'agent_token_client_id' => $own->public_id];

        $this->actingAs($admin)->withSession($flash)
            ->get(route('settings.agent-access.edit', ['client' => $other->public_id]))
            ->assertOk()->assertInertia(fn (Assert $page) => $page->missing('token_plaintext'));
        $this->withSession($flash)->get(route('settings.agent-access.edit', ['client' => $own->public_id]))
            ->assertOk()->assertInertia(fn (Assert $page) => $page->where('token_plaintext', 'synthetic-one-time-key'));
    }

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

    public function test_client_owners_cannot_restore_admin_revoked_adkit_permissions(): void
    {
        $owner = User::factory()->create(['email_verified_at' => now()]);
        $formerClient = Client::factory()->create();
        $currentClient = Client::factory()->create();
        $formerClient->users()->attach($owner, ['role' => 'owner', 'is_active' => true]);
        $profile = ProviderProfile::factory()->create(['provider' => 'adkit']);
        $account = ProviderAccount::factory()->for($profile, 'profile')->create();
        $revokedPermission = ClientAccountPermission::factory()->create([
            'client_id' => $formerClient->id,
            'provider_account_id' => $account->id,
            'is_active' => false,
            'can_schedule' => false,
        ]);

        $this->actingAs($owner)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->patch(route('settings.agent-access.permissions.update', [
                'client' => $formerClient->public_id,
                'permission' => $revokedPermission,
            ]), [
                'is_active' => true,
                'can_schedule' => true,
            ])->assertForbidden();

        $this->assertFalse($revokedPermission->fresh()->is_active);
        $this->assertFalse($revokedPermission->fresh()->can_schedule);

        ClientAccountPermission::factory()->create([
            'client_id' => $currentClient->id,
            'provider_account_id' => $account->id,
            'is_active' => true,
        ]);

        $this->actingAs($owner)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->patch(route('settings.agent-access.permissions.update', [
                'client' => $formerClient->public_id,
                'permission' => $revokedPermission,
            ]), [
                'is_active' => true,
                'can_schedule' => true,
            ])->assertForbidden();

        $this->assertFalse($revokedPermission->fresh()->is_active);
        $this->assertFalse($revokedPermission->fresh()->can_schedule);
        $this->assertTrue(ClientAccountPermission::query()
            ->whereBelongsTo($currentClient)
            ->whereBelongsTo($account, 'account')
            ->value('is_active'));
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
