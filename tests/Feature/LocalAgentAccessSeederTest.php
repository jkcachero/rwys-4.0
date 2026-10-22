<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\LocalAgentAccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class LocalAgentAccessSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function beforeRefreshingDatabase(): void
    {
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->assertEmpty(config('database.connections.sqlite.url'));
    }

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->app['env'] = 'local';
    }

    public function test_default_local_seed_creates_both_verified_admins_with_separate_assignment_authority(): void
    {
        $this->seed(DatabaseSeeder::class);
        $operator = User::where('email', 'test@admin.com')->sole();
        $john = User::where('email', 'johnkarlo.315@gmail.com')->sole();

        foreach ([$operator, $john] as $user) {
            $this->assertSame('admin', $user->role);
            $this->assertTrue($user->hasVerifiedEmail());
            $this->assertTrue(Hash::check('password', $user->password));
            $this->assertNotSame('password', $user->password);
        }
        $this->assertTrue(Gate::forUser($operator)->allows('assign-provider-accounts'));
        $this->assertFalse(Gate::forUser($john)->allows('assign-provider-accounts'));
        $this->assertDatabaseHas('clients', ['owner_id' => $john->id, 'name' => 'John Karlo', 'active' => true]);
        $this->assertDatabaseCount('client_accounts', 0);
        $this->assertDatabaseCount('personal_access_tokens', 0);
        Http::assertNothingSent();
    }

    public function test_repeated_default_seeding_does_not_duplicate_users_or_clients(): void
    {
        $this->seed(DatabaseSeeder::class);
        $before = User::orderBy('id')->get()->map(fn (User $user): array => $user->getAttributes())->all();
        $client = Client::sole()->getAttributes();
        $this->travel(2)->minutes();

        $this->seed(DatabaseSeeder::class);

        $this->assertSame($before, User::orderBy('id')->get()->map(fn (User $user): array => $user->getAttributes())->all());
        $this->assertSame($client, Client::sole()->getAttributes());
        $this->assertDatabaseCount('users', 3);
        $this->assertDatabaseCount('clients', 1);
    }

    public function test_existing_credentials_security_settings_and_client_state_are_not_reset(): void
    {
        $operator = User::factory()->create(['email' => 'test@admin.com', 'name' => 'Existing operator', 'password' => 'existing-operator-password', 'role' => 'admin', 'can_assign_provider_accounts' => false]);
        $john = User::factory()->create(['email' => 'johnkarlo.315@gmail.com', 'name' => 'Existing John', 'password' => 'existing-john-password', 'role' => 'admin', 'can_assign_provider_accounts' => true]);
        $client = Client::factory()->create(['owner_id' => $john->id, 'name' => 'Existing client', 'active' => false]);
        $before = [$operator->refresh()->getAttributes(), $john->refresh()->getAttributes(), $client->refresh()->getAttributes()];
        $this->travel(2)->minutes();

        $this->seed(LocalAgentAccessSeeder::class);

        $this->assertSame($before, [$operator->fresh()->getAttributes(), $john->fresh()->getAttributes(), $client->fresh()->getAttributes()]);
        $this->assertDatabaseCount('users', 2);
        $this->assertDatabaseCount('clients', 1);
    }

    public function test_demo_principals_are_never_seeded_outside_local_environment(): void
    {
        foreach (['production', 'staging', 'testing'] as $environment) {
            $this->app['env'] = $environment;
            $this->seed(LocalAgentAccessSeeder::class);
            $this->assertDatabaseCount('users', 0);
            $this->assertDatabaseCount('clients', 0);
        }
    }

    public function test_seeded_operator_can_select_john_but_john_cannot_assign_accounts(): void
    {
        $this->seed(LocalAgentAccessSeeder::class);
        $this->withoutVite();
        $operator = User::where('email', 'test@admin.com')->sole();
        $john = User::where('email', 'johnkarlo.315@gmail.com')->sole();
        $this->actingAs($operator)->withSession(['auth.password_confirmed_at' => time()]);
        $this->get(route('provider-accounts.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('settings/provider-accounts')->has('clients.data', 1)
            ->where('clients.data.0.owner.email', $john->email));
        $this->actingAs($john)->getJson(route('provider-accounts.clients'))->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_local_seed_does_not_configure_or_enable_provider_access(): void
    {
        config(['agent-api.zernio.enabled' => false, 'agent-api.zernio.publishing_enabled' => false, 'agent-api.zernio.api_key' => 'fictional-test-key']);
        $before = config('agent-api');

        $this->seed(LocalAgentAccessSeeder::class);

        $this->assertSame($before, config('agent-api'));
        $this->assertDatabaseCount('client_accounts', 0);
        Http::assertNothingSent();
    }
}
