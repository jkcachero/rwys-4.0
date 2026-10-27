<?php

namespace Tests\Feature;

use App\Enums\AgentAbility;
use App\Models\Client;
use App\Models\ClientAccountPermission;
use App\Models\PersonalAccessToken;
use App\Models\ProviderAccount;
use App\Models\ProviderProfile;
use App\Models\User;
use App\Services\AgentTokenService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class AdKitCapabilityTest extends TestCase
{
    use RefreshDatabase;

    private const ADKIT_READS = ['adkit.read'];

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.zernio.enabled', false);
        Http::preventStrayRequests();
    }

    public function test_fresh_token_grants_are_hidden_until_an_adkit_account_is_assigned(): void
    {
        [$owner, $client] = $this->principal();
        $token = app(AgentTokenService::class)->issue($owner, $client, 'Fresh');
        $this->assertSame(AgentAbility::clientTokenDefaults(), $token->accessToken->abilities);

        $this->withToken($token->plainTextToken)->getJson('/api/v1/capabilities')->assertOk()
            ->assertJsonPath('capabilities', ['context.read', 'zernio.accounts.read', 'operations.read', 'zernio.posts.publish']);
        $this->withToken($token->plainTextToken)->getJson('/api/v1/context')->assertOk()
            ->assertJsonPath('capabilities', AgentAbility::clientTokenDefaults());
        $this->assignAdKit($owner, $client);
        $this->withToken($token->plainTextToken)->getJson('/api/v1/capabilities')->assertOk()
            ->assertJsonPath('capabilities', [
                'context.read', 'zernio.accounts.read', 'operations.read', ...self::ADKIT_READS, 'zernio.posts.publish',
            ]);

        Http::assertNothingSent();
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function test_ready_adkit_capabilities_honor_token_account_scope_without_exposing_secrets(bool $restricted): void
    {
        [$owner, $client] = $this->principal();
        $account = $this->assignAdKit($owner, $client);
        $token = app(AgentTokenService::class)->issue($owner, $client, 'Ready', allowedAccountIds: $restricted ? [$account->public_id] : []);
        $token->accessToken->update(['abilities' => ['context.read', ...self::ADKIT_READS]]);

        $this->withToken($token->plainTextToken)->getJson('/api/v1/capabilities')->assertOk()
            ->assertJsonPath('capabilities', ['context.read', ...self::ADKIT_READS])
            ->assertJsonCount(2);

        Http::assertNothingSent();
    }

    #[TestWith(['unassigned'])]
    #[TestWith(['foreign_client'])]
    #[TestWith(['permission'])]
    #[TestWith(['inactive_account'])]
    #[TestWith(['inactive_profile'])]
    #[TestWith(['profile_provider'])]
    #[TestWith(['platform'])]
    #[TestWith(['token_scope'])]
    #[TestWith(['missing_connection'])]
    #[TestWith(['connection_provider'])]
    #[TestWith(['project_mismatch'])]
    #[TestWith(['empty_project'])]
    #[TestWith(['project_header'])]
    #[TestWith(['subject'])]
    #[TestWith(['account_id'])]
    #[TestWith(['empty_token'])]
    #[TestWith(['token_header'])]
    #[TestWith(['expired'])]
    #[TestWith(['expires_now'])]
    #[TestWith(['corrupt_token'])]
    #[TestWith(['corrupt_profile'])]
    #[TestWith(['corrupt_account'])]
    public function test_unready_adkit_setup_advertises_no_adkit_capabilities(string $reason): void
    {
        $this->freezeTime();
        [$owner, $client] = $this->principal();
        $account = $this->assignAdKit($owner, $client);
        $profile = $account->profile;
        $connection = $profile->providerConnection;
        $token = app(AgentTokenService::class)->issue($owner, $client, 'Unready');
        $token->accessToken->update(['abilities' => ['context.read', ...self::ADKIT_READS]]);
        match ($reason) {
            'unassigned' => $client->accountPermissions()->delete(),
            'foreign_client' => $account->permissions()->update(['client_id' => Client::factory()->create()->id]),
            'permission' => $account->permissions()->update(['is_active' => false]),
            'inactive_account' => $account->update(['is_active' => false]),
            'inactive_profile' => $profile->update(['is_active' => false]),
            'profile_provider' => $profile->update(['provider' => 'zernio']),
            'platform' => $account->update(['platform' => 'linkedin']),
            'token_scope' => $token->accessToken->update(['allowed_account_ids' => [fake()->uuid()]]),
            'missing_connection' => $profile->update(['provider_connection_id' => null]),
            'connection_provider' => $connection->update(['provider' => 'zernio']),
            'project_mismatch' => $connection->update(['client_id' => 'other-project']),
            'empty_project' => [$connection->update(['client_id' => ' ']), $profile->update(['provider_id' => ' '])],
            'project_header' => [$connection->update(['client_id' => "project\r\ninvalid"]), $profile->update(['provider_id' => "project\r\ninvalid"])],
            'subject' => $connection->update(['subject' => '999']),
            'account_id' => $account->update(['provider_id' => '123']),
            'empty_token' => $connection->update(['access_token' => ' ']),
            'token_header' => $connection->update(['access_token' => "secret\r\ninvalid"]),
            'expired' => $connection->update(['access_token_expires_at' => now()->subSecond()]),
            'expires_now' => $connection->update(['access_token_expires_at' => now()]),
            'corrupt_token' => DB::table('provider_connections')->where('id', $connection->id)->update(['access_token' => 'invalid-ciphertext']),
            'corrupt_profile' => DB::table('provider_profiles')->where('id', $profile->id)->update(['provider_id' => 'invalid-ciphertext']),
            'corrupt_account' => DB::table('provider_accounts')->where('id', $account->id)->update(['provider_id' => 'invalid-ciphertext']),
        };

        $this->withToken($token->plainTextToken)->getJson('/api/v1/capabilities')->assertOk()
            ->assertJsonPath('capabilities', ['context.read']);

        if (! in_array($reason, ['unassigned', 'foreign_client', 'permission', 'token_scope'], true)) {
            $loaded = $account->fresh(['profile.providerConnection']);
            DB::enableQueryLog();
            DB::flushQueryLog();
            try {
                $this->assertFalse($loaded->hasUsableAdKitConnection());
                $this->assertCount(0, DB::getQueryLog());
            } finally {
                DB::disableQueryLog();
            }
        }

        Http::assertNothingSent();
    }

    public function test_usable_connection_helper_uses_loaded_models_without_queries_or_project_assignments(): void
    {
        $this->freezeTime();
        [$owner, $client] = $this->principal();
        $account = $this->assignAdKit($owner, $client);
        $client->providerProfiles()->detach();
        $account->profile->providerConnection->update(['access_token_expires_at' => now()->addSecond()]);
        $loaded = $account->fresh(['profile.providerConnection']);
        DB::enableQueryLog();
        DB::flushQueryLog();

        try {
            $this->assertTrue($loaded->hasUsableAdKitConnection());
            $this->assertCount(0, DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
        }
        $token = app(AgentTokenService::class)->issue($owner, $client, 'No project assignment');
        $token->accessToken->update(['abilities' => ['context.read', ...self::ADKIT_READS]]);
        $this->withToken($token->plainTextToken)->getJson('/api/v1/capabilities')->assertOk()
            ->assertJsonPath('capabilities', ['context.read', ...self::ADKIT_READS]);
        Http::assertNothingSent();
    }

    public function test_ready_setup_does_not_advertise_ungranted_or_unsupported_capabilities(): void
    {
        [$owner, $client] = $this->principal();
        $this->assignAdKit($owner, $client);
        $token = app(AgentTokenService::class)->issue($owner, $client, 'Limited');
        $token->accessToken->update(['abilities' => ['context.read', 'adkit.read', 'legacy.read', 'adkit.*']]);

        $this->withToken($token->plainTextToken)->getJson('/api/v1/capabilities')->assertOk()
            ->assertJsonPath('capabilities', ['context.read', 'adkit.read']);

        Http::assertNothingSent();
    }

    public function test_discovery_checks_later_valid_profiles_without_n_plus_one_queries(): void
    {
        [$owner, $client] = $this->principal();
        $invalid = $this->assignAdKit($owner, $client);
        $invalid->profile->providerConnection->update(['client_id' => 'mismatched-project']);
        $token = app(AgentTokenService::class)->issue($owner, $client, 'Multiple profiles');
        $token->accessToken->update(['abilities' => ['context.read', ...self::ADKIT_READS]]);
        DB::enableQueryLog();
        DB::flushQueryLog();

        try {
            $this->withToken($token->plainTextToken)->getJson('/api/v1/capabilities')->assertOk()
                ->assertJsonPath('capabilities', ['context.read']);
            $singleProfileQueries = count(DB::getQueryLog());
            foreach (range(1, 5) as $number) {
                $this->assignAdKit(User::factory()->create(), $client);
            }
            DB::flushQueryLog();

            $this->withToken($token->plainTextToken)->getJson('/api/v1/capabilities')->assertOk()
                ->assertJsonPath('capabilities', ['context.read', ...self::ADKIT_READS]);

            $this->assertLessThanOrEqual($singleProfileQueries, count(DB::getQueryLog()));
        } finally {
            DB::disableQueryLog();
        }

        Http::assertNothingSent();
    }

    public function test_rotation_api_cannot_add_client_selected_adkit_grants_to_an_older_key(): void
    {
        [$owner, $client] = $this->principal();
        $old = app(AgentTokenService::class)->issue($owner, $client, 'Older key');
        $old->accessToken->update(['abilities' => ['context.read', 'zernio.accounts.read']]);

        $this->actingAs($owner)->withSession(['auth.password_confirmed_at' => time()])
            ->post(route('settings.agent-access.tokens.rotate', ['client' => $client, 'token' => $old->accessToken]), [
                'name' => 'Rotated key', 'allowed_account_ids' => [], 'abilities' => self::ADKIT_READS,
            ])->assertRedirect()->assertSessionHas('agent_token_plaintext');

        $this->assertDatabaseHas('personal_access_tokens', ['rotated_from_id' => $old->accessToken->id]);
        $this->assertSame(['context.read', 'zernio.accounts.read'], $owner->tokens()->where('rotated_from_id', $old->accessToken->id)->sole()->abilities);
        $this->assertNotNull($old->accessToken->fresh()->revoked_at);
        Http::assertNothingSent();
    }

    #[TestWith([['context.read', 'zernio.posts.read', 'zernio.posts.publish', 'legacy.read', '*', 'adkit.*'], ['context.read', 'zernio.posts.read', 'zernio.posts.publish']])]
    #[TestWith([['context.read', 'adkit.read'], ['context.read', 'adkit.read']])]
    public function test_rotation_preserves_only_existing_supported_grants(array $oldAbilities, array $expected): void
    {
        [$owner, $client] = $this->principal();
        $this->assignAdKit($owner, $client);
        $tokens = app(AgentTokenService::class);
        $old = $tokens->issue($owner, $client, 'Old');
        $old->accessToken->update(['abilities' => $oldAbilities]);

        $rotated = $tokens->rotate($owner, $client, $old->accessToken, 'Rotated');

        $this->assertSame($expected, $rotated->accessToken->fresh()->abilities);
        $this->assertNotNull($old->accessToken->fresh()->revoked_at);
        $this->withToken($rotated->plainTextToken)->getJson('/api/v1/capabilities')->assertOk()
            ->assertJsonPath('capabilities', array_values(array_diff($expected, ['zernio.posts.read'])));
        Http::assertNothingSent();
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function test_rotation_uses_persisted_grants_expiry_and_scope_instead_of_loaded_source(bool $directIssue): void
    {
        $this->freezeSecond();
        [$owner, $client] = $this->principal();
        $account = $this->assignAdKit($owner, $client);
        $tokens = app(AgentTokenService::class);
        $old = $tokens->issue($owner, $client, 'Stale source')->accessToken;
        PersonalAccessToken::query()->whereKey($old->id)->update([
            'abilities' => ['context.read'], 'expires_at' => now()->addDay(),
            'allowed_account_ids' => [$account->public_id],
        ]);

        $rotated = $directIssue
            ? $tokens->issue($owner, $client, 'Rotated', now()->addDays(30), [$account->public_id], $old)
            : $tokens->rotate($owner, $client, $old, 'Rotated', [$account->public_id]);

        $persisted = $rotated->accessToken->fresh();
        $this->assertSame(['context.read'], $persisted->abilities);
        $this->assertTrue($persisted->expires_at->equalTo(now()->addDay()));
        $this->assertSame([$account->public_id], $persisted->allowed_account_ids);
        $this->assertNotNull($old->fresh()->revoked_at);
        Http::assertNothingSent();
    }

    #[TestWith(['revoked', false])]
    #[TestWith(['revoked', true])]
    #[TestWith(['expired', false])]
    #[TestWith(['expired', true])]
    #[TestWith(['no_expiry', false])]
    #[TestWith(['no_expiry', true])]
    #[TestWith(['client', false])]
    #[TestWith(['client', true])]
    #[TestWith(['scope', false])]
    #[TestWith(['scope', true])]
    #[TestWith(['deleted', false])]
    #[TestWith(['deleted', true])]
    public function test_rotation_rejects_source_disabled_or_narrowed_after_model_load(string $change, bool $directIssue): void
    {
        $this->freezeTime();
        [$owner, $client] = $this->principal();
        $account = $this->assignAdKit($owner, $client);
        $tokens = app(AgentTokenService::class);
        $old = $tokens->issue($owner, $client, 'Stale source')->accessToken;
        $query = PersonalAccessToken::query()->whereKey($old->id);
        match ($change) {
            'revoked' => $query->update(['revoked_at' => now()]),
            'expired' => $query->update(['expires_at' => now()->subSecond()]),
            'no_expiry' => $query->update(['expires_at' => null]),
            'client' => $query->update(['client_id' => Client::factory()->create()->id]),
            'scope' => $query->update(['allowed_account_ids' => [$account->public_id]]),
            'deleted' => $query->delete(),
        };

        try {
            if ($directIssue) {
                $tokens->issue($owner, $client, 'Unsafe rotation', rotatedFrom: $old);
            } else {
                $tokens->rotate($owner, $client, $old, 'Unsafe rotation');
            }
            $this->fail('Rotation must reject a disabled or broadened source.');
        } catch (DomainException) {
            $this->assertDatabaseCount('personal_access_tokens', $change === 'deleted' ? 0 : 1);
            $this->assertDatabaseMissing('personal_access_tokens', ['rotated_from_id' => $old->id]);
        }

        Http::assertNothingSent();
    }

    #[TestWith(['client'])]
    #[TestWith(['verification'])]
    #[TestWith(['membership'])]
    public function test_issuance_revalidates_current_actor_and_client_state(string $change): void
    {
        [$owner, $client] = $this->principal();
        match ($change) {
            'client' => Client::query()->whereKey($client->id)->update(['is_active' => false]),
            'verification' => User::query()->whereKey($owner->id)->update(['email_verified_at' => null]),
            'membership' => $client->users()->updateExistingPivot($owner->id, ['is_active' => false]),
        };

        try {
            app(AgentTokenService::class)->issue($owner, $client, 'Unsafe issuance');
            $this->fail('Issuance must reject an inactive or unverified actor.');
        } catch (DomainException) {
            $this->assertDatabaseCount('personal_access_tokens', 0);
        }

        Http::assertNothingSent();
    }

    public function test_discovery_bounds_account_batches_and_finds_a_valid_account_after_the_first_batch(): void
    {
        [$owner, $client] = $this->principal();
        $first = $this->assignAdKit($owner, $client);
        $first->update(['provider_id' => 'invalid']);
        $accounts = ProviderAccount::factory()->count(99)->create([
            'provider_profile_id' => $first->provider_profile_id, 'platform' => 'meta', 'provider_id' => 'invalid',
        ]);
        foreach ($accounts as $account) {
            ClientAccountPermission::factory()->create(['client_id' => $client, 'provider_account_id' => $account]);
        }
        $last = ProviderAccount::factory()->create([
            'provider_profile_id' => $first->provider_profile_id, 'platform' => 'meta', 'provider_id' => 'act_123',
        ]);
        ClientAccountPermission::factory()->create(['client_id' => $client, 'provider_account_id' => $last]);
        $token = app(AgentTokenService::class)->issue($owner, $client, 'Batched discovery');
        DB::enableQueryLog();
        DB::flushQueryLog();

        try {
            $this->withToken($token->plainTextToken)->getJson('/api/v1/capabilities')->assertOk()
                ->assertJsonPath('capabilities', ['context.read', 'zernio.accounts.read', 'operations.read', ...self::ADKIT_READS, 'zernio.posts.publish']);

            $queries = collect(DB::getQueryLog())->pluck('query')
                ->filter(fn (string $sql): bool => str_starts_with($sql, 'select "id", "provider_profile_id", "provider_id", "platform", "is_active" from "provider_accounts"'));
            $this->assertCount(2, $queries);
            foreach ($queries as $sql) {
                $this->assertStringContainsString('limit 100', $sql);
            }
        } finally {
            DB::disableQueryLog();
        }

        Http::assertNothingSent();
    }

    /** @return array{User, Client} */
    private function principal(): array
    {
        $owner = User::factory()->create();
        $client = Client::factory()->create();
        $client->users()->attach($owner, ['role' => 'owner', 'is_active' => true]);

        return [$owner, $client];
    }

    private function assignAdKit(User $owner, Client $client): ProviderAccount
    {
        $connection = $owner->providerConnections()->create([
            'provider' => 'adkit', 'client_id' => 'synthetic-project', 'subject' => 'act_999',
            'access_token' => 'synthetic-secret', 'scopes' => ['read'],
        ]);
        $profile = ProviderProfile::factory()->create([
            'provider' => 'adkit', 'provider_connection_id' => $connection, 'provider_id' => 'synthetic-project',
        ]);
        $client->providerProfiles()->attach($profile, ['assigned_by' => $owner->id, 'assigned_at' => now()]);
        $account = ProviderAccount::factory()->create([
            'provider_profile_id' => $profile, 'provider_id' => 'act_123', 'platform' => 'meta',
        ]);
        ClientAccountPermission::factory()->create(['client_id' => $client, 'provider_account_id' => $account]);

        return $account;
    }
}
