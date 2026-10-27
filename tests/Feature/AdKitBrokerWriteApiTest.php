<?php

namespace Tests\Feature;

use App\Models\AgentOperation;
use App\Models\Client;
use App\Models\ClientAccountPermission;
use App\Models\PersonalAccessToken;
use App\Models\ProviderAccount;
use App\Models\ProviderProfile;
use App\Models\User;
use App\Services\AdKitBrokerWriteService;
use App\Services\AgentTokenService;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Request as BrokerRequest;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use Tests\TestCase;

class AdKitBrokerWriteApiTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/v1/manage/platform-api-requests';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.adkit' => ['base_url' => 'https://app.adkit.so/api/v1',
            'timeout' => 10, 'connect_timeout' => 3, 'max_response_bytes' => 100000]]);
        Http::preventStrayRequests();
    }

    public function test_publishes_active_campaign_without_rewriting_budget_and_replays_sanitized_receipt(): void
    {
        [$token] = $this->context();
        $this->fakeProvider(write: fn () => Http::response($this->receipt(['id' => '456', 'message' => 'synthetic-adkit-key stored-project '.$token, 'access_token' => 'secret'])));

        $response = $this->withToken($token)->withHeader('Idempotency-Key', 'campaign-1')
            ->postJson(self::URL.'?publish=true', $this->body())->assertOk()->assertJsonPath('status', 'published');
        $operation = AgentOperation::query()->sole();
        $response->assertHeader('X-Operation-Id', $operation->public_id)->assertJsonPath('results.0.raw.id', '456');
        $this->assertSame('succeeded', $operation->status);
        $this->assertStringNotContainsString('synthetic-adkit-key', json_encode($operation->outcome));
        $this->assertStringNotContainsString('stored-project', json_encode($operation->intent));
        Http::assertSent(fn (Request $request): bool => ($request['method'] ?? null) === 'POST'
            && $request['payload']['status'] === 'ACTIVE' && $request['payload']['daily_budget'] === '1879'
            && $request['accountId'] === 'act_999' && str_ends_with($request->url(), '?publish=true'));

        $this->withToken($token)->postJson(self::URL.'?publish=true', $this->body())->assertOk()
            ->assertHeader('X-Operation-Id', $operation->public_id)->assertDontSee($token)->assertDontSee('synthetic-adkit-key')
            ->assertJsonMissingPath('results.0.raw.access_token');
        Http::assertSentCount(2);
        $this->assertDatabaseCount('agent_operations', 1);
    }

    public function test_client_key_without_write_ability_can_read_and_create_assigned_account(): void
    {
        [$token] = $this->context(['adkit.read']);
        $this->fakeProvider(read: fn () => Http::response($this->readReceipt(['id' => '456', 'account_id' => '123', 'name' => 'Campaign'])));

        $this->withToken($token)->withHeader('Idempotency-Key', 'write-key-1')->postJson(self::URL.'?publish=true', $this->body())->assertOk();
        $this->withToken($token)->postJson(self::URL, ['platform' => 'meta', 'endpoint' => '/456', 'accountId' => 'act_123',
            'method' => 'GET', 'description' => 'Read campaign', 'payload' => ['fields' => 'name']])->assertOk()->assertJsonPath('response.name', 'Campaign');

        $this->assertSame('succeeded', AgentOperation::query()->sole()->status);
        Http::assertSentCount(5);
    }

    public function test_client_key_with_no_abilities_can_run_write_ownership_preflights(): void
    {
        [$token] = $this->context();
        $this->fakeProvider(read: fn () => Http::response($this->readReceipt(['id' => '888', 'account_id' => '123', 'campaign_id' => '456'])));
        $body = [...$this->body(), 'endpoint' => '/act_123/ads', 'payload' => ['name' => 'Ad', 'status' => 'ACTIVE', 'adset_id' => '888', 'creative' => '{"creative_id":"888"}']];

        $this->withToken($token)->withHeader('Idempotency-Key', 'write-only')->postJson(self::URL.'?publish=true', $body)->assertOk();

        Http::assertSentCount(3);
        $this->assertSame('succeeded', AgentOperation::query()->sole()->status);
    }

    #[TestWith(['unbound'])]
    #[TestWith(['missing-client'])]
    public function test_token_without_valid_client_binding_is_403_without_http(string $case): void
    {
        [$token, $client, , $key] = $this->context();
        if ($case === 'unbound') {
            $key->update(['client_id' => null]);
        } else {
            $client->update(['is_active' => false]);
        }

        $this->withToken($token)->withHeader('Idempotency-Key', 'client-binding')->postJson(self::URL.'?publish=true', $this->body())->assertForbidden();

        Http::assertNothingSent();
        $this->assertDatabaseCount('agent_operations', 0);
    }

    public function test_another_clients_assigned_account_is_404_without_http(): void
    {
        [$token] = $this->context();
        [, , $foreign] = $this->context();
        $foreign->update(['provider_id' => 'act_777', 'provider_id_hash' => hash('sha256', 'act_777')]);

        $this->withToken($token)->withHeader('Idempotency-Key', 'foreign-client')->postJson(self::URL.'?publish=true',
            [...$this->body(), 'endpoint' => '/act_777/campaigns'])->assertNotFound();

        Http::assertNothingSent();
        $this->assertDatabaseCount('agent_operations', 0);
    }

    #[TestWith(['target'])]
    #[TestWith(['context'])]
    public function test_account_prefixed_write_accepts_verified_target_or_connection_context(string $mode): void
    {
        [$token] = $this->context();
        $this->fakeProvider();
        $body = [...$this->body(), 'accountId' => $mode === 'target' ? 'act_123' : 'act_999'];

        $this->withToken($token)->withHeader('Idempotency-Key', 'account-context')->postJson(self::URL.'?publish=true', $body)->assertOk();

        Http::assertSent(fn (Request $request): bool => ($request['method'] ?? null) === 'POST' && $request['accountId'] === 'act_999');
    }

    public function test_unassigned_target_is_404_without_http(): void
    {
        [$token] = $this->context();
        $body = [...$this->body(), 'endpoint' => '/act_777/campaigns'];
        $this->withToken($token)->withHeader('Idempotency-Key', 'write-key-1')->postJson(self::URL.'?publish=true', $body)->assertNotFound();
        Http::assertNothingSent();
    }

    #[TestWith(['accountId', 'act_777'])]
    #[TestWith(['projectId', 'forged-project'])]
    public function test_forged_context_is_rejected_without_http(string $field, string $value): void
    {
        [$token] = $this->context();
        $this->withToken($token)->withHeader('Idempotency-Key', 'write-key-1')
            ->postJson(self::URL.'?publish=true', [...$this->body(), $field => $value])->assertUnprocessable();
        Http::assertNothingSent();
    }

    public function test_idempotency_key_is_required(): void
    {
        [$token] = $this->context();
        $this->withToken($token)->postJson(self::URL.'?publish=true', $this->body())->assertUnprocessable();
        Http::assertNothingSent();
    }

    #[TestWith([8])]
    #[TestWith([200])]
    public function test_idempotency_key_boundary_lengths_are_accepted(int $length): void
    {
        [$token] = $this->context();
        $this->fakeProvider();

        $this->withToken($token)->withHeader('Idempotency-Key', str_repeat('k', $length))->postJson(self::URL.'?publish=true', $this->body())->assertOk();

        $this->assertSame('succeeded', AgentOperation::query()->sole()->status);
        Http::assertSentCount(2);
    }

    #[TestWith([7])]
    #[TestWith([201])]
    public function test_write_service_rejects_out_of_bounds_idempotency_key_before_ledger_or_http(int $length): void
    {
        [, , $account] = $this->context();
        $request = BrokerRequest::create(self::URL, 'POST', server: ['HTTP_IDEMPOTENCY_KEY' => str_repeat('k', $length)]);
        $this->expectException(ValidationException::class);

        try {
            app(AdKitBrokerWriteService::class)->forward($request, $account, $this->body(), true,
                fn (): ProviderAccount => $account, fn (array $envelope): array => $envelope);
        } finally {
            Http::assertNothingSent();
            $this->assertDatabaseCount('agent_operations', 0);
        }
    }

    public function test_changed_intent_conflicts_without_another_http_call(): void
    {
        [$token] = $this->context();
        $this->fakeProvider();
        $this->withToken($token)->withHeader('Idempotency-Key', 'same-key-1')->postJson(self::URL.'?publish=true', $this->body())->assertOk();
        $body = $this->body();
        $body['payload']['daily_budget'] = '1880';
        $this->withToken($token)->postJson(self::URL.'?publish=true', $body)->assertConflict()->assertJsonPath('error', 'adkit_idempotency_conflict');
        Http::assertSentCount(2);
    }

    #[TestWith(['executing', 409])]
    #[TestWith(['unknown', 503])]
    public function test_pending_or_unknown_operation_is_never_redispatched(string $status, int $code): void
    {
        [$token] = $this->context();
        $this->fakeProvider();
        $this->withToken($token)->withHeader('Idempotency-Key', 'same-key-1')->postJson(self::URL.'?publish=true', $this->body())->assertOk();
        $operation = AgentOperation::query()->sole();
        $operation->update(['status' => $status]);
        $this->withToken($token)->postJson(self::URL.'?publish=true', $this->body())->assertStatus($code);
        Http::assertSentCount(2);
    }

    public function test_timeout_records_unknown_and_does_not_retry(): void
    {
        [$token] = $this->context();
        $this->fakeProvider(write: function (): never {
            throw new ConnectionException('private transport details');
        });
        $this->withToken($token)->withHeader('Idempotency-Key', 'timeout-key')->postJson(self::URL.'?publish=true', $this->body())
            ->assertStatus(503)->assertJsonPath('error', 'adkit_unconfirmed')->assertDontSee('private transport details');
        $this->assertSame('unknown', AgentOperation::query()->sole()->status);
        $this->withToken($token)->postJson(self::URL.'?publish=true', $this->body())->assertStatus(503);
        $this->assertDatabaseCount('agent_operations', 1);
    }

    #[TestWith(['campaign_id', 'adsets'])]
    #[TestWith(['adset_id', 'ads'])]
    public function test_foreign_parent_is_rejected_before_write(string $parent, string $edge): void
    {
        [$token] = $this->context();
        $this->fakeProvider(read: fn () => Http::response($this->readReceipt(['id' => '888', 'account_id' => '777'])));
        $body = [...$this->body(), 'endpoint' => '/act_123/'.$edge, 'payload' => ['name' => 'Child', $parent => '888', 'status' => 'PAUSED']];
        if ($edge === 'ads') {
            $body['payload']['creative'] = '{"creative_id":"999"}';
        } else {
            $body['payload'] += ['targeting' => '{"geo_locations":{"countries":["US"]}}',
                'billing_event' => 'IMPRESSIONS', 'optimization_goal' => 'LINK_CLICKS', 'promoted_object' => '{"page_id":"321"}'];
        }
        $this->withToken($token)->withHeader('Idempotency-Key', 'child-key-1')->postJson(self::URL.'?publish=true', $body)->assertNotFound();
        Http::assertNotSent(fn (Request $request): bool => ($request['method'] ?? null) === 'POST');
        $this->assertSame('failed', AgentOperation::query()->sole()->status);
    }

    #[TestWith(['permission', 404])]
    #[TestWith(['owner', 403])]
    public function test_authority_change_during_preflight_stops_dispatch(string $mutation, int $status): void
    {
        [$token, $client, $account, $key] = $this->context();
        $this->fakeProvider(member: function () use ($mutation, $client, $account, $key): void {
            if ($mutation === 'permission') {
                $account->permissions()->update(['is_active' => false]);
            } else {
                $client->users()->updateExistingPivot($key->tokenable_id, ['role' => 'member']);
            }
        });
        $this->withToken($token)->withHeader('Idempotency-Key', 'change-key')->postJson(self::URL.'?publish=true', $this->body())->assertStatus($status);
        Http::assertSentCount(1);
        $this->assertSame('failed', AgentOperation::query()->sole()->status);
    }

    public function test_receipt_survives_post_dispatch_revocation_and_new_authorized_key_can_replay(): void
    {
        [$token, $client, , $key] = $this->context();
        $this->fakeProvider(write: function () use ($key) {
            $key->update(['revoked_at' => now()]);

            return Http::response($this->receipt(['id' => '456']));
        });
        $this->withToken($token)->withHeader('Idempotency-Key', 'receipt-key')->postJson(self::URL.'?publish=true', $this->body())->assertForbidden();
        $operation = AgentOperation::query()->sole();
        $this->assertSame('succeeded', $operation->status);
        $this->assertSame('456', $operation->outcome['results'][0]['raw']['id']);
        $replacement = app(AgentTokenService::class)->issue($key->tokenable, $client, 'Replacement');
        $replacement->accessToken->update(['abilities' => []]);
        $this->app['auth']->forgetGuards();
        $this->withToken($replacement->plainTextToken)->postJson(self::URL.'?publish=true', $this->body())->assertOk()->assertHeader('X-Operation-Id', $operation->public_id);
        Http::assertSentCount(2);
    }

    #[TestWith(['123', 200])]
    #[TestWith(['777', 404])]
    public function test_numeric_read_verifies_metadata_before_requested_projection(string $owner, int $status): void
    {
        [$token] = $this->context();
        $calls = 0;
        $this->fakeProvider(read: function () use (&$calls, $owner) {
            $calls++;

            return Http::response($this->readReceipt(['id' => '456', 'account_id' => $owner, 'name' => 'Created campaign']));
        });
        $body = ['platform' => 'meta', 'endpoint' => '/456', 'accountId' => 'act_123', 'method' => 'GET', 'description' => 'Read created campaign', 'payload' => ['fields' => 'name']];
        $response = $this->withToken($token)->postJson(self::URL, $body)->assertStatus($status);
        $this->assertSame($status === 200 ? 2 : 1, $calls);
        if ($status === 200) {
            $response->assertJsonMissingPath('response.account_id')->assertJsonPath('response.name', 'Created campaign');
        } else {
            $response->assertDontSee('Created campaign');
        }
    }

    public function test_numeric_read_cannot_use_unassigned_connection_subject_as_target(): void
    {
        [$token] = $this->context();
        $body = ['platform' => 'meta', 'endpoint' => '/456', 'accountId' => 'act_999', 'method' => 'GET', 'description' => 'Read object', 'payload' => ['fields' => 'name']];

        $this->withToken($token)->postJson(self::URL, $body)->assertNotFound();

        Http::assertNothingSent();
    }

    #[TestWith(['adsets', 'campaign_id'])]
    #[TestWith(['ads', 'adset_id'])]
    #[TestWith(['insights', 'campaign_id'])]
    public function test_owned_numeric_parent_edge_is_read_only_after_metadata_verification(string $edge, string $parentField): void
    {
        [$token] = $this->context();
        $endpoints = [];
        $row = ['account_id' => '123', $parentField => '456'];
        $row += $edge === 'insights' ? ['spend' => '17.83'] : ['id' => '888', 'name' => 'Child'];
        $this->fakeProvider(read: function (Request $request) use (&$endpoints, $edge, $row) {
            $endpoints[] = $request['endpoint'];
            $this->assertSame('act_999', $request['accountId']);
            if ($request['endpoint'] === '/456') {
                $this->assertSame(['fields' => 'id,account_id'], $request['payload']);

                return Http::response($this->readReceipt(['id' => '456', 'account_id' => '123']));
            }
            $this->assertSame('/456/'.$edge, $request['endpoint']);

            return Http::response($this->readReceipt(['data' => [$row]]));
        });
        $payload = ['fields' => $parentField.($edge === 'insights' ? ',spend' : ',name')];
        if ($edge === 'insights') {
            $payload['date_preset'] = 'last_30d';
        }

        $response = $this->withToken($token)->postJson(self::URL, ['platform' => 'meta', 'endpoint' => '/456/'.$edge,
            'accountId' => 'act_123', 'method' => 'GET', 'description' => 'Read exact hierarchy', 'payload' => $payload])->assertOk();

        $response->assertJsonPath('response.data.0.'.$parentField, '456')->assertJsonMissingPath('response.data.0.account_id')
            ->assertJsonMissingPath('results.0.raw.data.0.account_id');
        $this->assertSame(['/456', '/456/'.$edge], $endpoints);
        Http::assertSentCount(3);
        $this->assertDatabaseCount('agent_operations', 0);
    }

    #[TestWith(['adsets', 'id'])]
    #[TestWith(['adsets', 'account_id'])]
    #[TestWith(['ads', 'id'])]
    #[TestWith(['ads', 'account_id'])]
    #[TestWith(['insights', 'id'])]
    #[TestWith(['insights', 'account_id'])]
    public function test_mismatched_numeric_parent_metadata_is_404_before_edge_read(string $edge, string $field): void
    {
        [$token] = $this->context();
        $this->fakeProvider(read: function (Request $request) use ($field) {
            $this->assertSame('/456', $request['endpoint']);

            return Http::response($this->readReceipt(['id' => '456', 'account_id' => '123', $field => '777']));
        });
        $payload = ['fields' => $edge === 'insights' ? 'spend' : 'name'];
        if ($edge === 'insights') {
            $payload['date_preset'] = 'last_30d';
        }

        $this->withToken($token)->postJson(self::URL, ['platform' => 'meta', 'endpoint' => '/456/'.$edge,
            'accountId' => 'act_123', 'method' => 'GET', 'description' => 'Read hierarchy', 'payload' => $payload])
            ->assertNotFound()->assertJsonMissingPath('response')->assertJsonMissingPath('results');

        Http::assertSentCount(2);
        Http::assertNotSent(fn (Request $request): bool => ($request['endpoint'] ?? null) === '/456/'.$edge);
    }

    #[TestWith(['adsets'])]
    #[TestWith(['ads'])]
    #[TestWith(['insights'])]
    public function test_owned_numeric_parent_does_not_allow_foreign_account_edge_results(string $edge): void
    {
        [$token] = $this->context();
        $this->fakeProvider(read: fn (Request $request) => Http::response($this->readReceipt($request['endpoint'] === '/456'
            ? ['id' => '456', 'account_id' => '123'] : ['data' => [['id' => '888', 'account_id' => '777', 'name' => 'Foreign child', 'spend' => '17.83']]])));
        $payload = ['fields' => $edge === 'insights' ? 'spend' : 'name'];
        if ($edge === 'insights') {
            $payload['date_preset'] = 'last_30d';
        }

        $this->withToken($token)->postJson(self::URL, ['platform' => 'meta', 'endpoint' => '/456/'.$edge,
            'accountId' => 'act_123', 'method' => 'GET', 'description' => 'Read hierarchy', 'payload' => $payload])
            ->assertStatus(502)->assertJsonPath('error', 'adkit_invalid_response')->assertDontSee('Foreign child')->assertDontSee('17.83');

        Http::assertSentCount(3);
    }

    #[TestWith(['adsets', 'response'])]
    #[TestWith(['adsets', 'raw'])]
    #[TestWith(['ads', 'response'])]
    #[TestWith(['ads', 'raw'])]
    public function test_same_account_wrong_parent_children_are_rejected_in_both_receipt_copies(string $edge, string $copy): void
    {
        [$token] = $this->context();
        $this->fakeProvider(read: function (Request $request) use ($edge, $copy) {
            if ($request['endpoint'] === '/456') {
                return Http::response($this->readReceipt(['id' => '456', 'account_id' => '123']));
            }
            $required = $edge === 'adsets' ? ['campaign_id'] : ['campaign_id', 'adset_id'];
            foreach ($required as $field) {
                $this->assertContains($field, explode(',', $request['payload']['fields']));
            }
            $good = ['data' => [['id' => '888', 'account_id' => '123', 'campaign_id' => '456', 'adset_id' => '999', 'name' => 'Child']]];
            $bad = ['data' => [[...$good['data'][0], 'campaign_id' => '777', 'adset_id' => '999']]];

            return Http::response(['platform' => 'meta', 'status' => 'executed', 'response' => $copy === 'response' ? $bad : $good,
                'results' => [['raw' => $copy === 'raw' ? $bad : $good]]]);
        });

        $this->withToken($token)->postJson(self::URL, ['platform' => 'meta', 'endpoint' => '/456/'.$edge,
            'accountId' => 'act_123', 'method' => 'GET', 'description' => 'Read exact parent', 'payload' => ['fields' => 'name']])
            ->assertStatus(502)->assertJsonPath('error', 'adkit_invalid_response')->assertJsonMissingPath('response');

        Http::assertSentCount(3);
    }

    public function test_reads_exact_two_adsets_and_three_ads_per_set_preserving_requested_projection(): void
    {
        [$token] = $this->context();
        $this->fakeProvider(read: function (Request $request) {
            $endpoint = $request['endpoint'];
            if (! str_contains(ltrim($endpoint, '/'), '/')) {
                return Http::response($this->readReceipt(['id' => ltrim($endpoint, '/'), 'account_id' => '123']));
            }
            if ($endpoint === '/456/adsets') {
                return Http::response($this->readReceipt(['data' => [
                    ['id' => '111', 'account_id' => '123', 'campaign_id' => '456', 'name' => 'Set A'],
                    ['id' => '222', 'account_id' => '123', 'campaign_id' => '456', 'name' => 'Set B'],
                ]]));
            }
            $parent = explode('/', ltrim($endpoint, '/'))[0];
            $rows = [];
            foreach (range(1, 3) as $index) {
                $rows[] = ['id' => $parent.$index, 'account_id' => '123', 'campaign_id' => '456', 'adset_id' => $parent, 'name' => 'Ad '.$index];
            }

            return Http::response($this->readReceipt(['data' => $rows]));
        });
        $base = ['platform' => 'meta', 'accountId' => 'act_123', 'method' => 'GET', 'description' => 'Read hierarchy'];

        $sets = $this->withToken($token)->postJson(self::URL, [...$base, 'endpoint' => '/456/adsets',
            'payload' => ['fields' => 'id,name,campaign_id']])->assertOk()->assertJsonCount(2, 'response.data')
            ->assertJsonPath('response.data.0.campaign_id', '456')->assertJsonMissingPath('response.data.0.account_id');
        $total = 0;
        foreach ($sets->json('response.data') as $set) {
            $ads = $this->withToken($token)->postJson(self::URL, [...$base, 'endpoint' => '/'.$set['id'].'/ads',
                'payload' => ['fields' => 'id,name']])->assertOk()->assertJsonCount(3, 'response.data')
                ->assertJsonMissingPath('response.data.0.campaign_id')->assertJsonMissingPath('response.data.0.adset_id')
                ->assertJsonMissingPath('results.0.raw.data.0.campaign_id')->assertJsonMissingPath('results.0.raw.data.0.adset_id');
            $total += count($ads->json('response.data'));
        }

        $this->assertSame(6, $total);
        Http::assertSentCount(9);
    }

    #[TestWith([true, 200])]
    #[TestWith([false, 502])]
    public function test_public_image_read_checks_hash_without_requesting_unsupported_account_identity(bool $matches, int $status): void
    {
        [$token] = $this->context();
        $hash = str_repeat('a', 32);
        $this->fakeProvider(read: fn () => Http::response($this->readReceipt(['data' => [['hash' => $matches ? $hash : str_repeat('b', 32), 'name' => 'Image']]])));
        $body = ['platform' => 'meta', 'endpoint' => '/act_123/adimages', 'method' => 'GET', 'description' => 'Read image', 'payload' => ['fields' => 'name', 'hashes' => json_encode([$hash])]];

        $response = $this->withToken($token)->postJson(self::URL, $body)->assertStatus($status);

        Http::assertSent(fn (Request $request): bool => ($request['payload']['fields'] ?? null) === 'name,hash');
        if ($matches) {
            $response->assertJsonPath('response.data.0.name', 'Image')->assertJsonMissingPath('response.data.0.hash');
        }
        Http::assertSentCount(2);
    }

    #[TestWith(['valid', 200])]
    #[TestWith(['page', 404])]
    #[TestWith(['instagram', 404])]
    #[TestWith(['image', 404])]
    public function test_creative_requires_account_page_page_instagram_and_target_image(string $case, int $status): void
    {
        [$token] = $this->context();
        $hash = str_repeat('a', 32);
        $body = [...$this->body(), 'endpoint' => '/act_123/adcreatives', 'payload' => [
            'name' => 'Creative', 'object_story_spec' => json_encode(['page_id' => '321', 'instagram_user_id' => '654',
                'photo_data' => ['image_hash' => $hash, 'caption' => 'Caption']]),
        ]];
        $this->fakeProvider(read: function (Request $request) use ($case, $hash) {
            if ($request['endpoint'] === '/321') {
                return Http::response($this->readReceipt(['id' => '321', 'instagram_business_account' => ['id' => $case === 'instagram' ? '777' : '654']]));
            }
            $this->assertSame('/act_123/adimages', $request['endpoint']);
            $this->assertSame(['hashes' => json_encode([$hash]), 'fields' => 'hash', 'limit' => 1], $request['payload']);

            return Http::response($this->readReceipt(['data' => [['hash' => $case === 'image' ? str_repeat('b', 32) : $hash]]]));
        });
        Http::fake(['https://app.adkit.so/api/v1/manage/meta/accounts/act_123/pages' => Http::response([
            'pages' => [['id' => $case === 'page' ? '777' : '321', 'name' => 'Page']],
        ])]);

        $this->withToken($token)->withHeader('Idempotency-Key', 'creative-key')->postJson(self::URL.'?publish=true', $body)->assertStatus($status);

        $this->assertSame($status === 200 ? 'succeeded' : 'failed', AgentOperation::query()->sole()->status);
        if ($status !== 200) {
            Http::assertNotSent(fn (Request $request): bool => ($request['method'] ?? null) === 'POST');
        } else {
            Http::assertSentCount(5);
        }
    }

    public function test_foreign_creative_cannot_be_copied_into_owned_adset(): void
    {
        [$token] = $this->context();
        $this->fakeProvider(read: fn (Request $request) => Http::response($this->readReceipt([
            'id' => ltrim($request['endpoint'], '/'), 'account_id' => $request['endpoint'] === '/888' ? '123' : '777', 'campaign_id' => '456',
        ])));
        $body = [...$this->body(), 'endpoint' => '/act_123/ads', 'payload' => ['name' => 'Ad', 'status' => 'ACTIVE', 'adset_id' => '888', 'creative' => '{"creative_id":"999"}']];

        $this->withToken($token)->withHeader('Idempotency-Key', 'foreign-creative')->postJson(self::URL.'?publish=true', $body)->assertNotFound();

        Http::assertSentCount(3);
        Http::assertNotSent(fn (Request $request): bool => ($request['method'] ?? null) === 'POST');
    }

    #[TestWith(['321', 200])]
    #[TestWith(['777', 404])]
    public function test_adset_promoted_page_must_be_allowed_for_target_account(string $allowedPage, int $status): void
    {
        [$token] = $this->context();
        $this->fakeProvider(read: fn () => Http::response($this->readReceipt(['id' => '456', 'account_id' => '123'])));
        Http::fake(['https://app.adkit.so/api/v1/manage/meta/accounts/act_123/pages' => Http::response([
            'pages' => [['id' => $allowedPage, 'name' => 'Page']],
        ])]);
        $body = [...$this->body(), 'endpoint' => '/act_123/adsets', 'payload' => ['name' => 'Set', 'status' => 'PAUSED', 'campaign_id' => '456',
            'targeting' => '{"geo_locations":{"countries":["US"]}}', 'billing_event' => 'IMPRESSIONS',
            'optimization_goal' => 'LINK_CLICKS', 'promoted_object' => '{"page_id":"321"}']];

        $this->withToken($token)->withHeader('Idempotency-Key', 'promoted-page')->postJson(self::URL.'?publish=true', $body)->assertStatus($status);

        Http::assertSentCount($status === 200 ? 4 : 3);
        $this->assertSame($status === 200 ? 'succeeded' : 'failed', AgentOperation::query()->sole()->status);
        if ($status === 404) {
            Http::assertNotSent(fn (Request $request): bool => ($request['method'] ?? null) === 'POST');
        }
    }

    #[TestWith(['adsets'])]
    #[TestWith(['ads'])]
    #[TestWith(['ads', true])]
    public function test_owned_parents_allow_child_write(string $edge, bool $integerIds = false): void
    {
        [$token] = $this->context();
        $this->fakeProvider(read: fn (Request $request) => Http::response($this->readReceipt([
            'id' => ltrim($request['endpoint'], '/'), 'account_id' => '123', 'campaign_id' => '456',
        ])));
        if ($edge === 'adsets') {
            Http::fake(['https://app.adkit.so/api/v1/manage/meta/accounts/act_123/pages' => Http::response([
                'pages' => [['id' => '321', 'name' => 'Page']],
            ])]);
        }
        $payload = ['name' => 'Child', 'status' => 'PAUSED'];
        $payload += $edge === 'ads' ? ['adset_id' => '888', 'creative' => '{"creative_id":"999"}']
            : ['campaign_id' => '456', 'targeting' => '{"geo_locations":{"countries":["US"]}}',
                'billing_event' => 'IMPRESSIONS', 'optimization_goal' => 'LINK_CLICKS', 'promoted_object' => '{"page_id":"321"}'];
        if ($integerIds) {
            $payload['adset_id'] = 888;
            $payload['creative'] = '{"creative_id":999}';
        }

        $this->withToken($token)->withHeader('Idempotency-Key', 'owned-child')->postJson(self::URL.'?publish=true',
            [...$this->body(), 'endpoint' => '/act_123/'.$edge, 'payload' => $payload])->assertOk();

        $this->assertSame('succeeded', AgentOperation::query()->sole()->status);
        Http::assertSentCount(4);
    }

    public function test_replay_rechecks_current_permission_before_any_http(): void
    {
        [$token, , $account] = $this->context();
        $this->fakeProvider();
        $this->withToken($token)->withHeader('Idempotency-Key', 'replay-permission')->postJson(self::URL.'?publish=true', $this->body())->assertOk();
        $account->permissions()->update(['is_active' => false]);

        $this->withToken($token)->postJson(self::URL.'?publish=true', $this->body())->assertNotFound()->assertJsonMissingPath('results');

        Http::assertSentCount(2);
        $this->assertSame('succeeded', AgentOperation::query()->sole()->status);
    }

    #[TestWith(['executing'])]
    #[TestWith(['unknown'])]
    public function test_confirmed_receipt_survives_first_ledger_save_failure_and_recovers_on_replay(string $state): void
    {
        $disk = Storage::fake('local');
        [$token, $client, $account] = $this->context();
        $failures = 1;
        $this->failReceiptSaves($failures);
        $this->fakeProvider(write: fn () => Http::response($this->receipt(['id' => '456', 'access_token' => 'secret', 'message' => $token.' synthetic-adkit-key stored-project'])));

        $this->withToken($token)->withHeader('Idempotency-Key', 'durable-receipt')->postJson(self::URL.'?publish=true', $this->body())
            ->assertStatus(503)->assertJsonPath('error', 'adkit_receipt_persistence_failed');

        $operation = AgentOperation::query()->sole();
        $this->assertSame('executing', $operation->status);
        $this->assertNull($operation->outcome);
        $path = 'agent-operations/'.$client->public_id.'/adkit-receipts/'.$operation->public_id;
        $disk->assertExists($path);
        $encrypted = $disk->get($path);
        $this->assertStringNotContainsString('"id":"456"', $encrypted);
        $journal = json_decode(Crypt::decryptString($encrypted), true);
        $this->assertSame($operation->public_id, $journal['operation_id']);
        $this->assertSame($client->public_id, $journal['client_id']);
        $this->assertSame($account->public_id, $journal['account_id']);
        $this->assertSame($operation->payload_hash, $journal['payload_hash']);
        $this->assertSame('456', $journal['receipt']['results'][0]['raw']['id']);
        $this->assertStringNotContainsString($token, json_encode($journal));
        $this->assertStringNotContainsString('synthetic-adkit-key', json_encode($journal));
        $this->assertStringNotContainsString('stored-project', json_encode($journal));
        $this->assertSame('private', $disk->getVisibility($path));
        $operation->update(['status' => $state]);

        $this->withToken($token)->postJson(self::URL.'?publish=true', $this->body())->assertOk()
            ->assertHeader('X-Operation-Id', $operation->public_id)->assertJsonPath('results.0.raw.id', '456')
            ->assertJsonMissingPath('results.0.raw.access_token');

        $this->assertSame('succeeded', $operation->fresh()->status);
        $this->assertSame($journal['receipt'], $operation->fresh()->outcome);
        $disk->assertMissing($path);
        Http::assertSentCount(2);
        $this->assertDatabaseCount('agent_operations', 1);
    }

    public function test_repeated_ledger_failures_keep_journal_and_never_redispatch(): void
    {
        $disk = Storage::fake('local');
        [$token, $client] = $this->context();
        $failures = 2;
        $this->failReceiptSaves($failures);
        $this->fakeProvider();

        $this->withToken($token)->withHeader('Idempotency-Key', 'repeat-save-failure')->postJson(self::URL.'?publish=true', $this->body())
            ->assertStatus(503)->assertJsonPath('error', 'adkit_receipt_persistence_failed');
        $operation = AgentOperation::query()->sole();
        $path = 'agent-operations/'.$client->public_id.'/adkit-receipts/'.$operation->public_id;
        $originalJournal = $disk->get($path);
        $this->withToken($token)->postJson(self::URL.'?publish=true', $this->body())->assertStatus(503)
            ->assertHeader('X-Operation-Id', $operation->public_id)->assertJsonPath('error', 'adkit_receipt_persistence_failed');

        $this->assertSame($originalJournal, $disk->get($path));
        $this->assertSame('executing', $operation->fresh()->status);
        $this->withToken($token)->postJson(self::URL.'?publish=true', $this->body())->assertOk()->assertJsonPath('results.0.raw.id', '456');
        $disk->assertMissing($path);
        Http::assertSentCount(2);
        $this->assertDatabaseCount('agent_operations', 1);
    }

    #[TestWith(['operation_id'])]
    #[TestWith(['client_id'])]
    #[TestWith(['account_id'])]
    #[TestWith(['payload_hash'])]
    public function test_journal_binding_mismatch_cannot_recover_or_redispatch(string $field): void
    {
        $disk = Storage::fake('local');
        [$token, $client] = $this->context();
        $failures = 1;
        $this->failReceiptSaves($failures);
        $this->fakeProvider();
        $this->withToken($token)->withHeader('Idempotency-Key', 'journal-binding')->postJson(self::URL.'?publish=true', $this->body())->assertStatus(503);
        $operation = AgentOperation::query()->sole();
        $path = 'agent-operations/'.$client->public_id.'/adkit-receipts/'.$operation->public_id;
        $journal = json_decode(Crypt::decryptString($disk->get($path)), true);
        $journal[$field] = 'foreign-or-mismatched';
        $disk->put($path, Crypt::encryptString(json_encode($journal)));

        $this->withToken($token)->postJson(self::URL.'?publish=true', $this->body())->assertStatus(503)
            ->assertJsonPath('error', 'adkit_receipt_journal_invalid')->assertJsonMissingPath('results');

        $this->assertSame('executing', $operation->fresh()->status);
        $disk->assertExists($path);
        Http::assertSentCount(2);
    }

    #[TestWith(['null'])]
    #[TestWith(['ciphertext'])]
    public function test_invalid_journal_cannot_be_treated_as_a_missing_receipt(string $corruption): void
    {
        $disk = Storage::fake('local');
        [$token, $client] = $this->context();
        $failures = 1;
        $this->failReceiptSaves($failures);
        $this->fakeProvider();
        $this->withToken($token)->withHeader('Idempotency-Key', 'invalid-journal')->postJson(self::URL.'?publish=true', $this->body())->assertStatus(503);
        $operation = AgentOperation::query()->sole();
        $path = 'agent-operations/'.$client->public_id.'/adkit-receipts/'.$operation->public_id;
        $disk->put($path, $corruption === 'null' ? Crypt::encryptString('null') : 'not-authenticated-ciphertext');

        $this->withToken($token)->postJson(self::URL.'?publish=true', $this->body())->assertStatus(503)
            ->assertJsonPath('error', 'adkit_receipt_journal_invalid');

        Http::assertSentCount(2);
        $disk->assertExists($path);
    }

    public function test_journal_recovery_requires_current_assignment(): void
    {
        $disk = Storage::fake('local');
        [$token, $client, $account] = $this->context();
        $failures = 1;
        $this->failReceiptSaves($failures);
        $this->fakeProvider();
        $this->withToken($token)->withHeader('Idempotency-Key', 'journal-permission')->postJson(self::URL.'?publish=true', $this->body())->assertStatus(503);
        $operation = AgentOperation::query()->sole();
        $account->permissions()->update(['is_active' => false]);

        $this->withToken($token)->postJson(self::URL.'?publish=true', $this->body())->assertNotFound()->assertJsonMissingPath('results');

        $disk->assertExists('agent-operations/'.$client->public_id.'/adkit-receipts/'.$operation->public_id);
        Http::assertSentCount(2);
    }

    public function test_revocation_during_dispatch_does_not_prevent_fallback_receipt_storage(): void
    {
        $disk = Storage::fake('local');
        [$token, $client, , $key] = $this->context();
        $failures = 1;
        $this->failReceiptSaves($failures);
        $this->fakeProvider(write: function () use ($key) {
            $key->update(['revoked_at' => now()]);

            return Http::response($this->receipt());
        });

        $this->withToken($token)->withHeader('Idempotency-Key', 'revoked-save-failure')->postJson(self::URL.'?publish=true', $this->body())
            ->assertForbidden()->assertJsonMissingPath('resource_ids');

        $operation = AgentOperation::query()->sole();
        $path = 'agent-operations/'.$client->public_id.'/adkit-receipts/'.$operation->public_id;
        $journal = json_decode(Crypt::decryptString($disk->get($path)), true);
        $this->assertSame('456', $journal['receipt']['results'][0]['raw']['id']);
        Http::assertSentCount(2);
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function test_ledger_and_journal_failure_preserve_safe_resource_evidence_not_fake_execution_failure(bool $loggingFails): void
    {
        [$token, $client, $account] = $this->context();
        $failures = 10;
        $this->failReceiptSaves($failures);
        $disk = \Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('put')->once()->andReturnFalse();
        Storage::shouldReceive('disk')->with('local')->andReturn($disk);
        Log::spy();
        if ($loggingFails) {
            Log::shouldReceive('critical')->once()->andThrow(new RuntimeException('private logging failure'));
        }
        $this->fakeProvider(write: fn () => Http::response($this->receipt(['id' => '456', 'message' => $token.' synthetic-adkit-key stored-project'])));

        $response = $this->withToken($token)->withHeader('Idempotency-Key', 'both-storage-fail')->postJson(self::URL.'?publish=true', $this->body())
            ->assertStatus(503)->assertJsonPath('error', 'adkit_receipt_persistence_failed')
            ->assertJsonPath('provider_status', 'published')->assertJsonPath('resource_ids', ['456'])
            ->assertDontSee($token)->assertDontSee('synthetic-adkit-key')->assertDontSee('stored-project')->assertDontSee('private SQL failure');

        $operation = AgentOperation::query()->sole();
        $response->assertHeader('X-Operation-Id', $operation->public_id);
        $this->assertSame('executing', $operation->status);
        Log::shouldHaveReceived('critical')->once()->with('AdKit confirmed receipt could not be persisted.', [
            'operation_id' => $operation->public_id, 'client_id' => $client->public_id, 'account_id' => $account->public_id,
            'resource_ids' => ['456'], 'image_hashes' => [],
        ]);
        Http::assertSentCount(2);
    }

    public function test_successful_ledger_path_does_not_create_receipt_journal(): void
    {
        $disk = Storage::fake('local');
        [$token] = $this->context();
        $this->fakeProvider();

        $this->withToken($token)->withHeader('Idempotency-Key', 'normal-no-journal')->postJson(self::URL.'?publish=true', $this->body())->assertOk();

        $disk->assertEmpty();
        Http::assertSentCount(2);
    }

    private function failReceiptSaves(int &$failures): void
    {
        AgentOperation::updating(function (AgentOperation $operation) use (&$failures): void {
            if ($operation->status === 'succeeded' && $operation->isDirty('outcome') && $failures-- > 0) {
                throw new QueryException('sqlite', 'update agent_operations set outcome = ?', ['private binding'], new RuntimeException('private SQL failure'));
            }
        });
    }

    public function test_associative_key_order_does_not_change_intent_but_explicit_value_types_do(): void
    {
        [$token] = $this->context();
        $this->fakeProvider();
        $body = $this->body();
        $this->withToken($token)->withHeader('Idempotency-Key', 'canonical-key')->postJson(self::URL.'?publish=true', $body)->assertOk();
        $body['payload'] = array_reverse($body['payload'], true);

        $this->withToken($token)->postJson(self::URL.'?publish=true', array_reverse($body, true))->assertOk();
        $body['payload']['daily_budget'] = 1879;
        $this->withToken($token)->postJson(self::URL.'?publish=true', $body)->assertConflict();

        Http::assertSentCount(2);
    }

    public function test_post_without_publish_is_not_silently_published_and_unproven_draft_is_unknown(): void
    {
        [$token] = $this->context();
        $this->fakeProvider(write: fn () => Http::response(['platform' => 'meta', 'status' => 'executed', 'response' => ['id' => '456'], 'results' => []]));

        $this->withToken($token)->withHeader('Idempotency-Key', 'unproven-draft')->postJson(self::URL, $this->body())->assertStatus(503);

        Http::assertSent(fn (Request $request): bool => ($request['method'] ?? null) === 'POST' && ! str_contains($request->url(), 'publish=true'));
        $this->assertSame('unknown', AgentOperation::query()->sole()->status);
    }

    public function test_synthetic_draft_receipt_is_saved_not_marked_executed(): void
    {
        [$token] = $this->context();
        $envelope = ['platform' => 'meta', 'status' => 'draft', 'draftId' => 'synthetic-draft-id'];
        $this->fakeProvider(write: fn () => Http::response($envelope));

        $this->withToken($token)->withHeader('Idempotency-Key', 'synthetic-draft')->postJson(self::URL, $this->body())->assertOk()
            ->assertJsonPath('draftId', 'synthetic-draft-id')->assertJsonPath('status', 'draft');

        $operation = AgentOperation::query()->sole();
        $this->assertSame('draft', $operation->status);
        $this->assertSame($envelope, $operation->outcome);
        Http::assertSentCount(2);
    }

    #[TestWith([400, 'failed', 502])]
    #[TestWith([401, 'failed', 502])]
    #[TestWith([403, 'failed', 502])]
    #[TestWith([404, 'failed', 502])]
    #[TestWith([422, 'failed', 502])]
    #[TestWith([408, 'unknown', 503])]
    #[TestWith([425, 'unknown', 503])]
    #[TestWith([429, 'unknown', 503])]
    #[TestWith([500, 'unknown', 503])]
    public function test_provider_failures_are_classified_without_redispatch(int $upstream, string $state, int $status): void
    {
        [$token] = $this->context();
        $this->fakeProvider(write: fn () => Http::response(['error' => 'private provider failure'], $upstream));

        $response = $this->withToken($token)->withHeader('Idempotency-Key', 'provider-failure')->postJson(self::URL.'?publish=true', $this->body())
            ->assertStatus($status)->assertDontSee('private provider failure');

        $operation = AgentOperation::query()->sole();
        $response->assertHeader('X-Operation-Id', $operation->public_id);
        $this->assertSame($state, $operation->status);
        $replay = $this->withToken($token)->postJson(self::URL.'?publish=true', $this->body())->assertStatus($status)
            ->assertHeader('X-Operation-Id', $operation->public_id);
        if ($state === 'unknown') {
            $response->assertJsonPath('error', 'adkit_unconfirmed');
            $replay->assertJsonPath('error', 'adkit_unconfirmed');
        }
        $this->assertDatabaseCount('agent_operations', 1);
        Http::assertSentCount(2);
    }

    public function test_malformed_success_is_unknown_and_error_identifies_operation(): void
    {
        [$token] = $this->context();
        $this->fakeProvider(write: fn () => Http::response(['platform' => 'meta', 'status' => 'published', 'results' => []]));

        $response = $this->withToken($token)->withHeader('Idempotency-Key', 'malformed-success')->postJson(self::URL.'?publish=true', $this->body())->assertStatus(503);

        $operation = AgentOperation::query()->sole();
        $response->assertHeader('X-Operation-Id', $operation->public_id);
        $this->assertSame('unknown', $operation->status);
        Http::assertSentCount(2);
    }

    public function test_overlap_cannot_dispatch_while_first_request_is_in_preflight(): void
    {
        [$token] = $this->context();
        $this->fakeProvider(member: function () use ($token): void {
            $this->withToken($token)->withHeader('Idempotency-Key', 'overlap-key')->postJson(self::URL.'?publish=true', $this->body())
                ->assertConflict()->assertJsonPath('error', 'adkit_operation_pending');
        });

        $this->withToken($token)->withHeader('Idempotency-Key', 'overlap-key')->postJson(self::URL.'?publish=true', $this->body())->assertOk();

        Http::assertSentCount(2);
        $this->assertDatabaseCount('agent_operations', 1);
    }

    public function test_image_upload_receipt_uses_hash_not_numeric_resource_identity(): void
    {
        [$token] = $this->context();
        $bytes = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=';
        $hash = str_repeat('a', 32);
        $this->fakeProvider(write: fn () => Http::response(['platform' => 'meta', 'status' => 'published',
            'results' => [['platformId' => '', 'action' => 'created', 'raw' => ['images' => ['image.png' => ['hash' => $hash]]]]]]));
        $body = [...$this->body(), 'endpoint' => '/act_123/adimages', 'payload' => ['bytes' => $bytes]];

        $response = $this->withToken($token)->withHeader('Idempotency-Key', 'image-upload')->postJson(self::URL.'?publish=true', $body)->assertOk();
        $this->assertSame($hash, $response->json('results')[0]['raw']['images']['image.png']['hash']);

        $operation = AgentOperation::query()->sole();
        $this->assertStringNotContainsString($bytes, json_encode($operation->intent));
        $this->assertSame('succeeded', $operation->status);
        Http::assertSentCount(2);
    }

    /** @param list<string> $abilities
     * @return array{string, Client, ProviderAccount, PersonalAccessToken}
     */
    private function context(array $abilities = []): array
    {
        $owner = User::factory()->create();
        $client = Client::factory()->create();
        $client->users()->attach($owner, ['role' => 'owner', 'is_active' => true]);
        $connection = $owner->providerConnections()->create(['provider' => 'adkit', 'client_id' => 'stored-project', 'subject' => 'act_999', 'access_token' => 'synthetic-adkit-key', 'scopes' => []]);
        $profile = ProviderProfile::factory()->create(['provider' => 'adkit', 'provider_connection_id' => $connection, 'provider_id' => 'stored-project']);
        $account = ProviderAccount::factory()->create(['provider_profile_id' => $profile, 'provider_id' => 'act_123', 'provider_id_hash' => hash('sha256', 'act_123'), 'platform' => 'meta']);
        ClientAccountPermission::factory()->create(['client_id' => $client, 'provider_account_id' => $account]);
        $token = app(AgentTokenService::class)->issue($owner, $client, 'Write test');
        $token->accessToken->update(['abilities' => $abilities, 'allowed_account_ids' => [$account->public_id]]);

        return [$token->plainTextToken, $client, $account, $token->accessToken];
    }

    private function body(): array
    {
        return ['platform' => 'meta', 'endpoint' => '/act_123/campaigns', 'method' => 'POST', 'description' => 'Create campaign',
            'payload' => ['name' => 'Campaign', 'objective' => 'OUTCOME_TRAFFIC', 'status' => 'ACTIVE', 'daily_budget' => '1879', 'special_ad_categories' => '[]']];
    }

    private function receipt(array $raw = ['id' => '456']): array
    {
        return ['platform' => 'meta', 'status' => 'published', 'results' => [['platformId' => $raw['id'] ?? '', 'action' => 'created', 'raw' => $raw]]];
    }

    private function readReceipt(array $raw): array
    {
        return ['platform' => 'meta', 'status' => 'executed', 'response' => $raw, 'results' => [['platformId' => '', 'action' => 'created', 'raw' => $raw]]];
    }

    private function fakeProvider(?Closure $write = null, ?Closure $read = null, ?Closure $member = null): void
    {
        Http::fake([
            'https://app.adkit.so/api/v1/manage/meta/accounts' => function () use ($member) {
                $member?->__invoke();

                return Http::response(['accounts' => [['id' => 'act_123', 'platform' => 'meta', 'name' => 'Account', 'currency' => 'USD', 'status' => 1]]]);
            },
            'https://app.adkit.so/api/v1/manage/platform-api-requests*' => function (Request $request) use ($write, $read) {
                return ($request['method'] ?? null) === 'GET' ? $read($request) : ($write ? $write($request) : Http::response($this->receipt()));
            },
        ]);
    }
}
