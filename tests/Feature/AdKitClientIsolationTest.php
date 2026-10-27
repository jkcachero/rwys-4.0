<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientAccountPermission;
use App\Models\ProviderAccount;
use App\Models\ProviderConnection;
use App\Models\ProviderProfile;
use App\Models\User;
use App\Services\AgentTokenService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Sanctum\NewAccessToken;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class AdKitClientIsolationTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const string BASE = 'https://app.adkit.so/api/v1';

    private const string BROKER = '/api/v1/manage/platform-api-requests';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.adkit' => [
            'api_key' => 'synthetic-isolation-secret',
            'project_id' => 'shared-isolation-project', 'meta_account_id' => '999',
            'base_url' => self::BASE, 'timeout' => 10, 'connect_timeout' => 3,
            'max_response_bytes' => 100000,
        ]]);
        Http::preventStrayRequests();
    }

    public function test_admin_assigns_two_clients_to_one_project_without_project_links_or_operator_grants(): void
    {
        [$admin, $john, , $charlie, $johnAccount, $charlieAccount] = $this->assignedClients();

        $this->assertDatabaseCount('provider_connections', 1);
        $this->assertDatabaseCount('provider_profiles', 1);
        $this->assertDatabaseCount('provider_accounts', 2);
        $this->assertDatabaseCount('client_provider_profiles', 0);
        $this->assertDatabaseCount('client_operator_grants', 0);
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertSame($johnAccount->provider_profile_id, $charlieAccount->provider_profile_id);
        $this->assertSame('shared-isolation-project', ProviderProfile::query()->sole()->provider_id);
        $connection = ProviderConnection::query()->sole();
        $this->assertSame('act_999', $connection->subject);
        $this->assertSame('shared-isolation-project', $connection->client_id);
        foreach ([[$john, $johnAccount], [$charlie, $charlieAccount]] as [$client, $account]) {
            $this->assertDatabaseHas('client_account_permissions', [
                'client_id' => $client->id, 'provider_account_id' => $account->id,
                'is_active' => true, 'can_schedule' => false,
            ]);
        }
        $this->assertDatabaseMissing('client_account_permissions', ['client_id' => $john->id, 'provider_account_id' => $charlieAccount->id]);
        $this->assertDatabaseMissing('client_account_permissions', ['client_id' => $charlie->id, 'provider_account_id' => $johnAccount->id]);
        $this->assertTrue($admin->isAdmin());
        Http::assertSentCount(4);
        Http::assertSent(fn (Request $request): bool => $request->url() === self::BASE.'/manage/platform-api-requests'
            && $request['endpoint'] === '/act_456'
            && $request['accountId'] === 'act_999' && $request['method'] === 'GET');

        $this->fakeProvider();
        $this->getJson($this->assignmentPath($john))->assertOk()->assertJsonCount(2, 'accounts')
            ->assertJsonPath('accounts.0.id', 'act_123')
            ->assertJsonPath('accounts.0.assigned_to_current_client', true)
            ->assertJsonPath('accounts.1.id', 'act_456')
            ->assertJsonPath('accounts.1.assigned_elsewhere', true);
        Http::assertSentCount(1);
        $this->assertDatabaseCount('client_provider_profiles', 0);
    }

    public function test_admin_owner_client_bearer_reads_only_john_and_charlie_target_returns_404_without_http(): void
    {
        [$admin, $john, , , $johnAccount, $charlieAccount] = $this->assignedClients();
        $token = $this->issue($admin, $john, [$johnAccount->public_id]);
        $this->asBearer($token);
        $this->fakeProvider();

        $this->getJson('/api/v1/capabilities')->assertOk()->assertJsonPath('capabilities', ['context.read', 'adkit.read']);
        Http::assertNothingSent();
        $this->postJson(self::BROKER, $this->report('act_123'))->assertOk()
            ->assertJsonPath('response.data', [['account_id' => '123', 'spend' => '12.30']])
            ->assertJsonPath('results.0.raw.data', [['account_id' => '123', 'spend' => '12.30']]);
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request): bool => $request->url() === self::BASE.'/manage/platform-api-requests'
            && $request['endpoint'] === '/act_123/insights'
            && $request['method'] === 'GET' && $request['accountId'] === 'act_999'
            && $request->hasHeader('Authorization', 'Bearer synthetic-isolation-secret')
            && $request->hasHeader('x-adkit-project-id', 'shared-isolation-project'));

        // Deliberately know both public IDs: even a global admin's Client key cannot cross Clients.
        $token->accessToken->update(['allowed_account_ids' => [$johnAccount->public_id, $charlieAccount->public_id]]);
        $this->fakeProvider();
        $this->postJson(self::BROKER, $this->report('act_456'))->assertNotFound()->assertJsonMissingPath('response');
        Http::assertNothingSent();
    }

    public function test_conflicting_active_client_claims_fail_closed_without_provider_requests(): void
    {
        [$admin, $john, , $charlie, $johnAccount] = $this->assignedClients();
        $token = $this->issue($admin, $john, [$johnAccount->public_id]);
        ClientAccountPermission::factory()->create([
            'client_id' => $charlie,
            'provider_account_id' => $johnAccount,
        ]);
        $this->actingAs($admin)->get(route('settings.agent-access.edit', ['client' => $john->public_id]))
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('selectedClient.adkit_accounts.0.available', false));
        $this->asBearer($token);
        $this->fakeProvider();

        $this->getJson('/api/v1/capabilities')->assertOk()->assertJsonPath('capabilities', ['context.read']);
        $this->postJson(self::BROKER, $this->report('act_123'))->assertNotFound()->assertJsonMissingPath('response');

        Http::assertNothingSent();
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function test_duplicate_raw_account_claims_across_profiles_return_404_for_every_client_until_conflict_removed(bool $differentProject): void
    {
        [$admin, $john, $owner, $charlie, $johnAccount] = $this->assignedClients();
        $connection = $owner->providerConnections()->create([
            'provider' => 'adkit', 'client_id' => $differentProject ? 'other-project' : 'shared-isolation-project',
            'subject' => 'act_999', 'access_token' => 'synthetic-duplicate-secret', 'scopes' => ['read'],
        ]);
        $profile = ProviderProfile::factory()->create([
            'provider' => 'adkit', 'provider_connection_id' => $connection,
            'provider_id' => $connection->client_id, 'is_active' => true,
        ]);
        $duplicate = ProviderAccount::factory()->create([
            'provider_profile_id' => $profile, 'platform' => 'meta',
            'provider_id' => 'act_123', 'provider_id_hash' => hash('sha256', 'act_123'), 'is_active' => true,
        ]);
        $foreign = ClientAccountPermission::factory()->create(['client_id' => $charlie, 'provider_account_id' => $duplicate]);
        $johnKey = $this->issue($admin, $john, [$johnAccount->public_id]);
        $charlieKey = $this->issue($owner, $charlie, [$duplicate->public_id]);
        foreach ([$johnKey, $charlieKey] as $key) {
            $key->accessToken->update(['allowed_account_ids' => [$johnAccount->public_id, $duplicate->public_id]]);
        }
        $this->fakeProvider();

        foreach ([$johnKey, $charlieKey] as $key) {
            $this->asBearer($key);
            $this->postJson(self::BROKER, $this->report('act_123'))->assertNotFound()->assertJsonMissingPath('response');
            $this->getJson('/api/v1/capabilities')->assertOk()->assertJsonPath('capabilities', ['context.read']);
        }
        Http::assertNothingSent();

        $foreign->update(['is_active' => false]);
        $john->accountPermissions()->where('provider_account_id', $johnAccount->id)->update(['is_active' => false]);
        foreach ([$johnKey, $charlieKey] as $key) {
            $this->asBearer($key);
            $this->postJson(self::BROKER, $this->report('act_123'))->assertNotFound();
            $this->getJson('/api/v1/capabilities')->assertOk()->assertJsonPath('capabilities', ['context.read']);
        }
        Http::assertNothingSent();

        $john->accountPermissions()->where('provider_account_id', $johnAccount->id)->update(['is_active' => true]);
        $this->asBearer($johnKey);
        $this->getJson('/api/v1/capabilities')->assertOk()->assertJsonPath('capabilities', ['context.read', 'adkit.read']);
        $this->postJson(self::BROKER, $this->report('act_123'))->assertOk()->assertJsonPath('response.data.0.account_id', '123');
        Http::assertSentCount(2);
        $this->assertDatabaseCount('client_provider_profiles', 0);
    }

    public function test_charlie_client_bearer_reads_own_account_but_john_target_returns_404_without_http(): void
    {
        [, , $owner, $charlie, $johnAccount, $charlieAccount] = $this->assignedClients();
        $token = $this->issue($owner, $charlie, [$charlieAccount->public_id]);
        $this->asBearer($token);
        $this->fakeProvider();

        $this->getJson('/api/v1/capabilities')->assertOk()->assertJsonPath('capabilities', ['context.read', 'adkit.read']);
        $this->postJson(self::BROKER, $this->report('act_456'))->assertOk()
            ->assertJsonPath('response.data', [['account_id' => '456', 'spend' => '45.60']]);
        Http::assertSentCount(2);

        $token->accessToken->update(['allowed_account_ids' => [$johnAccount->public_id, $charlieAccount->public_id]]);
        $this->fakeProvider();
        $this->postJson(self::BROKER, $this->report('act_123'))->assertNotFound()->assertJsonMissingPath('response');
        Http::assertNothingSent();
    }

    public function test_unassigned_client_knowing_all_ids_and_project_link_alone_receive_404_without_http(): void
    {
        [$admin, , , , $johnAccount, $charlieAccount] = $this->assignedClients();
        $unassigned = Client::factory()->create(['is_active' => true]);
        $unassigned->users()->attach($admin, ['role' => 'owner', 'is_active' => true]);
        $token = $this->issue($admin, $unassigned);
        $token->accessToken->update(['allowed_account_ids' => [$johnAccount->public_id, $charlieAccount->public_id]]);
        $this->asBearer($token);
        $this->fakeProvider();

        foreach (['act_123', 'act_456'] as $id) {
            $this->postJson(self::BROKER, $this->report($id))->assertNotFound()->assertJsonMissingPath('response');
        }
        $this->getJson('/api/v1/capabilities')->assertOk()->assertJsonPath('capabilities', ['context.read']);
        Http::assertNothingSent();

        $unassigned->providerProfiles()->attach($johnAccount->provider_profile_id, ['assigned_by' => $admin->id, 'assigned_at' => now()]);
        foreach (['act_123', 'act_456'] as $id) {
            $this->postJson(self::BROKER, $this->report($id))->assertNotFound()->assertJsonMissingPath('response');
        }
        $this->getJson('/api/v1/capabilities')->assertOk()->assertJsonPath('capabilities', ['context.read']);
        $this->assertDatabaseMissing('client_account_permissions', ['client_id' => $unassigned->id]);
        Http::assertNothingSent();
    }

    public function test_admin_removal_disables_only_john_pair_and_old_keys_immediately_reflect_access_without_rotation(): void
    {
        [$admin, $john, $owner, $charlie, $johnAccount, $charlieAccount] = $this->assignedClients();
        $johnKey = $this->issue($admin, $john, [$johnAccount->public_id]);
        $charlieKey = $this->issue($owner, $charlie, [$charlieAccount->public_id]);
        $johnMetadata = $johnKey->accessToken->fresh()->getRawOriginal();
        $charlieMetadata = $charlieKey->accessToken->fresh()->getRawOriginal();
        $charliePermission = $charlie->accountPermissions()->sole()->getRawOriginal();
        $this->fakeProvider();

        $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => time()])
            ->delete($this->assignmentPath($john).'/'.$johnAccount->public_id)
            ->assertRedirect(route('settings.agent-access.edit', ['client' => $john->public_id]));

        $this->assertDatabaseHas('client_account_permissions', ['client_id' => $john->id, 'provider_account_id' => $johnAccount->id, 'is_active' => false]);
        $this->assertSame($charliePermission, $charlie->accountPermissions()->sole()->getRawOriginal());
        $this->assertSame($johnMetadata, $johnKey->accessToken->fresh()->getRawOriginal());
        $this->assertSame($charlieMetadata, $charlieKey->accessToken->fresh()->getRawOriginal());
        Http::assertNothingSent();

        $this->asBearer($johnKey);
        $this->postJson(self::BROKER, $this->report('act_123'))->assertNotFound();
        $this->getJson('/api/v1/capabilities')->assertOk()->assertJsonPath('capabilities', ['context.read']);
        Http::assertNothingSent();
        $this->asBearer($charlieKey);
        $this->getJson('/api/v1/capabilities')->assertOk()->assertJsonPath('capabilities', ['context.read', 'adkit.read']);
        $this->postJson(self::BROKER, $this->report('act_456'))->assertOk()
            ->assertJsonPath('response.data.0.account_id', '456');
        Http::assertSentCount(2);
        $this->assertDatabaseCount('personal_access_tokens', 2);
        $this->assertNull($johnKey->accessToken->fresh()->revoked_at);
        $this->assertNull($charlieKey->accessToken->fresh()->revoked_at);
        $this->assertNull($johnKey->accessToken->fresh()->rotated_from_id);
        $this->assertNull($charlieKey->accessToken->fresh()->rotated_from_id);
        $this->assertDatabaseCount('client_provider_profiles', 0);
    }

    public function test_admin_selected_client_shows_only_its_registered_public_account_metadata_and_not_foreign_tokens(): void
    {
        [$admin, $john, $owner, $charlie, $johnAccount, $charlieAccount] = $this->assignedClients();
        $foreignKey = $this->issue($owner, $charlie, [$charlieAccount->public_id]);
        $this->fakeProvider();

        $johnResponse = $this->actingAs($admin)->get(route('settings.agent-access.edit', ['client' => $john->public_id]))
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('selectedClient.id', $john->public_id)
            ->where('selectedClient.can_assign_adkit_accounts', true)
            ->where('selectedClient.adkit_accounts', [['id' => $johnAccount->public_id, 'name' => 'John ads', 'platform' => 'meta', 'available' => true]])
            ->missing('token_plaintext'));
        $this->assertStringNotContainsString('Charlie ads', json_encode($johnResponse->inertiaProps(), JSON_THROW_ON_ERROR));
        $response = $this->get(route('settings.agent-access.edit', ['client' => $charlie->public_id]))
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('clients', 2)
            ->where('selectedClient.id', $charlie->public_id)
            ->where('selectedClient.can_manage', false)
            ->where('selectedClient.can_assign_adkit_accounts', true)
            ->where('selectedClient.adkit_accounts.0.id', $charlieAccount->public_id)
            ->where('selectedClient.tokens', [])
            ->missing('token_plaintext'));

        $props = json_encode($response->inertiaProps(), JSON_THROW_ON_ERROR);
        foreach ([$foreignKey->plainTextToken, $foreignKey->accessToken->token, 'synthetic-isolation-secret', 'shared-isolation-project'] as $secret) {
            $this->assertStringNotContainsString($secret, $props);
        }
        $this->assertFalse($charlie->users()->whereKey($admin->id)->exists());
        $this->assertDatabaseCount('client_operator_grants', 0);
        Http::assertNothingSent();
    }

    public function test_admin_can_select_a_client_outside_the_first_directory_page(): void
    {
        [$admin, , , $charlie] = $this->assignedClients();
        $clients = Client::factory()->count(101)->create(['name' => 'A directory client']);
        foreach ($clients as $client) {
            $client->users()->attach($admin, ['role' => 'owner', 'is_active' => true]);
        }

        $this->actingAs($admin)->get(route('settings.agent-access.edit', ['client' => $charlie->public_id]))
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('selectedClient.id', $charlie->public_id)
            ->where('client_pagination.page', 1)
            ->where('client_pagination.has_more', true));
        $this->get(route('settings.agent-access.edit', ['clients_page' => 2]))
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('client_pagination.page', 2)
            ->where('client_pagination.has_more', false));
    }

    public function test_unavailable_adkit_connections_are_not_offered_as_available_token_accounts(): void
    {
        [$admin, $john, , , $account] = $this->assignedClients();
        $account->profile->providerConnection->update(['access_token_expires_at' => now()->subMinute()]);

        $this->actingAs($admin)->get(route('settings.agent-access.edit', ['client' => $john->public_id]))
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('selectedClient.adkit_accounts.0.id', $account->public_id)
            ->where('selectedClient.adkit_accounts.0.available', false));
    }

    public function test_regular_owner_views_only_own_assigned_metadata_without_admin_controls_or_foreign_account_name(): void
    {
        [, , $owner, $charlie, , $charlieAccount] = $this->assignedClients();
        $this->fakeProvider();

        $response = $this->actingAs($owner)->get(route('settings.agent-access.edit', ['client' => $charlie->public_id]))
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('clients', 1)
            ->where('selectedClient.id', $charlie->public_id)
            ->where('selectedClient.can_manage', true)
            ->where('selectedClient.can_assign_adkit_accounts', false)
            ->where('selectedClient.adkit_accounts', [['id' => $charlieAccount->public_id, 'name' => 'Charlie ads', 'platform' => 'meta', 'available' => true]])
            ->missing('token_plaintext'));

        $this->assertStringNotContainsString('John ads', json_encode($response->inertiaProps(), JSON_THROW_ON_ERROR));
        Http::assertNothingSent();
    }

    /** @return array{User, Client, User, Client, ProviderAccount, ProviderAccount} */
    private function assignedClients(): array
    {
        $admin = User::factory()->create(['name' => 'John', 'role' => 'admin', 'email_verified_at' => now()]);
        $owner = User::factory()->create(['name' => 'Charlie', 'role' => 'user', 'email_verified_at' => now()]);
        $john = Client::factory()->create(['name' => 'John Client', 'is_active' => true]);
        $charlie = Client::factory()->create(['name' => 'Charlie Client', 'is_active' => true]);
        $john->users()->attach($admin, ['role' => 'owner', 'is_active' => true]);
        $charlie->users()->attach($owner, ['role' => 'owner', 'is_active' => true]);
        $this->fakeProvider();

        $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => time()]);
        foreach ([[$john, 'act_123'], [$charlie, 'act_456']] as [$client, $id]) {
            $this->post($this->assignmentPath($client), ['ad_account_id' => $id])
                ->assertRedirect(route('settings.agent-access.edit', ['client' => $client->public_id]))
                ->assertSessionHasNoErrors();
        }

        return [$admin, $john, $owner, $charlie,
            ProviderAccount::query()->where('provider_id_hash', hash('sha256', 'act_123'))->sole(),
            ProviderAccount::query()->where('provider_id_hash', hash('sha256', 'act_456'))->sole()];
    }

    /** @param list<string> $accountIds */
    private function issue(User $owner, Client $client, array $accountIds = []): NewAccessToken
    {
        $token = app(AgentTokenService::class)->issue($owner, $client, 'Isolation key', allowedAccountIds: $accountIds);
        $token->accessToken->update(['abilities' => ['context.read', 'adkit.read']]);

        return $token;
    }

    private function asBearer(NewAccessToken $token): void
    {
        $this->app['auth']->forgetGuards();
        $this->app['session']->flush();
        $this->withToken($token->plainTextToken);
    }

    private function assignmentPath(Client $client): string
    {
        return '/settings/agent-access/'.$client->public_id.'/adkit-accounts';
    }

    /** @return array<string, mixed> */
    private function report(string $accountId): array
    {
        return ['platform' => 'meta', 'endpoint' => '/'.$accountId.'/insights', 'method' => 'GET', 'description' => 'Read isolated account statistics',
            'payload' => ['fields' => 'account_id,spend', 'date_preset' => 'last_30d', 'level' => 'account', 'limit' => 25]];
    }

    private function fakeProvider(): void
    {
        Http::fake([
            // The transport closes response streams, so every discovery needs a fresh response.
            self::BASE.'/manage/meta/accounts' => fn () => Http::response(['accounts' => [
                ['id' => 'act_123', 'platform' => 'meta', 'name' => 'John ads', 'currency' => 'USD', 'status' => 1],
                ['id' => 'act_456', 'platform' => 'meta', 'name' => 'Charlie ads', 'currency' => 'USD', 'status' => 1],
            ]]),
            self::BASE.'/manage/platform-api-requests' => function (Request $request) {
                $id = explode('/', ltrim($request['endpoint'], '/'))[0];
                $this->assertContains($id, ['act_123', 'act_456']);
                $this->assertSame('GET', $request['method']);
                $identity = ['id' => $id, 'account_id' => substr($id, 4),
                    'name' => $id === 'act_123' ? 'John ads' : 'Charlie ads',
                    'account_status' => 1, 'disable_reason' => 0, 'currency' => 'USD', 'timezone_name' => 'America/New_York'];
                $row = str_ends_with($request['endpoint'], '/insights')
                    ? ['account_id' => substr($id, 4), 'spend' => $id === 'act_123' ? '12.30' : '45.60']
                    : $identity;
                $projected = array_intersect_key($row, array_flip(explode(',', $request['payload']['fields'])));
                $raw = str_ends_with($request['endpoint'], '/insights') ? ['data' => [$projected]] : $projected;

                return Http::response(['platform' => 'meta', 'status' => 'executed', 'response' => $raw,
                    'results' => [['platformId' => '', 'action' => 'created', 'raw' => $raw]]]);
            },
        ]);
    }
}
