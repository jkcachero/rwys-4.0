<?php

namespace Tests\Feature;

use App\Exceptions\AdKit\AdKitRequestException;
use App\Http\Requests\Settings\AssignAdKitAccountRequest;
use App\Models\Client;
use App\Models\ClientAccountPermission;
use App\Models\ProviderAccount;
use App\Models\ProviderConnection;
use App\Models\ProviderProfile;
use App\Models\User;
use App\Services\AdKitAccountAssignmentService;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class AdKitAccountAssignmentTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const string BASE = 'https://app.adkit.so/api/v1';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.adkit' => [
            'api_key' => 'synthetic-secret', 'project_id' => 'internal-project',
            'meta_account_id' => '123', 'base_url' => self::BASE,
            'timeout' => 10, 'connect_timeout' => 3, 'max_response_bytes' => 100000,
        ]]);
        Http::preventStrayRequests();
    }

    public function test_discovery_is_read_only_and_exposes_only_account_metadata(): void
    {
        [$admin, $client] = $this->context();
        $this->fakeProvider();
        DB::enableQueryLog();

        $result = $this->service()->discover($admin, $client);

        $this->assertSame(['accounts' => [
            ['id' => 'act_456', 'name' => 'John account', 'currency' => 'USD', 'status' => 1, 'assigned_to_current_client' => false, 'assigned_elsewhere' => false],
            ['id' => 'act_789', 'name' => 'Charlie account', 'currency' => 'USD', 'status' => 1, 'assigned_to_current_client' => false, 'assigned_elsewhere' => false],
        ]], $result);
        foreach (DB::getQueryLog() as $query) {
            $this->assertDoesNotMatchRegularExpression('/\A\s*(insert|update|delete)/i', $query['query']);
        }
        DB::disableQueryLog();
        $this->assertNoMappings();
        Http::assertSentCount(1);
    }

    public function test_assigns_exact_target_without_grants_or_project_pivot_and_logs_only_public_identifiers(): void
    {
        [$admin, $client] = $this->context();
        $this->fakeProvider();
        Log::spy();

        $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => time()])
            ->post(route('settings.agent-access.adkit-accounts.store', $client), ['ad_account_id' => 'act_456'])
            ->assertRedirect(route('settings.agent-access.edit', ['client' => $client->public_id]))->assertSessionHas('success');
        $account = ProviderAccount::query()->sole();

        $this->assertSame('act_456', $account->provider_id);
        $this->assertSame('Verified account', $account->name);
        $connection = ProviderConnection::query()->sole();
        $this->assertSame('act_123', $connection->subject);
        $this->assertSame('internal-project', $connection->client_id);
        $this->assertSame('synthetic-secret', $connection->access_token);
        $this->assertSame('internal-project', $account->profile->provider_id);
        $this->assertDatabaseCount('provider_accounts', 1);
        $this->assertDatabaseCount('client_provider_profiles', 0);
        $this->assertDatabaseCount('client_operator_grants', 0);
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertDatabaseHas('client_account_permissions', ['client_id' => $client->id, 'provider_account_id' => $account->id, 'is_active' => true, 'can_schedule' => false]);
        Log::shouldHaveReceived('info')->once()->with('AdKit account assigned.', ['actor_id' => $admin->id, 'client_id' => $client->public_id, 'account_id' => $account->public_id]);
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request): bool => $request->url() === self::BASE.'/manage/platform-api-requests'
            && $request['accountId'] === 'act_123' && $request['endpoint'] === '/act_456' && $request['method'] === 'GET'
            && $request['payload']['fields'] === 'id,name,account_id,account_status,disable_reason,currency,timezone_name');
    }

    public function test_two_clients_share_one_project_indirectly_but_not_account_permissions(): void
    {
        [$admin, $john] = $this->context();
        [$otherAdmin, $charlie] = $this->context();
        $this->fakeProvider();

        $johnAccount = $this->service()->assign($admin, $john, 'act_456');
        $charlieAccount = $this->service()->assign($otherAdmin, $charlie, 'act_789');

        $this->assertSame($johnAccount->provider_profile_id, $charlieAccount->provider_profile_id);
        $this->assertDatabaseCount('provider_connections', 1);
        $this->assertDatabaseCount('provider_profiles', 1);
        $this->assertDatabaseCount('client_provider_profiles', 0);
        $this->assertDatabaseCount('client_account_permissions', 2);
        $this->assertDatabaseMissing('client_account_permissions', ['client_id' => $john->id, 'provider_account_id' => $charlieAccount->id]);
        $this->assertDatabaseMissing('client_account_permissions', ['client_id' => $charlie->id, 'provider_account_id' => $johnAccount->id]);
        $discovery = $this->service()->discover($admin, $john);
        $this->assertTrue($discovery['accounts'][0]['assigned_to_current_client']);
        $this->assertTrue($discovery['accounts'][1]['assigned_elsewhere']);
        Http::assertSentCount(5);
    }

    public function test_foreign_active_claim_is_rejected_before_http(): void
    {
        [$admin, $john] = $this->context();
        [, $charlie] = $this->context();
        [, , $account] = $this->mapping($admin);
        ClientAccountPermission::factory()->create(['client_id' => $charlie, 'provider_account_id' => $account]);

        try {
            $this->service()->assign($admin, $john, 'act_456');
            $this->fail('A foreign active claim was accepted.');
        } catch (AdKitRequestException $exception) {
            $this->assertSame('adkit_mapping_invalid', $exception->errorCode);
        }

        Http::assertNothingSent();
        $this->assertDatabaseCount('client_account_permissions', 1);
    }

    public function test_removal_disables_only_selected_pair_and_reassignment_preserves_history(): void
    {
        [$admin, $john] = $this->context();
        [, $charlie] = $this->context();
        [$connection, $profile, $account] = $this->mapping($admin);
        $permission = ClientAccountPermission::factory()->create(['client_id' => $john, 'provider_account_id' => $account, 'can_schedule' => true]);
        $oldPermission = ClientAccountPermission::factory()->create(['client_id' => $charlie, 'provider_account_id' => $account, 'is_active' => false]);
        $unrelated = ClientAccountPermission::factory()->create(['client_id' => $charlie]);
        $before = [$connection->getRawOriginal(), $profile->getRawOriginal(), $account->getRawOriginal(), $oldPermission->fresh()->getRawOriginal(), $unrelated->fresh()->getRawOriginal()];
        Log::spy();

        $this->service()->remove($admin, $john, $account);

        $this->assertFalse($permission->fresh()->is_active);
        $this->assertTrue($permission->fresh()->can_schedule);
        $this->assertSame($before, [$connection->fresh()->getRawOriginal(), $profile->fresh()->getRawOriginal(), $account->fresh()->getRawOriginal(), $oldPermission->fresh()->getRawOriginal(), $unrelated->fresh()->getRawOriginal()]);
        Log::shouldHaveReceived('info')->once()->with('AdKit account removed.', ['actor_id' => $admin->id, 'client_id' => $john->public_id, 'account_id' => $account->public_id]);
        Http::assertNothingSent();
        $this->fakeProvider();
        $this->service()->assign($admin, $john, 'act_456');
        $this->assertTrue($permission->fresh()->is_active);
        $this->assertTrue($permission->fresh()->can_schedule);
        $this->assertFalse($oldPermission->fresh()->is_active);
        Http::assertSentCount(2);
    }

    #[TestWith(['admin'])]
    #[TestWith(['client'])]
    #[TestWith(['owner'])]
    #[TestWith(['membership'])]
    public function test_authority_changes_during_http_prevent_all_assignment_writes(string $change): void
    {
        [$admin, $client, $owner] = $this->context();
        $this->fakeProvider(onRead: function () use ($change, $admin, $client, $owner): void {
            match ($change) {
                'admin' => User::query()->whereKey($admin)->update(['role' => 'user']),
                'client' => Client::query()->whereKey($client)->update(['is_active' => false]),
                'owner' => User::query()->whereKey($owner)->update(['email_verified_at' => null]),
                'membership' => $client->users()->updateExistingPivot($owner->id, ['is_active' => false]),
            };
        });

        try {
            $this->service()->assign($admin, $client, 'act_456');
            $this->fail('Stale authority was accepted.');
        } catch (AuthorizationException) {
            $this->assertNoMappings();
        }
        Http::assertSentCount(2);
    }

    #[TestWith(['admin'])]
    #[TestWith(['owner'])]
    public function test_authority_revoked_during_discovery_returns_403_before_identity_read(string $change): void
    {
        [$admin, $client, $owner] = $this->context();
        $this->fakeProvider(onDiscovery: function () use ($change, $admin, $owner): void {
            match ($change) {
                'admin' => User::query()->whereKey($admin)->update(['role' => 'user']),
                'owner' => User::query()->whereKey($owner)->update(['email_verified_at' => null]),
            };
        });

        $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => time()])
            ->postJson(route('settings.agent-access.adkit-accounts.store', $client), ['ad_account_id' => 'act_456'])
            ->assertForbidden();

        Http::assertSentCount(1);
        $this->assertNoMappings();
    }

    #[TestWith(['connection'])]
    #[TestWith(['profile'])]
    #[TestWith(['account'])]
    #[TestWith(['foreign permission'])]
    public function test_mapping_changes_during_discovery_prevent_identity_read(string $change): void
    {
        [$admin, $client] = $this->context();
        [$connection, $profile, $account] = $this->mapping($admin);
        $this->fakeProvider(onDiscovery: function () use ($change, $connection, $profile, $account): void {
            match ($change) {
                'connection' => $connection->update(['access_token' => 'rotated-secret']),
                'profile' => $profile->update(['name' => 'Changed profile']),
                'account' => $account->update(['name' => 'Changed account']),
                'foreign permission' => ClientAccountPermission::factory()->create(['provider_account_id' => $account]),
            };
        });

        $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => time()])
            ->post(route('settings.agent-access.adkit-accounts.store', $client), ['ad_account_id' => 'act_456'])
            ->assertRedirect(route('settings.agent-access.edit', ['client' => $client->public_id]))
            ->assertSessionHasErrors('ad_account_id');

        Http::assertSentCount(1);
        $this->assertDatabaseMissing('client_account_permissions', ['client_id' => $client->id]);
        $this->assertDatabaseCount('provider_connections', 1);
        $this->assertDatabaseCount('provider_profiles', 1);
        $this->assertDatabaseCount('provider_accounts', 1);
    }

    #[TestWith(['access_token'])]
    #[TestWith(['subject'])]
    #[TestWith(['client_id'])]
    #[TestWith(['profile'])]
    #[TestWith(['account'])]
    #[TestWith(['foreign permission'])]
    public function test_mapping_changes_during_preflight_cannot_be_overwritten(string $change): void
    {
        [$admin, $client] = $this->context();
        [$connection, $profile, $account] = $this->mapping($admin);
        $this->fakeProvider(onRead: function () use ($change, $connection, $profile, $account): void {
            match ($change) {
                'profile' => $profile->update(['is_active' => false]),
                'account' => $account->update(['is_active' => false]),
                'foreign permission' => ClientAccountPermission::factory()->create(['provider_account_id' => $account]),
                default => $connection->update([$change => 'changed-value']),
            };
        });

        try {
            $this->service()->assign($admin, $client, 'act_456');
            $this->fail('A changed mapping was accepted.');
        } catch (AdKitRequestException) {
            $this->assertDatabaseMissing('client_account_permissions', ['client_id' => $client->id]);
        }
        Http::assertSentCount(2);
    }

    #[TestWith(['membership'])]
    #[TestWith(['id'])]
    #[TestWith(['account_id'])]
    #[TestWith(['name'])]
    #[TestWith(['currency'])]
    #[TestWith(['timezone_name'])]
    #[TestWith(['account_status'])]
    #[TestWith(['disable_reason'])]
    public function test_invalid_live_identity_never_persists(string $failure): void
    {
        [$admin, $client] = $this->context();
        $this->fakeProvider($failure);

        try {
            $this->service()->assign($admin, $client, 'act_456');
            $this->fail('Invalid account was assigned.');
        } catch (AdKitRequestException $exception) {
            $this->assertSame('adkit_invalid_response', $exception->errorCode);
        }
        $this->assertNoMappings();
        Http::assertSentCount($failure === 'membership' ? 1 : 2);
    }

    #[TestWith([2, 1])]
    #[TestWith(['2', '1'])]
    #[TestWith([2, '1'])]
    #[TestWith(['2', 1])]
    public function test_provider_disabled_account_can_be_explicitly_assigned_for_inspection(int|string $status, int|string $reason): void
    {
        [$admin, $client] = $this->context();
        $this->fakeProvider(identityOverrides: ['account_status' => $status, 'disable_reason' => $reason]);

        $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => time()])
            ->post(route('settings.agent-access.adkit-accounts.store', $client), ['ad_account_id' => 'act_456'])
            ->assertRedirect(route('settings.agent-access.edit', ['client' => $client->public_id]))
            ->assertSessionHas('success')->assertSessionHasNoErrors();

        $account = ProviderAccount::query()->sole();
        $this->assertSame('act_456', $account->provider_id);
        $this->assertTrue($account->is_active);
        $this->assertDatabaseHas('client_account_permissions', ['client_id' => $client->id, 'provider_account_id' => $account->id, 'is_active' => true, 'can_schedule' => false]);
        $this->assertDatabaseCount('client_provider_profiles', 0);
        Http::assertSentCount(2);
    }

    #[TestWith(['account_status', null])]
    #[TestWith(['account_status', false])]
    #[TestWith(['account_status', 2.5])]
    #[TestWith(['account_status', []])]
    #[TestWith(['disable_reason', null])]
    #[TestWith(['disable_reason', true])]
    #[TestWith(['disable_reason', 1.5])]
    #[TestWith(['disable_reason', []])]
    public function test_invalid_provider_status_types_are_rejected_without_mappings(string $field, mixed $value): void
    {
        [$admin, $client] = $this->context();
        $this->fakeProvider(identityOverrides: [$field => $value]);

        $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => time()])
            ->post(route('settings.agent-access.adkit-accounts.store', $client), ['ad_account_id' => 'act_456'])
            ->assertRedirect(route('settings.agent-access.edit', ['client' => $client->public_id]))
            ->assertSessionHasErrors('ad_account_id');

        $this->assertNoMappings();
        Http::assertSentCount(2);
    }

    public function test_cross_project_claim_appearing_after_preflight_is_rejected_by_final_global_check(): void
    {
        [$admin, $client] = $this->context();
        [$otherAdmin, $otherClient] = $this->context();
        [$connection, $profile, $existing] = $this->mapping($admin);
        [$otherConnection, $otherProfile, $otherExisting] = $this->mapping($otherAdmin, project: 'other-project', accountId: 'act_999');
        $before = [$connection->getRawOriginal(), $profile->getRawOriginal(), $existing->getRawOriginal()];
        $this->fakeProvider(onRead: function () use ($otherClient, $otherProfile): void {
            $account = ProviderAccount::factory()->create([
                'provider_profile_id' => $otherProfile, 'platform' => 'meta',
                'provider_id' => 'act_789', 'provider_id_hash' => hash('sha256', 'act_789'),
            ]);
            ClientAccountPermission::factory()->create(['client_id' => $otherClient, 'provider_account_id' => $account]);
        });

        $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => time()])
            ->post(route('settings.agent-access.adkit-accounts.store', $client), ['ad_account_id' => 'act_789'])
            ->assertRedirect(route('settings.agent-access.edit', ['client' => $client->public_id]))
            ->assertSessionHasErrors('ad_account_id');

        $this->assertDatabaseCount('provider_accounts', 3);
        $this->assertSame(1, ProviderAccount::query()->where('provider_id_hash', hash('sha256', 'act_789'))->count());
        $this->assertDatabaseCount('client_account_permissions', 1);
        $this->assertDatabaseMissing('client_account_permissions', ['client_id' => $client->id]);
        $this->assertSame($before, [$connection->fresh()->getRawOriginal(), $profile->fresh()->getRawOriginal(), $existing->fresh()->getRawOriginal()]);
        $this->assertModelExists($otherConnection);
        $this->assertModelExists($otherExisting);
        Http::assertSentCount(2);
    }

    /**
     * Exercises independent SQLite connections, not PostgreSQL advisory-lock contention.
     * PostgreSQL contention still requires verification with independent PostgreSQL sessions.
     */
    public function test_sqlite_competing_cross_project_assignment_fails_closed_until_first_writer_commits(): void
    {
        [$admin, $client] = $this->context();
        [$otherAdmin, $otherClient] = $this->context();
        $this->mapping($admin);
        $this->mapping($otherAdmin, project: 'other-project', accountId: 'act_999');
        $this->fakeProvider();
        $source = DB::connection();
        $default = DB::getDefaultConnection();
        $configuration = array_replace(config('database.connections.sqlite'), [
            'database' => 'file:adkit-assignment-'.bin2hex(random_bytes(8)).'?mode=memory&cache=shared',
            'busy_timeout' => 0,
        ]);
        config(['database.connections.adkit_first' => $configuration, 'database.connections.adkit_second' => $configuration]);
        $first = DB::connection('adkit_first');
        $second = DB::connection('adkit_second');
        $contended = false;
        $refused = false;

        try {
            // Clone the existing test schema into shared memory, never the configured live database.
            foreach ($source->select("SELECT sql FROM sqlite_schema WHERE sql IS NOT NULL AND type IN ('table', 'index') AND name NOT LIKE 'sqlite_%' ORDER BY CASE type WHEN 'table' THEN 0 ELSE 1 END") as $definition) {
                $first->statement($definition->sql);
            }
            foreach (['users', 'clients', 'provider_connections', 'provider_profiles', 'provider_accounts', 'client_user'] as $table) {
                $first->table($table)->insert($source->table($table)->get()->map(fn ($row): array => (array) $row)->all());
            }
            $this->assertNotSame($first->getPdo(), $second->getPdo());
            $this->assertSame(0, (int) $first->selectOne('PRAGMA read_uncommitted')->read_uncommitted);
            $this->assertSame(0, (int) $second->selectOne('PRAGMA read_uncommitted')->read_uncommitted);
            $first->beforeExecuting(function (string $sql) use (&$contended, &$refused, $first, $second, $otherAdmin, $otherClient): void {
                if ($contended || ! str_starts_with($sql, 'insert into "provider_accounts"')) {
                    return;
                }
                $contended = true;
                $this->assertSame(1, $first->transactionLevel());
                $this->assertSame(0, $second->table('provider_accounts')->where('provider_id_hash', hash('sha256', 'act_789'))->count());
                DB::setDefaultConnection('adkit_second');
                try {
                    $this->service()->assign($otherAdmin, $otherClient, 'act_789');
                    $this->fail('SQLite allowed the competing writer to claim the same absent identity.');
                } catch (AdKitRequestException $exception) {
                    $this->assertSame('adkit_mapping_invalid', $exception->errorCode);
                    $refused = true;
                } finally {
                    DB::setDefaultConnection('adkit_first');
                }
            });
            DB::setDefaultConnection('adkit_first');

            $account = $this->service()->assign($admin, $client, 'act_789');

            $this->assertTrue($contended);
            $this->assertTrue($refused);
            $this->assertSame(0, $first->transactionLevel());
            $this->assertSame(0, $second->transactionLevel());
            $this->assertSame(1, $second->table('provider_accounts')->where('provider_id_hash', hash('sha256', 'act_789'))->count());
            $this->assertSame(1, $second->table('client_account_permissions')->where('client_id', $client->id)->where('provider_account_id', $account->id)->count());
            $this->assertSame(0, $second->table('client_account_permissions')->where('client_id', $otherClient->id)->count());
            Http::assertSentCount(4);
        } finally {
            DB::setDefaultConnection($default);
            DB::purge('adkit_first');
            DB::purge('adkit_second');
        }
    }

    #[TestWith(['role'])]
    #[TestWith(['verified'])]
    #[TestWith(['client'])]
    #[TestWith(['owner'])]
    public function test_fresh_authority_not_names_or_cached_models_controls_access(string $failure): void
    {
        [$admin, $client, $owner] = $this->context();
        match ($failure) {
            'role' => User::query()->whereKey($admin)->update(['role' => 'user', 'name' => 'Admin John']),
            'verified' => User::query()->whereKey($admin)->update(['email_verified_at' => null]),
            'client' => Client::query()->whereKey($client)->update(['is_active' => false]),
            'owner' => $client->users()->updateExistingPivot($owner->id, ['role' => 'member']),
        };

        try {
            $this->service()->discover($admin, $client);
            $this->fail('Invalid authority was accepted.');
        } catch (AuthorizationException) {
            $this->assertNoMappings();
        }
        Http::assertNothingSent();
    }

    #[TestWith(['api_key', null, 'adkit_configuration_error'])]
    #[TestWith(['project_id', "bad\nproject", 'adkit_configuration_error'])]
    #[TestWith(['meta_account_id', null, 'adkit_configuration_error'])]
    public function test_unavailable_configuration_fails_safely_without_http(string $key, mixed $value, string $code): void
    {
        [$admin, $client] = $this->context();
        config(['services.adkit.'.$key => $value]);

        try {
            $this->service()->discover($admin, $client);
            $this->fail('Invalid configuration was accepted.');
        } catch (AdKitRequestException $exception) {
            $this->assertSame($code, $exception->errorCode);
            $this->assertStringNotContainsString('synthetic-secret', $exception->getMessage());
            $this->assertStringNotContainsString('internal-project', $exception->getMessage());
        }
        Http::assertNothingSent();
        $this->assertNoMappings();
    }

    public function test_existing_credentials_take_precedence_and_disabled_account_is_not_enabled(): void
    {
        [$admin, $client] = $this->context();
        [$connection, , $account] = $this->mapping($admin);
        $connection->update(['access_token' => 'persisted-secret']);
        config(['services.adkit.api_key' => null, 'services.adkit.project_id' => null, 'services.adkit.meta_account_id' => null]);
        $this->fakeProvider();
        $this->service()->assign($admin, $client, 'act_456');
        Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer persisted-secret'));
        $account->update(['is_active' => false]);

        $this->expectException(AdKitRequestException::class);
        $this->service()->assign($admin, $client, 'act_456');
    }

    public function test_existing_project_pivot_does_not_control_individual_adkit_assignment(): void
    {
        [$admin, $john] = $this->context();
        [, $charlie] = $this->context();
        [, $profile] = $this->mapping($admin);
        $charlie->providerProfiles()->attach($profile, ['assigned_at' => now()]);
        $pivot = (array) DB::table('client_provider_profiles')->sole();
        $this->fakeProvider();

        $account = $this->service()->assign($admin, $john, 'act_789');

        $this->assertSame($pivot, (array) DB::table('client_provider_profiles')->sole());
        $this->assertDatabaseHas('client_account_permissions', ['client_id' => $john->id, 'provider_account_id' => $account->id, 'is_active' => true]);
        $this->assertDatabaseMissing('client_account_permissions', ['client_id' => $charlie->id]);
        Http::assertSentCount(2);
    }

    #[TestWith(['foreign'])]
    #[TestWith(['non-adkit'])]
    public function test_removal_requires_client_pair_and_adkit_profile_without_http(string $case): void
    {
        [$admin, $client] = $this->context();
        if ($case === 'foreign') {
            [, , $account] = $this->mapping($admin);
            $permission = ClientAccountPermission::factory()->create(['provider_account_id' => $account]);
        } else {
            $permission = ClientAccountPermission::factory()->create(['client_id' => $client]);
            $account = $permission->account;
        }

        try {
            $this->service()->remove($admin, $client, $account);
            $this->fail('An out-of-scope removal was accepted.');
        } catch (HttpException $exception) {
            $this->assertSame(404, $exception->getStatusCode());
        }

        $this->assertTrue($permission->fresh()->is_active);
        Http::assertNothingSent();
    }

    public function test_provider_failure_is_static_and_does_not_persist_or_log_response_body(): void
    {
        [$admin, $client] = $this->context();
        Http::fake([self::BASE.'/manage/meta/accounts' => Http::response(['error' => 'synthetic-secret internal-project'], 500)]);
        Log::spy();

        try {
            $this->service()->assign($admin, $client, 'act_456');
            $this->fail('A provider failure was accepted.');
        } catch (AdKitRequestException $exception) {
            $this->assertSame('AdKit could not complete the request.', $exception->getMessage());
        }

        $this->assertNoMappings();
        Log::shouldNotHaveReceived('info');
        Http::assertSentCount(1);
    }

    public function test_rejected_secret_override_is_not_flashed_back_to_session(): void
    {
        [$admin, $client] = $this->context();

        $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => time()])
            ->from(route('settings.agent-access.edit', ['client' => $client->public_id]))
            ->post($this->path($client), ['ad_account_id' => 'act_456', 'api_key' => 'submitted-secret'])
            ->assertRedirect(route('settings.agent-access.edit', ['client' => $client->public_id]))
            ->assertSessionHasErrors('ad_account_id')->assertSessionMissing('_old_input.api_key');

        Http::assertNothingSent();
        $this->assertNoMappings();
    }

    public function test_request_boundary_rejects_secret_overrides_with_safe_selected_client_redirect(): void
    {
        [$admin, $client] = $this->context();
        $request = AssignAdKitAccountRequest::create($this->path($client), 'POST', ['ad_account_id' => 'act_456', 'api_key' => 'submitted-secret']);
        $request->setContainer($this->app)->setRedirector(app('redirect'));
        $request->setUserResolver(fn () => $admin);
        $route = new Route('POST', 'settings/agent-access/{client}/adkit-accounts', []);
        $route->bind($request)->setParameter('client', $client);
        $request->setRouteResolver(fn () => $route);

        try {
            $request->validateResolved();
            $this->fail('The credential override was accepted.');
        } catch (ValidationException $exception) {
            $this->assertSame(['ad_account_id' => ['Only an AdKit ad account selection is accepted.']], $exception->errors());
            $this->assertNotNull($exception->response);
            $this->assertSame(route('settings.agent-access.edit', ['client' => $client->public_id]), $exception->response->getTargetUrl());
            $this->assertFalse(session()->has('_old_input.api_key'));
        }

        Http::assertNothingSent();
        $this->assertNoMappings();
    }

    public function test_guest_read_returns_401_and_browser_redirect_without_http(): void
    {
        [, $client] = $this->context();

        $this->getJson($this->path($client))->assertUnauthorized();
        $this->get($this->path($client))->assertRedirect(route('login'));

        Http::assertNothingSent();
    }

    #[TestWith(['owner'])]
    #[TestWith(['member'])]
    #[TestWith(['outsider'])]
    public function test_verified_regular_users_are_forbidden_403(string $role): void
    {
        [, $client, $owner] = $this->context();
        $user = $role === 'owner' ? $owner : User::factory()->create(['name' => 'Admin John']);
        if ($role === 'member') {
            $client->users()->attach($user, ['role' => 'member', 'is_active' => true]);
        }

        $this->actingAs($user)->getJson($this->path($client))->assertForbidden();
        $this->withSession(['auth.password_confirmed_at' => time()])->postJson($this->path($client), ['ad_account_id' => 'act_456'])->assertForbidden();

        Http::assertNothingSent();
        $this->assertNoMappings();
    }

    public function test_unverified_admin_is_blocked_and_mutations_require_password_confirmation(): void
    {
        [$admin, $client] = $this->context();
        $unverified = User::factory()->unverified()->create(['role' => 'admin']);
        $this->actingAs($unverified)->getJson($this->path($client))->assertForbidden();

        $this->actingAs($admin)->post($this->path($client), ['ad_account_id' => 'act_456'])->assertRedirect(route('password.confirm'));

        Http::assertNothingSent();
        $this->assertNoMappings();
    }

    #[TestWith([[]])]
    #[TestWith([['ad_account_id' => '456']])]
    #[TestWith([['ad_account_id' => 'act_456/ads']])]
    #[TestWith([['ad_account_id' => 'act_456', 'api_key' => 'secret']])]
    #[TestWith([['ad_account_id' => 'act_456', 'client_id' => 1]])]
    #[TestWith([['ad_account_id' => 'act_456', 'project_id' => 'override']])]
    public function test_store_rejects_invalid_or_extra_input_without_http(array $body): void
    {
        [$admin, $client] = $this->context();

        $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => time()])
            ->postJson($this->path($client), $body)->assertUnprocessable()->assertJsonValidationErrors('ad_account_id');

        Http::assertNothingSent();
        $this->assertNoMappings();
    }

    public function test_read_needs_no_password_and_mutation_redirects_to_selected_client(): void
    {
        [$admin, $client] = $this->context();
        $this->fakeProvider();

        $this->actingAs($admin)->getJson($this->path($client))->assertOk()->assertJsonCount(2, 'accounts');
        $this->withSession(['auth.password_confirmed_at' => time()])->post($this->path($client), ['ad_account_id' => 'act_456'])
            ->assertRedirect(route('settings.agent-access.edit', ['client' => $client->public_id]))->assertSessionHas('success');
        $account = ProviderAccount::query()->sole();
        $this->delete(route('settings.agent-access.adkit-accounts.destroy', ['client' => $client, 'account' => $account]))
            ->assertRedirect(route('settings.agent-access.edit', ['client' => $client->public_id]));
        $this->assertFalse($account->permissions()->sole()->is_active);
        Http::assertSentCount(3);
    }

    public function test_missing_configuration_discovery_and_store_errors_are_static_and_safe(): void
    {
        [$admin, $client] = $this->context();
        config(['services.adkit.api_key' => null]);

        $this->actingAs($admin)->getJson($this->path($client))->assertStatus(503)->assertExactJson(['error' => 'adkit_configuration_error', 'message' => 'AdKit configuration is unavailable.']);
        $this->withSession(['auth.password_confirmed_at' => time()])->post($this->path($client), ['ad_account_id' => 'act_456'])
            ->assertRedirect(route('settings.agent-access.edit', ['client' => $client->public_id]))->assertSessionHasErrors('ad_account_id');

        Http::assertNothingSent();
        $this->assertNoMappings();
    }

    public function test_removal_rejects_foreign_and_non_adkit_accounts_404(): void
    {
        [$admin, $client] = $this->context();
        [, , $foreign] = $this->mapping($admin);
        $nonAdkit = ClientAccountPermission::factory()->create(['client_id' => $client])->account;

        $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => time()]);
        $this->delete($this->path($client).'/'.$foreign->public_id)->assertNotFound();
        $this->delete($this->path($client).'/'.$nonAdkit->public_id)->assertNotFound();

        Http::assertNothingSent();
        $this->assertTrue($nonAdkit->permissions()->sole()->is_active);
    }

    private function path(Client $client): string
    {
        return route('settings.agent-access.adkit-accounts.index', $client, false);
    }

    private function service(): AdKitAccountAssignmentService
    {
        return app(AdKitAccountAssignmentService::class);
    }

    /** @return array{User, Client, User} */
    private function context(): array
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $client = Client::factory()->create();
        $owner = User::factory()->create();
        $client->users()->attach($owner, ['role' => 'owner', 'is_active' => true]);

        return [$admin, $client, $owner];
    }

    /** @return array{ProviderConnection, ProviderProfile, ProviderAccount} */
    private function mapping(User $admin, string $project = 'internal-project', string $accountId = 'act_456'): array
    {
        $connection = $admin->providerConnections()->create(['provider' => 'adkit', 'client_id' => $project, 'subject' => 'act_123', 'access_token' => 'synthetic-secret', 'scopes' => ['ads_read']]);
        $profile = ProviderProfile::factory()->create(['provider' => 'adkit', 'provider_connection_id' => $connection, 'provider_id' => $project, 'provider_id_hash' => hash('sha256', $project)]);
        $account = ProviderAccount::factory()->create(['provider_profile_id' => $profile, 'platform' => 'meta', 'provider_id' => $accountId, 'provider_id_hash' => hash('sha256', $accountId)]);

        return [$connection->fresh(), $profile->fresh(), $account->fresh()];
    }

    private function assertNoMappings(): void
    {
        foreach (['provider_connections', 'provider_profiles', 'provider_accounts', 'client_account_permissions', 'client_provider_profiles', 'client_operator_grants'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }

    /** @param array<string, mixed> $identityOverrides */
    private function fakeProvider(?string $failure = null, ?Closure $onRead = null, ?Closure $onDiscovery = null, array $identityOverrides = []): void
    {
        Http::fake([
            self::BASE.'/manage/meta/accounts' => function () use ($failure, $onDiscovery) {
                $onDiscovery?->__invoke();

                return Http::response(['accounts' => $failure === 'membership' ? [] : [
                    ['id' => 'act_456', 'platform' => 'meta', 'name' => 'John account', 'currency' => 'USD', 'status' => 1],
                    ['id' => 'act_789', 'platform' => 'meta', 'name' => 'Charlie account', 'currency' => 'USD', 'status' => 1],
                ]]);
            },
            self::BASE.'/manage/platform-api-requests' => function (Request $request) use ($failure, $onRead, $identityOverrides) {
                $onRead?->__invoke();
                $id = substr($request['endpoint'], 1);
                $identity = ['id' => $id, 'account_id' => substr($id, 4), 'name' => 'Verified account', 'account_status' => 1, 'disable_reason' => 0, 'currency' => 'USD', 'timezone_name' => 'America/New_York'];
                if ($failure !== null) {
                    $identity[$failure] = match ($failure) {
                        'id' => 'act_999', 'account_id' => '999', default => null,
                    };
                }

                return Http::response(['platform' => 'meta', 'status' => 'executed', 'response' => array_replace($identity, $identityOverrides)]);
            },
        ]);
    }
}
