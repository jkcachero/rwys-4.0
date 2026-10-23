<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientOperatorGrant;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\LocalAgentAccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class LocalAgentAccessSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_seeds_the_required_local_personas_and_owned_client(): void
    {
        $this->runSeederInLocalEnvironment();

        $operator = User::query()->where('email', 'test@admin.com')->firstOrFail();
        $owner = User::query()->where('email', 'johnkarlo.315@gmail.com')->firstOrFail();
        $client = Client::query()->where('public_id', LocalAgentAccessSeeder::CLIENT_PUBLIC_ID)->firstOrFail();

        $this->assertSame('admin', $operator->role);
        $this->assertNotNull($operator->email_verified_at);
        $this->assertTrue(Hash::check('password', $operator->password));
        $this->assertSame('admin', $owner->role);
        $this->assertNotNull($owner->email_verified_at);
        $this->assertTrue(Hash::check('password', $owner->password));
        $this->assertTrue($client->hasActiveOwner($owner));
        $this->assertFalse($client->hasActiveOwner($operator));
        $this->assertDatabaseHas('client_operator_grants', [
            'client_id' => $client->id,
            'user_id' => $operator->id,
            'granted_by' => $owner->id,
            'is_active' => true,
        ]);
        $this->assertDatabaseMissing('client_operator_grants', [
            'client_id' => $client->id,
            'user_id' => $owner->id,
        ]);
        $this->assertDatabaseCount('provider_profiles', 0);
        $this->assertDatabaseCount('provider_accounts', 0);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_reruns_preserve_existing_security_and_revoked_fixture_state(): void
    {
        $this->runSeederInLocalEnvironment();

        $operator = User::query()->where('email', 'test@admin.com')->firstOrFail();
        $owner = User::query()->where('email', 'johnkarlo.315@gmail.com')->firstOrFail();
        $client = Client::query()->where('public_id', LocalAgentAccessSeeder::CLIENT_PUBLIC_ID)->firstOrFail();
        $operatorPassword = Hash::make('operator-changed-password');
        $ownerPassword = Hash::make('owner-changed-password');

        $operator->forceFill([
            'password' => $operatorPassword,
            'role' => 'user',
            'email_verified_at' => null,
            'two_factor_secret' => 'operator-two-factor-secret',
        ])->save();
        $owner->forceFill([
            'password' => $ownerPassword,
            'two_factor_secret' => 'owner-two-factor-secret',
        ])->save();
        $client->forceFill([
            'name' => 'Renamed Client',
            'approved_origins' => ['https://changed.example'],
            'is_active' => false,
        ])->save();
        $client->users()->updateExistingPivot($owner->id, ['is_active' => false]);
        ClientOperatorGrant::query()->whereBelongsTo($client)->whereBelongsTo($operator)->update(['is_active' => false]);

        $this->runSeederInLocalEnvironment();

        $operator->refresh();
        $owner->refresh();
        $client->refresh();

        $this->assertSame(2, User::query()->count());
        $this->assertSame(1, Client::query()->count());
        $this->assertSame($operatorPassword, $operator->password);
        $this->assertSame('user', $operator->role);
        $this->assertNull($operator->email_verified_at);
        $this->assertSame('operator-two-factor-secret', $operator->two_factor_secret);
        $this->assertSame($ownerPassword, $owner->password);
        $this->assertSame('owner-two-factor-secret', $owner->two_factor_secret);
        $this->assertSame('Renamed Client', $client->name);
        $this->assertSame(['https://changed.example'], $client->approved_origins);
        $this->assertFalse($client->is_active);
        $this->assertFalse((bool) $client->users()->whereKey($owner->id)->firstOrFail()->pivot->is_active);
        $this->assertFalse(ClientOperatorGrant::query()->whereBelongsTo($client)->whereBelongsTo($operator)->firstOrFail()->is_active);
    }

    public function test_it_does_not_create_demo_principals_outside_local_environment(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('clients', 0);
        $this->assertDatabaseCount('client_operator_grants', 0);
    }

    private function runSeederInLocalEnvironment(): void
    {
        $environment = $this->app->environment();
        $this->app->detectEnvironment(fn (): string => 'local');

        try {
            $this->seed(DatabaseSeeder::class);
        } finally {
            $this->app->detectEnvironment(fn (): string => $environment);
        }
    }
}
