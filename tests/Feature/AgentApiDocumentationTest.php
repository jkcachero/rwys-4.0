<?php

namespace Tests\Feature;

use App\Models\AgentApiAudit;
use App\Models\AgentOperation;
use App\Models\Client;
use App\Models\ClientAccount;
use App\Models\User;
use App\Services\AgentTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AgentApiDocumentationTest extends TestCase
{
    use RefreshDatabase;

    private array $document;

    protected function beforeRefreshingDatabase(): void
    {
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->assertEmpty(config('database.connections.sqlite.url'));
    }

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Http::fake([]);
        $this->document = json_decode(file_get_contents(public_path('openapi/agent-api-v1.json')), true, 512, JSON_THROW_ON_ERROR);
    }

    public function test_openapi_matches_every_real_route_and_ability(): void
    {
        $this->assertSame('3.1.0', $this->document['openapi']);
        $this->assertSame(['type' => 'http', 'scheme' => 'bearer'], $this->document['components']['securitySchemes']['rwysToken']);
        $this->assertSame([['rwysToken' => []]], $this->document['security']);
        $actual = [];
        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/v1/')) {
                continue;
            }
            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $actual[strtolower($method).' /'.$route->uri()] = $route->defaults['agent_ability'];
            }
        }
        $documented = [];
        $ids = [];
        foreach ($this->document['paths'] as $path => $methods) {
            foreach ($methods as $method => $operation) {
                $documented[$method.' '.$path] = $operation['x-required-ability'];
                $this->assertNotEmpty($operation['description']);
                $this->assertNotEmpty($operation['operationId']);
                $ids[] = $operation['operationId'];
                $this->assertArrayNotHasKey('security', $operation);
                preg_match_all('/\{([^}]+)\}/', $path, $matches);
                $parameters = array_map(fn (array $parameter): array => $this->resolve($parameter), $operation['parameters'] ?? []);
                $this->assertEqualsCanonicalizing($matches[1], array_column(array_filter($parameters, fn (array $parameter): bool => $parameter['in'] === 'path'), 'name'));
                foreach ($operation['responses'] as $response) {
                    $response = $this->resolve($response);
                    $this->assertArrayHasKey('X-Request-ID', $response['headers']);
                    $this->assertArrayHasKey('Cache-Control', $response['headers']);
                }
            }
        }
        ksort($actual);
        ksort($documented);
        $this->assertCount(11, $actual);
        $this->assertCount(10, $this->document['paths']);
        $this->assertSame($actual, $documented);
        $this->assertCount(11, array_unique($ids));
        $schedule = Route::getRoutes()->getByName('agent-api.posts.schedule');
        $this->assertSame('zernio.posts.schedule', $schedule->defaults['agent_ability']);
        $this->assertTrue($schedule->defaults['agent_write']);
        $walk = function (array $node) use (&$walk): void {
            if (isset($node['$ref'])) {
                $this->assertNotEmpty($this->resolve($node));
            }
            foreach ($node as $value) {
                if (is_array($value)) {
                    $walk($value);
                }
            }
        };
        $walk($this->document);
    }

    public function test_inputs_are_closed_and_pagination_contracts_are_distinct(): void
    {
        foreach ($this->document['paths'] as $path => $methods) {
            foreach ($methods as $method => $operation) {
                $parameters = array_map(fn (array $parameter): array => $this->resolve($parameter), $operation['parameters'] ?? []);
                $queries = array_values(array_filter($parameters, fn (array $parameter): bool => $parameter['in'] === 'query'));
                $expected = match ($path) {
                    '/api/v1/operations' => ['limit', 'after'],
                    '/api/v1/zernio/accounts', '/api/v1/zernio/accounts/{account}/posts' => $method === 'get' ? ['page', 'limit'] : [],
                    default => [],
                };
                $this->assertSame($expected, array_column($queries, 'name'));
                $this->assertTrue($operation['x-reject-unknown-inputs']);
                if ($method === 'get') {
                    $this->assertArrayNotHasKey('requestBody', $operation);
                }
            }
        }
        $schemas = $this->document['components']['schemas'];
        $this->assertSame(['limit', 'has_more', 'next_cursor', 'complete'], array_keys($schemas['CursorPagination']['properties']));
        $this->assertSame(1000, $this->document['components']['parameters']['Page']['schema']['maximum']);
        $this->assertSame(100, $this->document['components']['parameters']['Limit']['schema']['maximum']);
        $this->assertSame(['content'], $schemas['PublishInput']['required']);
        $this->assertFalse($schemas['PublishInput']['additionalProperties']);
        $this->assertSame(['content'], array_keys($schemas['PublishInput']['properties']));
        $schedule = $this->document['paths']['/api/v1/zernio/accounts/{account}/scheduled-posts']['post'];
        $this->assertSame(['multipart/form-data'], array_keys($schedule['requestBody']['content']));
        $input = $this->resolve($schedule['requestBody']['content']['multipart/form-data']['schema']);
        $this->assertFalse($input['additionalProperties']);
        $this->assertSame(['content', 'scheduled_for', 'image'], $input['required']);
        $this->assertSame($input['required'], array_keys($input['properties']));
        $this->assertSame(2200, $input['properties']['content']['maxLength']);
        $this->assertSame('date-time', $input['properties']['scheduled_for']['format']);
        $this->assertSame('binary', $input['properties']['image']['format']);
        $this->assertSame(2097152, $input['properties']['image']['maxLength']);
        foreach ([200, 202, 401, 403, 404, 409, 413, 422, 429, 502, 503] as $status) {
            $this->assertArrayHasKey($status, $schedule['responses']);
        }
        $this->assertSame(['id', 'provider', 'environment', 'platform', 'status', 'write_enabled'], array_keys($schemas['Account']['properties']));
        $this->assertSame(['id', 'client_id', 'client_account_id', 'capability', 'state', 'provider_post_id', 'created_at', 'updated_at', 'last_reconciled_at'], array_keys($schemas['Operation']['properties']));
        $this->assertEqualsCanonicalizing([...AgentOperation::TERMINAL_STATES, ...AgentOperation::RECONCILABLE_STATES, 'pending'], $schemas['Operation']['properties']['state']['enum']);
        $this->assertSame(AgentTokenService::ABILITIES, $schemas['Capabilities']['properties']['abilities']['items']['enum']);
    }

    public function test_documented_examples_and_success_responses_match_schemas(): void
    {
        foreach ($this->document['components']['schemas'] as $schema) {
            foreach ($schema['examples'] ?? [] as $example) {
                $this->assertSchema($example, $schema);
            }
        }
        $owner = User::factory()->create();
        $client = Client::factory()->create(['owner_id' => $owner->id]);
        $account = ClientAccount::factory()->create(['client_id' => $client->id]);
        AgentOperation::factory()->create(['client_account_id' => $account->id]);
        $token = app(AgentTokenService::class)->issue($owner, $client, 'Documentation', AgentTokenService::READ_ABILITIES);
        $this->withToken($token->plainTextToken);
        config(['agent-api.zernio.enabled' => true, 'agent-api.zernio.api_key' => 'fictional-test-secret']);
        foreach (['/api/v1/context', '/api/v1/capabilities', '/api/v1/zernio/accounts', '/api/v1/operations'] as $path) {
            $response = $this->getJson($path)->assertOk();
            $schema = $this->document['paths'][$path]['get']['responses']['200']['content']['application/json']['schema'];
            $this->assertSchema($response->json(), $schema);
            $response->assertHeader('X-Request-ID', $response->json('request_id'));
            $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        }
        Http::assertNothingSent();
    }

    #[DataProvider('errors')]
    public function test_http_errors_match_documented_envelope(int $status, string $code): void
    {
        $owner = User::factory()->create();
        $client = Client::factory()->create(['owner_id' => $owner->id]);
        $account = ClientAccount::factory()->create(['client_id' => $client->id, 'publishing_enabled' => true]);
        config(['agent-api.zernio.enabled' => true, 'agent-api.zernio.publishing_enabled' => true, 'agent-api.zernio.api_key' => 'fictional-test-secret']);
        $token = app(AgentTokenService::class)->issue($owner, $client, 'Documentation', $status === 403 ? ['context.read'] : [...AgentTokenService::READ_ABILITIES, 'zernio.posts.publish']);
        $this->withToken($status === 401 ? 'invalid' : $token->plainTextToken);
        $path = '/api/v1/zernio/accounts';
        if ($status === 404) {
            $path .= '/0/status';
        } elseif ($status === 402) {
            Http::fake(['https://zernio.com/api/v1/analytics*' => Http::response(['error' => 'Private billing detail', 'code' => 'analytics_access_required'], 402)]);
            $path .= '/'.$account->id.'/posts/cccccccccccccccccccccccc/analytics';
        } elseif ($status === 422) {
            $path .= '?limit=101';
        } elseif ($status === 429) {
            config(['agent-api.reads_per_minute' => 1]);
            AgentApiAudit::create(['request_id' => (string) Str::uuid(), 'client_id' => $client->id, 'token_id' => $token->accessToken->id, 'capability' => 'context.read', 'is_write' => false, 'started_at' => now(), 'finished_at' => now(), 'status' => 200]);
        } elseif ($status === 502) {
            Http::fake(['*' => Http::response(['invalid' => true])]);
            $path .= '/'.$account->id.'/status';
        } elseif ($status === 503) {
            config(['agent-api.zernio.enabled' => false]);
        }
        if ($status === 409) {
            $key = 'documentation-conflict';
            AgentOperation::factory()->create(['client_account_id' => $account->id, 'key_hash' => hash('sha256', $key)]);
            $response = $this->withHeader('Idempotency-Key', $key)->postJson('/api/v1/zernio/accounts/'.$account->id.'/posts', ['content' => 'A different immutable intent']);
        } else {
            $response = $this->getJson($path);
        }
        $response->assertStatus($status)->assertJsonPath('error.code', $code)
            ->assertJsonPath('error.message', str_replace('_', ' ', ucfirst($code)).'.')
            ->assertHeader('X-Request-ID', $response->json('request_id'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $documented = $this->document['components']['responses']['Error'.$status];
        $this->assertSchema($response->json(), $documented['content']['application/json']['schema']);
        $this->assertSame($code, $documented['content']['application/json']['example']['error']['code']);
        $this->assertSchema($documented['content']['application/json']['example'], $documented['content']['application/json']['schema']);
        if ($status === 429) {
            $response->assertHeader('Retry-After', '60');
        }
        if (in_array($status, [402, 502], true)) {
            Http::assertSentCount(1);
        } else {
            Http::assertNothingSent();
        }
    }

    public static function errors(): array
    {
        return [[401, 'unauthenticated'], [402, 'analytics_access_required'], [403, 'forbidden'], [404, 'not_found'], [409, 'idempotency_conflict'], [422, 'invalid_input'], [429, 'rate_limited'], [502, 'provider_unavailable'], [503, 'service_unavailable']];
    }

    #[DataProvider('providerResponses')]
    public function test_provider_backed_responses_match_the_public_contract(string $action, int $status): void
    {
        $owner = User::factory()->create();
        $client = Client::factory()->create(['owner_id' => $owner->id]);
        $account = ClientAccount::factory()->create(['client_id' => $client->id, 'profile_id' => 'aaaaaaaaaaaaaaaaaaaaaaaa', 'provider_account_id' => 'bbbbbbbbbbbbbbbbbbbbbbbb', 'platform' => 'linkedin', 'publishing_enabled' => true]);
        config(['agent-api.zernio.enabled' => true, 'agent-api.zernio.publishing_enabled' => true, 'agent-api.zernio.api_key' => 'fictional-test-secret']);
        $token = app(AgentTokenService::class)->issue($owner, $client, 'Documentation', [...AgentTokenService::READ_ABILITIES, 'zernio.posts.publish']);
        $this->withToken($token->plainTextToken);
        $post = ['_id' => 'cccccccccccccccccccccccc', 'profileId' => $account->profile_id, 'content' => 'Fictional example text', 'status' => 'published', 'platforms' => [['accountId' => $account->provider_account_id, 'platform' => 'linkedin', 'status' => 'published']]];
        Http::fake(function (Request $request) use ($account, $post, $status) {
            $pagination = ['page' => 1, 'limit' => 25, 'total' => 1, 'pages' => 1];
            if (str_contains($request->url(), '/accounts')) {
                return Http::response(['accounts' => [['_id' => $account->provider_account_id, 'profileId' => $account->profile_id, 'platform' => 'linkedin', 'isActive' => true]], 'hasAnalyticsAccess' => false, 'pagination' => $pagination]);
            }
            if ($request->method() === 'POST') {
                return $status === 202 ? Http::response(['postId' => $post['_id']], 207) : Http::response(['post' => $post], 201);
            }
            if (str_contains($request->url(), '/posts/')) {
                return Http::response(['post' => $post]);
            }

            return Http::response(['posts' => [$post], 'pagination' => $pagination]);
        });
        $path = '/api/v1/zernio/accounts/{account}/'.($action === 'status' ? 'status' : 'posts');
        if ($action === 'detail') {
            $path .= '/{post}';
        }
        $method = $action === 'publish' ? 'post' : 'get';
        $url = str_replace(['{account}', '{post}'], [(string) $account->id, $post['_id']], $path);
        $response = $method === 'post'
            ? $this->withHeader('Idempotency-Key', 'documentation-example')->postJson($url, ['content' => $post['content']])
            : $this->getJson($url);
        $response->assertStatus($status)->assertHeader('X-Request-ID', $response->json('request_id'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertSchema($response->json(), $this->document['paths'][$path][$method]['responses'][(string) $status]['content']['application/json']['schema']);
        Http::assertSentCount($action === 'publish' ? 2 : 1);
        if ($action === 'publish') {
            $response->assertJsonPath('data.state', $status === 202 ? 'unknown' : 'succeeded');
            $this->assertSame($status === 202 ? null : $post['_id'], $response->json('data.provider_post_id'));
            $poll = $this->getJson('/api/v1/operations/'.$response->json('data.id'))->assertOk();
            $this->assertSchema($poll->json(), $this->document['paths']['/api/v1/operations/{operation}']['get']['responses']['200']['content']['application/json']['schema']);
            $poll->assertHeader('X-Request-ID', $poll->json('request_id'));
            Http::assertSentCount(2);
        }
    }

    public static function providerResponses(): array
    {
        return [['status', 200], ['list', 200], ['detail', 200], ['publish', 200], ['publish', 202]];
    }

    public function test_scheduled_multipart_response_replay_and_read_only_denial_match_contract(): void
    {
        $this->freezeTime();
        config(['agent-api.zernio.enabled' => true, 'agent-api.zernio.publishing_enabled' => true, 'agent-api.zernio.instagram_scheduling_enabled' => true, 'agent-api.zernio.api_key' => 'fictional-test-secret']);
        $owner = User::factory()->create();
        $client = Client::factory()->create(['owner_id' => $owner->id]);
        $account = ClientAccount::factory()->create(['client_id' => $client->id, 'platform' => 'instagram', 'publishing_enabled' => true]);
        $readToken = app(AgentTokenService::class)->issue($owner, $client, 'Original read token', AgentTokenService::READ_ABILITIES, [$account->id]);
        $writeToken = app(AgentTokenService::class)->issue($owner, $client, 'Temporary scheduling token', ['zernio.posts.schedule', 'operations.read'], [$account->id]);
        $image = UploadedFile::fake()->createWithContent('generic.jpg', base64_decode('/9j/4AAQSkZJRgABAQEAYABgAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////2wBDAf//////////////////////////////////////////////////////////////////////////////////////wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAX/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIQAxAAAAF//8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABBQJ//8QAFBEBAAAAAAAAAAAAAAAAAAAAAP/aAAgBAwEBPwF//8QAFBEBAAAAAAAAAAAAAAAAAAAAAP/aAAgBAgEBPwF//8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQAGPwJ//8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPyF//9oADAMBAAIAAwAAABD/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oACAEDAQE/EB//xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oACAECAQE/EB//xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oACAEBAAE/EB//2Q=='));
        $scheduledFor = now()->addMinutes(5)->utc()->format('Y-m-d\TH:i:s\Z');
        $input = ['content' => 'Test', 'scheduled_for' => $scheduledFor, 'image' => $image];
        $path = '/api/v1/zernio/accounts/{account}/scheduled-posts';
        $url = str_replace('{account}', (string) $account->id, $path);
        $headers = ['Accept' => 'application/json', 'Content-Type' => 'multipart/form-data', 'Idempotency-Key' => 'documentation-schedule'];
        $denied = $this->withToken($readToken->plainTextToken)->post($url, $input, $headers)->assertForbidden()->assertJsonPath('error.code', 'forbidden');
        $this->assertSchema($denied->json(), $this->document['components']['responses']['Error403']['content']['application/json']['schema']);
        Http::assertNothingSent();
        $this->assertSame(0, AgentOperation::count());
        Http::fake(function (Request $request) use ($account, $scheduledFor) {
            if ($request->method() === 'GET') {
                return Http::response(['accounts' => [['_id' => $account->provider_account_id, 'profileId' => $account->profile_id, 'platform' => 'instagram', 'isActive' => true]], 'hasAnalyticsAccess' => false, 'pagination' => ['page' => 1, 'limit' => 25, 'total' => 1, 'pages' => 1]]);
            }
            if (str_ends_with($request->url(), '/media/upload-direct')) {
                $this->assertTrue($request->isMultipart());
                $this->assertStringContainsString(AgentOperation::sole()->id.'.jpg', $request->body());

                return Http::response(['url' => 'https://cdn.zernio.com/generic.jpg']);
            }
            $this->assertFalse($request['publishNow']);
            $this->assertSame('UTC', $request['timezone']);

            return Http::response(['post' => ['_id' => str_repeat('c', 24), 'profileId' => $account->profile_id,
                'content' => 'Test', 'status' => 'scheduled', 'scheduledFor' => $scheduledFor, 'timezone' => 'UTC',
                'mediaItems' => [['type' => 'image', 'url' => 'https://cdn.zernio.com/generic.jpg']],
                'platforms' => [['accountId' => $account->provider_account_id, 'platform' => 'instagram', 'status' => 'pending']]]], 201);
        });
        $response = $this->withToken($writeToken->plainTextToken)->post($url, $input, $headers)->assertStatus(202)
            ->assertJsonPath('data.capability', 'zernio.posts.schedule')->assertJsonPath('data.state', 'provider_pending');
        $id = $response->json('data.id');
        $this->assertSchema($response->json(), $this->document['paths'][$path]['post']['responses']['202']['content']['application/json']['schema']);
        $response->assertHeader('X-Request-ID', $response->json('request_id'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertStringNotContainsString('https://cdn', $response->getContent());
        $this->assertArrayNotHasKey('intent', $response->json('data'));
        $this->assertArrayNotHasKey('result', $response->json('data'));
        $poll = $this->getJson('/api/v1/operations/'.$id)->assertOk()->assertJsonPath('data.state', 'provider_pending');
        $this->assertSchema($poll->json(), $this->document['paths']['/api/v1/operations/{operation}']['get']['responses']['200']['content']['application/json']['schema']);
        $this->travel(10)->minutes();
        $this->post($url, $input, $headers)->assertStatus(202)->assertJsonPath('data.id', $id);
        AgentOperation::whereKey($id)->update(['state' => 'failed']);
        $terminal = $this->post($url, $input, $headers)->assertOk()->assertJsonPath('data.state', 'failed');
        $this->assertSchema($terminal->json(), $this->document['paths'][$path]['post']['responses']['200']['content']['application/json']['schema']);
        Http::assertSentCount(3);
        $this->assertSame(AgentTokenService::READ_ABILITIES, $readToken->accessToken->fresh()->abilities);
    }

    #[DataProvider('analyticsResponses')]
    public function test_analytics_responses_match_documented_projection(int $status, string $postId, ?array $metrics, string $syncStatus): void
    {
        $owner = User::factory()->create();
        $client = Client::factory()->create(['owner_id' => $owner->id]);
        $account = ClientAccount::factory()->create(['client_id' => $client->id, 'profile_id' => 'aaaaaaaaaaaaaaaaaaaaaaaa', 'provider_account_id' => 'bbbbbbbbbbbbbbbbbbbbbbbb', 'platform' => 'linkedin']);
        config(['agent-api.zernio.enabled' => true, 'agent-api.zernio.api_key' => 'fictional-test-secret']);
        $token = app(AgentTokenService::class)->issue($owner, $client, 'Analytics documentation', ['zernio.analytics.read']);
        $postStatus = $status === 424 ? 'failed' : 'published';
        Http::fake(['https://zernio.com/api/v1/analytics*' => Http::response([
            'postId' => $postId, 'latePostId' => 'cccccccccccccccccccccccc', 'profileId' => $account->profile_id,
            'status' => $postStatus, 'syncStatus' => $syncStatus, 'analytics' => $metrics === null ? null : [...$metrics, 'privateMetric' => 'Not public'],
            'platformAnalytics' => [[
                'accountId' => $account->provider_account_id, 'platform' => 'linkedin', 'status' => $postStatus,
                'syncStatus' => $syncStatus, 'analytics' => $metrics === null ? null : [...$metrics, 'privateMetric' => 'Not public'], 'errorCode' => null, 'isOwner' => null,
                'accountUsername' => 'Private account name', 'errorMessage' => 'Private upstream detail',
            ]],
            'content' => 'Private post text',
        ], $status)]);
        $path = '/api/v1/zernio/accounts/{account}/posts/{post}/analytics';

        $response = $this->withToken($token->plainTextToken)->getJson(str_replace(['{account}', '{post}'], [(string) $account->id, $postId], $path));

        $response->assertStatus($status)->assertExactJson([
            'data' => [
                'post_id' => $postId, 'zernio_post_id' => 'cccccccccccccccccccccccc', 'status' => $postStatus,
                'sync_status' => $syncStatus, 'analytics' => $metrics,
                'platform_analytics' => [[
                    'account_id' => $account->provider_account_id, 'platform' => 'linkedin', 'status' => $postStatus,
                    'sync_status' => $syncStatus, 'analytics' => $metrics, 'error_code' => null, 'is_owner' => null,
                ]],
                'upstream_status' => $status, 'source' => 'zernio',
            ],
            'request_id' => $response->json('request_id'),
        ])->assertHeader('X-Request-ID', $response->json('request_id'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertSchema($response->json(), $this->document['paths'][$path]['get']['responses'][(string) $status]['content']['application/json']['schema']);
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && parse_url($request->url(), PHP_URL_PATH) === '/api/v1/analytics'
            && $request['postId'] === $postId && $request['profileId'] === $account->profile_id
            && $request['accountId'] === $account->provider_account_id);
    }

    public static function analyticsResponses(): array
    {
        return [
            'measured zero' => [200, 'cccccccccccccccccccccccc', [
                'likes' => 0, 'engagementRate' => 1.25, 'completionRate' => 0,
                'follows' => null, 'videoDurationSeconds' => null,
                'impressionSources' => ['forYou' => 0.75], 'audienceTypes' => ['follower' => 0.5],
                'audienceCountries' => ['US' => 0.8], 'retentionCurve' => ['4' => 0.5],
                'lastUpdated' => '2026-10-22T12:00:00Z',
            ], 'synced'],
            'ExternalPost record ID without likes' => [200, 'eeeeeeeeeeeeeeeeeeeeeeee', ['views' => 7], 'synced'],
            'sync pending' => [202, 'cccccccccccccccccccccccc', null, 'pending'],
            'all platforms failed' => [424, 'cccccccccccccccccccccccc', null, 'unavailable'],
        ];
    }

    public function test_guides_reference_the_contract_and_separate_operator_actions_from_isolated_tests(): void
    {
        foreach (['external-agent-api-quickstart.md', 'external-agent-api-operator-walkthrough.md'] as $file) {
            $guide = file_get_contents(base_path('.artifacts/plans/'.$file));
            $this->assertStringContainsString('php artisan test --compact tests/Feature/AgentApiDocumentationTest.php', $guide);
            foreach (['<APP_ORIGIN>', 'Settings', 'Agent access', 'Account assignments', 'My API tokens', '/settings/agent-access', 'Create token', 'Rotate', 'Revoke', 'read -rsp', 'curl -q --config -', 'unset RWYS_TOKEN', '75', '120', '24 lowercase hexadecimal', 'not live IDs'] as $required) {
                $this->assertStringContainsString($required, $guide);
            }
            $this->assertStringNotContainsString('curl -H', $guide);
            foreach (['zernio.analytics.read', '/posts/<POST_ID>/analytics', 'Postman Vault', 'analytics_access_required', 'platform_analytics[0].analytics.likes', '1.331.1', 'https://zernio.com/openapi.yaml', 'https://docs.zernio.com'] as $required) {
                $this->assertStringContainsString($required, $guide);
            }
            foreach (['zernio.posts.schedule', 'scheduled-posts', 'AGENT_ZERNIO_INSTAGRAM_SCHEDULING_ENABLED', 'Allow scheduled Instagram images (write access)', '2 MiB', 'provider_pending', 'five', 'Test', 'original read token', 'https://docs.zernio.com/posts/create-post.mdx', 'https://docs.zernio.com/platforms/instagram.mdx'] as $required) {
                $this->assertStringContainsString($required, $guide);
            }
        }
        $walkthrough = file_get_contents(base_path('.artifacts/plans/external-agent-api-operator-walkthrough.md'));
        $this->assertLessThan(strpos($walkthrough, 'php artisan migrate --no-interaction'), strpos($walkthrough, 'php artisan migrate --pretend --no-interaction'));
        $this->assertStringContainsString('Separate approval required', $walkthrough);
    }

    private function resolve(array $node): array
    {
        while (isset($node['$ref'])) {
            $this->assertStringStartsWith('#/', $node['$ref']);
            $target = $this->document;
            foreach (explode('/', substr($node['$ref'], 2)) as $segment) {
                $segment = str_replace(['~1', '~0'], ['/', '~'], $segment);
                $this->assertArrayHasKey($segment, $target);
                $target = $target[$segment];
            }
            $node = $target;
        }

        return $node;
    }

    /** Validates the schema keywords used by this contract, not a general OpenAPI validator. */
    private function assertSchema(mixed $value, array $schema): void
    {
        $schema = $this->resolve($schema);
        $type = match (true) {
            $value === null => 'null', is_bool($value) => 'boolean', is_int($value) => 'integer',
            is_string($value) => 'string', is_float($value) => 'number',
            is_array($value) => array_is_list($value) ? 'array' : 'object',
            default => 'unsupported',
        };
        $this->assertTrue(in_array($type, (array) $schema['type'], true) || ($type === 'integer' && in_array('number', (array) $schema['type'], true)));
        if (isset($schema['enum'])) {
            $this->assertTrue(in_array($value, $schema['enum'], true));
        }
        if (isset($schema['const'])) {
            $this->assertSame($schema['const'], $value);
        }
        if ($type === 'object') {
            foreach ($schema['required'] ?? [] as $field) {
                $this->assertArrayHasKey($field, $value);
            }
            foreach ($value as $field => $item) {
                if (($schema['additionalProperties'] ?? true) === false) {
                    $this->assertArrayHasKey($field, $schema['properties']);
                }
                if (isset($schema['properties'][$field])) {
                    $this->assertSchema($item, $schema['properties'][$field]);
                } elseif (is_array($schema['additionalProperties'] ?? null)) {
                    $this->assertSchema($item, $schema['additionalProperties']);
                }
            }
        } elseif ($type === 'array') {
            foreach ($value as $item) {
                $this->assertSchema($item, $schema['items']);
            }
        } elseif ($type === 'string') {
            if (isset($schema['pattern'])) {
                $this->assertMatchesRegularExpression('~'.$schema['pattern'].'~u', $value);
            }
            if (isset($schema['minLength'])) {
                $this->assertGreaterThanOrEqual($schema['minLength'], mb_strlen($value));
            }
            if (isset($schema['maxLength'])) {
                $this->assertLessThanOrEqual($schema['maxLength'], mb_strlen($value));
            }
            if (($schema['format'] ?? null) === 'uuid') {
                $this->assertTrue(Str::isUuid($value));
            }
            if (($schema['format'] ?? null) === 'date-time') {
                $this->assertNotFalse(strtotime($value));
            }
        } elseif ($type === 'integer') {
            $this->assertGreaterThanOrEqual($schema['minimum'] ?? PHP_INT_MIN, $value);
            $this->assertLessThanOrEqual($schema['maximum'] ?? PHP_INT_MAX, $value);
        }
    }
}
