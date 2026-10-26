<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientAccountPermission;
use App\Models\ClientOperatorGrant;
use App\Models\ProviderAccount;
use App\Models\ProviderConnection;
use App\Models\ProviderProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class ConnectAdKitTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const string BASE = 'https://app.adkit.so/api/v1';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.adkit' => [
            'enabled' => true, 'api_key' => 'synthetic-secret', 'project_id' => 'synthetic-project',
            'meta_account_id' => '123', 'base_url' => self::BASE,
            'timeout' => 10, 'connect_timeout' => 3, 'max_response_bytes' => 100000,
        ]]);
        Http::preventStrayRequests();
    }

    #[TestWith(['enabled', false])]
    #[TestWith(['enabled', null])]
    #[TestWith(['enabled', 'true'])]
    #[TestWith(['api_key', null])]
    #[TestWith(['api_key', ' '])]
    #[TestWith(['api_key', "secret\r\nInjected: value"])]
    #[TestWith(['project_id', null])]
    #[TestWith(['project_id', ' '])]
    #[TestWith(['project_id', "project\nInjected"])]
    #[TestWith(['project_id', 123])]
    #[TestWith(['meta_account_id', null])]
    #[TestWith(['meta_account_id', 'act_act_123'])]
    #[TestWith(['meta_account_id', 'act_123/ads'])]
    #[TestWith(['meta_account_id', '123abc'])]
    #[TestWith(['meta_account_id', 123])]
    public function test_invalid_configuration_refuses_without_database_or_http(string $key, mixed $value): void
    {
        config(['services.adkit.'.$key => $value]);

        $this->assertSame(1, $this->runConnect('00000000-0000-4000-8000-000000000001', '1'));

        Http::assertNothingSent();
    }

    #[TestWith(['not-a-uuid', '1'])]
    #[TestWith(['00000000-0000-4000-8000-000000000001', null])]
    #[TestWith(['00000000-0000-4000-8000-000000000001', '0'])]
    #[TestWith(['00000000-0000-4000-8000-000000000001', '1 OR 1=1'])]
    public function test_invalid_identifiers_refuse_without_database_or_http(string $client, ?string $operator): void
    {
        $this->assertSame(1, $this->runConnect($client, $operator));

        Http::assertNothingSent();
    }

    #[TestWith(['not admin'])]
    #[TestWith(['unverified operator'])]
    #[TestWith(['inactive client'])]
    #[TestWith(['unverified owner'])]
    #[TestWith(['inactive owner'])]
    #[TestWith(['no owner'])]
    #[TestWith(['no grant'])]
    #[TestWith(['inactive grant'])]
    #[TestWith(['owner without grant'])]
    #[TestWith(['missing client'])]
    #[TestWith(['missing operator'])]
    public function test_denied_authority_has_zero_outbound_requests_and_no_connection(string $reason): void
    {
        [$client, $operator, $owner] = $this->context();
        match ($reason) {
            'not admin' => $operator->update(['role' => 'user']),
            'unverified operator' => $operator->forceFill(['email_verified_at' => null])->save(),
            'inactive client' => $client->update(['is_active' => false]),
            'unverified owner' => $owner->forceFill(['email_verified_at' => null])->save(),
            'inactive owner' => $client->users()->updateExistingPivot($owner->id, ['is_active' => false]),
            'no owner' => $client->users()->detach(),
            'no grant' => $client->operatorGrants()->delete(),
            'inactive grant' => $client->operatorGrants()->update(['is_active' => false]),
            'owner without grant' => [$client->users()->attach($operator, ['role' => 'owner', 'is_active' => true]), $client->operatorGrants()->delete()],
            'missing client' => $client->delete(),
            'missing operator' => $operator->delete(),
        };

        $this->assertSame(1, $this->runConnect($client->public_id, (string) $operator->id));

        Http::assertNothingSent();
        $this->assertDatabaseCount('provider_connections', 0);
        $this->assertDatabaseCount('provider_profiles', 0);
    }

    #[TestWith(['123'])]
    #[TestWith(['act_123'])]
    public function test_connects_only_configured_account_with_encrypted_credentials_and_exclusive_mapping(string $configuredAccount): void
    {
        [$client, $operator] = $this->context();
        config(['services.adkit.meta_account_id' => $configuredAccount]);
        $this->fakeProvider(onRead: function (): void {
            $this->assertDatabaseCount('provider_connections', 0);
            $this->assertDatabaseCount('provider_profiles', 0);
            $this->assertDatabaseCount('provider_accounts', 0);
        });

        $this->assertSame(0, $this->runConnect($client->public_id, (string) $operator->id));

        $connection = ProviderConnection::query()->sole();
        $this->assertSame($operator->id, $connection->user_id);
        $this->assertSame('adkit', $connection->provider);
        $this->assertSame('synthetic-project', $connection->client_id);
        $this->assertSame('act_123', $connection->subject);
        $this->assertSame('synthetic-secret', $connection->access_token);
        $this->assertSame(['ads_read'], $connection->scopes);
        $this->assertSame('synthetic-secret', Crypt::decryptString($connection->getRawOriginal('access_token')));
        $profile = ProviderProfile::query()->sole();
        $this->assertSame($connection->id, $profile->provider_connection_id);
        $this->assertSame('synthetic-project', $profile->provider_id);
        $this->assertSame(hash('sha256', 'synthetic-project'), $profile->provider_id_hash);
        $this->assertSame('AdKit project', $profile->name);
        $this->assertSame('synthetic-project', Crypt::decryptString($profile->getRawOriginal('provider_id')));
        $account = ProviderAccount::query()->sole();
        $this->assertSame('act_123', $account->provider_id);
        $this->assertSame('meta', $account->platform);
        $this->assertSame('Verified account', $account->name);
        $this->assertSame($profile->id, $account->provider_profile_id);
        $this->assertSame('act_123', Crypt::decryptString($account->getRawOriginal('provider_id')));
        $this->assertDatabaseHas('client_provider_profiles', ['client_id' => $client->id, 'provider_profile_id' => $profile->id, 'assigned_by' => $operator->id]);
        $this->assertDatabaseHas('client_account_permissions', ['client_id' => $client->id, 'provider_account_id' => $account->id, 'is_active' => true, 'can_schedule' => false]);
        $this->assertDatabaseCount('client_provider_profiles', 1);
        $this->assertDatabaseCount('client_account_permissions', 1);
        $this->assertDatabaseCount('client_operator_grants', 1);
        $this->assertDatabaseCount('personal_access_tokens', 0);
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request): bool => $request->url() === self::BASE.'/manage/platform-api-requests'
            && $request->hasHeader('Authorization', 'Bearer synthetic-secret')
            && $request->hasHeader('x-adkit-project-id', 'synthetic-project')
            && $request->data() === [
                'platform' => 'meta', 'accountId' => 'act_123', 'endpoint' => '/act_123', 'method' => 'GET',
                'description' => 'Verify account access',
                'payload' => ['fields' => 'id,name,account_id,account_status,disable_reason,currency,timezone_name'],
            ]);
    }

    #[TestWith(['membership'])]
    #[TestWith(['id'])]
    #[TestWith(['account_id'])]
    #[TestWith(['account_status'])]
    #[TestWith(['disable_reason'])]
    #[TestWith(['name'])]
    #[TestWith(['currency'])]
    #[TestWith(['timezone_name'])]
    #[TestWith(['provider error'])]
    public function test_preflight_failure_never_persists_mapping(string $reason): void
    {
        [$client, $operator] = $this->context();
        $this->fakeProvider($reason);

        $this->assertSame(1, $this->runConnect($client->public_id, (string) $operator->id));

        $this->assertDatabaseCount('provider_connections', 0);
        $this->assertDatabaseCount('provider_profiles', 0);
        $this->assertDatabaseCount('provider_accounts', 0);
        $this->assertDatabaseCount('client_provider_profiles', 0);
        $this->assertDatabaseCount('client_account_permissions', 0);
        Http::assertSentCount(in_array($reason, ['membership', 'provider error'], true) ? 1 : 2);
    }

    public function test_failed_rotation_keeps_previous_credentials_and_mapping_unchanged(): void
    {
        [$client, $operator] = $this->context();
        [$connection, $profile, $account] = $this->mapping($client, $operator);
        $before = [$connection->getRawOriginal(), $profile->getRawOriginal(), $account->getRawOriginal()];
        $this->fakeProvider('id');

        $this->assertSame(1, $this->runConnect($client->public_id, (string) $operator->id));

        $this->assertSame($before, [$connection->fresh()->getRawOriginal(), $profile->fresh()->getRawOriginal(), $account->fresh()->getRawOriginal()]);
        Http::assertSentCount(2);
    }

    public function test_inconsistent_account_rolls_back_credential_rotation_and_preserves_mapping(): void
    {
        [$client, $operator] = $this->context();
        [$connection, $profile, $account] = $this->mapping($client, $operator);
        $account->update(['platform' => 'google']);
        $before = [$connection->getRawOriginal(), $profile->getRawOriginal(), $account->getRawOriginal()];
        $this->fakeProvider();

        $this->assertSame(1, $this->runConnect($client->public_id, (string) $operator->id));

        $this->assertSame($before, [$connection->fresh()->getRawOriginal(), $profile->fresh()->getRawOriginal(), $account->fresh()->getRawOriginal()]);
        $this->assertDatabaseCount('provider_connections', 1);
        $this->assertDatabaseCount('provider_profiles', 1);
        $this->assertDatabaseCount('client_provider_profiles', 1);
        $this->assertDatabaseCount('provider_accounts', 1);
        $this->assertDatabaseCount('client_account_permissions', 1);
        Http::assertSentCount(2);
    }

    public function test_rotation_is_idempotent_and_preserves_disabled_flags_and_other_accounts(): void
    {
        [$client, $operator] = $this->context();
        [$connection, $profile, $account] = $this->mapping($client, $operator);
        $profile->update(['is_active' => false]);
        $account->update(['is_active' => false]);
        $account->permissions()->update(['is_active' => false, 'can_schedule' => false]);
        $other = ProviderAccount::factory()->create(['provider_profile_id' => $profile]);
        ClientAccountPermission::factory()->create(['client_id' => $client, 'provider_account_id' => $other]);
        $assignment = DB::table('client_provider_profiles')->sole();
        $this->fakeProvider();

        $this->assertSame(0, $this->runConnect($client->public_id, (string) $operator->id, 'credentials rotated for the same mapping'));

        $this->assertSame('synthetic-secret', $connection->fresh()->access_token);
        $this->assertSame('Verified account', $account->fresh()->name);
        $this->assertFalse($profile->fresh()->is_active);
        $this->assertFalse($account->fresh()->is_active);
        $this->assertFalse($account->permissions()->sole()->is_active);
        $this->assertFalse($account->permissions()->sole()->can_schedule);
        $this->assertTrue($other->fresh()->is_active);
        $this->assertTrue($other->permissions()->sole()->is_active);
        $this->assertSame((array) $assignment, (array) DB::table('client_provider_profiles')->sole());
        $this->assertDatabaseCount('provider_connections', 1);
        $this->assertDatabaseCount('provider_profiles', 1);
        $this->assertDatabaseCount('provider_accounts', 2);
        Http::assertSentCount(2);
    }

    #[TestWith(['client'])]
    #[TestWith(['operator'])]
    #[TestWith(['orphaned connection'])]
    #[TestWith(['project'])]
    #[TestWith(['subject'])]
    #[TestWith(['foreign permission'])]
    #[TestWith(['other mapped profile'])]
    public function test_existing_mapping_cannot_be_hijacked_or_retargeted(string $reason): void
    {
        [$client, $operator] = $this->context();
        [$connection, $profile] = $this->mapping($client, $operator);
        match ($reason) {
            'client' => [$client->providerProfiles()->detach(), Client::factory()->create()->providerProfiles()->attach($profile, ['assigned_at' => now()])],
            'operator' => $connection->forceFill(['user_id' => User::factory()->create(['role' => 'admin'])->id])->save(),
            'orphaned connection' => $profile->update(['provider_connection_id' => null]),
            'project' => config(['services.adkit.project_id' => 'different-project']),
            'subject' => config(['services.adkit.meta_account_id' => '456']),
            'foreign permission' => ClientAccountPermission::factory()->create(['client_id' => Client::factory(), 'provider_account_id' => $profile->accounts()->sole()]),
            'other mapped profile' => ProviderProfile::factory()->create(['provider' => 'adkit', 'provider_connection_id' => $connection]),
        };
        $before = $connection->fresh()->getRawOriginal();

        $this->assertSame(1, $this->runConnect($client->public_id, (string) $operator->id));

        $this->assertSame($before, $connection->fresh()->getRawOriginal());
        $this->assertDatabaseCount('provider_connections', 1);
        $this->assertDatabaseCount('provider_profiles', $reason === 'other mapped profile' ? 2 : 1);
        Http::assertNothingSent();
    }

    #[TestWith(['grant'])]
    #[TestWith(['operator'])]
    #[TestWith(['owner'])]
    #[TestWith(['client'])]
    #[TestWith(['assignment'])]
    #[TestWith(['connection'])]
    public function test_authority_and_ownership_are_rechecked_after_preflight(string $reason): void
    {
        [$client, $operator, $owner] = $this->context();
        [$connection, $profile] = $this->mapping($client, $operator);
        $this->fakeProvider(onRead: function () use ($reason, $client, $operator, $owner, $profile, $connection): void {
            match ($reason) {
                'grant' => $client->operatorGrants()->update(['is_active' => false]),
                'operator' => $operator->update(['role' => 'user']),
                'owner' => $owner->forceFill(['email_verified_at' => null])->save(),
                'client' => $client->update(['is_active' => false]),
                'assignment' => DB::table('client_provider_profiles')->where('provider_profile_id', $profile->id)->update(['client_id' => Client::factory()->create()->id]),
                'connection' => $connection->update(['subject' => 'act_456']),
            };
        });

        $this->assertSame(1, $this->runConnect($client->public_id, (string) $operator->id));

        $this->assertSame('old-secret', $connection->fresh()->access_token);
        $this->assertDatabaseCount('provider_connections', 1);
        $this->assertDatabaseCount('provider_accounts', 1);
        Http::assertSentCount(2);
    }

    #[TestWith(['456'])]
    #[TestWith(['act_456'])]
    public function test_selected_target_is_verified_and_mapped_separately_from_connection_context(string $target): void
    {
        [$client, $operator] = $this->context();
        $this->fakeProvider(targetId: 'act_456');

        $this->assertSame(0, $this->runConnect($client->public_id, (string) $operator->id, account: $target));

        $connection = ProviderConnection::query()->sole();
        $profile = ProviderProfile::query()->sole();
        $account = ProviderAccount::query()->sole();
        $this->assertSame('act_123', $connection->subject);
        $this->assertSame('synthetic-project', $connection->client_id);
        $this->assertSame('synthetic-project', $profile->provider_id);
        $this->assertSame($connection->id, $profile->provider_connection_id);
        $this->assertSame($profile->id, $account->provider_profile_id);
        $this->assertSame('act_456', $account->provider_id);
        $this->assertSame(hash('sha256', 'act_456'), $account->provider_id_hash);
        $this->assertSame('Verified account', $account->name);
        $this->assertDatabaseHas('client_provider_profiles', ['client_id' => $client->id, 'provider_profile_id' => $profile->id]);
        $this->assertDatabaseHas('client_account_permissions', ['client_id' => $client->id, 'provider_account_id' => $account->id, 'is_active' => true, 'can_schedule' => false]);
        $this->assertDatabaseCount('client_account_permissions', 1);
        $this->assertDatabaseCount('personal_access_tokens', 0);
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request): bool => $request->url() === self::BASE.'/manage/meta/accounts'
            && $request->method() === 'GET'
            && $request->hasHeader('Authorization', 'Bearer synthetic-secret')
            && $request->hasHeader('x-adkit-project-id', 'synthetic-project'));
        Http::assertSent(fn (Request $request): bool => $request->url() === self::BASE.'/manage/platform-api-requests'
            && $request->hasHeader('Authorization', 'Bearer synthetic-secret')
            && $request->hasHeader('x-adkit-project-id', 'synthetic-project')
            && $request->data() === [
                'platform' => 'meta', 'accountId' => 'act_123', 'endpoint' => '/act_456', 'method' => 'GET',
                'description' => 'Verify account access',
                'payload' => ['fields' => 'id,name,account_id,account_status,disable_reason,currency,timezone_name'],
            ]);
    }

    public function test_adding_target_reuses_context_and_preserves_existing_accounts_and_permissions(): void
    {
        [$client, $operator] = $this->context();
        [$connection, $profile, $existing] = $this->mapping($client, $operator);
        $permission = $existing->permissions()->sole();
        $before = [$existing->getRawOriginal(), $permission->getRawOriginal()];
        $this->fakeProvider(targetId: 'act_456');

        $this->assertSame(0, $this->runConnect($client->public_id, (string) $operator->id, account: '456'));

        $this->assertSame('act_123', $connection->fresh()->subject);
        $this->assertSame($before, [$existing->fresh()->getRawOriginal(), $permission->fresh()->getRawOriginal()]);
        $this->assertDatabaseCount('provider_connections', 1);
        $this->assertDatabaseCount('provider_profiles', 1);
        $this->assertDatabaseCount('client_provider_profiles', 1);
        $this->assertDatabaseCount('provider_accounts', 2);
        $this->assertDatabaseCount('client_account_permissions', 2);
        $this->assertSame('act_456', $profile->accounts()->where('id', '!=', $existing->id)->sole()->provider_id);
        Http::assertSentCount(2);
    }

    #[TestWith(['unknown', null, 1])]
    #[TestWith(['id', 'id', 2])]
    #[TestWith(['account_id', 'account_id', 2])]
    public function test_unknown_or_mismatched_selected_target_never_persists(string $reason, ?string $failure, int $requests): void
    {
        [$client, $operator] = $this->context();
        $this->fakeProvider($failure, targetId: $reason === 'unknown' ? 'act_123' : 'act_456');

        $this->assertSame(1, $this->runConnect($client->public_id, (string) $operator->id, account: '456'));

        $this->assertDatabaseCount('provider_connections', 0);
        $this->assertDatabaseCount('provider_profiles', 0);
        $this->assertDatabaseCount('provider_accounts', 0);
        $this->assertDatabaseCount('client_provider_profiles', 0);
        $this->assertDatabaseCount('client_account_permissions', 0);
        Http::assertSentCount($requests);
    }

    #[TestWith([''])]
    #[TestWith(['act_act_456'])]
    #[TestWith(['act_456/ads'])]
    #[TestWith(['456abc'])]
    #[TestWith([' 456'])]
    public function test_invalid_selected_target_syntax_refuses_without_database_or_http(string $target): void
    {
        $this->assertSame(1, $this->runConnect('00000000-0000-4000-8000-000000000001', '1', account: $target));

        Http::assertNothingSent();
    }

    /** @return array{Client, User, User} */
    private function context(): array
    {
        $client = Client::factory()->create();
        $owner = User::factory()->create();
        $operator = User::factory()->create(['role' => 'admin']);
        $client->users()->attach($owner, ['role' => 'owner', 'is_active' => true]);
        ClientOperatorGrant::factory()->create(['client_id' => $client, 'user_id' => $operator, 'granted_by' => $owner]);

        return [$client, $operator, $owner];
    }

    /** @return array{ProviderConnection, ProviderProfile, ProviderAccount} */
    private function mapping(Client $client, User $operator): array
    {
        $connection = $operator->providerConnections()->create(['provider' => 'adkit', 'client_id' => 'synthetic-project', 'subject' => 'act_123', 'access_token' => 'old-secret', 'scopes' => ['ads_read']]);
        $profile = ProviderProfile::factory()->create(['provider' => 'adkit', 'provider_connection_id' => $connection, 'provider_id' => 'synthetic-project', 'provider_id_hash' => hash('sha256', 'synthetic-project')]);
        $client->providerProfiles()->attach($profile, ['assigned_by' => $operator->id, 'assigned_at' => now()]);
        $account = ProviderAccount::factory()->create(['provider_profile_id' => $profile, 'provider_id' => 'act_123', 'provider_id_hash' => hash('sha256', 'act_123'), 'platform' => 'meta']);
        ClientAccountPermission::factory()->create(['client_id' => $client, 'provider_account_id' => $account]);

        return [$connection->fresh(), $profile->fresh(), $account->fresh()];
    }

    private function runConnect(string $client, ?string $operator, ?string $expectedOutput = null, ?string $account = null): int
    {
        $parameters = ['client' => $client];
        if ($operator !== null) {
            $parameters['--operator'] = $operator;
        }
        if ($account !== null) {
            $parameters['--account'] = $account;
        }
        $status = Artisan::call('adkit:connect', $parameters);
        $output = Artisan::output();
        foreach (['synthetic-secret', 'old-secret', 'synthetic-project', 'act_123', 'act_456', 'different-project'] as $secret) {
            $this->assertStringNotContainsString($secret, $output);
        }
        if ($expectedOutput !== null) {
            $this->assertStringContainsString($expectedOutput, $output);
        }

        return $status;
    }

    private function fakeProvider(?string $failure = null, ?\Closure $onRead = null, string $targetId = 'act_123'): void
    {
        Http::fake([
            self::BASE.'/manage/meta/accounts' => function () use ($failure, $targetId) {
                if ($failure === 'provider error') {
                    return Http::response(['error' => 'synthetic-secret synthetic-project act_123'], 500);
                }

                return Http::response(['accounts' => array_map(fn (int $id): array => [
                    'id' => 'act_'.$id, 'platform' => 'meta', 'name' => 'Discovered account', 'currency' => 'USD', 'status' => 1,
                ], $failure === 'membership' ? range(200, 229) : ($targetId === 'act_123' ? [123, ...range(200, 228)] : [123, 456, ...range(200, 227)]))]);
            },
            self::BASE.'/manage/platform-api-requests' => function () use ($failure, $onRead, $targetId) {
                $onRead?->__invoke();
                $row = ['id' => $targetId, 'account_id' => substr($targetId, 4), 'name' => 'Verified account', 'account_status' => 1, 'disable_reason' => 0, 'currency' => 'USD', 'timezone_name' => 'America/New_York'];
                if ($failure !== null) {
                    $row[$failure] = match ($failure) {
                        'id' => 'act_777', 'account_id' => '777', default => null,
                    };
                }

                return Http::response(['platform' => 'meta', 'status' => 'executed', 'response' => $row]);
            },
        ]);
    }
}
