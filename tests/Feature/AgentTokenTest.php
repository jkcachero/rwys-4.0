<?php

namespace Tests\Feature;

use App\Models\AgentAccessToken;
use App\Models\Client;
use App\Models\ClientAccount;
use App\Models\User;
use App\Services\AgentTokenService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class AgentTokenTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

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
        $this->owner = User::factory()->create();
        $this->client = Client::forceCreate(['owner_id' => $this->owner->id, 'name' => 'Owned client', 'active' => true]);
        $this->actingAs($this->owner)->withSession(['auth.password_confirmed_at' => time()]);
    }

    public function test_issuance_returns_plaintext_once_and_persists_only_a_scoped_hash(): void
    {
        $response = $this->postJson(route('agent-tokens.store'), ['client_id' => $this->client->id, 'name' => 'My agent'])
            ->assertCreated()->assertHeader('Cache-Control', 'no-store, private');
        $plain = $response->json('plain_text_token');
        $token = AgentAccessToken::findToken($plain);
        $this->assertNotNull($token);
        $this->assertSame(hash('sha256', explode('|', $plain, 2)[1]), $token->token);
        $this->assertSame($this->client->id, $token->client_id);
        $this->assertNull($token->allowed_account_ids);
        $this->assertSame(AgentTokenService::READ_ABILITIES, $token->abilities);
        $this->assertTrue($token->expires_at->between(now()->addDays(29), now()->addDays(30)));
        $this->assertStringNotContainsString($plain, serialize(session()->all()));
        $this->get(route('agent-tokens.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('settings/agent-tokens')->has('tokens.data', 1)
            ->missing('tokens.data.0.token')->missing('plain_text_token'));
    }

    public function test_scheduling_requires_explicit_scope_permission_and_all_server_gates(): void
    {
        config(['agent-api.zernio.enabled' => true, 'agent-api.zernio.publishing_enabled' => true, 'agent-api.zernio.instagram_scheduling_enabled' => true]);
        $account = ClientAccount::factory()->create(['client_id' => $this->client->id, 'platform' => 'instagram', 'publishing_enabled' => true]);
        $payload = ['client_id' => $this->client->id, 'name' => 'Scheduler', 'abilities' => ['zernio.posts.schedule'], 'allowed_account_ids' => [$account->id]];
        foreach (['enabled', 'publishing_enabled', 'instagram_scheduling_enabled'] as $gate) {
            config(['agent-api.zernio.'.$gate => false]);
            $this->postJson(route('agent-tokens.store'), $payload)->assertUnprocessable();
            config(['agent-api.zernio.'.$gate => true]);
        }
        $account->update(['publishing_enabled' => false]);
        $this->owner->update(['role' => 'admin']);
        $this->postJson(route('agent-tokens.store'), $payload)->assertUnprocessable();
        $account->update(['publishing_enabled' => true]);
        $this->postJson(route('agent-tokens.store'), $payload)->assertCreated();
        foreach ([['active' => false], ['platform' => 'linkedin'], ['client_provider_profile_id' => null]] as $change) {
            $original = $account->getAttributes();
            $account->forceFill($change)->save();
            $this->postJson(route('agent-tokens.store'), $payload)->assertUnprocessable();
            $account->forceFill($original)->save();
        }
        $outside = ClientAccount::factory()->create(['client_id' => $this->client->id]);
        $this->postJson(route('agent-tokens.store'), array_replace($payload, ['allowed_account_ids' => [$outside->id]]))->assertUnprocessable();
    }

    public function test_read_tokens_never_gain_scheduling_on_creation_or_rotation(): void
    {
        config(['agent-api.zernio.enabled' => true, 'agent-api.zernio.publishing_enabled' => true, 'agent-api.zernio.instagram_scheduling_enabled' => true]);
        ClientAccount::factory()->create(['client_id' => $this->client->id, 'platform' => 'instagram', 'publishing_enabled' => true]);
        $issued = $this->postJson(route('agent-tokens.store'), ['client_id' => $this->client->id, 'name' => 'Read only'])->assertCreated();
        $token = AgentAccessToken::findToken($issued->json('plain_text_token'));
        $this->assertSame(AgentTokenService::READ_ABILITIES, $token->abilities);
        $rotated = $this->postJson(route('agent-tokens.rotate', $token))->assertCreated();
        $this->assertSame(AgentTokenService::READ_ABILITIES, AgentAccessToken::findToken($rotated->json('plain_text_token'))->abilities);
        $this->get(route('agent-tokens.index'))->assertInertia(fn (Assert $page) => $page->where('instagramSchedulingEnabled', true));
        config(['agent-api.zernio.publishing_enabled' => false]);
        $this->get(route('agent-tokens.index'))->assertInertia(fn (Assert $page) => $page->where('instagramSchedulingEnabled', false));
    }

    public function test_token_transactions_query_users_before_clients_and_existing_token_locks(): void
    {
        $tables = [];
        DB::listen(function (QueryExecuted $query) use (&$tables): void {
            if (preg_match('/^select .* from "(users|clients|personal_access_tokens)"/', $query->sql, $matches)) {
                $tables[] = $matches[1];
            }
        });
        $service = app(AgentTokenService::class);
        $issued = $service->issue($this->owner, $this->client, 'Order', ['context.read']);
        $this->assertSame(['users', 'clients'], $tables);
        $tables = [];
        $rotated = $service->rotate($this->owner, $issued->accessToken);
        $this->assertSame(['users', 'personal_access_tokens', 'clients', 'personal_access_tokens'], $tables);
        $tables = [];
        $service->revoke($this->owner, $rotated->accessToken);
        $this->assertSame(['users', 'personal_access_tokens', 'clients', 'personal_access_tokens'], $tables);
    }

    public function test_rotation_and_revocation_reject_token_identity_changes_after_the_unlocked_snapshot(): void
    {
        $service = app(AgentTokenService::class);
        $other = Client::factory()->create(['owner_id' => $this->owner->id]);
        $armed = false;
        $tokenId = null;
        $change = [];
        DB::listen(function (QueryExecuted $query) use (&$armed, &$tokenId, &$change): void {
            if ($armed && str_starts_with($query->sql, 'select * from "clients"')) {
                $armed = false;
                DB::table('personal_access_tokens')->where('id', $tokenId)->update($change);
            }
        });
        foreach (['rotate', 'revoke'] as $action) {
            foreach ([['client_id' => $other->id], ['tokenable_id' => $other->owner_id + 1000], ['tokenable_type' => Client::class]] as $mutation) {
                $issued = $service->issue($this->owner, $this->client, 'Race', ['context.read']);
                $tokenId = $issued->accessToken->id;
                $change = $mutation;
                $armed = true;
                try {
                    $service->{$action}($this->owner, $issued->accessToken);
                    $this->fail('Token identity changes must fail closed.');
                } catch (HttpException $exception) {
                    $this->assertSame(403, $exception->getStatusCode());
                    $this->assertNotNull(AgentAccessToken::findToken($issued->plainTextToken));
                }
            }
        }
    }

    public function test_management_requires_session_verification_and_recent_password_confirmation(): void
    {
        $this->withSession(['auth.password_confirmed_at' => 0])->getJson(route('agent-tokens.index'))->assertStatus(423);
        $this->postJson(route('agent-tokens.store'), ['client_id' => $this->client->id, 'name' => 'Denied'])->assertStatus(423);
        $this->owner->forceFill(['email_verified_at' => null])->save();
        $this->withSession(['auth.password_confirmed_at' => time()])->getJson(route('agent-tokens.index'))->assertForbidden();
        auth()->forgetGuards();
        $this->getJson(route('agent-tokens.index'))->assertUnauthorized();
    }

    public function test_agent_access_entry_routes_client_owners_and_ordinary_admins_to_personal_tokens(): void
    {
        $this->get('/settings/agent-access')->assertRedirect(route('agent-tokens.index'));
        $this->owner->update(['role' => 'admin']);
        $this->get('/settings/agent-access')->assertRedirect(route('agent-tokens.index'));
        $this->assertFalse($this->owner->fresh()->canAssignProviderAccounts());
    }

    public function test_agent_access_entry_routes_only_authorized_operators_to_assignments(): void
    {
        $operator = User::factory()->create(['role' => 'admin', 'can_assign_provider_accounts' => true]);
        $this->actingAs($operator, 'web');
        $this->get('/settings/agent-access')->assertRedirect(route('provider-accounts.index'));
        $operator->forceFill(['can_assign_provider_accounts' => false])->save();
        $this->get('/settings/agent-access')->assertRedirect(route('agent-tokens.index'));
    }

    public function test_agent_access_entry_keeps_human_session_verification_and_recent_authentication(): void
    {
        $this->withSession(['auth.password_confirmed_at' => 0])->getJson('/settings/agent-access')->assertStatus(423);
        $this->owner->forceFill(['email_verified_at' => null])->save();
        $this->withSession(['auth.password_confirmed_at' => time()])->getJson('/settings/agent-access')->assertForbidden();
        auth()->forgetGuards();
        $this->getJson('/settings/agent-access')->assertUnauthorized();
    }

    public function test_unknown_fields_wildcards_and_empty_account_scopes_are_rejected(): void
    {
        foreach ([['unexpected' => true], ['name_confirmation' => 'Invalid'], ['abilities.*' => 'context.read'], ['abilities' => ['*']], ['abilities' => ['unknown']], ['allowed_account_ids' => []], ['allowed_account_ids' => ['1']], ['client_id' => null]] as $invalid) {
            $this->postJson(route('agent-tokens.store'), array_replace(['client_id' => $this->client->id, 'name' => 'Invalid'], $invalid))->assertUnprocessable();
        }
        $this->postJson(route('agent-tokens.store').'?unexpected=true', ['client_id' => $this->client->id, 'name' => 'Invalid'])->assertUnprocessable();
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_foreign_inactive_and_unowned_clients_cannot_issue_tokens(): void
    {
        foreach ([['owner_id' => User::factory()->create()->id], ['active' => false], ['owner_id' => null]] as $change) {
            $client = Client::forceCreate(array_replace(['owner_id' => $this->owner->id, 'name' => 'Unavailable', 'active' => true], $change));
            $this->postJson(route('agent-tokens.store'), ['client_id' => $client->id, 'name' => 'Denied'])->assertForbidden();
        }
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_account_restrictions_and_publishing_are_checked_against_current_mapping(): void
    {
        $account = ClientAccount::factory()->create(['client_id' => $this->client->id, 'provider' => 'zernio', 'environment' => 'production', 'profile_id' => 'profile', 'provider_account_id' => 'account', 'platform' => 'instagram', 'active' => true, 'publishing_enabled' => false]);
        $payload = ['client_id' => $this->client->id, 'name' => 'Publisher', 'abilities' => ['zernio.posts.publish'], 'allowed_account_ids' => [$account->id]];
        $this->postJson(route('agent-tokens.store'), $payload)->assertUnprocessable();
        config(['agent-api.zernio.publishing_enabled' => true]);
        $this->postJson(route('agent-tokens.store'), $payload)->assertUnprocessable();
        $account->forceFill(['publishing_enabled' => true])->save();
        $this->postJson(route('agent-tokens.store'), $payload)->assertUnprocessable();
        $account->forceFill(['platform' => 'linkedin', 'environment' => 'staging'])->save();
        $this->postJson(route('agent-tokens.store'), $payload)->assertUnprocessable();
        $account->forceFill(['environment' => 'production'])->save();
        $this->postJson(route('agent-tokens.store'), $payload)->assertCreated();
        $this->postJson(route('agent-tokens.store'), array_replace($payload, ['allowed_account_ids' => [999999]]))->assertUnprocessable();
        $account->forceFill(['active' => false])->save();
        $this->postJson(route('agent-tokens.store'), $payload)->assertUnprocessable();
    }

    public function test_a_stale_verified_user_cannot_issue_rotate_or_revoke_after_verification_is_removed(): void
    {
        $service = app(AgentTokenService::class);
        $issued = $service->issue($this->owner, $this->client, 'Agent', ['context.read']);
        User::query()->whereKey($this->owner->id)->update(['email_verified_at' => null]);
        $this->assertTrue($this->owner->hasVerifiedEmail());

        foreach (['issue', 'rotate', 'revoke'] as $action) {
            try {
                if ($action === 'issue') {
                    $service->issue($this->owner, $this->client, 'Denied', ['context.read']);
                } else {
                    $service->{$action}($this->owner, $issued->accessToken);
                }
                $this->fail('A stale verified user must not manage tokens.');
            } catch (HttpException $exception) {
                $this->assertSame(403, $exception->getStatusCode());
            }
        }
        $this->assertDatabaseCount('personal_access_tokens', 1);
        $this->assertNotNull(AgentAccessToken::findToken($issued->plainTextToken));
    }

    public function test_publishing_is_granted_only_for_supported_accounts_within_the_selected_scope(): void
    {
        config(['agent-api.zernio.publishing_enabled' => true]);
        $unsupported = ClientAccount::factory()->create(['client_id' => $this->client->id, 'platform' => 'instagram', 'publishing_enabled' => true]);
        foreach (['linkedin', 'facebook', 'threads', 'bluesky'] as $platform) {
            $account = ClientAccount::factory()->create(['client_id' => $this->client->id, 'platform' => $platform, 'publishing_enabled' => true]);
            $this->postJson(route('agent-tokens.store'), ['client_id' => $this->client->id, 'name' => 'Publisher', 'abilities' => ['zernio.posts.publish'], 'allowed_account_ids' => [$account->id]])->assertCreated();
        }
        $this->postJson(route('agent-tokens.store'), ['client_id' => $this->client->id, 'name' => 'Unsupported', 'abilities' => ['zernio.posts.publish'], 'allowed_account_ids' => [$unsupported->id]])->assertUnprocessable();
    }

    public function test_rotation_preserves_exact_scope_and_expiry_and_revokes_the_old_bearer(): void
    {
        $issued = app(AgentTokenService::class)->issue($this->owner, $this->client, 'Agent', ['context.read']);
        $old = $issued->accessToken;
        $expiry = $old->expires_at->toIso8601String();
        $response = $this->postJson(route('agent-tokens.rotate', $old))->assertCreated();
        $replacement = AgentAccessToken::findToken($response->json('plain_text_token'));
        $this->assertNull(AgentAccessToken::findToken($issued->plainTextToken));
        $this->assertSame(['context.read'], $replacement->abilities);
        $this->assertSame($old->client_id, $replacement->client_id);
        $this->assertSame($old->allowed_account_ids, $replacement->allowed_account_ids);
        $this->assertSame($expiry, $replacement->expires_at->toIso8601String());
        $this->postJson(route('agent-tokens.rotate', $old))->assertNotFound();
    }

    public function test_inactive_clients_remain_visible_and_revoked_tokens_stay_invalid_after_reactivation(): void
    {
        $issued = app(AgentTokenService::class)->issue($this->owner, $this->client, 'Agent', ['context.read']);
        $this->client->forceFill(['active' => false])->save();
        $this->get(route('agent-tokens.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('clients', 1)->where('clients.0.id', $this->client->id)->where('clients.0.active', false)
            ->has('tokens.data', 1)->where('tokens.data.0.id', $issued->accessToken->id)
            ->missing('tokens.data.0.token'));
        $this->postJson(route('agent-tokens.store'), ['client_id' => $this->client->id, 'name' => 'Denied'])->assertForbidden();
        $this->postJson(route('agent-tokens.rotate', $issued->accessToken))->assertForbidden();
        $this->assertNotNull(AgentAccessToken::findToken($issued->plainTextToken));
        $this->deleteJson(route('agent-tokens.destroy', $issued->accessToken))->assertNoContent();
        $this->assertDatabaseHas('clients', ['id' => $this->client->id]);
        $this->assertDatabaseHas('users', ['id' => $this->owner->id]);
        $this->assertNull(AgentAccessToken::findToken($issued->plainTextToken));
        $this->client->forceFill(['active' => true])->save();
        auth()->forgetGuards();
        $this->withToken($issued->plainTextToken)->getJson(route('agent-api.context'))->assertUnauthorized();
        $this->assertNull(AgentAccessToken::findToken($issued->plainTextToken));
    }

    public function test_another_owner_cannot_list_rotate_or_revoke_tokens(): void
    {
        $issued = app(AgentTokenService::class)->issue($this->owner, $this->client, 'Private', ['context.read']);
        $this->actingAs(User::factory()->create());
        $this->get(route('agent-tokens.index'))->assertInertia(fn (Assert $page) => $page->has('clients', 0)->has('tokens.data', 0));
        $this->postJson(route('agent-tokens.rotate', $issued->accessToken))->assertForbidden();
        $this->deleteJson(route('agent-tokens.destroy', $issued->accessToken))->assertForbidden();
    }

    public function test_real_bearer_tokens_cannot_manage_tokens_without_a_human_session(): void
    {
        $issued = app(AgentTokenService::class)->issue($this->owner, $this->client, 'Agent', ['context.read']);
        Route::middleware('auth:sanctum')->get('/_agent-token-test', fn () => response()->json(['user_id' => auth()->id()]));
        auth()->forgetGuards();
        $this->withToken($issued->plainTextToken)->getJson('/_agent-token-test')->assertOk()->assertJsonPath('user_id', $this->owner->id);
        auth()->forgetGuards();
        $this->withToken($issued->plainTextToken)->getJson(route('agent-tokens.index'))->assertUnauthorized();
    }

    public function test_restricted_rotation_revalidates_permissions_and_preserves_the_account_scope(): void
    {
        config(['agent-api.zernio.publishing_enabled' => true]);
        $account = ClientAccount::factory()->create(['client_id' => $this->client->id, 'provider' => 'zernio', 'environment' => 'production', 'profile_id' => 'profile', 'provider_account_id' => 'restricted', 'platform' => 'linkedin', 'active' => true, 'publishing_enabled' => true]);
        $issued = app(AgentTokenService::class)->issue($this->owner, $this->client, 'Publisher', ['context.read', 'zernio.posts.publish'], [$account->id]);
        config(['agent-api.zernio.publishing_enabled' => false]);
        $this->postJson(route('agent-tokens.rotate', $issued->accessToken))->assertUnprocessable();
        $this->assertNotNull(AgentAccessToken::findToken($issued->plainTextToken));
        config(['agent-api.zernio.publishing_enabled' => true]);
        $account->update(['platform' => 'instagram']);
        $this->postJson(route('agent-tokens.rotate', $issued->accessToken))->assertUnprocessable();
        $account->update(['platform' => 'linkedin', 'environment' => 'staging']);
        $this->postJson(route('agent-tokens.rotate', $issued->accessToken))->assertUnprocessable();
        $this->assertNotNull(AgentAccessToken::findToken($issued->plainTextToken));
        $account->update(['environment' => 'production']);
        $response = $this->postJson(route('agent-tokens.rotate', $issued->accessToken))->assertCreated();
        $replacement = AgentAccessToken::findToken($response->json('plain_text_token'));
        $this->assertSame([$account->id], $replacement->allowed_account_ids);
        $this->assertSame(['context.read', 'zernio.posts.publish'], $replacement->abilities);
    }

    public function test_rotation_rejects_expired_or_unbounded_tokens_without_deleting_them(): void
    {
        foreach ([null, now()->subDay(), now()->addDays(31)] as $expiry) {
            $issued = app(AgentTokenService::class)->issue($this->owner, $this->client, 'Agent', ['context.read']);
            $issued->accessToken->forceFill(['expires_at' => $expiry])->save();
            $this->postJson(route('agent-tokens.rotate', $issued->accessToken))->assertUnprocessable();
            $this->assertNotNull(AgentAccessToken::findToken($issued->plainTextToken));
        }
    }

    public function test_rotation_cannot_replace_a_token_with_an_original_lifetime_over_thirty_days(): void
    {
        $issued = app(AgentTokenService::class)->issue($this->owner, $this->client, 'Agent', ['context.read']);
        $issued->accessToken->forceFill(['created_at' => now()->subDays(29), 'expires_at' => now()->addDays(2)])->save();
        $this->postJson(route('agent-tokens.rotate', $issued->accessToken))->assertUnprocessable();
        $this->assertNotNull(AgentAccessToken::findToken($issued->plainTextToken));
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_mutations_require_password_confirmation_and_reject_unknown_fields(): void
    {
        $issued = app(AgentTokenService::class)->issue($this->owner, $this->client, 'Agent', ['context.read']);
        $this->withSession(['auth.password_confirmed_at' => 0]);
        $this->postJson(route('agent-tokens.rotate', $issued->accessToken))->assertStatus(423);
        $this->deleteJson(route('agent-tokens.destroy', $issued->accessToken))->assertStatus(423);
        $this->withSession(['auth.password_confirmed_at' => time()]);
        $this->postJson(route('agent-tokens.rotate', $issued->accessToken), ['abilities' => ['*']])->assertUnprocessable();
        $this->deleteJson(route('agent-tokens.destroy', $issued->accessToken), ['client_id' => 0])->assertUnprocessable();
        $this->assertNotNull(AgentAccessToken::findToken($issued->plainTextToken));
    }

    public function test_mutation_routes_enforce_csrf_even_with_a_confirmed_session(): void
    {
        $issued = app(AgentTokenService::class)->issue($this->owner, $this->client, 'Agent', ['context.read']);
        $this->app->bind(PreventRequestForgery::class, fn ($app) => new class($app, $app['encrypter']) extends PreventRequestForgery
        {
            protected function runningUnitTests(): bool
            {
                return false;
            }
        });
        $this->postJson(route('agent-tokens.store'), ['client_id' => $this->client->id, 'name' => 'Denied'])->assertStatus(419);
        $this->postJson(route('agent-tokens.rotate', $issued->accessToken))->assertStatus(419);
        $this->deleteJson(route('agent-tokens.destroy', $issued->accessToken))->assertStatus(419);
        $this->withSession(['_token' => 'test-csrf-token'])->postJson(route('agent-tokens.store'), ['client_id' => $this->client->id, 'name' => 'Allowed'], ['X-CSRF-TOKEN' => 'test-csrf-token'])->assertCreated();
        $this->assertContains('web', Route::getRoutes()->getByName('agent-tokens.store')->gatherMiddleware());
    }
}
