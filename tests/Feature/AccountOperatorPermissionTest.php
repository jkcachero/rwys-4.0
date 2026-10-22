<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AccountOperatorPermissionTest extends TestCase
{
    use RefreshDatabase;

    protected function beforeRefreshingDatabase(): void
    {
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->assertEmpty(config('database.connections.sqlite.url'));
    }

    public function test_admin_identity_alone_never_grants_assignment_permission(): void
    {
        foreach (['test@admin.com', 'johnkarlo@admin.com'] as $email) {
            $user = User::factory()->create(['email' => $email, 'role' => 'admin']);

            $this->assertFalse($user->fresh()->can_assign_provider_accounts);
            $this->assertFalse($user->canAssignProviderAccounts());
            $this->assertFalse(Gate::forUser($user)->allows('assign-provider-accounts'));
        }

        $this->assertFalse(Gate::allows('assign-provider-accounts'));
    }

    public function test_absent_permission_attribute_fails_closed(): void
    {
        $user = User::factory()->make(['role' => 'admin']);
        unset($user->can_assign_provider_accounts);

        $this->assertFalse($user->canAssignProviderAccounts());
    }

    #[DataProvider('permissionRequirements')]
    public function test_permission_requires_verified_admin_and_explicit_flag(string $role, bool $verified, bool $flag, bool $allowed): void
    {
        $user = User::factory()->create([
            'role' => $role,
            'email_verified_at' => $verified ? now() : null,
            'can_assign_provider_accounts' => $flag,
        ]);

        $this->assertSame($flag, $user->fresh()->can_assign_provider_accounts);
        $this->assertSame($allowed, $user->canAssignProviderAccounts());
        $this->assertSame($allowed, Gate::forUser($user)->allows('assign-provider-accounts'));
    }

    /** @return array<string, array{string, bool, bool, bool}> */
    public static function permissionRequirements(): array
    {
        return [
            'operator' => ['admin', true, true, true],
            'ordinary admin' => ['admin', true, false, false],
            'unverified admin' => ['admin', false, true, false],
            'user with flag' => ['user', true, true, false],
            'unknown role' => ['operator', true, true, false],
        ];
    }

    public function test_permission_is_not_mass_assignable_or_serialized(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $this->assertFalse($user->isFillable('can_assign_provider_accounts'));
        $user->update(['name' => 'Updated profile', 'can_assign_provider_accounts' => true]);
        $this->assertFalse($user->fresh()->can_assign_provider_accounts);

        $user->forceFill(['can_assign_provider_accounts' => true])->save();
        $this->assertArrayNotHasKey('can_assign_provider_accounts', $user->toArray());
        $this->assertArrayNotHasKey('can_assign_provider_accounts', json_decode($user->toJson(), true));
    }

    public function test_gate_uses_persisted_state_for_grants_revocation_role_and_verification(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $gate = Gate::forUser($user);
        $user->can_assign_provider_accounts = true;
        $this->assertFalse($gate->allows('assign-provider-accounts'));

        $this->artisan('agent:account-operator', ['email' => $user->email])->assertSuccessful();
        $this->assertTrue($gate->allows('assign-provider-accounts'));
        $stale = $user->fresh();
        $this->artisan('agent:account-operator', ['email' => $user->email, '--revoke' => true])->assertSuccessful();
        $this->assertTrue($stale->canAssignProviderAccounts());
        $this->assertFalse(Gate::forUser($stale)->allows('assign-provider-accounts'));

        $user->forceFill(['can_assign_provider_accounts' => true])->save();
        foreach ([['role' => 'user'], ['role' => 'admin', 'email_verified_at' => null]] as $changes) {
            DB::table('users')->where('id', $user->id)->update($changes);
            $this->assertFalse($gate->allows('assign-provider-accounts'));
        }

        $user->delete();
        $this->assertFalse($gate->allows('assign-provider-accounts'));
    }

    public function test_profile_update_cannot_grant_assignment_permission(): void
    {
        $user = User::factory()->create(['role' => 'admin']);

        $this->actingAs($user)->patch(route('profile.update'), [
            'name' => 'Updated profile',
            'email' => $user->email,
            'can_assign_provider_accounts' => true,
        ])->assertSessionHasNoErrors()->assertRedirect(route('profile.edit'));

        $this->assertSame('Updated profile', $user->fresh()->name);
        $this->assertFalse($user->fresh()->can_assign_provider_accounts);
        $this->assertFalse(Gate::forUser($user)->allows('assign-provider-accounts'));
    }

    public function test_cli_grant_is_explicit_idempotent_and_preserves_other_user_attributes(): void
    {
        Log::spy();
        $operator = User::factory()->create(['email' => 'test@admin.com', 'role' => 'admin']);
        $other = User::factory()->create(['email' => 'johnkarlo@admin.com', 'role' => 'admin']);
        $before = $operator->fresh()->getAttributes();
        $otherBefore = $other->fresh()->getAttributes();

        foreach ([1, 2] as $attempt) {
            $this->artisan('agent:account-operator', ['email' => $operator->email])->assertSuccessful();
        }

        $after = $operator->fresh()->getAttributes();
        unset($before['updated_at'], $before['can_assign_provider_accounts'], $after['updated_at'], $after['can_assign_provider_accounts']);
        $this->assertSame($before, $after);
        $this->assertTrue($operator->fresh()->canAssignProviderAccounts());
        $this->assertSame($otherBefore, $other->fresh()->getAttributes());
        $this->assertDatabaseCount('users', 2);
        Log::shouldHaveReceived('info')->with('Provider account assignment permission changed.', [
            'user_id' => $operator->id, 'operation' => 'grant',
        ])->twice();
    }

    public function test_invalid_cli_grants_leave_all_users_unchanged(): void
    {
        Log::spy();
        $admin = User::factory()->create(['email' => 'exact@example.test', 'role' => 'admin']);
        $user = User::factory()->create(['role' => 'user']);
        $unverified = User::factory()->unverified()->create(['role' => 'admin']);
        $before = DB::table('users')->orderBy('id')->get()->toJson();

        foreach (['missing@example.test', '', 'EXACT@example.test', ' '.$admin->email, $admin->email.' ', $user->email, $unverified->email] as $email) {
            $this->assertSame(1, Artisan::call('agent:account-operator', ['email' => $email]));
            $this->assertStringNotContainsString($admin->email, Artisan::output());
            $this->assertSame($before, DB::table('users')->orderBy('id')->get()->toJson());
        }

        $this->assertSame(1, Artisan::call('agent:account-operator', ['email' => 'missing@example.test', '--revoke' => true]));
        $this->assertSame($before, DB::table('users')->orderBy('id')->get()->toJson());
        Log::shouldNotHaveReceived('info');
    }

    public function test_cli_revokes_even_for_unverified_non_admin_and_logs_only_safe_context(): void
    {
        Log::spy();
        $user = User::factory()->unverified()->create(['role' => 'user', 'can_assign_provider_accounts' => true]);
        $before = $user->fresh()->getAttributes();

        $this->artisan('agent:account-operator', ['email' => $user->email, '--revoke' => true])->assertSuccessful();

        $after = $user->fresh()->getAttributes();
        unset($before['updated_at'], $before['can_assign_provider_accounts'], $after['updated_at'], $after['can_assign_provider_accounts']);
        $this->assertSame($before, $after);
        $this->assertFalse($user->fresh()->can_assign_provider_accounts);
        Log::shouldHaveReceived('info')->once()->with('Provider account assignment permission changed.', [
            'user_id' => $user->id, 'operation' => 'revoke',
        ]);
    }

    public function test_database_failure_does_not_change_users_or_expose_details(): void
    {
        Log::spy();
        $user = User::factory()->create(['role' => 'admin']);
        $before = DB::table('users')->get()->toJson();
        DB::unprepared("CREATE TRIGGER reject_permission BEFORE UPDATE ON users BEGIN SELECT RAISE(ABORT, 'secret-database-detail'); END");

        $this->assertSame(1, Artisan::call('agent:account-operator', ['email' => $user->email]));

        $this->assertStringNotContainsString('secret-database-detail', Artisan::output());
        $this->assertStringNotContainsString($user->email, Artisan::output());
        $this->assertSame($before, DB::table('users')->get()->toJson());
        Log::shouldNotHaveReceived('info');
    }
}
