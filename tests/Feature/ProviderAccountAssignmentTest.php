<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientAccount;
use App\Models\ClientProviderProfile;
use App\Models\User;
use App\Services\AgentTokenService;
use App\Services\ProviderAccountAssignmentService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ProviderAccountAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private const ACCOUNT = 'aaaaaaaaaaaaaaaaaaaaaaaa';

    private const PROFILE = 'bbbbbbbbbbbbbbbbbbbbbbbb';

    private User $operator;

    private Client $client;

    protected function beforeRefreshingDatabase(): void
    {
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->assertEmpty(config('database.connections.sqlite.url'));
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Http::preventStrayRequests();
        config(['agent-api.zernio.enabled' => true, 'agent-api.zernio.api_key' => 'assignment-test-secret']);
        $this->operator = User::factory()->create(['role' => 'admin']);
        $this->operator->forceFill(['can_assign_provider_accounts' => true])->save();
        $this->client = Client::factory()->create(['owner_id' => User::factory()->create()->id]);
        $this->actingAs($this->operator, 'web')->withSession(['auth.password_confirmed_at' => time()]);
    }

    public function test_index_is_local_only_and_has_the_fixed_ui_contract(): void
    {
        ClientAccount::factory()->create(['client_id' => $this->client->id]);
        $this->get(route('provider-accounts.index'))->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertInertia(fn (Assert $page) => $page->component('settings/provider-accounts', false)
                ->has('clients.data', 1)->where('clients.data.0.owner.id', $this->client->owner_id)
                ->where('clients.pagination.limit', 25)->where('clients.pagination.has_more', false)
                ->where('selectedClientId', null)->where('selectedClient', null)->has('mappings', 0)->where('providerEnabled', true)->where('profileAssignmentsReady', true));
        $this->get(route('provider-accounts.index', ['client_id' => $this->client->id]))
            ->assertInertia(fn (Assert $page) => $page->component('settings/provider-accounts', false)
                ->where('selectedClientId', $this->client->id)->has('mappings', 1)->where('mappings.0.account_count', 1)->missing('mappings.0.accounts')->missing('mappings.0.mapping_hash'));
        Http::assertNothingSent();
    }

    public function test_assignment_page_renders_the_csrf_source_used_by_its_fetch_helper(): void
    {
        $this->withSession(['_token' => 'assignment-csrf-test'])
            ->get(route('provider-accounts.index'))->assertOk()
            ->assertSee('<meta name="csrf-token" content="assignment-csrf-test">', false);
        Http::assertNothingSent();
    }

    public function test_selected_client_outside_the_first_page_has_safe_fallback_details(): void
    {
        Client::factory()->count(25)->create();
        $selected = Client::factory()->create();
        $owner = $selected->owner;
        DB::enableQueryLog();
        $response = $this->get(route('provider-accounts.index', ['client_id' => $selected->id]));
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $response->assertOk()->assertInertia(fn (Assert $page) => $page->component('settings/provider-accounts', false)
            ->has('clients.data', 25)->where('selectedClientId', $selected->id)
            ->where('selectedClient', ['id' => $selected->id, 'name' => $selected->name,
                'owner' => ['id' => $owner->id, 'name' => $owner->name, 'email' => $owner->email]])
            ->where('clients.data', fn ($clients) => ! collect($clients)->contains('id', $selected->id)));
        $selectedOwnerQueries = array_filter($queries, fn ($query) => str_starts_with($query['query'], 'select "id", "name", "email", "email_verified_at" from "users"'));
        $this->assertCount(1, $selectedOwnerQueries);
        Http::assertNothingSent();
    }

    public function test_john_requires_both_admin_role_and_explicit_grant_then_can_use_every_assignment_route(): void
    {
        $john = User::factory()->create(['name' => 'John', 'role' => 'user']);
        $this->actingAs($john, 'web');
        $this->getJson(route('provider-accounts.index'))->assertForbidden();
        $john->forceFill(['role' => 'admin'])->save();
        $this->getJson(route('provider-accounts.index'))->assertForbidden();
        $john->forceFill(['can_assign_provider_accounts' => true])->save();
        $this->fakeAccounts();
        $this->get(route('provider-accounts.index'))->assertOk();
        $this->getJson(route('provider-accounts.clients'))->assertOk();
        $this->getJson(route('provider-accounts.discover', ['client_id' => $this->client->id]))->assertOk();
        $created = $this->postJson(route('provider-accounts.store'), $this->payload());
        $this->postJson(route('provider-accounts.store'), $this->payload())->assertOk();
        $this->assertSame('admin', $john->fresh()->role);
        $this->assertTrue($john->fresh()->canAssignProviderAccounts());
        $this->assertDatabaseCount('client_accounts', 1);
        $created->assertCreated();
    }

    public function test_admin_role_alone_and_non_admin_with_flag_are_denied_every_route(): void
    {
        foreach ([['role' => 'admin', 'can_assign_provider_accounts' => false], ['role' => 'user', 'can_assign_provider_accounts' => true]] as $attributes) {
            $this->operator->forceFill($attributes)->save();
            $this->client->update(['owner_id' => $this->operator->id]);
            $this->assertFalse(Gate::forUser($this->operator)->allows('assign-provider-accounts'));
            foreach (['index', 'clients', 'discover'] as $action) {
                $this->getJson(route('provider-accounts.'.$action, ['client_id' => $this->client->id]))->assertForbidden();
            }
            $this->postJson(route('provider-accounts.store'), $this->payload())->assertForbidden();
        }
        Http::assertNothingSent();
    }

    public function test_routes_require_web_session_verification_and_password_confirmation(): void
    {
        $this->assertTrue(Gate::forUser($this->operator)->allows('assign-provider-accounts'));
        $this->withSession(['auth.password_confirmed_at' => 0])->getJson(route('provider-accounts.clients'))->assertStatus(423);
        $this->postJson(route('provider-accounts.store'), $this->payload())->assertStatus(423);
        $this->operator->forceFill(['email_verified_at' => null])->save();
        $this->withSession(['auth.password_confirmed_at' => time()])->getJson(route('provider-accounts.clients'))->assertForbidden();
        $this->operator->forceFill(['email_verified_at' => now()])->save();
        $token = $this->operator->createToken('Bearer only')->plainTextToken;
        auth()->forgetGuards();
        $this->withToken($token)->getJson(route('provider-accounts.clients'))->assertUnauthorized();
        Http::assertNothingSent();
    }

    public function test_csrf_and_required_route_middleware_are_enforced(): void
    {
        $this->app->bind(PreventRequestForgery::class, fn ($app) => new class($app, $app['encrypter']) extends PreventRequestForgery
        {
            protected function runningUnitTests(): bool
            {
                return false;
            }
        });
        $this->postJson(route('provider-accounts.store'), $this->payload())->assertStatus(419);
        foreach (['web', 'auth:web', 'verified', 'password.confirm', 'can:assign-provider-accounts', 'throttle:30,1', 'cache.headers:no_store;private'] as $middleware) {
            $this->assertContains($middleware, Route::getRoutes()->getByName('provider-accounts.store')->gatherMiddleware());
        }
        Http::assertNothingSent();

        $this->fakeAccounts();
        $this->withSession(['_token' => 'assignment-csrf-test'])->postJson(
            route('provider-accounts.store'),
            $this->payload(),
            ['X-CSRF-TOKEN' => 'assignment-csrf-test'],
        )->assertCreated();
        Http::assertSentCount(2);
    }

    public function test_unknown_fields_and_non_strict_assignment_identifiers_are_rejected_without_io(): void
    {
        foreach ([['provider_account_id' => self::ACCOUNT], ['credentials' => 'secret'], ['platform' => 'linkedin'], ['owner_id' => 1], ['active' => true], ['publishing_enabled' => true], ['provider' => 'zernio'], ['environment' => 'production'], ['client_id' => (string) $this->client->id], ['profile_id' => 'invalid'], ['profile_id' => strtoupper(self::PROFILE)]] as $invalid) {
            $this->postJson(route('provider-accounts.store'), array_replace($this->payload(), $invalid))->assertUnprocessable();
        }
        $this->postJson(route('provider-accounts.store').'?unexpected=1', $this->payload())->assertUnprocessable();
        foreach (['client_id='.$this->client->id, 'client_id=999999', 'provider_account_id='.self::ACCOUNT] as $query) {
            $this->postJson(route('provider-accounts.store').'?'.$query, $this->payload())->assertUnprocessable();
        }
        $this->post(route('provider-accounts.store'), [...$this->payload(), 'attachment' => UploadedFile::fake()->create('payload.txt')], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->post(route('provider-accounts.store'), ['client_id' => $this->client->id, 'profile_id' => UploadedFile::fake()->create('profile.txt')], ['Accept' => 'application/json'])->assertUnprocessable();
        Http::assertNothingSent();
    }

    public function test_inactive_missing_unowned_and_unverified_owner_clients_never_trigger_io(): void
    {
        foreach ([['active' => false], ['owner_id' => null], ['owner_id' => User::factory()->unverified()->create()->id]] as $attributes) {
            $client = Client::factory()->create($attributes);
            $this->getJson(route('provider-accounts.discover', ['client_id' => $client->id]))->assertUnprocessable();
            $this->postJson(route('provider-accounts.store'), $this->payload($client->id))->assertUnprocessable();
        }
        $this->postJson(route('provider-accounts.store'), $this->payload(999999))->assertUnprocessable();
        Http::assertNothingSent();
    }

    public function test_discovery_is_one_bounded_real_provider_page_without_browser_scope_or_credentials(): void
    {
        $this->fakeAccounts();
        $this->getJson(route('provider-accounts.discover', ['client_id' => $this->client->id, 'page' => 2, 'limit' => 25, 'search' => 'demo']))
            ->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('data.0.id', self::PROFILE)
            ->assertJsonPath('data.0.name', 'Demo')->assertJsonPath('pagination.has_more', false)
            ->assertJsonMissingPath('data.0.accessToken');
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->method() === 'GET' && $request->url() === 'https://zernio.com/api/v1/profiles?limit=25&skip=25&name=demo'
            && $request->hasHeader('Authorization', 'Bearer assignment-test-secret') && ! isset($request['profileId']));
    }

    public function test_assignment_uses_canonical_values_and_safe_audit_metadata(): void
    {
        $transactionLevel = DB::transactionLevel();
        Http::fake(function ($request) use ($transactionLevel) {
            $this->assertSame($transactionLevel, DB::transactionLevel());

            return Http::response(str_contains($request->url(), '/profiles/') ? ['profile' => ['_id' => self::PROFILE, 'name' => 'Demo']] : $this->providerPayload([$this->account()], 1, 100));
        });
        Log::spy();
        $response = $this->postJson(route('provider-accounts.store'), $this->payload());
        $mapping = ClientAccount::query()->sole();
        $claim = ClientProviderProfile::query()->sole();
        $response->assertExactJson(['data' => [...$claim->only(ProviderAccountAssignmentService::PROFILE_FIELDS), 'account_count' => 1,
            'accounts' => [$mapping->only(ProviderAccountAssignmentService::MAPPING_FIELDS)]]]);
        $this->assertSame($claim->id, $mapping->client_provider_profile_id);
        $this->assertSame(self::PROFILE, $mapping->profile_id);
        $this->assertSame('linkedin', $mapping->platform);
        $this->assertSame('zernio', $mapping->provider);
        $this->assertSame('production', $mapping->environment);
        $this->assertTrue($mapping->active);
        $this->assertFalse($mapping->publishing_enabled);
        $this->assertSame($mapping->mappingHash(), $mapping->mapping_hash);
        Log::shouldHaveReceived('info')->once()->with('provider_profile_assignment', ['operator_id' => $this->operator->id, 'client_id' => $this->client->id, 'profile_claim_id' => $claim->id, 'account_count' => 1]);
        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/accounts?') && $request['profileId'] === self::PROFILE && $request['page'] === 1 && $request['limit'] === 100);
        $response->assertCreated();
    }

    public function test_assignment_grants_client_scoped_visibility_without_assignment_authority(): void
    {
        $john = User::factory()->create(['email' => 'johnkarlo.315@gmail.com', 'role' => 'admin']);
        $this->client->update(['owner_id' => $john->id]);
        $foreign = ClientAccount::factory()->create();
        $this->fakeAccounts(array_map(fn (string $platform, int $index): array => $this->account([
            '_id' => str_pad(dechex($index + 1), 24, '0', STR_PAD_LEFT), 'platform' => $platform,
        ]), ['linkedin', 'facebook', 'threads', 'bluesky'], range(0, 3)));
        $created = $this->postJson(route('provider-accounts.store'), $this->payload());
        $mappingId = $created->assertJsonCount(4, 'data.accounts')->assertJsonPath('data.account_count', 4)->json('data.accounts.0.id');
        $this->assertDatabaseCount('client_accounts', 5);
        $this->assertSame(1, ClientAccount::query()->where('client_id', $this->client->id)->distinct()->count('client_provider_profile_id'));

        $issued = app(AgentTokenService::class)->issue($john, $this->client, 'Assigned account test', AgentTokenService::READ_ABILITIES);
        $this->actingAs($john, 'web')->withToken($issued->plainTextToken);
        $this->getJson('/api/v1/zernio/accounts')->assertOk()->assertJsonCount(4, 'data')
            ->assertJsonPath('data.0.id', $mappingId);
        $this->getJson('/api/v1/zernio/accounts/'.$foreign->id.'/posts')->assertNotFound();
        $this->getJson(route('provider-accounts.discover', ['client_id' => $this->client->id]))->assertForbidden();
        $this->postJson(route('provider-accounts.store'), $this->payload())->assertForbidden();
        $this->assertSame('admin', $john->fresh()->role);
        $this->assertFalse($john->fresh()->canAssignProviderAccounts());
        Http::assertSentCount(2);
        $created->assertCreated();
    }

    public function test_duplicate_is_idempotent_and_preserves_publishing_and_timestamps(): void
    {
        $this->fakeAccounts();
        $mapping = $this->mapping(['publishing_enabled' => true]);
        $before = $mapping->refresh()->getAttributes();
        $this->travel(2)->minutes();
        $this->postJson(route('provider-accounts.store'), $this->payload())->assertOk()->assertJsonPath('data.accounts.0.id', $mapping->id)->assertJsonPath('data.accounts.0.publishing_enabled', true);
        $this->assertSame($before, $mapping->fresh()->getAttributes());
        $this->assertDatabaseCount('client_accounts', 1);
    }

    public function test_unique_constraint_races_return_safe_conflicts_without_sql_details(): void
    {
        $this->fakeAccounts();
        ClientAccount::creating(function (): void {
            throw new UniqueConstraintViolationException('sqlite', 'INSERT PRIVATE-SQL', [], new PDOException('PRIVATE-DB-DETAILS'));
        });
        $response = $this->postJson(route('provider-accounts.store'), $this->payload())->assertConflict();
        $this->assertStringNotContainsString('PRIVATE-SQL', $response->getContent());
        $this->assertStringNotContainsString('PRIVATE-DB-DETAILS', $response->getContent());
        $this->assertDatabaseCount('client_accounts', 0);
    }

    public function test_service_rejects_a_stale_operator_before_any_provider_io(): void
    {
        User::query()->whereKey($this->operator->id)->update(['can_assign_provider_accounts' => false]);
        $this->assertTrue($this->operator->canAssignProviderAccounts());
        try {
            app(ProviderAccountAssignmentService::class)->assign($this->operator, $this->client, self::ACCOUNT);
            $this->fail('Revoked operators must not assign accounts.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        Http::assertNothingSent();
        $this->assertDatabaseCount('client_accounts', 0);
    }

    public function test_existing_foreign_inactive_or_changed_mapping_conflicts_without_overwriting(): void
    {
        $this->fakeAccounts();
        foreach ([['client_id' => Client::factory()->create()->id], ['profile_id' => 'cccccccccccccccccccccccc'], ['platform' => 'facebook']] as $attributes) {
            $mapping = $this->mapping($attributes);
            $before = $mapping->refresh()->getAttributes();
            $this->postJson(route('provider-accounts.store'), $this->payload())->assertConflict();
            $this->assertSame($before, $mapping->fresh()->getAttributes());
            $mapping->delete();
            ClientProviderProfile::query()->delete();
        }
    }

    public function test_duplicate_accounts_and_foreign_profile_metadata_are_not_trusted(): void
    {
        $cases = [[$this->account(['profileId' => 'cccccccccccccccccccccccc'])], [$this->account(), $this->account()]];
        foreach ($cases as $accounts) {
            $this->fakeAccounts($accounts);
            $this->postJson(route('provider-accounts.store'), $this->payload())->assertStatus(502);
        }
        $this->assertDatabaseCount('client_accounts', 0);
        $this->assertDatabaseCount('client_provider_profiles', 0);
    }

    public function test_provider_failures_and_disabled_configuration_have_safe_json_envelopes(): void
    {
        Http::fake(['https://zernio.com/*' => Http::response(['private' => 'UPSTREAM-SECRET'], 500)]);
        $response = $this->postJson(route('provider-accounts.store'), $this->payload())->assertStatus(502)->assertJsonPath('error.code', 'provider_unavailable');
        $this->assertStringNotContainsString('UPSTREAM-SECRET', $response->getContent());
        config(['agent-api.zernio.enabled' => false]);
        $this->getJson(route('provider-accounts.discover', ['client_id' => $this->client->id]))->assertStatus(503)->assertJsonPath('error.code', 'provider_not_configured');
        $this->assertDatabaseCount('client_accounts', 0);
    }

    public function test_revoked_operator_or_changed_client_during_provider_get_prevents_write(): void
    {
        $change = null;
        Http::fake(function () use (&$change) {
            match ($change) {
                'permission' => User::query()->whereKey($this->operator->id)->update(['can_assign_provider_accounts' => false]),
                'owner' => Client::query()->whereKey($this->client->id)->update(['owner_id' => User::factory()->create()->id]),
                'active' => Client::query()->whereKey($this->client->id)->update(['active' => false]),
                'verification' => User::query()->whereKey($this->client->owner_id)->update(['email_verified_at' => null]),
            };

            return Http::response(['profile' => ['_id' => self::PROFILE, 'name' => 'Demo']]);
        });
        foreach (['permission', 'owner', 'active', 'verification'] as $change) {
            $this->operator->forceFill(['can_assign_provider_accounts' => true])->save();
            $this->client->update(['active' => true, 'owner_id' => User::factory()->create()->id]);
            $this->postJson(route('provider-accounts.store'), $this->payload())->assertForbidden();
        }
        $this->assertDatabaseCount('client_accounts', 0);
    }

    public function test_client_browse_is_bounded_searchable_and_eager_loads_owners(): void
    {
        Client::factory()->count(30)->create(['name' => 'Searchable']);
        Client::factory()->create(['name' => 'Searchable', 'active' => false]);
        DB::enableQueryLog();
        $response = $this->getJson(route('provider-accounts.clients', ['search' => 'Searchable', 'page' => 2]));
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $response->assertOk()->assertJsonCount(5, 'data')->assertJsonPath('pagination.total', 30)->assertJsonPath('pagination.page', 2)
            ->assertJsonStructure(['data' => [['id', 'name', 'owner' => ['id', 'name', 'email']]], 'pagination' => ['page', 'limit', 'total', 'pages', 'has_more']]);
        $ownerQueries = array_filter($queries, fn ($query) => preg_match('/^select (?:\*|"id", "name", "email") from "users"/', $query['query']));
        $this->assertLessThanOrEqual(5, count($ownerQueries));
        Http::assertNothingSent();
    }

    public function test_client_search_matches_client_name_owner_name_and_email_case_insensitively(): void
    {
        $this->client->update(['name' => 'Unrelated workspace']);
        $this->client->owner->update(['name' => 'Unrelated owner', 'email' => 'unrelated@example.test']);
        $john = User::factory()->create(['name' => 'John Karlo', 'email' => 'johnkarlo.315@gmail.com']);
        $client = Client::factory()->create(['owner_id' => $john->id, 'name' => 'Local agent test']);

        Client::factory()->create([
            'name' => 'Local agent test inactive', 'active' => false,
            'owner_id' => User::factory()->create(['name' => 'John Karlo'])->id,
        ]);
        Client::factory()->create([
            'name' => 'Local agent test unverified',
            'owner_id' => User::factory()->unverified()->create(['name' => 'John Karlo'])->id,
        ]);
        Client::factory()->create(['name' => 'John Karlo orphan', 'owner_id' => null]);

        foreach (['John Karlo', 'john karlo', 'JOHNKARLO.315@GMAIL.COM', 'johnkarlo.315', 'Local agent test', 'local AGENT TEST'] as $search) {
            $this->getJson(route('provider-accounts.clients', ['search' => $search]))
                ->assertOk()->assertJsonCount(1, 'data')
                ->assertJsonPath('data.0.id', $client->id)
                ->assertJsonPath('data.0.owner.email', $john->email)
                ->assertJsonPath('pagination.total', 1);
        }
        Http::assertNothingSent();
    }

    public function test_pagination_search_and_unknown_discovery_parameters_are_validated_before_io(): void
    {
        foreach (['clients', 'discover'] as $action) {
            foreach ([['page' => 1001], ['page' => 0], ['limit' => 101], ['limit' => 0], ['search' => str_repeat('a', 101)], ['search' => "x\x01y"], ['profile_id' => self::PROFILE], ['api_key' => 'browser-key']] as $invalid) {
                $query = array_replace($action === 'discover' ? ['client_id' => $this->client->id] : [], $invalid);
                $this->getJson(route('provider-accounts.'.$action, $query))->assertUnprocessable();
            }
        }
        Http::assertNothingSent();
    }

    public function test_empty_profile_is_exclusively_reserved_even_when_another_client_has_different_accounts(): void
    {
        $this->fakeAccounts([]);
        $created = $this->postJson(route('provider-accounts.store'), $this->payload());
        $claimId = $created->assertJsonPath('data.account_count', 0)->assertJsonPath('data.accounts', [])->json('data.id');
        $this->fakeAccounts([$this->account(['_id' => 'cccccccccccccccccccccccc'])]);
        $other = Client::factory()->create(['owner_id' => $this->operator->id]);
        $this->postJson(route('provider-accounts.store'), $this->payload($other->id))->assertConflict();
        $this->assertDatabaseCount('client_provider_profiles', 1);
        $this->assertDatabaseHas('client_provider_profiles', ['id' => $claimId, 'client_id' => $this->client->id]);
        $this->assertDatabaseCount('client_accounts', 0);
        $created->assertCreated();
    }

    public function test_explicit_sync_adds_new_accounts_deactivates_missing_and_never_revives_inactive_rows(): void
    {
        $this->fakeAccounts();
        $created = $this->postJson(route('provider-accounts.store'), $this->payload());
        $claimId = $created->json('data.id');
        $original = ClientAccount::query()->sole();
        $original->update(['publishing_enabled' => true]);
        $hash = $original->mapping_hash;
        $this->travel(2)->minutes();
        $this->fakeAccounts([$this->account(['_id' => 'cccccccccccccccccccccccc', 'isActive' => false])]);
        $this->postJson(route('provider-accounts.store'), $this->payload())->assertOk()->assertJsonPath('data.id', $claimId)
            ->assertJsonPath('data.account_count', 1)->assertJsonCount(1, 'data.accounts')->assertJsonPath('data.accounts.0.active', false);
        $this->assertFalse($original->fresh()->active);
        $this->assertTrue($original->fresh()->publishing_enabled);
        $this->assertSame($hash, $original->fresh()->mapping_hash);
        $this->assertDatabaseCount('client_accounts', 2);
        $this->fakeAccounts([$this->account(), $this->account(['_id' => 'cccccccccccccccccccccccc'])]);
        $this->postJson(route('provider-accounts.store'), $this->payload())->assertOk()
            ->assertJsonCount(2, 'data.accounts')->assertJsonPath('data.accounts.0.id', $original->id)
            ->assertJsonPath('data.accounts.0.active', false)->assertJsonPath('data.accounts.0.publishing_enabled', true)
            ->assertJsonPath('data.accounts.1.active', false);
        $this->assertSame(now()->toDateTimeString(), ClientProviderProfile::findOrFail($claimId)->last_synced_at->toDateTimeString());
        $created->assertCreated();
    }

    public function test_upstream_inactive_deactivates_existing_active_without_resetting_publishing(): void
    {
        $mapping = $this->mapping(['publishing_enabled' => true]);
        $this->fakeAccounts([$this->account(['isActive' => false])]);
        $this->postJson(route('provider-accounts.store'), $this->payload())->assertOk()
            ->assertJsonPath('data.accounts.0.id', $mapping->id)->assertJsonPath('data.accounts.0.active', false)
            ->assertJsonPath('data.accounts.0.publishing_enabled', true);
    }

    public function test_page_two_failure_leaves_no_claim_or_partial_mappings(): void
    {
        $this->fakePagedAccounts(101, failSecond: true);
        $this->postJson(route('provider-accounts.store'), $this->payload())->assertStatus(502);
        Http::assertSentCount(3);
        $this->assertDatabaseCount('client_provider_profiles', 0);
        $this->assertDatabaseCount('client_accounts', 0);
    }

    public function test_three_complete_pages_import_all_300_accounts_with_four_reads(): void
    {
        $this->fakePagedAccounts(300);
        $created = $this->postJson(route('provider-accounts.store'), $this->payload())
            ->assertJsonCount(300, 'data.accounts')->assertJsonPath('data.account_count', 300);
        Http::assertSentCount(4);
        $this->assertDatabaseCount('client_accounts', 300);
        $this->assertDatabaseCount('client_provider_profiles', 1);
        $created->assertCreated();
    }

    public function test_oversized_profile_is_rejected_without_claiming_or_fetching_more_pages(): void
    {
        $this->fakePagedAccounts(301);
        $this->postJson(route('provider-accounts.store'), $this->payload())->assertUnprocessable();
        Http::assertSentCount(2);
        $this->assertDatabaseCount('client_provider_profiles', 0);
        $this->assertDatabaseCount('client_accounts', 0);
    }

    public function test_changed_totals_short_pages_duplicate_ids_and_wrong_page_metadata_abort_atomically(): void
    {
        foreach (['total', 'short', 'duplicate', 'page', 'limit', 'pages', 'profile'] as $corruption) {
            $this->fakePagedAccounts(101, corruption: $corruption);
            $this->postJson(route('provider-accounts.store'), $this->payload())->assertStatus(502);
            $this->assertDatabaseCount('client_provider_profiles', 0);
            $this->assertDatabaseCount('client_accounts', 0);
        }
    }

    public function test_account_conflict_rolls_back_a_new_claim_and_all_other_mappings(): void
    {
        $foreign = $this->mapping(['client_id' => Client::factory()->create()->id, 'profile_id' => 'dddddddddddddddddddddddd']);
        $before = $foreign->refresh()->getAttributes();
        $this->fakeAccounts([$this->account(['_id' => 'cccccccccccccccccccccccc']), $this->account()]);
        $this->postJson(route('provider-accounts.store'), $this->payload())->assertConflict();
        $this->assertDatabaseCount('client_provider_profiles', 1);
        $this->assertDatabaseMissing('client_provider_profiles', ['profile_id' => self::PROFILE]);
        $this->assertDatabaseCount('client_accounts', 1);
        $this->assertSame($before, $foreign->fresh()->getAttributes());
    }

    public function test_profile_get_wrong_identity_and_not_found_never_claim(): void
    {
        Http::fake(['https://zernio.com/*' => Http::sequence()
            ->push(['profile' => ['_id' => self::ACCOUNT, 'name' => 'Wrong']])
            ->push(['secret' => 'PRIVATE-PROFILE'], 404)]);
        $this->postJson(route('provider-accounts.store'), $this->payload())->assertStatus(502);
        $response = $this->postJson(route('provider-accounts.store'), $this->payload())->assertNotFound()->assertJsonPath('error.code', 'provider_profile_not_found');
        $this->assertStringNotContainsString('PRIVATE-PROFILE', $response->getContent());
        $this->assertDatabaseCount('client_provider_profiles', 0);
    }

    public function test_missing_profile_table_keeps_index_local_and_assignment_unavailable(): void
    {
        Schema::shouldReceive('hasTable')->with('client_provider_profiles')->andReturn(false);
        $this->get(route('provider-accounts.index', ['client_id' => $this->client->id]))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('settings/provider-accounts', false)
                ->where('profileAssignmentsReady', false)->has('mappings', 0)->where('providerEnabled', true));
        $this->postJson(route('provider-accounts.store'), $this->payload())->assertStatus(503)->assertJsonPath('error.code', 'profile_assignments_not_ready');
        $this->getJson(route('provider-accounts.discover', ['client_id' => $this->client->id]))->assertStatus(503);
        Http::assertNothingSent();
    }

    public function test_missing_account_fk_keeps_index_local_and_assignment_unavailable(): void
    {
        Schema::shouldReceive('hasTable')->with('client_provider_profiles')->andReturn(true);
        Schema::shouldReceive('hasTable')->with('client_accounts')->andReturn(true);
        Schema::shouldReceive('hasColumn')->with('client_accounts', 'client_provider_profile_id')->andReturn(false);
        $this->get(route('provider-accounts.index', ['client_id' => $this->client->id]))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('settings/provider-accounts', false)
                ->where('profileAssignmentsReady', false)->has('mappings', 0));
        $this->postJson(route('provider-accounts.store'), $this->payload())->assertStatus(503);
        Http::assertNothingSent();
    }

    public function test_missing_sync_version_disables_assignment_before_any_provider_io(): void
    {
        Schema::shouldReceive('hasTable')->with('client_provider_profiles')->andReturn(true);
        Schema::shouldReceive('hasTable')->with('client_accounts')->andReturn(true);
        Schema::shouldReceive('hasColumn')->with('client_accounts', 'client_provider_profile_id')->andReturn(true);
        Schema::shouldReceive('hasColumn')->with('client_provider_profiles', 'sync_version')->andReturn(false);
        $this->get(route('provider-accounts.index', ['client_id' => $this->client->id]))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('settings/provider-accounts', false)
                ->where('profileAssignmentsReady', false)->has('mappings', 0));
        $this->postJson(route('provider-accounts.store'), $this->payload())->assertStatus(503);
        Http::assertNothingSent();
    }

    public function test_index_caps_profile_claims_counts_accounts_and_allows_null_backfill_names(): void
    {
        $first = ClientProviderProfile::factory()->create(['client_id' => $this->client->id, 'name' => null]);
        ClientAccount::factory()->count(4)->create([
            'client_id' => $this->client->id, 'client_provider_profile_id' => $first->id,
            'profile_id' => $first->profile_id, 'provider' => $first->provider, 'environment' => $first->environment,
        ]);
        ClientProviderProfile::factory()->count(26)->create(['client_id' => $this->client->id]);
        DB::enableQueryLog();
        $response = $this->get(route('provider-accounts.index', ['client_id' => $this->client->id]));
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $response->assertOk()->assertInertia(fn (Assert $page) => $page->component('settings/provider-accounts', false)
            ->has('mappings', 25)->where('mappings.0.id', $first->id)->where('mappings.0.name', null)
            ->where('mappings.0.account_count', 4)->missing('mappings.0.accounts')->missing('mappings.0.provider'));
        $profileQueries = array_filter($queries, fn (array $query): bool => str_contains($query['query'], 'from "client_provider_profiles"'));
        $this->assertCount(1, $profileQueries);
        Http::assertNothingSent();
    }

    public function test_inactive_claim_is_not_revived_and_foreign_fk_is_not_repointed(): void
    {
        $mapping = $this->mapping();
        $claim = ClientProviderProfile::findOrFail($mapping->client_provider_profile_id);
        $claim->update(['active' => false]);
        $this->fakeAccounts();
        $this->postJson(route('provider-accounts.store'), $this->payload())->assertConflict();
        $this->assertFalse($claim->fresh()->active);
        $claim->update(['active' => true]);
        $other = ClientProviderProfile::factory()->create();
        $mapping->update(['client_provider_profile_id' => $other->id]);
        $this->postJson(route('provider-accounts.store'), $this->payload())->assertConflict();
        $this->assertSame($other->id, $mapping->fresh()->client_provider_profile_id);
        $this->assertNull($claim->fresh()->last_synced_at);
    }

    public function test_mid_account_read_revocation_prevents_all_writes(): void
    {
        Http::fake(['https://zernio.com/*' => function ($request) {
            if (str_contains($request->url(), '/profiles/')) {
                return Http::response(['profile' => ['_id' => self::PROFILE, 'name' => 'Demo']]);
            }
            User::query()->whereKey($this->operator->id)->update(['can_assign_provider_accounts' => false]);

            return Http::response($this->providerPayload([$this->account()], 1, 100));
        }]);
        $this->postJson(route('provider-accounts.store'), $this->payload())->assertForbidden();
        $this->assertDatabaseCount('client_provider_profiles', 0);
        $this->assertDatabaseCount('client_accounts', 0);
        Http::assertSentCount(2);
    }

    public function test_empty_profile_with_zero_pages_can_still_be_reserved(): void
    {
        Http::fake(['https://zernio.com/*' => fn ($request) => Http::response(
            str_contains($request->url(), '/profiles/') ? ['profile' => ['_id' => self::PROFILE, 'name' => 'Empty']]
                : ['accounts' => [], 'hasAnalyticsAccess' => true, 'pagination' => ['page' => 1, 'limit' => 100, 'total' => 0, 'pages' => 0]],
        )]);
        $this->postJson(route('provider-accounts.store'), $this->payload())->assertCreated()->assertJsonPath('data.account_count', 0);
        $this->assertDatabaseCount('client_accounts', 0);
        $this->assertDatabaseCount('client_provider_profiles', 1);
    }

    public function test_legacy_unlinked_rows_missing_from_complete_sync_are_deactivated(): void
    {
        $mapping = $this->mapping();
        $mapping->update(['client_provider_profile_id' => null]);
        $this->fakeAccounts([]);
        $this->postJson(route('provider-accounts.store'), $this->payload())->assertOk()->assertJsonCount(0, 'data.accounts');
        $this->assertFalse($mapping->fresh()->active);
        $this->assertDatabaseCount('client_accounts', 1);
    }

    #[DataProvider('overlappingSyncs')]
    public function test_stale_snapshot_cannot_overwrite_a_newer_sync(bool $existingClaim, bool $newerEmpty): void
    {
        if ($existingClaim) {
            ClientProviderProfile::factory()->create(['client_id' => $this->client->id, 'profile_id' => self::PROFILE]);
        }
        $nested = false;
        $winner = null;
        $transactionLevel = DB::transactionLevel();
        Http::fake(['https://zernio.com/*' => function ($request) use (&$nested, &$winner, $newerEmpty, $transactionLevel) {
            $this->assertSame($transactionLevel, DB::transactionLevel());
            if (str_contains($request->url(), '/profiles/')) {
                return Http::response(['profile' => ['_id' => self::PROFILE, 'name' => $nested ? 'Newer' : 'Older']]);
            }
            if ($nested) {
                return Http::response($this->providerPayload($newerEmpty ? [] : [$this->account(['_id' => 'cccccccccccccccccccccccc'])], 1, 100));
            }
            $nested = true;
            $winner = app(ProviderAccountAssignmentService::class)->assign($this->operator, $this->client, self::PROFILE);
            $nested = false;

            return Http::response($this->providerPayload($newerEmpty ? [$this->account()] : [], 1, 100));
        }]);

        $this->postJson(route('provider-accounts.store'), $this->payload())->assertConflict();
        $claim = ClientProviderProfile::query()->sole();
        $this->assertSame($winner->id, $claim->id);
        $this->assertSame('Newer', $claim->name);
        $this->assertSame(1, $claim->sync_version);
        $this->assertSame($winner->last_synced_at->toDateTimeString(), $claim->last_synced_at->toDateTimeString());
        $this->assertSame($existingClaim, ! $winner->wasRecentlyCreated);
        $this->assertDatabaseCount('client_accounts', $newerEmpty ? 0 : 1);
        $this->assertDatabaseMissing('client_accounts', ['provider_account_id' => self::ACCOUNT]);
        if (! $newerEmpty) {
            $this->assertDatabaseHas('client_accounts', [
                'client_provider_profile_id' => $claim->id, 'provider_account_id' => 'cccccccccccccccccccccccc', 'active' => true,
            ]);
        }
        Http::assertSentCount(4);
    }

    /** @return array<string, array{bool, bool}> */
    public static function overlappingSyncs(): array
    {
        return [
            'new claim newer account' => [false, false],
            'new claim newer empty' => [false, true],
            'existing claim newer account' => [true, false],
            'existing claim newer empty' => [true, true],
        ];
    }

    public function test_replaced_claim_with_the_same_version_rejects_the_old_snapshot(): void
    {
        $baseline = ClientProviderProfile::factory()->create(['client_id' => $this->client->id, 'profile_id' => self::PROFILE]);
        $replacement = null;
        Http::fake(['https://zernio.com/*' => function ($request) use ($baseline, &$replacement) {
            if (str_contains($request->url(), '/profiles/')) {
                $baseline->delete();
                $replacement = ClientProviderProfile::factory()->create([
                    'client_id' => $this->client->id, 'profile_id' => self::PROFILE, 'name' => 'Replacement',
                ]);

                return Http::response(['profile' => ['_id' => self::PROFILE, 'name' => 'Old snapshot']]);
            }

            return Http::response($this->providerPayload([$this->account()], 1, 100));
        }]);
        $this->postJson(route('provider-accounts.store'), $this->payload())->assertConflict();
        $this->assertNotSame($baseline->id, $replacement->id);
        $this->assertSame('Replacement', $replacement->fresh()->name);
        $this->assertSame(0, $replacement->fresh()->sync_version);
        $this->assertNull($replacement->fresh()->last_synced_at);
        $this->assertDatabaseCount('client_accounts', 0);
    }

    public function test_new_claim_baseline_is_captured_before_the_profile_get(): void
    {
        $nested = false;
        Http::fake(['https://zernio.com/*' => function ($request) use (&$nested) {
            if (str_contains($request->url(), '/profiles/')) {
                if (! $nested) {
                    $nested = true;
                    app(ProviderAccountAssignmentService::class)->assign($this->operator, $this->client, self::PROFILE);
                    $nested = false;
                }

                return Http::response(['profile' => ['_id' => self::PROFILE, 'name' => $nested ? 'Newer' : 'Older']]);
            }

            return Http::response($this->providerPayload($nested ? [$this->account()] : [], 1, 100));
        }]);
        $this->postJson(route('provider-accounts.store'), $this->payload())->assertConflict();
        $claim = ClientProviderProfile::query()->sole();
        $this->assertSame('Newer', $claim->name);
        $this->assertSame(1, $claim->sync_version);
        $this->assertTrue(ClientAccount::query()->sole()->active);
        Http::assertSentCount(4);
    }

    public function test_operator_and_known_owner_are_locked_in_one_sorted_query_before_the_client(): void
    {
        $this->fakeAccounts();
        foreach (['operator_first', 'owner_first', 'same_user'] as $order) {
            if ($order === 'owner_first') {
                $this->operator = User::factory()->create(['role' => 'admin']);
                $this->operator->forceFill(['can_assign_provider_accounts' => true])->save();
                $this->actingAs($this->operator, 'web');
            } elseif ($order === 'same_user') {
                $this->client->update(['owner_id' => $this->operator->id]);
            }
            DB::flushQueryLog();
            DB::enableQueryLog();
            $response = $this->postJson(route('provider-accounts.store'), $this->payload());
            $queries = DB::getQueryLog();
            DB::disableQueryLog();
            $response->assertSuccessful();
            $locks = array_filter($queries, fn (array $query): bool => str_starts_with($query['query'], 'select * from "users" where "id" in ('));
            $this->assertCount(1, $locks);
            $index = array_key_first($locks);
            $this->assertStringContainsString('order by "id" asc', $queries[$index]['query']);
            $this->assertEqualsCanonicalizing(array_values(array_unique([$this->operator->id, $this->client->owner_id])), $queries[$index]['bindings']);
            $nextQueries = array_values(array_filter(array_slice($queries, $index + 1),
                fn (array $query): bool => ! str_starts_with($query['query'], 'select * from "users"')));
            $this->assertStringStartsWith('select * from "clients"', $nextQueries[0]['query']);
        }
    }

    public function test_fresh_replay_advances_the_version_and_failed_sync_does_not(): void
    {
        $mapping = $this->mapping();
        $claim = ClientProviderProfile::findOrFail($mapping->client_provider_profile_id);
        $this->assertSame(0, $claim->sync_version);
        $this->fakeAccounts();
        $this->postJson(route('provider-accounts.store'), $this->payload())->assertOk();
        $this->assertSame(1, $claim->fresh()->sync_version);
        $this->postJson(route('provider-accounts.store'), $this->payload())->assertOk();
        $before = $claim->fresh()->getAttributes();
        $this->assertSame(2, $claim->fresh()->sync_version);
        $this->fakePagedAccounts(101, failSecond: true);
        $this->postJson(route('provider-accounts.store'), $this->payload())->assertStatus(502);
        $this->assertSame($before, $claim->fresh()->getAttributes());
    }

    private function fakePagedAccounts(int $total, bool $failSecond = false, ?string $corruption = null): void
    {
        Http::swap(new HttpFactory);
        Http::preventStrayRequests();
        $transactionLevel = DB::transactionLevel();
        Http::fake(['https://zernio.com/*' => function ($request) use ($total, $failSecond, $corruption, $transactionLevel) {
            $this->assertSame($transactionLevel, DB::transactionLevel());
            if (str_contains($request->url(), '/profiles/')) {
                return Http::response(['profile' => ['_id' => self::PROFILE, 'name' => 'Demo']]);
            }
            $page = (int) $request['page'];
            if ($page === 2 && $failSecond) {
                return Http::response(['private' => 'UPSTREAM-SECRET'], 500);
            }
            $accounts = array_map(fn (int $id): array => $this->account(['_id' => str_pad(dechex($id), 24, '0', STR_PAD_LEFT)]),
                range(($page - 1) * 100 + 1, min($page * 100, $total)));
            $pagination = ['page' => $page, 'limit' => 100, 'total' => $total, 'pages' => (int) ceil($total / 100)];
            if ($page === 2) {
                match ($corruption) {
                    'total' => $pagination['total']++,
                    'short' => array_pop($accounts),
                    'duplicate' => $accounts[0]['_id'] = str_pad('1', 24, '0', STR_PAD_LEFT),
                    'page' => $pagination['page'] = 1,
                    'limit' => $pagination['limit'] = 25,
                    'pages' => $pagination['pages'] = 3,
                    'profile' => $accounts[0]['profileId'] = self::ACCOUNT,
                    default => null,
                };
            }

            return Http::response(['accounts' => $accounts, 'hasAnalyticsAccess' => true, 'pagination' => $pagination]);
        }]);
    }

    private function payload(?int $clientId = null): array
    {
        return ['client_id' => $clientId ?? $this->client->id, 'profile_id' => self::PROFILE];
    }

    private function account(array $attributes = []): array
    {
        return array_replace(['_id' => self::ACCOUNT, 'profileId' => ['_id' => self::PROFILE], 'platform' => 'linkedin', 'username' => 'demo', 'displayName' => 'Demo', 'isActive' => true, 'accessToken' => 'UPSTREAM-SECRET'], $attributes);
    }

    private function providerPayload(array $accounts, int $page = 1, int $limit = 25): array
    {
        return ['accounts' => $accounts, 'hasAnalyticsAccess' => true, 'pagination' => ['page' => $page, 'limit' => $limit, 'total' => count($accounts), 'pages' => $page]];
    }

    private function fakeAccounts(?array $accounts = null): void
    {
        Http::swap(new HttpFactory);
        Http::preventStrayRequests();
        Http::fake(['https://zernio.com/*' => function ($request) use ($accounts) {
            if (str_contains($request->url(), '/profiles/')) {
                return Http::response(['profile' => ['_id' => self::PROFILE, 'name' => 'Demo']]);
            }
            if (str_contains($request->url(), '/profiles?')) {
                return Http::response(['profiles' => [['_id' => self::PROFILE, 'name' => 'Demo', 'accountCount' => 1]],
                    'skip' => (int) $request['skip'], 'limit' => (int) $request['limit'], 'total' => 26]);
            }

            return Http::response($this->providerPayload($accounts ?? [$this->account()], (int) $request['page'], (int) $request['limit']));
        }]);
    }

    private function mapping(array $attributes = []): ClientAccount
    {
        return ClientAccount::factory()->create(array_replace(['client_id' => $this->client->id, 'provider' => 'zernio', 'environment' => 'production', 'provider_account_id' => self::ACCOUNT, 'profile_id' => self::PROFILE, 'platform' => 'linkedin', 'active' => true, 'publishing_enabled' => false], $attributes));
    }
}
