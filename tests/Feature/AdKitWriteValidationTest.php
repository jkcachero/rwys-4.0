<?php

namespace Tests\Feature;

use App\Http\Requests\Api\V1\ForwardAdKitRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class AdKitWriteValidationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        // Isolate the real FormRequest boundary from the concurrently implemented broker.
        Route::post('/_test/adkit-validation', fn (ForwardAdKitRequest $request) => response()->json($request->validated()));
    }

    #[DataProvider('validWrites')]
    public function test_accepts_operation_payloads_without_rewriting_types(string $edge, array $payload): void
    {
        $body = self::body($edge, $payload);

        $this->postJson('/_test/adkit-validation?publish=true', $body, ['Idempotency-Key' => 'synthetic-operation'])
            ->assertOk()->assertExactJson($body);

        Http::assertNothingSent();
    }

    public static function validWrites(): array
    {
        return [
            'campaign active non-policy budget' => ['campaigns', ['name' => 'Synthetic campaign', 'objective' => 'OUTCOME_ENGAGEMENT', 'status' => 'ACTIVE', 'daily_budget' => '1750', 'bid_strategy' => 'LOWEST_COST_WITHOUT_CAP', 'special_ad_categories' => '[]']],
            'campaign integer budget' => ['campaigns', ['name' => 'Synthetic', 'objective' => 'OUTCOME_TRAFFIC', 'status' => 'PAUSED', 'lifetime_budget' => 5000, 'special_ad_categories' => '["HOUSING"]']],
            'adset campaign budget' => ['adsets', ['campaign_id' => '1234', 'name' => 'Synthetic set', 'targeting' => '{"geo_locations":{"countries":["PH"]}}', 'billing_event' => 'IMPRESSIONS', 'optimization_goal' => 'POST_ENGAGEMENT', 'promoted_object' => '{"page_id":"123"}', 'status' => 'ACTIVE', 'is_dynamic_creative' => false]],
            'ad' => ['ads', ['adset_id' => '3456', 'creative' => '{"creative_id":"4567"}', 'name' => 'Synthetic ad', 'status' => 'PAUSED']],
            'photo creative' => ['adcreatives', ['name' => 'Synthetic photo', 'object_story_spec' => '{"page_id":"123","instagram_user_id":"456","photo_data":{"image_hash":"abcdef0123456789abcdef0123456789","caption":"Synthetic caption"}}']],
            'link creative' => ['adcreatives', ['name' => 'Synthetic link', 'object_story_spec' => '{"page_id":"123","link_data":{"image_hash":"abcdef0123456789abcdef0123456789","link":"https://example.com/landing","message":"Synthetic message","name":"Headline","description":"Text","call_to_action":{"type":"LEARN_MORE","value":{"link":"https://example.com/landing"}}}}']],
            'image' => ['adimages', ['bytes' => self::png()]],
        ];
    }

    #[DataProvider('invalidWrites')]
    public function test_rejects_bad_payload_before_provider(array $overrides, string $field): void
    {
        $body = array_replace(self::body('campaigns', self::validWrites()['campaign active non-policy budget'][1]), $overrides);

        $this->postJson('/_test/adkit-validation', $body, ['Idempotency-Key' => 'synthetic-operation'], JSON_PRESERVE_ZERO_FRACTION)
            ->assertUnprocessable()->assertJsonValidationErrors($field);

        Http::assertNothingSent();
    }

    public static function invalidWrites(): array
    {
        $campaign = self::validWrites()['campaign active non-policy budget'][1];
        $set = self::validWrites()['adset campaign budget'][1];

        return [
            'numeric write' => [['endpoint' => '1234'], 'endpoint'],
            'numeric adsets write' => [['endpoint' => '1234/adsets'], 'endpoint'],
            'numeric ads write' => [['endpoint' => '1234/ads'], 'endpoint'],
            'numeric insights write' => [['endpoint' => '1234/insights'], 'endpoint'],
            'array endpoint' => [['endpoint' => ['act_123/campaigns']], 'endpoint'],
            'insight write' => [['endpoint' => 'act_123/insights'], 'endpoint'],
            'remote host' => [['endpoint' => 'https://evil.test/act_123/campaigns'], 'endpoint'],
            'traversal' => [['endpoint' => 'act_123/../campaigns'], 'endpoint'],
            'query endpoint' => [['endpoint' => 'act_123/campaigns?publish=true'], 'endpoint'],
            'body publish' => [['publish' => true], 'publish'],
            'token payload' => [['payload' => [...$campaign, 'access_token' => 'secret']], 'payload'],
            'missing name' => [['payload' => array_diff_key($campaign, ['name' => true])], 'payload.name'],
            'bad status' => [['payload' => [...$campaign, 'status' => 'anything']], 'payload.status'],
            'zero budget' => [['payload' => [...$campaign, 'daily_budget' => '0']], 'payload.daily_budget'],
            'float budget' => [['payload' => [...$campaign, 'daily_budget' => 1.5]], 'payload.daily_budget'],
            'array categories' => [['payload' => [...$campaign, 'special_ad_categories' => []]], 'payload.special_ad_categories'],
            'object categories' => [['payload' => [...$campaign, 'special_ad_categories' => '{}']], 'payload.special_ad_categories'],
            'array targeting' => [['endpoint' => 'act_123/adsets', 'payload' => [...$set, 'targeting' => []]], 'payload.targeting'],
            'list targeting' => [['endpoint' => 'act_123/adsets', 'payload' => [...$set, 'targeting' => '[]']], 'payload.targeting'],
            'bad promoted page' => [['endpoint' => 'act_123/adsets', 'payload' => [...$set, 'promoted_object' => '{"page_id":"bad"}']], 'payload.promoted_object'],
            'string dynamic boolean' => [['endpoint' => 'act_123/adsets', 'payload' => [...$set, 'is_dynamic_creative' => 'false']], 'payload.is_dynamic_creative'],
            'creative injection' => [['endpoint' => 'act_123/ads', 'payload' => ['name' => 'Synthetic', 'status' => 'ACTIVE', 'adset_id' => '123', 'creative' => '{"creative_id":"456","url":"https://evil.test"}']], 'payload.creative'],
            'remote image' => [['endpoint' => 'act_123/adcreatives', 'payload' => ['name' => 'Synthetic', 'object_story_spec' => '{"page_id":"123","photo_data":{"url":"http://127.0.0.1/image"}}']], 'payload.object_story_spec'],
            'invalid hash' => [['endpoint' => 'act_123/adcreatives', 'payload' => ['name' => 'Synthetic', 'object_story_spec' => '{"page_id":"123","photo_data":{"image_hash":"../bad"}}']], 'payload.object_story_spec'],
            'list cta' => [['endpoint' => 'act_123/adcreatives', 'payload' => ['name' => 'Synthetic', 'object_story_spec' => '{"page_id":"123","link_data":{"image_hash":"abcdef0123456789abcdef0123456789","link":"https://example.com","call_to_action":[]}}']], 'payload.object_story_spec'],
            'boolean parent id' => [['endpoint' => 'act_123/adsets', 'payload' => [...$set, 'campaign_id' => true]], 'payload.campaign_id'],
            'float parent id' => [['endpoint' => 'act_123/adsets', 'payload' => [...$set, 'campaign_id' => 123.0]], 'payload.campaign_id'],
            'boolean page id' => [['endpoint' => 'act_123/adsets', 'payload' => [...$set, 'promoted_object' => '{"page_id":true}']], 'payload.promoted_object'],
            'truncated png' => [['endpoint' => 'act_123/adimages', 'payload' => ['bytes' => base64_encode(substr(base64_decode(self::png()), 0, 33))]], 'payload.bytes'],
            'oversized dimensions' => [['endpoint' => 'act_123/adimages', 'payload' => ['bytes' => base64_encode(substr_replace(base64_decode(self::png()), pack('N', 8193), 16, 4))]], 'payload.bytes'],
            'invalid image' => [['endpoint' => 'act_123/adimages', 'payload' => ['bytes' => base64_encode('not an image')]], 'payload.bytes'],
            'url image' => [['endpoint' => 'act_123/adimages', 'payload' => ['bytes' => 'https://evil.test/image']], 'payload.bytes'],
            'oversized image' => [['endpoint' => 'act_123/adimages', 'payload' => ['bytes' => base64_encode(str_repeat('x', 2097153))]], 'payload.bytes'],
        ];
    }

    #[DataProvider('queries')]
    public function test_query_contract(string $method, string $query, bool $valid): void
    {
        $body = $method === 'POST' ? self::body('campaigns', self::validWrites()['campaign active non-policy budget'][1])
            : [...self::body('campaigns', ['fields' => 'name']), 'method' => 'GET'];

        $response = $this->postJson('/_test/adkit-validation'.$query, $body, ['Idempotency-Key' => 'synthetic-operation']);

        if ($valid) {
            $response->assertOk()->assertExactJson($body);
        } else {
            $response->assertUnprocessable()->assertJsonValidationErrors('query');
        }
        Http::assertNothingSent();
    }

    public static function queries(): array
    {
        return [
            ['POST', '', true], ['POST', '?publish=true', true], ['POST', '?publish=false', true],
            ['POST', '?publish=1', false], ['POST', '?publish=TRUE', false], ['POST', '?publish[]=true', false],
            ['POST', '?publish=true&token=secret', false], ['GET', '?publish=false', false], ['GET', '?x=1', false],
        ];
    }

    #[DataProvider('idempotencyKeys')]
    public function test_write_requires_bounded_idempotency_header(?string $key, bool $valid): void
    {
        $headers = $key === null ? [] : ['Idempotency-Key' => $key];
        $response = $this->postJson('/_test/adkit-validation', self::body('campaigns', self::validWrites()['campaign active non-policy budget'][1]), $headers);

        if ($valid) {
            $response->assertOk();
        } else {
            $response->assertUnprocessable()->assertJsonValidationErrors('Idempotency-Key');
        }
        Http::assertNothingSent();
    }

    public static function idempotencyKeys(): array
    {
        return [[null, false], ['short', false], [str_repeat('x', 201), false], [str_repeat('x', 8), true], [str_repeat('x', 200), true], ['with spaces', true]];
    }

    #[DataProvider('reads')]
    public function test_read_selectors_and_scalar_projection(string $endpoint, array $payload, ?string $account, bool $valid): void
    {
        $body = [...self::body('campaigns', $payload), 'endpoint' => $endpoint, 'method' => 'GET'];
        if ($account !== null) {
            $body['accountId'] = $account;
        }

        $response = $this->postJson('/_test/adkit-validation', $body);

        if ($valid) {
            $response->assertOk()->assertExactJson($body);
        } else {
            $response->assertUnprocessable();
        }
        Http::assertNothingSent();
    }

    public static function reads(): array
    {
        return [
            ['act_123', ['fields' => 'name'], null, true],
            ['act_123/adcreatives', ['fields' => 'id,name,page_id'], null, true],
            ['act_123/adimages', ['fields' => 'hash,name', 'hashes' => '["abcdef0123456789abcdef0123456789"]'], null, true],
            ['1234', ['fields' => 'id,account_id,campaign_id,adset_id,name,status,effective_status,objective,daily_budget,lifetime_budget,page_id'], 'act_123', true],
            ['1234', ['fields' => 'id'], null, false],
            ['1234', ['fields' => 'id'], '123', false],
            ['1234/adsets', ['fields' => 'id,account_id,campaign_id,status'], 'act_123', true],
            ['/1234/ads', ['fields' => 'id,account_id,adset_id,effective_status'], 'act_123', true],
            ['1234/insights', ['fields' => 'account_id,spend', 'date_preset' => 'maximum'], 'act_123', true],
            ['1234/adsets', ['fields' => 'currency'], 'act_123', false],
            ['1234/ads', ['fields' => 'account_status'], 'act_123', false],
            ['1234/insights', ['fields' => 'status', 'date_preset' => 'maximum'], 'act_123', false],
            ['1234/insights', ['fields' => 'spend'], 'act_123', false],
            ['1234/campaigns', ['fields' => 'id'], 'act_123', false],
            ['1234/adcreatives', ['fields' => 'id'], 'act_123', false],
            ['1234/adsets/ads', ['fields' => 'id'], 'act_123', false],
            ['act_123/ads', ['fields' => 'creative{id}'], null, false],
            ['act_123/ads', ['fields' => 'id,id'], null, false],
            ['act_123/ads', ['fields' => 'id', 'hashes' => '[]'], null, false],
            ['act_123/adimages', ['fields' => 'hash', 'hashes' => '["../bad"]'], null, false],
        ];
    }

    #[DataProvider('numericEdges')]
    public function test_numeric_edges_require_target_account_selector(string $edge): void
    {
        $payload = $edge === 'insights' ? ['fields' => 'spend', 'date_preset' => 'maximum'] : ['fields' => 'id'];
        $body = [...self::body('campaigns', $payload), 'endpoint' => '/1234/'.$edge, 'method' => 'GET'];

        $this->postJson('/_test/adkit-validation', $body)->assertUnprocessable()
            ->assertJsonValidationErrors('accountId')
            ->assertJsonPath('errors.accountId.0', 'Numeric object reads require the target accountId.');

        Http::assertNothingSent();
    }

    public static function numericEdges(): array
    {
        return [['adsets'], ['ads'], ['insights']];
    }

    private static function body(string $edge, array $payload): array
    {
        return ['platform' => 'meta', 'endpoint' => 'act_123/'.$edge, 'method' => 'POST', 'description' => 'Synthetic validation fixture', 'payload' => $payload];
    }

    private static function png(): string
    {
        return 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aX1sAAAAASUVORK5CYII=';
    }
}
