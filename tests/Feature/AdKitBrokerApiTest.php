<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientAccountPermission;
use App\Models\PersonalAccessToken;
use App\Models\ProviderAccount;
use App\Models\ProviderProfile;
use App\Models\User;
use App\Services\AgentTokenService;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class AdKitBrokerApiTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/v1/manage/platform-api-requests';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.adkit' => [
            'base_url' => 'https://app.adkit.so/api/v1',
            'timeout' => 10, 'connect_timeout' => 3, 'max_response_bytes' => 100000,
        ]]);
        Http::preventStrayRequests();
    }

    public function test_forwards_original_stats_body_with_server_credentials_and_preserves_envelope(): void
    {
        [$token] = $this->context();
        $body = $this->body();
        $envelope = $this->envelope(['data' => [[
            'account_id' => '123', 'account_currency' => 'USD', 'spend' => '180.28',
            'impressions' => '43684', 'clicks' => '245', 'reach' => '16060',
            'date_start' => '2026-10-26', 'date_stop' => '2026-10-26', 'cpc' => null,
        ]]]);
        $this->fakeProvider($envelope);

        $response = $this->withToken($token)->withHeader('x-adkit-project-id', 'caller-spoof')
            ->postJson(self::URL, $body)->assertOk();

        $this->assertSame($envelope, array_diff_key($response->json(), ['request_id' => true]));
        $response->assertJsonMissingPath('response.data.0.cpm')->assertJsonStructure(['request_id']);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://app.adkit.so/api/v1/manage/platform-api-requests'
            && $request->data() === [...$body, 'accountId' => 'act_999']
            && $request->hasHeader('Authorization', 'Bearer synthetic-adkit-key')
            && $request->hasHeader('x-adkit-project-id', 'stored-project')
            && ! $request->hasHeader('Authorization', 'Bearer '.$token));
        Http::assertSentCount(2);
    }

    #[TestWith([''])]
    #[TestWith(['/campaigns'])]
    #[TestWith(['/ads'])]
    #[TestWith(['/adsets'])]
    public function test_account_scoped_reads_preserve_raw_ids_budgets_and_empty_pages(string $edge): void
    {
        [$token] = $this->context();
        $body = $this->body();
        $body['endpoint'] = 'act_123'.$edge;
        $body['payload'] = ['fields' => $edge === '' ? 'id,account_id,name' : 'id,account_id,name,daily_budget'];
        $row = ['id' => $edge === '' ? 'act_123' : '456', 'account_id' => '123', 'name' => 'Anonymized', 'daily_budget' => '100000000000000001'];
        $this->fakeProvider($this->envelope($edge === '' ? $row : ['data' => [$row]]));

        $response = $this->withToken($token)->postJson(self::URL, $body)->assertOk();

        $response->assertJsonPath($edge === '' ? 'response.id' : 'response.data.0.id', $row['id']);
        Http::assertSent(fn (Request $request): bool => ($request['payload']['limit'] ?? null) === 25);
        Http::assertSentCount(2);
    }

    public function test_empty_insights_and_provider_cursors_are_preserved_without_following_urls(): void
    {
        [$token] = $this->context();
        $body = $this->body();
        $body['payload'] = ['fields' => 'account_id,spend', 'date_preset' => 'maximum', 'after' => 'opaque_+=/-', 'limit' => 100];
        $this->fakeProvider($this->envelope(['data' => [], 'paging' => [
            'cursors' => ['before' => 'before_123', 'after' => 'after_123'],
            'next' => 'https://graph.facebook.com/v25.0/act_123/insights?after=after_123&access_token=secret&limit=100',
            'previous' => 'https://graph.facebook.com/v25.0/act_123/insights?before=before_123',
        ]], [['platformId' => '', 'action' => 'created', 'raw' => ['data' => []],
            'echo' => ['key' => 'synthetic-adkit-key', 'bearer' => $token]]]));

        $response = $this->withToken($token)->postJson(self::URL, $body)->assertOk()
            ->assertJsonPath('response.data', [])->assertJsonPath('response.paging.cursors.after', 'after_123')
            ->assertJsonPath('response.paging.next', 'https://graph.facebook.com/v25.0/act_123/insights?after=after_123&limit=100')
            ->assertJsonPath('response.paging.previous', 'https://graph.facebook.com/v25.0/act_123/insights?before=before_123');

        $this->assertStringNotContainsString('synthetic-adkit-key', $response->getContent());
        $this->assertStringNotContainsString($token, $response->getContent());
        Http::assertSent(fn (Request $request): bool => ($request['payload'] ?? null) === $body['payload']);
        Http::assertSentCount(2);
    }

    #[DataProvider('invalidBodies')]
    public function test_invalid_operations_fail_validation_without_http(array $override): void
    {
        [$token] = $this->context();
        $this->withToken($token)->postJson(self::URL, array_replace_recursive($this->body(), $override))
            ->assertUnprocessable()->assertJsonStructure(['errors', 'request_id']);

        Http::assertNothingSent();
    }

    public static function invalidBodies(): array
    {
        return [
            'write on a read-only provider edge' => [['method' => 'POST']],
            'platform' => [['platform' => 'google']],
            'provider secret' => [['api_key' => 'spoof']],
            'project' => [['projectId' => 'spoof']],
            'payload secret' => [['payload' => ['access_token' => 'spoof']]],
            'publish' => [['payload' => ['publish' => true]]],
            'filter' => [['payload' => ['filtering' => '[]']]],
            'url' => [['endpoint' => 'https://evil.invalid/act_123/insights']],
            'query' => [['endpoint' => '/act_123/insights?publish=true']],
            'fragment' => [['endpoint' => '/act_123/insights#x']],
            'traversal' => [['endpoint' => '/act_123/../insights']],
            'encoded path' => [['endpoint' => '/act_123%2finsights']],
            'object id' => [['endpoint' => '/456/insights']],
            'expansion' => [['payload' => ['fields' => 'campaigns{id,name}']]],
            'unknown fields' => [['payload' => ['fields' => 'account_id,secret']]],
            'fields array' => [['payload' => ['fields' => ['account_id']]]],
            'zero limit' => [['payload' => ['limit' => 0]]],
            'large limit' => [['payload' => ['limit' => 101]]],
            'cursor' => [['payload' => ['after' => 'https://evil.invalid?x']]],
            'long cursor' => [['payload' => ['after' => str_repeat('a', 4097)]]],
            'description' => [['description' => str_repeat('a', 201)]],
            'level' => [['payload' => ['level' => 'business']]],
            'preset' => [['payload' => ['date_preset' => 'all_time']]],
            'both ranges' => [['payload' => ['date_preset' => 'last_30d']]],
            'array time range' => [['payload' => ['time_range' => ['since' => '2026-01-01', 'until' => '2026-01-02']]]],
            'invalid calendar' => [['payload' => ['time_range' => '{"since":"2026-02-30","until":"2026-03-01"}']]],
            'future' => [['payload' => ['time_range' => '{"since":"2099-01-01","until":"2099-01-02"}']]],
            'reversed' => [['payload' => ['time_range' => '{"since":"2026-02-02","until":"2026-02-01"}']]],
            '91 days' => [['payload' => ['time_range' => '{"since":"2026-01-01","until":"2026-04-01"}']]],
            'range override' => [['payload' => ['time_range' => '{"since":"2026-01-01","until":"2026-01-02","extra":true}']]],
            'account context spoof' => [['accountId' => 'act_777']],
        ];
    }

    public function test_insights_require_a_time_window_and_query_parameters_are_rejected(): void
    {
        [$token] = $this->context();
        $body = $this->body();
        unset($body['payload']['time_range']);
        $this->withToken($token)->postJson(self::URL, $body)->assertUnprocessable();
        $this->withToken($token)->postJson(self::URL.'?publish=true', $this->body())->assertUnprocessable();
        Http::assertNothingSent();
    }

    public function test_ninety_inclusive_days_and_matching_optional_context_are_accepted(): void
    {
        [$token] = $this->context();
        $body = $this->body();
        $body['accountId'] = 'act_999';
        $body['payload']['time_range'] = '{"since":"2026-01-01","until":"2026-03-31"}';
        $this->fakeProvider($this->envelope(['data' => []]));

        $this->withToken($token)->postJson(self::URL, $body)->assertOk();
        Http::assertSent(fn (Request $request): bool => Arr::sortRecursive($request->data()) === Arr::sortRecursive($body));
        Http::assertSentCount(2);
    }

    #[TestWith(['none', 401])]
    #[TestWith(['revoked', 403])]
    #[TestWith(['unbound', 403])]
    #[TestWith(['owner', 403])]
    #[TestWith(['client', 403])]
    #[TestWith(['scope', 404])]
    #[TestWith(['assignment', 404])]
    #[TestWith(['permission', 404])]
    #[TestWith(['inactive', 404])]
    public function test_unauthorized_context_does_not_call_upstream(string $case, int $status): void
    {
        [$token, $client, $account, $accessToken] = $this->context();
        match ($case) {
            'none' => $token = '',
            'revoked' => $accessToken->update(['revoked_at' => now()]),
            'unbound' => $accessToken->update(['client_id' => null]),
            'owner' => $client->users()->updateExistingPivot($accessToken->tokenable_id, ['is_active' => false]),
            'client' => $client->update(['is_active' => false]),
            'scope' => $accessToken->update(['allowed_account_ids' => [fake()->uuid()]]),
            'assignment' => $client->accountPermissions()->delete(),
            'permission' => $account->permissions()->update(['is_active' => false]),
            'inactive' => $account->update(['is_active' => false]),
        };
        $this->withToken($token)->postJson(self::URL, $this->body())->assertStatus($status);
        Http::assertNothingSent();
    }

    #[TestWith(['mapping', 503])]
    #[TestWith(['missing', 503])]
    #[TestWith(['expired', 503])]
    #[TestWith(['empty', 503])]
    public function test_unusable_backend_configuration_does_not_call_upstream(string $case, int $status): void
    {
        [$token, , $account] = $this->context();
        match ($case) {
            'mapping' => $account->profile->providerConnection->update(['client_id' => 'wrong-project']),
            'missing' => $account->profile->update(['provider_connection_id' => null]),
            'expired' => $account->profile->providerConnection->update(['access_token_expires_at' => now()->subDay()]),
            'empty' => $account->profile->providerConnection->update(['access_token' => '']),
        };
        $this->withToken($token)->postJson(self::URL, $this->body())->assertStatus($status);
        Http::assertNothingSent();
    }

    #[TestWith([1, 'revoked', 403])]
    #[TestWith([2, 'revoked', 403])]
    #[TestWith([1, 'mapping', 503])]
    #[TestWith([2, 'mapping', 503])]
    #[TestWith([1, 'permission', 404])]
    #[TestWith([2, 'permission', 404])]
    public function test_concurrent_authority_or_mapping_change_stops_next_call_or_discards_result(int $stage, string $mutation, int $status): void
    {
        [$token, , $account, $accessToken] = $this->context();
        $calls = 0;
        $this->fakeProvider($this->envelope(['data' => [['account_id' => '123', 'spend' => '180.28']]]), function () use (&$calls, $stage, $mutation, $account, $accessToken): void {
            if (++$calls === $stage) {
                match ($mutation) {
                    'revoked' => $accessToken->update(['revoked_at' => now()]),
                    'mapping' => $account->profile->providerConnection->update(['access_token' => 'replacement']),
                    'permission' => $account->permissions()->update(['is_active' => false]),
                };
            }
        });

        $this->withToken($token)->postJson(self::URL, $this->body())->assertStatus($status)
            ->assertJsonMissingPath('response')->assertDontSee('180.28');
        Http::assertSentCount($stage);
    }

    #[TestWith([['data' => [['account_id' => '777']]]])]
    #[TestWith([['data' => [['spend' => '180.28']]]])]
    #[TestWith([['data' => 'invalid']])]
    #[TestWith([['data' => [['account_id' => '123'], ['account_id' => '123']]]])]
    public function test_cross_account_or_malformed_pages_are_not_exposed(array $page): void
    {
        [$token] = $this->context();
        $body = $this->body();
        $body['payload']['limit'] = 1;
        $this->fakeProvider($this->envelope($page));
        $this->withToken($token)->postJson(self::URL, $body)->assertStatus(502)
            ->assertJsonPath('error', 'adkit_invalid_response')->assertJsonMissingPath('response');
        Http::assertSentCount(2);
    }

    public function test_membership_must_include_target_not_just_connection_subject(): void
    {
        [$token] = $this->context();
        $this->fakeProvider($this->envelope(['data' => []]), connectedId: 'act_999');
        $this->withToken($token)->postJson(self::URL, $this->body())->assertStatus(502);
        Http::assertSentCount(1);
    }

    public function test_api_preserves_stats_and_scrubs_nested_credentials(): void
    {
        [$token] = $this->context();
        $this->fakeProvider($this->envelope(['data' => [['account_id' => '123', 'spend' => '180.28', 'cpc' => null]],
            'paging' => ['next' => 'https://graph.facebook.com/act_123/insights?access_token=secret&after=safe&context=stored-project']],
            [['platformId' => '', 'action' => 'created', 'raw' => ['data' => [['account_id' => '123', 'spend' => '180.28', 'cpc' => null]]],
                'echo' => ['access_token' => 'secret', 'message' => 'synthetic-adkit-key '.$token.' stored-project',
                    'client_id' => 'stored-project', 'stored-project' => 'internal project echo',
                    $token => 'echoed key', 'encoded' => rawurlencode($token), 'partial' => explode('|', $token, 2)[1]]]]));

        $response = $this->withToken($token)->postJson(self::URL, $this->body())->assertOk();

        $data = $response->json();
        $this->assertSame('180.28', $data['response']['data'][0]['spend']);
        $this->assertArrayNotHasKey('impressions', $data['response']['data'][0]);
        $this->assertNull($data['response']['data'][0]['cpc']);
        $this->assertSame('https://graph.facebook.com/act_123/insights?after=safe&context=[redacted]', $data['response']['paging']['next']);
        $this->assertArrayNotHasKey('access_token', $data['results'][0]['echo']);
        $this->assertStringNotContainsString($token, $response->getContent());
        $this->assertStringNotContainsString(explode('|', $token, 2)[1], $response->getContent());
        $this->assertStringNotContainsString('synthetic-adkit-key', $response->getContent());
        $this->assertStringNotContainsString('stored-project', $response->getContent());
        Http::assertSentCount(2);
    }

    #[TestWith(['', 'id,name,account_status', 'id,name,account_status,account_id'])]
    #[TestWith(['', 'name,account_status', 'name,account_status,id,account_id'])]
    #[TestWith(['/campaigns', 'id,name,status', 'id,name,status,account_id'])]
    #[TestWith(['/ads', 'name,status', 'name,status,id,account_id'])]
    #[TestWith(['/adsets', 'name,status', 'name,status,id,account_id'])]
    #[TestWith(['/insights', 'spend', 'spend,account_id'])]
    public function test_verification_fields_are_injected_and_only_added_fields_are_removed_from_both_raw_copies(string $edge, string $fields, string $upstreamFields): void
    {
        [$token] = $this->context();
        $body = $this->body();
        $body['endpoint'] = '/act_123'.$edge;
        $body['payload'] = ['fields' => $fields];
        if ($edge === '/insights') {
            $body['payload']['date_preset'] = 'last_30d';
        }
        $row = ['id' => $edge === '' ? 'act_123' : '456', 'account_id' => '123', 'name' => 'Anonymized', 'status' => 'ACTIVE', 'account_status' => 1, 'spend' => '180.28'];
        $expected = [...array_intersect_key($row, array_flip(explode(',', $fields))), 'provider_default' => null];
        Http::fake([
            'https://app.adkit.so/api/v1/manage/meta/accounts' => Http::response(['accounts' => [['id' => 'act_123', 'platform' => 'meta', 'name' => 'Anonymized', 'currency' => 'USD', 'status' => 1]]]),
            'https://app.adkit.so/api/v1/manage/platform-api-requests' => function (Request $request) use ($row, $edge) {
                $projected = [...array_intersect_key($row, array_flip(explode(',', $request['payload']['fields']))), 'provider_default' => null];
                $raw = $edge === '' ? $projected : ['data' => [$projected]];

                return Http::response($this->envelope($raw, [['platformId' => '', 'action' => 'created', 'raw' => $raw]]));
            },
        ]);

        $response = $this->withToken($token)->postJson(self::URL, $body)->assertOk();
        $this->assertSame($expected, $response->json($edge === '' ? 'response' : 'response.data.0'));
        $this->assertSame($expected, $response->json($edge === '' ? 'results.0.raw' : 'results.0.raw.data.0'));
        $response->assertJsonPath('results.0.platformId', '')->assertJsonPath('results.0.action', 'created');
        Http::assertSent(fn (Request $request): bool => ($request['payload']['fields'] ?? null) === $upstreamFields);
        Http::assertSentCount(2);
    }

    #[TestWith(['response', 'account_id', '777'])]
    #[TestWith(['raw', 'account_id', '777'])]
    #[TestWith(['raw', 'id', 'act_777'])]
    public function test_injected_identity_mismatch_in_either_raw_copy_returns_502(string $copy, string $field, string $value): void
    {
        [$token] = $this->context();
        $body = [...$this->body(), 'endpoint' => '/act_123', 'payload' => ['fields' => 'name,account_status']];
        $identity = ['id' => 'act_123', 'account_id' => '123', 'name' => 'Anonymized', 'account_status' => 1];
        $changed = [...$identity, $field => $value];
        $this->fakeProvider($this->envelope($copy === 'response' ? $changed : $identity,
            [['platformId' => '', 'action' => 'created', 'raw' => $copy === 'raw' ? $changed : $identity]]));

        $this->withToken($token)->postJson(self::URL, $body)->assertStatus(502)
            ->assertJsonPath('error', 'adkit_invalid_response')->assertJsonMissingPath('response')->assertJsonMissingPath('results');
        Http::assertSentCount(2);
    }

    public function test_post_reads_use_read_buckets_and_invalid_writes_use_write_buckets(): void
    {
        $this->freezeTime();
        [$token, $client, , $accessToken] = $this->context();
        $this->fakeProvider($this->envelope(['data' => []]));

        foreach (range(1, 7) as $attempt) {
            $this->withToken($token)->postJson(self::URL, $this->body())->assertOk();
        }
        foreach (['client' => $client->id, 'token' => $accessToken->id] as $kind => $id) {
            $this->assertSame(7, RateLimiter::attempts("agent:read:{$kind}:{$id}"));
            $this->assertSame(0, RateLimiter::attempts("agent:write:{$kind}:{$id}"));
        }
        Http::assertSentCount(14);
        $this->withToken($token)->postJson(self::URL, [...$this->body(), 'method' => 'POST'])
            ->assertUnprocessable()->assertJsonValidationErrors(['endpoint']);
        Http::assertSentCount(14);
        foreach (['client' => $client->id, 'token' => $accessToken->id] as $kind => $id) {
            $this->assertSame(7, RateLimiter::attempts("agent:read:{$kind}:{$id}"));
            $this->assertSame(1, RateLimiter::attempts("agent:write:{$kind}:{$id}"));
        }
    }

    public function test_body_target_owned_only_by_another_client_is_404_without_http(): void
    {
        [$token, , , $accessToken] = $this->context();
        $accessToken->update(['allowed_account_ids' => []]);
        [, , $foreignAccount] = $this->context();
        $foreignAccount->update(['provider_id' => 'act_777', 'provider_id_hash' => hash('sha256', 'act_777')]);

        $this->withToken($token)->postJson(self::URL, [...$this->body(), 'endpoint' => '/act_777/insights'])
            ->assertNotFound()->assertJsonMissingPath('response');
        Http::assertNothingSent();
    }

    public function test_only_documented_envelope_keys_are_returned_and_unexpected_root_secrets_are_dropped(): void
    {
        [$token] = $this->context();
        $envelope = $this->envelope(['data' => [['account_id' => '123', 'spend' => '180.28']]]);
        $this->fakeProvider([...$envelope, 'unexpected' => 'undocumented-sensitive-value',
            'debug' => ['credentials' => 'synthetic-adkit-key'], 'request_id' => 'upstream-request-id']);

        $response = $this->withToken($token)->postJson(self::URL, $this->body())->assertOk();

        $this->assertSame($envelope, array_diff_key($response->json(), ['request_id' => true]));
        $response->assertJsonStructure(['request_id'])->assertJsonPath('results.0.action', 'created')
            ->assertDontSee('undocumented-sensitive-value')->assertDontSee('synthetic-adkit-key')->assertDontSee('upstream-request-id');
        Http::assertSent(fn (Request $request): bool => ($request['method'] ?? null) === 'GET');
        Http::assertSentCount(2);
    }

    #[TestWith(['missing'])]
    #[TestWith(['null'])]
    #[TestWith(['scalar'])]
    #[TestWith(['associative'])]
    #[TestWith(['scalar entry'])]
    #[TestWith(['list entry'])]
    #[TestWith(['mixed entries'])]
    public function test_invalid_results_envelopes_return_502_without_exposing_any_provider_data(string $case): void
    {
        [$token] = $this->context();
        $envelope = $this->envelope(['data' => [['account_id' => '123', 'spend' => '180.28']]]);
        $envelope['results'] = match ($case) {
            'missing', 'null' => null,
            'scalar' => 'undocumented-sensitive-value',
            'associative' => ['echo' => 'undocumented-sensitive-value'],
            'scalar entry' => ['undocumented-sensitive-value'],
            'list entry' => [['undocumented-sensitive-value']],
            'mixed entries' => [...$envelope['results'], 'undocumented-sensitive-value'],
        };
        if ($case === 'missing') {
            unset($envelope['results']);
        }
        $this->fakeProvider($envelope);

        $this->withToken($token)->postJson(self::URL, $this->body())->assertStatus(502)
            ->assertJsonPath('error', 'adkit_invalid_response')->assertJsonMissingPath('response')->assertJsonMissingPath('results')
            ->assertDontSee('180.28')->assertDontSee('undocumented-sensitive-value');
        Http::assertSentCount(2);
    }

    public function test_empty_results_list_is_preserved(): void
    {
        [$token] = $this->context();
        $this->fakeProvider($this->envelope(['data' => []], []));

        $this->withToken($token)->postJson(self::URL, $this->body())->assertOk()->assertJsonPath('results', []);
        Http::assertSentCount(2);
    }

    /** @return array{string, Client, ProviderAccount, PersonalAccessToken} */
    private function context(): array
    {
        $owner = User::factory()->create();
        $client = Client::factory()->create();
        $client->users()->attach($owner, ['role' => 'owner', 'is_active' => true]);
        $connection = $owner->providerConnections()->create(['provider' => 'adkit', 'client_id' => 'stored-project', 'subject' => 'act_999', 'access_token' => 'synthetic-adkit-key', 'scopes' => ['read']]);
        $profile = ProviderProfile::factory()->create(['provider' => 'adkit', 'provider_connection_id' => $connection, 'provider_id' => 'stored-project']);
        $client->providerProfiles()->attach($profile, ['assigned_by' => $owner->id, 'assigned_at' => now()]);
        $account = ProviderAccount::factory()->create(['provider_profile_id' => $profile, 'provider_id' => 'act_123', 'provider_id_hash' => hash('sha256', 'act_123'), 'platform' => 'meta']);
        ClientAccountPermission::factory()->create(['client_id' => $client, 'provider_account_id' => $account]);
        $token = app(AgentTokenService::class)->issue($owner, $client, 'Broker test');
        $token->accessToken->update(['abilities' => ['adkit.read'], 'allowed_account_ids' => [$account->public_id]]);

        return [$token->plainTextToken, $client, $account, $token->accessToken];
    }

    /** @return array<string, mixed> */
    private function body(): array
    {
        return ['platform' => 'meta', 'endpoint' => '/act_123/insights', 'method' => 'GET', 'description' => 'Read account statistics', 'payload' => [
            'fields' => 'account_id,account_currency,spend,impressions,clicks,reach,cpc,cpm,ctr,date_start,date_stop',
            'time_range' => '{"since":"2026-10-26","until":"2026-10-26"}', 'level' => 'account', 'limit' => 25,
        ]];
    }

    /** @param array<string, mixed> $response
     * @param  list<array<string, mixed>>|null  $results
     * @return array<string, mixed>
     */
    private function envelope(array $response, ?array $results = null): array
    {
        return ['platform' => 'meta', 'status' => 'executed', 'response' => $response,
            'results' => $results ?? [['platformId' => '', 'action' => 'created', 'raw' => $response]]];
    }

    /** @param array<string, mixed> $envelope */
    private function fakeProvider(array $envelope, ?Closure $onCall = null, string $connectedId = 'act_123'): void
    {
        Http::fake([
            'https://app.adkit.so/api/v1/manage/meta/accounts' => function () use ($onCall, $connectedId) {
                $onCall?->__invoke();

                return Http::response(['accounts' => [['id' => $connectedId, 'platform' => 'meta', 'name' => 'Anonymized', 'currency' => 'USD', 'status' => 1]]]);
            },
            'https://app.adkit.so/api/v1/manage/platform-api-requests' => function () use ($onCall, $envelope) {
                $onCall?->__invoke();

                return Http::response($envelope);
            },
        ]);
    }
}
