<?php

namespace Tests\Feature;

use App\Models\AgentOperation;
use App\Models\Client;
use App\Models\ClientAccount;
use App\Models\ClientProviderProfile;
use App\Services\AgentOperationService;
use App\Services\AgentTokenService;
use App\Services\ClientProviderProfileService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ClientProviderProfileTest extends TestCase
{
    use RefreshDatabase;

    protected function beforeRefreshingDatabase(): void
    {
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->assertEmpty(config('database.connections.sqlite.url'));
    }

    public function test_claim_is_idempotent_and_one_client_can_have_multiple_profiles(): void
    {
        Http::preventStrayRequests();
        $client = Client::factory()->create();
        $service = app(ClientProviderProfileService::class);
        $first = DB::transaction(fn () => $service->claim($client, 'zernio', 'production', 'first', 'Editorial'));
        $again = DB::transaction(fn () => $service->claim($client, 'zernio', 'production', 'first'));
        DB::transaction(fn () => $service->claim($client, 'zernio', 'production', 'second'));

        $this->assertSame($first->id, $again->id);
        $this->assertTrue($first->wasRecentlyCreated);
        $this->assertFalse($again->wasRecentlyCreated);
        $this->assertSame('Editorial', $again->name);
        $this->assertCount(2, $client->providerProfiles);
        Http::assertNothingSent();
    }

    public function test_profile_names_fit_the_documented_transport_bound_without_truncation(): void
    {
        $this->assertSame('text', Schema::getColumnType('client_provider_profiles', 'name'));
        $client = Client::factory()->create();
        foreach ([str_repeat('a', 255), str_repeat('a', 256), str_repeat('a', 1024), str_repeat("\u{1F600}", 256)] as $index => $name) {
            $claim = DB::transaction(fn () => app(ClientProviderProfileService::class)->claim(
                $client, 'zernio', 'production', str_repeat((string) ($index + 1), 24), $name,
            ));
            $this->assertSame($name, $claim->fresh()->name);
        }
    }

    public function test_profile_migration_supports_read_only_sql_preview(): void
    {
        $migration = require glob(database_path('migrations/*create_client_provider_profiles_table.php'))[0];
        $queries = DB::pretend(fn () => $migration->up());

        $this->assertNotEmpty($queries);
        $this->assertDatabaseCount('client_provider_profiles', 0);
        $this->assertTrue(Schema::hasColumn('client_accounts', 'client_provider_profile_id'));
    }

    public function test_claim_reads_owner_then_queries_user_before_locking_client_and_rechecks_ownership(): void
    {
        $client = Client::factory()->create();
        $otherOwner = Client::factory()->create()->owner;
        $tables = [];
        DB::listen(function (QueryExecuted $query) use (&$tables, $client, $otherOwner): void {
            if (preg_match('/^select .* from "(clients|users)"/', $query->sql, $matches)) {
                $tables[] = $matches[1];
                if ($tables === ['clients', 'users']) {
                    DB::table('clients')->where('id', $client->id)->update(['owner_id' => $otherOwner->id]);
                }
            }
        });

        try {
            DB::transaction(fn () => app(ClientProviderProfileService::class)->claim($client, 'zernio', 'production', 'owner-race'));
            $this->fail('An owner change between discovery and locking must be rejected.');
        } catch (RuntimeException) {
            $this->assertSame(['clients', 'users', 'clients'], $tables);
            $this->assertDatabaseCount('client_provider_profiles', 0);
        }
    }

    public function test_sync_version_defaults_to_zero_and_is_cast_to_integer(): void
    {
        $profile = ClientProviderProfile::factory()->create()->refresh();
        $this->assertSame(0, $profile->sync_version);
        $profile->update(['sync_version' => '2']);
        $this->assertSame(2, $profile->refresh()->sync_version);
    }

    public function test_foreign_inactive_and_inconsistent_claims_are_never_reassigned_or_reactivated(): void
    {
        $account = ClientAccount::factory()->create();
        $profile = $account->providerProfile;
        $other = Client::factory()->create();
        $service = app(ClientProviderProfileService::class);
        foreach (['foreign', 'inactive', 'mismatched'] as $case) {
            $profile->update(['active' => $case !== 'inactive']);
            if ($case === 'mismatched') {
                $account->update(['client_provider_profile_id' => ClientProviderProfile::factory()->create()->id]);
            }
            try {
                DB::transaction(fn () => $service->claim($case === 'foreign' ? $other : $account->client, $profile->provider, $profile->environment, $profile->profile_id));
                $this->fail('Invalid claim must fail closed.');
            } catch (RuntimeException) {
                $this->assertSame($account->client_id, $profile->fresh()->client_id);
                $this->assertSame($case !== 'inactive', $profile->fresh()->active);
            }
        }
    }

    public function test_scope_requires_active_claim_and_every_mapping_column_to_match(): void
    {
        $account = ClientAccount::factory()->create();
        $original = $account->getAttributes();
        $this->assertTrue($account->hasOwnedProfile());
        foreach (['client_id' => Client::factory()->create()->id, 'provider' => 'other', 'environment' => 'staging', 'profile_id' => 'other', 'client_provider_profile_id' => null] as $column => $value) {
            DB::table('client_accounts')->where('id', $account->id)->update([$column => $value]);
            $this->assertFalse($account->hasOwnedProfile(), $column);
            DB::table('client_accounts')->where('id', $account->id)->update($original);
        }
        $account->providerProfile->update(['active' => false]);
        $this->assertFalse($account->hasOwnedProfile());
        $this->assertSame(0, ClientAccount::query()->withOwnedProfile()->count());
    }

    public function test_foreign_legacy_accounts_block_a_claim_even_when_inactive(): void
    {
        $account = ClientAccount::factory()->create(['active' => false]);
        $profile = $account->providerProfile;
        $account->update(['client_provider_profile_id' => null]);
        $profile->delete();
        $this->expectException(RuntimeException::class);
        DB::transaction(fn () => app(ClientProviderProfileService::class)->claim(Client::factory()->create(), $account->provider, $account->environment, $account->profile_id));
    }

    public function test_migration_backfills_in_place_without_changing_mapping_or_evidence(): void
    {
        $client = Client::factory()->create();
        $accounts = ClientAccount::factory()->count(4)->create(['client_id' => $client->id, 'profile_id' => 'shared', 'publishing_enabled' => true]);
        $issued = app(AgentTokenService::class)->issue($client->owner, $client, 'Preserved', ['context.read'], $accounts->modelKeys());
        $operation = AgentOperation::factory()->create(['client_account_id' => $accounts->first()->id]);
        $tokenBefore = $issued->accessToken->fresh()->getAttributes();
        $operationBefore = $operation->fresh()->getAttributes();
        $before = DB::table('client_accounts')->orderBy('id')->get()->map(fn ($row) => collect((array) $row)->except('client_provider_profile_id')->all())->all();
        $migration = require glob(database_path('migrations/*create_client_provider_profiles_table.php'))[0];
        $migration->down();
        $migration->up();

        $this->assertDatabaseCount('client_provider_profiles', 1);
        $profile = ClientProviderProfile::query()->sole();
        $this->assertSame($client->id, $profile->client_id);
        $this->assertNull($profile->name);
        $this->assertTrue($profile->active);
        $this->assertSame(0, $profile->sync_version);
        $this->assertCount(4, $profile->accounts);
        $this->assertSame($before, DB::table('client_accounts')->orderBy('id')->get()->map(fn ($row) => collect((array) $row)->except('client_provider_profile_id')->all())->all());
        $this->assertSame($accounts->modelKeys(), ClientAccount::query()->withOwnedProfile()->pluck('id')->all());
        $this->assertSame($tokenBefore, $issued->accessToken->fresh()->getAttributes());
        $this->assertSame($operationBefore, $operation->fresh()->getAttributes());
    }

    public function test_four_accounts_share_one_claim_and_remain_visible_with_exact_token_scope(): void
    {
        Http::preventStrayRequests();
        config(['agent-api.zernio.enabled' => true]);
        Route::prefix('api')->middleware('api')->group(base_path('routes/agent-api.php'));
        $client = Client::factory()->create();
        $accounts = ClientAccount::factory()->count(4)->create(['client_id' => $client->id, 'profile_id' => 'shared']);
        $issued = app(AgentTokenService::class)->issue($client->owner, $client, 'All four', ['zernio.accounts.read'], $accounts->modelKeys());
        $this->assertCount(1, $client->providerProfiles);
        $this->assertSame($accounts->modelKeys(), $issued->accessToken->allowed_account_ids);
        $this->withToken($issued->plainTextToken)->getJson('/api/v1/zernio/accounts')->assertOk()->assertJsonCount(4, 'data');
        auth()->forgetGuards();
        $this->actingAs($client->owner)->withSession(['auth.password_confirmed_at' => time()])
            ->get(route('agent-tokens.index'))->assertInertia(fn (Assert $page) => $page->has('clients.0.accounts', 4));
        Http::assertNothingSent();
    }

    /** @return array<string, array{string}> */
    public static function invalidOwnership(): array
    {
        return ['missing' => ['missing'], 'inactive' => ['inactive'], 'foreign' => ['foreign'], 'provider' => ['provider'], 'environment' => ['environment'], 'profile' => ['profile']];
    }

    #[DataProvider('invalidOwnership')]
    public function test_invalid_claims_deny_api_tokens_settings_publish_and_reconciliation(string $case): void
    {
        Http::preventStrayRequests();
        config(['agent-api.zernio.enabled' => true, 'agent-api.zernio.publishing_enabled' => true]);
        Route::prefix('api')->middleware('api')->group(base_path('routes/agent-api.php'));
        $account = ClientAccount::factory()->create(['publishing_enabled' => true]);
        $client = $account->client;
        $issued = app(AgentTokenService::class)->issue($client->owner, $client, 'Before change', [...AgentTokenService::READ_ABILITIES, 'zernio.posts.publish'], [$account->id]);
        $operation = AgentOperation::factory()->create(['client_account_id' => $account->id, 'state' => 'unknown', 'recovery_post_id' => str_repeat('c', 24)]);
        $hash = $account->mappingHash();
        if ($case === 'missing') {
            $account->update(['client_provider_profile_id' => null]);
        } else {
            $change = match ($case) {
                'inactive' => ['active' => false],
                'foreign' => ['client_id' => Client::factory()->create()->id],
                'provider' => ['provider' => 'other'],
                'environment' => ['environment' => 'staging'],
                'profile' => ['profile_id' => 'different'],
            };
            $account->providerProfile->update($change);
        }
        $this->withToken($issued->plainTextToken)->getJson('/api/v1/zernio/accounts')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/zernio/accounts/'.$account->id.'/posts')->assertNotFound();
        auth()->forgetGuards();
        $this->actingAs($client->owner)->withSession(['auth.password_confirmed_at' => time()]);
        $this->get(route('agent-tokens.index'))->assertInertia(fn (Assert $page) => $page->has('clients.0.accounts', 0));
        $this->postJson(route('agent-tokens.store'), ['client_id' => $client->id, 'name' => 'Denied', 'allowed_account_ids' => [$account->id]])->assertUnprocessable();
        $this->postJson(route('agent-tokens.rotate', $issued->accessToken))->assertUnprocessable();
        try {
            app(AgentOperationService::class)->publish($issued->accessToken, $account, 'Denied', 'invalid-claim');
            $this->fail('Direct publishing must require profile ownership.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertSame('unknown', app(AgentOperationService::class)->reconcile($operation)->state);
        $this->assertSame($hash, $account->fresh()->mappingHash());
        $this->assertSame([$account->id], $issued->accessToken->fresh()->allowed_account_ids);
        $this->assertDatabaseCount('agent_operations', 1);
        Http::assertNothingSent();
    }

    public function test_foreign_admin_cannot_read_accounts_attached_to_another_clients_claim(): void
    {
        config(['agent-api.zernio.enabled' => true]);
        Http::preventStrayRequests();
        Route::prefix('api')->middleware('api')->group(base_path('routes/agent-api.php'));
        $account = ClientAccount::factory()->create();
        $other = Client::factory()->create();
        $other->owner->update(['role' => 'admin']);
        $foreign = ClientAccount::factory()->create(['client_id' => $other->id, 'profile_id' => $account->profile_id]);
        $this->assertSame($account->client_provider_profile_id, $foreign->client_provider_profile_id);
        $this->assertFalse($foreign->hasOwnedProfile());
        $issued = app(AgentTokenService::class)->issue($other->owner, $other, 'Admin', AgentTokenService::READ_ABILITIES);
        $this->withToken($issued->plainTextToken)->getJson('/api/v1/zernio/accounts')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/zernio/accounts/'.$account->id.'/posts')->assertNotFound();
        $this->getJson('/api/v1/zernio/accounts/'.$foreign->id.'/posts')->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_provider_success_cannot_be_recorded_after_profile_ownership_is_disabled_in_flight(): void
    {
        config(['agent-api.zernio.enabled' => true, 'agent-api.zernio.publishing_enabled' => true, 'agent-api.zernio.api_key' => 'test-only']);
        $account = ClientAccount::factory()->create(['publishing_enabled' => true]);
        $issued = app(AgentTokenService::class)->issue($account->client->owner, $account->client, 'Publisher', ['zernio.posts.publish'], [$account->id]);
        Http::preventStrayRequests();
        Http::fake(function (Request $request) use ($account) {
            if ($request->method() === 'GET') {
                return Http::response([
                    'accounts' => [['_id' => $account->provider_account_id, 'profileId' => $account->profile_id, 'platform' => 'linkedin', 'isActive' => true]],
                    'hasAnalyticsAccess' => false,
                    'pagination' => ['page' => 1, 'limit' => 25, 'total' => 1, 'pages' => 1],
                ]);
            }
            $account->providerProfile->update(['active' => false]);

            return Http::response(['post' => [
                '_id' => str_repeat('c', 24), 'profileId' => $account->profile_id, 'content' => 'Hello', 'status' => 'published',
                'platforms' => [['platform' => 'linkedin', 'accountId' => $account->provider_account_id, 'status' => 'published']],
            ]], 201);
        });

        $operation = app(AgentOperationService::class)->publish($issued->accessToken, $account, 'Hello', 'in-flight');

        $this->assertSame('executing', $operation->state);
        $this->assertNull($operation->provider_post_id);
        $this->assertNull($operation->result);
        Http::assertSentCount(2);
    }

    public function test_migration_rejects_cross_client_groups_before_any_ddl_including_inactive_rows(): void
    {
        ClientAccount::factory()->create(['profile_id' => 'shared']);
        ClientAccount::factory()->create(['profile_id' => 'shared', 'active' => false]);
        $migration = require glob(database_path('migrations/*create_client_provider_profiles_table.php'))[0];
        $migration->down();
        try {
            $migration->up();
            $this->fail('Ambiguous ownership must abort the migration.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Provider profile ownership conflicts must be resolved before migration.', $exception->getMessage());
            $this->assertFalse(Schema::hasTable('client_provider_profiles'));
            $this->assertFalse(Schema::hasColumn('client_accounts', 'client_provider_profile_id'));
            $this->assertDatabaseCount('client_accounts', 2);
        }
    }
}
