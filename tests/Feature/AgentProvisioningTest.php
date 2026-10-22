<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientAccount;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AgentProvisioningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    protected function beforeRefreshingDatabase(): void
    {
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->assertEmpty(config('database.connections.sqlite.url'));
    }

    protected function tearDown(): void
    {
        Http::assertNothingSent();

        parent::tearDown();
    }

    public function test_client_only_provisioning_is_idempotent_and_leaves_all_users_unchanged(): void
    {
        $owner = User::factory()->create();
        User::factory()->create(['role' => 'admin']);
        $users = DB::table('users')->orderBy('id')->get()->toJson();

        foreach ([1, 2] as $attempt) {
            $this->artisan('agent:provision-client', ['owner_email' => $owner->email, 'name' => 'Editorial'])
                ->expectsOutput('Client ID: 1')
                ->assertSuccessful();
        }

        $this->assertDatabaseCount('clients', 1);
        $this->assertDatabaseHas('clients', ['owner_id' => $owner->id, 'name' => 'Editorial', 'active' => true]);
        $this->assertDatabaseCount('client_accounts', 0);
        $this->assertSame($users, DB::table('users')->orderBy('id')->get()->toJson());
    }

    public function test_owner_must_exist_be_verified_and_match_email_exactly(): void
    {
        $owner = User::factory()->create(['email' => 'owner@example.test']);
        $unverified = User::factory()->unverified()->create();

        foreach (['missing@example.test', 'OWNER@example.test', ' owner@example.test', $unverified->email] as $email) {
            $this->artisan('agent:provision-client', ['owner_email' => $email, 'name' => 'Editorial'])
                ->assertFailed();
        }

        $this->assertDatabaseCount('clients', 0);
        $this->assertDatabaseCount('users', 2);
        $this->assertSame('owner@example.test', $owner->fresh()->email);
    }

    public function test_client_matching_is_scoped_to_exact_owner_and_name(): void
    {
        $owner = User::factory()->create();
        $other = Client::factory()->create(['name' => 'Editorial']);
        Client::factory()->create(['owner_id' => null, 'name' => 'Editorial']);
        Client::factory()->create(['owner_id' => $owner->id, 'name' => 'editorial']);

        $this->artisan('agent:provision-client', ['owner_email' => $owner->email, 'name' => 'Editorial'])
            ->assertSuccessful();

        $this->assertDatabaseCount('clients', 4);
        $this->assertDatabaseHas('clients', ['owner_id' => $owner->id, 'name' => 'Editorial']);
        $this->assertNotSame($owner->id, $other->fresh()->owner_id);
    }

    public function test_inactive_client_is_not_reactivated_or_given_an_account(): void
    {
        $owner = User::factory()->create();
        $client = Client::factory()->create(['owner_id' => $owner->id, 'name' => 'Editorial', 'active' => false]);
        $before = $client->refresh()->getAttributes();

        $this->artisan('agent:provision-client', $this->mapping($owner))->assertFailed();

        $this->assertSame($before, $client->fresh()->getAttributes());
        $this->assertDatabaseCount('clients', 1);
        $this->assertDatabaseCount('client_accounts', 0);
    }

    #[DataProvider('platforms')]
    public function test_new_mapping_defaults_to_production_read_only_and_publishing_requires_opt_in(string $platform): void
    {
        $owner = User::factory()->create();
        $parameters = $this->mapping($owner, ['--platform' => $platform]);

        $this->assertSame(0, Artisan::call('agent:provision-client', $parameters));
        $account = ClientAccount::query()->sole();
        $this->assertSame("Client ID: {$account->client_id}\nLocal account ID: {$account->id}\n", Artisan::output());
        $this->assertDatabaseHas('client_accounts', [
            'id' => $account->id, 'provider' => 'zernio', 'environment' => 'production',
            'profile_id' => '0123456789abcdef01234567', 'provider_account_id' => 'abcdef0123456789abcdef01',
            'platform' => $platform, 'active' => true, 'publishing_enabled' => false,
        ]);

        $this->artisan('agent:provision-client', $this->mapping($owner, [
            '--platform' => $platform, '--account' => 'fedcba9876543210fedcba98', '--allow-publishing' => true,
        ]))->assertSuccessful();

        $this->assertDatabaseHas('client_accounts', ['provider_account_id' => 'fedcba9876543210fedcba98', 'publishing_enabled' => true]);
    }

    public function test_another_clients_profile_cannot_be_claimed_with_a_different_account_id(): void
    {
        $owner = User::factory()->create();
        $this->artisan('agent:provision-client', $this->mapping($owner))->assertSuccessful();
        $account = ClientAccount::query()->sole();
        $before = $account->getAttributes();
        $admin = User::factory()->create(['role' => 'admin']);

        $this->artisan('agent:provision-client', $this->mapping($admin, ['--account' => 'fedcba9876543210fedcba98']))->assertFailed();

        $this->assertDatabaseCount('clients', 1);
        $this->assertDatabaseCount('client_provider_profiles', 1);
        $this->assertSame($before, $account->fresh()->getAttributes());
        $this->assertTrue($account->hasOwnedProfile());
    }

    /** @return array<string, array{string}> */
    public static function platforms(): array
    {
        return [
            'linkedin' => ['linkedin'],
            'facebook' => ['facebook'],
            'threads' => ['threads'],
            'bluesky' => ['bluesky'],
        ];
    }

    /** @param array<string, mixed> $options */
    #[DataProvider('invalidMappings')]
    public function test_invalid_mapping_inputs_leave_no_partial_client(array $options): void
    {
        $owner = User::factory()->create();
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $this->artisan('agent:provision-client', array_replace([
            'owner_email' => $owner->email, 'name' => 'Editorial',
        ], $options))->assertFailed();

        $this->assertSame([], $queries);
        $this->assertDatabaseCount('clients', 0);
        $this->assertDatabaseCount('client_accounts', 0);
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function invalidMappings(): array
    {
        $complete = ['--profile' => '0123456789abcdef01234567', '--account' => 'abcdef0123456789abcdef01', '--platform' => 'linkedin', '--confirmed-mapping' => true];
        $cases = [
            'unconfirmed' => [array_replace($complete, ['--confirmed-mapping' => false])],
            'confirmation only' => [['--confirmed-mapping' => true]],
            'publishing only' => [['--allow-publishing' => true]],
            'unsupported platform' => [array_replace($complete, ['--platform' => 'instagram', '--allow-publishing' => true])],
            'platform case' => [array_replace($complete, ['--platform' => 'LinkedIn'])],
            'empty environment' => [array_replace($complete, ['--environment' => ''])],
            'empty name' => [['name' => '   ']],
        ];

        foreach (['--profile', '--account', '--platform'] as $field) {
            $partial = $complete;
            unset($partial[$field]);
            $cases['missing '.$field] = [$partial];
        }

        foreach (['--profile', '--account'] as $field) {
            foreach (['', 'profile_123', 'account-123', str_repeat('a', 23), str_repeat('a', 25),
                str_repeat('A', 24), str_repeat('g', 24), str_repeat('_', 24), str_repeat('-', 24),
                ' '.str_repeat('a', 24), str_repeat('a', 24).' ', str_repeat('a', 24)."\n",
                'abc/def', 'https://example.test', 'caf'."\u{00e9}"] as $index => $value) {
                $cases[$field.' invalid '.$index] = [array_replace($complete, [$field => $value])];
            }
        }

        return $cases;
    }

    public function test_existing_mapping_is_idempotent_and_never_escalates_publishing(): void
    {
        $owner = User::factory()->create();
        $this->artisan('agent:provision-client', $this->mapping($owner))->assertSuccessful();
        $account = ClientAccount::query()->sole();
        $before = $account->getAttributes();

        $this->artisan('agent:provision-client', $this->mapping($owner))->assertSuccessful();
        $this->artisan('agent:provision-client', $this->mapping($owner, ['--allow-publishing' => true]))->assertFailed();

        $this->assertSame($before, $account->fresh()->getAttributes());
        $this->assertDatabaseCount('client_accounts', 1);
    }

    public function test_existing_publishing_grant_is_not_downgraded_and_inactive_account_is_rejected(): void
    {
        $owner = User::factory()->create();
        $this->artisan('agent:provision-client', $this->mapping($owner, ['--allow-publishing' => true]))->assertSuccessful();
        $account = ClientAccount::query()->sole();
        $before = $account->getAttributes();

        $this->artisan('agent:provision-client', $this->mapping($owner))->assertSuccessful();
        $this->assertSame($before, $account->fresh()->getAttributes());

        $account->forceFill(['active' => false])->save();
        $before = $account->refresh()->getAttributes();
        $this->artisan('agent:provision-client', $this->mapping($owner, ['--allow-publishing' => true]))->assertFailed();
        $this->assertSame($before, $account->fresh()->getAttributes());
    }

    public function test_mapping_cannot_be_reassigned_or_have_profile_or_platform_changed(): void
    {
        $owner = User::factory()->create();
        $this->artisan('agent:provision-client', $this->mapping($owner))->assertSuccessful();
        $account = ClientAccount::query()->sole();
        $before = $account->getAttributes();
        $otherOwner = User::factory()->create();

        foreach ([['name' => 'Other'], ['--profile' => 'fedcba9876543210fedcba98'], ['--platform' => 'facebook'], ['owner_email' => $otherOwner->email]] as $override) {
            $this->artisan('agent:provision-client', $this->mapping($owner, $override))->assertFailed();
        }

        $this->assertSame($before, $account->fresh()->getAttributes());
        $this->assertDatabaseCount('clients', 1);
        $this->assertDatabaseCount('client_accounts', 1);
    }

    public function test_mapping_identity_is_scoped_to_environment_and_accepts_exact_provider_ids(): void
    {
        $owner = User::factory()->create();
        foreach (['production', 'staging'] as $environment) {
            $this->artisan('agent:provision-client', $this->mapping($owner, [
                '--environment' => $environment,
            ]))->assertSuccessful();
        }
        $this->assertDatabaseCount('clients', 1);
        $this->assertDatabaseCount('client_accounts', 2);
    }

    public function test_database_failure_rolls_back_client_and_does_not_expose_exception_details(): void
    {
        $owner = User::factory()->create();
        DB::unprepared("CREATE TRIGGER reject_account BEFORE INSERT ON client_accounts BEGIN SELECT RAISE(ABORT, 'secret-database-detail'); END");

        $this->assertSame(1, Artisan::call('agent:provision-client', $this->mapping($owner)));
        $this->assertSame("Provisioning failed; no changes were saved.\n", Artisan::output());
        $this->assertDatabaseCount('clients', 0);
        $this->assertDatabaseCount('client_accounts', 0);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function mapping(User $owner, array $overrides = []): array
    {
        return array_replace([
            'owner_email' => $owner->email,
            'name' => 'Editorial',
            '--profile' => '0123456789abcdef01234567',
            '--account' => 'abcdef0123456789abcdef01',
            '--platform' => 'linkedin',
            '--confirmed-mapping' => true,
        ], $overrides);
    }
}
