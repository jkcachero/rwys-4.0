<?php

namespace Tests\Feature;

use App\Enums\AgentAbility;
use App\Models\AgentOperation;
use App\Models\Client;
use App\Models\ClientAccountPermission;
use App\Models\ProviderAccount;
use App\Models\ProviderProfile;
use App\Models\User;
use App\Services\AgentTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class AdKitOperationAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    public function test_restricted_key_only_lists_and_shows_allowed_account_receipts_and_denies_other_cursor_with_404(): void
    {
        [$token, $client, $accountA, $accountB] = $this->context(restricted: true);
        $this->freezeTime();
        $older = $this->operation($client, $accountA, ['requested_at' => now()->subMinutes(2)]);
        $hidden = $this->operation($client, $accountB, ['requested_at' => now()->subMinute(), 'outcome' => ['receipt' => 'account-b-private']]);
        $newer = $this->operation($client, $accountA);

        $this->withToken($token)->getJson('/api/v1/operations?limit=1')->assertOk()
            ->assertJsonPath('data.0.id', $newer->public_id)->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.next_cursor', $newer->public_id)->assertDontSee('account-b-private');
        $this->withToken($token)->getJson('/api/v1/operations?limit=1&cursor='.$newer->public_id)->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $older->public_id)
            ->assertJsonPath('meta.next_cursor', null)->assertDontSee('account-b-private');
        $this->withToken($token)->getJson('/api/v1/operations/'.$newer->public_id)->assertOk()
            ->assertJsonPath('data.account_id', $accountA->public_id)
            ->assertJsonPath('data.outcome.results.0.raw.id', '456')
            ->assertJsonMissingPath('data.intent')->assertJsonMissingPath('data.recovery_context')
            ->assertJsonMissingPath('data.providerAccount')->assertDontSee('private-adkit-key')->assertDontSee('private-project');
        $this->assertHidden($token, $hidden);

        Http::assertNothingSent();
    }

    #[TestWith(['removed'])]
    #[TestWith(['inactive-permission'])]
    #[TestWith(['inactive-account'])]
    #[TestWith(['inactive-profile'])]
    #[TestWith(['conflicting-claim'])]
    public function test_current_assignment_changes_hide_receipt_and_return_404_for_show_and_cursor(string $change): void
    {
        [$token, $client, $account] = $this->context(restricted: true);
        $operation = $this->operation($client, $account);
        $this->withToken($token)->getJson('/api/v1/operations/'.$operation->public_id)->assertOk();

        match ($change) {
            'removed' => $account->permissions()->delete(),
            'inactive-permission' => $account->permissions()->update(['is_active' => false]),
            'inactive-account' => $account->update(['is_active' => false]),
            'inactive-profile' => $account->profile->update(['is_active' => false]),
            'conflicting-claim' => ClientAccountPermission::factory()->create([
                'provider_account_id' => ProviderAccount::factory()->create([
                    'provider_profile_id' => ProviderProfile::factory()->create(['provider' => 'adkit']),
                    'provider_id' => $account->provider_id,
                    'provider_id_hash' => $account->provider_id_hash,
                    'platform' => 'meta',
                ]),
            ]),
        };

        $this->withToken($token)->getJson('/api/v1/operations')->assertOk()->assertJsonCount(0, 'data');
        $this->assertHidden($token, $operation);

        Http::assertNothingSent();
    }

    public function test_empty_account_scope_shows_all_currently_assigned_adkit_accounts_only(): void
    {
        [$token, $client, $accountA, $accountB] = $this->context();
        $first = $this->operation($client, $accountA);
        $second = $this->operation($client, $accountB);
        $unassigned = $this->operation($client, ProviderAccount::factory()->create([
            'provider_profile_id' => $accountA->provider_profile_id, 'platform' => 'meta',
        ]));
        $wrongProvider = $this->operation($client, ProviderAccount::factory()->create());
        ClientAccountPermission::factory()->create(['client_id' => $client, 'provider_account_id' => $wrongProvider->provider_account_id]);
        $missingAccount = $this->operation($client, null);

        $this->withToken($token)->getJson('/api/v1/operations')->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $second->public_id)->assertJsonPath('data.1.id', $first->public_id);
        $this->withToken($token)->getJson('/api/v1/operations/'.$second->public_id)->assertOk()
            ->assertJsonPath('data.account_id', $accountB->public_id);
        foreach ([$unassigned, $wrongProvider, $missingAccount] as $hidden) {
            $this->assertHidden($token, $hidden);
        }

        Http::assertNothingSent();
    }

    public function test_other_client_operation_uuid_is_404_even_for_admin_who_owns_both_clients(): void
    {
        [$token, $client, $account] = $this->context();
        $owner = $client->users()->sole();
        $owner->forceFill(['role' => 'admin'])->save();
        $otherClient = Client::factory()->create();
        $otherClient->users()->attach($owner, ['role' => 'owner', 'is_active' => true]);
        $foreign = $this->operation($otherClient, $account);

        $this->withToken($token)->getJson('/api/v1/operations')->assertOk()->assertJsonCount(0, 'data');
        $this->assertHidden($token, $foreign);

        Http::assertNothingSent();
    }

    public function test_zernio_operation_history_keeps_client_only_access_despite_account_restrictions_and_removed_assignment(): void
    {
        [$token, $client] = $this->context(restricted: true);
        $account = ProviderAccount::factory()->create();
        $operation = AgentOperation::factory()->create([
            'client_id' => $client, 'provider_account_id' => $account,
            'capability' => AgentAbility::PostsPublish->value,
            'status' => 'succeeded', 'outcome' => ['provider_status' => 'published'],
        ]);

        $this->withToken($token)->getJson('/api/v1/operations')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $operation->public_id);
        $this->withToken($token)->getJson('/api/v1/operations/'.$operation->public_id)->assertOk()
            ->assertJsonPath('data.outcome.provider_status', 'published');
        $this->withToken($token)->getJson('/api/v1/operations?cursor='.$operation->public_id)->assertOk()
            ->assertJsonCount(0, 'data');

        Http::assertNothingSent();
    }

    private function assertHidden(string $token, AgentOperation $operation): void
    {
        $this->withToken($token)->getJson('/api/v1/operations/'.$operation->public_id)->assertNotFound()
            ->assertJsonMissingPath('data')->assertJsonMissingPath('outcome');
        $this->withToken($token)->getJson('/api/v1/operations?cursor='.$operation->public_id)->assertNotFound()
            ->assertJsonMissingPath('data')->assertJsonMissingPath('outcome');
    }

    /** @return array{string, Client, ProviderAccount, ProviderAccount} */
    private function context(bool $restricted = false): array
    {
        $owner = User::factory()->create();
        $client = Client::factory()->create();
        $client->users()->attach($owner, ['role' => 'owner', 'is_active' => true]);
        $connection = $owner->providerConnections()->create([
            'provider' => 'adkit', 'client_id' => 'private-project', 'subject' => 'act_999',
            'access_token' => 'private-adkit-key', 'scopes' => [],
        ]);
        $profile = ProviderProfile::factory()->create([
            'provider' => 'adkit', 'provider_connection_id' => $connection,
            'provider_id' => 'private-project', 'provider_id_hash' => hash('sha256', 'private-project'),
        ]);
        $accounts = [];
        foreach (['act_123', 'act_456'] as $providerId) {
            $account = ProviderAccount::factory()->create([
                'provider_profile_id' => $profile, 'provider_id' => $providerId,
                'provider_id_hash' => hash('sha256', $providerId), 'platform' => 'meta',
            ]);
            ClientAccountPermission::factory()->create(['client_id' => $client, 'provider_account_id' => $account]);
            $accounts[] = $account;
        }
        $token = app(AgentTokenService::class)->issue($owner, $client, 'Operation reads',
            allowedAccountIds: $restricted ? [$accounts[0]->public_id] : []);
        $token->accessToken->update(['abilities' => [AgentAbility::OperationsRead->value]]);

        return [$token->plainTextToken, $client, ...$accounts];
    }

    /** @param array<string, mixed> $attributes */
    private function operation(Client $client, ?ProviderAccount $account, array $attributes = []): AgentOperation
    {
        return AgentOperation::factory()->create([
            'client_id' => $client, 'provider_account_id' => $account,
            'capability' => 'adkit.write', 'status' => 'succeeded',
            'outcome' => ['platform' => 'meta', 'status' => 'published', 'results' => [['raw' => ['id' => '456']]]],
            'intent' => ['description' => 'Private operation intent'],
            'recovery_context' => ['private' => 'not-for-api'],
            ...$attributes,
        ]);
    }
}
