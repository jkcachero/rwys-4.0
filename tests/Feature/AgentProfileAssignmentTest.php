<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientAccountPermission;
use App\Models\ClientOperatorGrant;
use App\Models\ProviderAccount;
use App\Models\ProviderConnection;
use App\Models\ProviderProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AgentProfileAssignmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_verified_admin_with_explicit_grant_can_discover_zernio_profiles_for_an_eligible_client(): void
    {
        [$operator, $client] = $this->operatorAndClient();
        $this->zernioConnection($operator);
        config(['services.zernio.enabled' => true]);
        Http::preventStrayRequests();
        Http::fake([
            'https://zernio.test/v1/profiles*' => Http::response([
                'profiles' => [
                    ['_id' => 'profile-1', 'name' => 'Acme Social', 'accountCount' => 4],
                ],
                'total' => 1,
                'skip' => 0,
                'limit' => 25,
            ]),
        ]);

        $this->actingAs($operator)
            ->getJson(route('settings.agent-access.assignments.profiles', $client))
            ->assertOk()
            ->assertExactJson([
                'profiles' => [
                    ['id' => 'profile-1', 'name' => 'Acme Social', 'account_count' => 4],
                ],
                'page' => 1,
                'has_more' => false,
            ]);

        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://zernio.test/v1/profiles?limit=25&skip=0');
    }

    public function test_operator_grant_exposes_the_client_and_assignment_controls_without_owner_token_controls(): void
    {
        [$operator, $client] = $this->operatorAndClient();

        $this->actingAs($operator)
            ->get(route('settings.agent-access.edit'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('clients.0.id', $client->public_id)
                ->where('clients.0.role', 'operator')
                ->where('selectedClient.id', $client->public_id)
                ->where('selectedClient.can_assign_profiles', true)
                ->where('selectedClient.can_manage', false)
                ->where('selectedClient.tokens', [])
            );
    }

    public function test_admin_role_without_active_assignment_grant_cannot_discover_or_assign_profiles(): void
    {
        [$operator, $client] = $this->operatorAndClient(granted: false);

        $this->actingAs($operator)
            ->getJson(route('settings.agent-access.assignments.profiles', $client))
            ->assertForbidden();

        $this->actingAs($operator)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->post(route('settings.agent-access.assignments.store', $client), [
                'provider_profile_id' => 'profile-1',
            ])->assertForbidden();
    }

    public function test_operator_can_atomically_assign_a_complete_profile_and_defaults_new_accounts_to_read_only(): void
    {
        [$operator, $client] = $this->operatorAndClient();
        $connection = $this->zernioConnection($operator);
        config(['services.zernio.enabled' => true]);
        Http::preventStrayRequests();
        Http::fake([
            'https://zernio.test/v1/profiles/profile-1' => Http::response([
                'profile' => ['_id' => 'profile-1', 'name' => 'Acme Social'],
            ]),
            'https://zernio.test/v1/accounts*' => Http::response([
                'accounts' => [
                    ['_id' => 'account-1', 'profileId' => 'profile-1', 'platform' => 'linkedin', 'displayName' => 'Acme LinkedIn'],
                    ['_id' => 'account-2', 'profileId' => 'profile-1', 'platform' => 'instagram', 'displayName' => 'Acme Instagram'],
                ],
                'total' => 2,
                'skip' => 0,
                'limit' => 100,
            ]),
        ]);

        $this->actingAs($operator)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->post(route('settings.agent-access.assignments.store', $client), [
                'provider_profile_id' => 'profile-1',
            ])->assertRedirect(route('settings.agent-access.edit', ['client' => $client->public_id]));

        $profile = ProviderProfile::query()->sole();
        $this->assertSame($connection->id, $profile->provider_connection_id);
        $this->assertSame('profile-1', $profile->provider_id);
        $this->assertSame('Acme Social', $profile->name);
        $this->assertSame(1, $profile->sync_generation);
        $this->assertTrue($client->providerProfiles()->whereKey($profile->id)->exists());
        $this->assertDatabaseCount('provider_accounts', 2);

        $permissions = ClientAccountPermission::query()->where('client_id', $client->id)->get();
        $this->assertCount(2, $permissions);
        $this->assertTrue($permissions->every->is_active);
        $this->assertTrue($permissions->every->can_read);
        $this->assertTrue($permissions->every(fn (ClientAccountPermission $permission): bool => ! $permission->can_publish && ! $permission->can_schedule));
    }

    public function test_assignment_rejects_an_incomplete_or_wrong_profile_snapshot_without_persisting_any_mapping(): void
    {
        [$operator, $client] = $this->operatorAndClient();
        $this->zernioConnection($operator);
        config(['services.zernio.enabled' => true]);
        Http::preventStrayRequests();
        Http::fake([
            'https://zernio.test/v1/profiles/profile-1' => Http::response([
                'profile' => ['_id' => 'profile-1', 'name' => 'Acme Social'],
            ]),
            'https://zernio.test/v1/accounts*' => Http::response([
                'accounts' => [
                    ['_id' => 'account-1', 'profileId' => 'different-profile', 'platform' => 'linkedin', 'displayName' => 'Wrong Account'],
                ],
                'total' => 1,
                'skip' => 0,
                'limit' => 100,
            ]),
        ]);

        $this->actingAs($operator)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->post(route('settings.agent-access.assignments.store', $client), [
                'provider_profile_id' => 'profile-1',
            ])->assertSessionHasErrors('provider_profile_id');

        $this->assertDatabaseCount('provider_profiles', 0);
        $this->assertDatabaseCount('provider_accounts', 0);
        $this->assertDatabaseCount('client_provider_profiles', 0);
    }

    public function test_assignment_provider_failure_returns_inertia_validation_feedback(): void
    {
        [$operator, $client] = $this->operatorAndClient();
        $this->zernioConnection($operator);
        config(['services.zernio.enabled' => true]);
        Http::preventStrayRequests();
        Http::fake([
            'https://zernio.test/v1/profiles/profile-1' => Http::response([
                'message' => 'Unexpected provider response',
            ], 502),
        ]);

        $this->actingAs($operator)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->withHeader('X-Inertia', 'true')
            ->post(route('settings.agent-access.assignments.store', $client), [
                'provider_profile_id' => 'profile-1',
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('provider_profile_id');

        $this->assertDatabaseCount('provider_profiles', 0);
        $this->assertDatabaseCount('provider_accounts', 0);
        $this->assertDatabaseCount('client_provider_profiles', 0);
    }

    public function test_sync_provider_failure_returns_inertia_validation_feedback(): void
    {
        [$operator, $client] = $this->operatorAndClient();
        $connection = $this->zernioConnection($operator);
        $profile = ProviderProfile::factory()->create([
            'provider_connection_id' => $connection->id,
            'provider_id' => 'profile-1',
            'provider_id_hash' => hash('sha256', 'profile-1'),
            'sync_generation' => 3,
        ]);
        $client->providerProfiles()->attach($profile, ['assigned_by' => $operator->id, 'assigned_at' => now()]);
        config(['services.zernio.enabled' => true]);
        Http::preventStrayRequests();
        Http::fake([
            'https://zernio.test/v1/profiles/profile-1' => Http::response([
                'message' => 'Unexpected provider response',
            ], 502),
        ]);

        $this->actingAs($operator)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->withHeader('X-Inertia', 'true')
            ->post(route('settings.agent-access.assignments.sync', [$client, $profile]))
            ->assertRedirect()
            ->assertSessionHasErrors('provider_profile_id');

        $this->assertSame(3, $profile->fresh()->sync_generation);
    }

    public function test_sync_preserves_disabled_permissions_and_deactivates_removed_accounts(): void
    {
        [$operator, $client] = $this->operatorAndClient();
        $connection = $this->zernioConnection($operator);
        $profile = ProviderProfile::factory()->create([
            'provider_connection_id' => $connection->id,
            'provider_id' => 'profile-1',
            'provider_id_hash' => hash('sha256', 'profile-1'),
            'sync_generation' => 3,
        ]);
        $client->providerProfiles()->attach($profile, ['assigned_by' => $operator->id, 'assigned_at' => now()]);
        $kept = ProviderAccount::factory()->for($profile, 'profile')->create([
            'provider_id' => 'account-1',
            'provider_id_hash' => hash('sha256', 'account-1'),
            'is_active' => false,
        ]);
        $removed = ProviderAccount::factory()->for($profile, 'profile')->create([
            'provider_id' => 'account-2',
            'provider_id_hash' => hash('sha256', 'account-2'),
            'is_active' => true,
        ]);
        $keptPermission = ClientAccountPermission::factory()->create([
            'client_id' => $client->id,
            'provider_account_id' => $kept->id,
            'is_active' => false,
            'can_read' => false,
            'can_publish' => false,
            'can_schedule' => false,
        ]);
        $removedPermission = ClientAccountPermission::factory()->create([
            'client_id' => $client->id,
            'provider_account_id' => $removed->id,
            'is_active' => true,
            'can_read' => true,
            'can_publish' => true,
        ]);
        config(['services.zernio.enabled' => true]);
        Http::preventStrayRequests();
        Http::fake([
            'https://zernio.test/v1/profiles/profile-1' => Http::response([
                'profile' => ['_id' => 'profile-1', 'name' => 'Renamed Social'],
            ]),
            'https://zernio.test/v1/accounts*' => Http::response([
                'accounts' => [
                    ['_id' => 'account-1', 'profileId' => 'profile-1', 'platform' => 'linkedin', 'displayName' => 'Renamed LinkedIn'],
                    ['_id' => 'account-3', 'profileId' => 'profile-1', 'platform' => 'threads', 'displayName' => 'New Threads'],
                ],
                'total' => 2,
                'skip' => 0,
                'limit' => 100,
            ]),
        ]);

        $this->actingAs($operator)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->post(route('settings.agent-access.assignments.sync', [$client, $profile]))
            ->assertRedirect(route('settings.agent-access.edit', ['client' => $client->public_id]));

        $this->assertFalse($kept->fresh()->is_active);
        $this->assertFalse($keptPermission->fresh()->is_active);
        $this->assertFalse($keptPermission->fresh()->can_read);
        $this->assertFalse($removed->fresh()->is_active);
        $this->assertFalse($removedPermission->fresh()->is_active);
        $this->assertTrue($removedPermission->fresh()->can_publish);
        $this->assertSame(4, $profile->fresh()->sync_generation);

        $newAccount = ProviderAccount::query()->where('provider_id_hash', hash('sha256', 'account-3'))->sole();
        $newPermission = ClientAccountPermission::query()->where('provider_account_id', $newAccount->id)->sole();
        $this->assertTrue($newAccount->is_active);
        $this->assertTrue($newPermission->is_active);
        $this->assertTrue($newPermission->can_read);
        $this->assertFalse($newPermission->can_publish);
    }

    /** @return array{User, Client} */
    private function operatorAndClient(bool $granted = true): array
    {
        $operator = User::factory()->create([
            'role' => 'admin',
            'email_verified_at' => now(),
        ]);
        $owner = User::factory()->create(['email_verified_at' => now()]);
        $client = Client::factory()->create(['is_active' => true]);
        $client->users()->attach($owner, ['role' => 'owner', 'is_active' => true]);

        if ($granted) {
            ClientOperatorGrant::factory()->create([
                'client_id' => $client->id,
                'user_id' => $operator->id,
                'granted_by' => $owner->id,
                'is_active' => true,
            ]);
        }

        return [$operator, $client];
    }

    private function zernioConnection(User $operator): ProviderConnection
    {
        config(['services.zernio.base_url' => 'https://zernio.test']);

        return $operator->providerConnections()->create([
            'provider' => 'zernio',
            'access_token' => 'secret-token',
            'access_token_expires_at' => now()->addHour(),
            'scopes' => [],
        ]);
    }
}
