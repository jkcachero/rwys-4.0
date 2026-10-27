<?php

namespace Tests\Feature;

use App\Enums\AgentAbility;
use App\Http\Requests\Api\V1\ForwardAdKitRequest;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

class AgentOpenApiContractTest extends TestCase
{
    public function test_openapi_contract_matches_implemented_agent_routes_and_abilities(): void
    {
        $contract = Yaml::parseFile(base_path('openapi/agent-api-v1.yaml'));

        $documentedOperations = collect($contract['paths'])
            ->flatMap(function (array $pathOperations, string $path): array {
                return collect($pathOperations)
                    ->only(['get', 'post', 'put', 'patch', 'delete'])
                    ->mapWithKeys(fn (array $operation, string $method): array => [
                        strtoupper($method).' /api/v1'.$path => [
                            'ability' => $operation['x-agent-ability'],
                            'operation_id' => $operation['operationId'],
                        ],
                    ])
                    ->all();
            })
            ->sortKeys();

        $implementedOperations = collect(app('router')->getRoutes()->getRoutes())
            ->filter(fn (Route $route): bool => str_starts_with($route->uri(), 'api/v1/'))
            ->mapWithKeys(function (Route $route): array {
                $abilityMiddleware = collect($route->gatherMiddleware())
                    ->first(fn (string $middleware): bool => str_starts_with($middleware, 'agent.ability:'));

                $method = collect($route->methods())->first(fn (string $method): bool => $method !== 'HEAD');

                return [$method.' /'.$route->uri() => [
                    'ability' => $abilityMiddleware === null ? null : str($abilityMiddleware)->after('agent.ability:')->toString(),
                    'operation_id' => null,
                ]];
            })
            ->sortKeys();

        $this->assertSame(
            $implementedOperations->keys()->all(),
            $documentedOperations->keys()->all(),
        );

        $implementedOperations->each(function (array $implemented, string $route) use ($documentedOperations): void {
            $this->assertSame($implemented['ability'], $documentedOperations[$route]['ability']);
        });

        $operationIds = $documentedOperations->pluck('operation_id');

        $this->assertNotContains(null, $operationIds);
        $this->assertCount($operationIds->count(), $operationIds->unique());
        $this->assertSame('3.1.0', $contract['openapi']);
        $this->assertSame('/api/v1', $contract['servers'][0]['url']);
        $this->assertSame(
            AgentAbility::clientTokenDefaults(),
            $contract['components']['schemas']['Ability']['enum'],
        );
    }

    public function test_openapi_success_and_error_schemas_are_strict(): void
    {
        $schemas = Yaml::parseFile(base_path('openapi/agent-api-v1.yaml'))['components']['schemas'];

        foreach ([
            'ContextResponse',
            'CapabilitiesResponse',
            'AccountListResponse',
            'AccountStatusResponse',
            'PostListResponse',
            'PostResponse',
            'AnalyticsResponse',
            'OperationListResponse',
            'OperationResponse',
            'ErrorResponse',
            'ValidationErrorResponse',
            'AdKitReadResponse',
            'AdKitErrorResponse',
            'AdKitReceiptPersistenceErrorResponse',
        ] as $schema) {
            $this->assertFalse($schemas[$schema]['additionalProperties'], $schema.' must reject unknown fields.');
            $this->assertContains('request_id', $schemas[$schema]['required']);
        }
    }

    public function test_adkit_contract_exposes_only_the_bearer_authenticated_provider_forward_operation(): void
    {
        $contract = Yaml::parseFile(base_path('openapi/agent-api-v1.yaml'));
        $this->assertSame([], array_values(array_filter(array_keys($contract['paths']), fn (string $path): bool => str_starts_with($path, '/adkit'))));
        $this->assertArrayHasKey('/manage/platform-api-requests', $contract['paths']);
        $this->assertSame(['post'], array_keys($contract['paths']['/manage/platform-api-requests']));
        $operation = $contract['paths']['/manage/platform-api-requests']['post'];

        $this->assertSame('forwardAdKitRequest', $operation['operationId']);
        $this->assertNull($operation['x-agent-ability']);
        $this->assertSame('client-assigned-account', $operation['x-agent-authorization']);
        $this->assertSame([['bearerAuth' => []]], $contract['security']);
        $this->assertSame($contract['security'], $operation['security'] ?? $contract['security']);
        $this->assertSame(['bearerAuth'], array_keys($contract['components']['securitySchemes']));
        $this->assertSame('bearer', $contract['components']['securitySchemes']['bearerAuth']['scheme']);
        $this->assertSame([
            'context.read', 'zernio.accounts.read', 'zernio.posts.read', 'zernio.analytics.read',
            'operations.read', 'zernio.posts.publish',
        ], $contract['components']['schemas']['Ability']['enum']);
        $this->assertSame(['#/components/parameters/AdKitPublish', '#/components/parameters/AdKitIdempotencyKey'], array_column($operation['parameters'], '$ref'));
        $this->assertSame('#/components/schemas/AdKitForwardRequest', $operation['requestBody']['content']['application/json']['schema']['$ref']);
        $this->assertTrue($operation['requestBody']['required']);
        $this->assertSame('#/components/schemas/AdKitForwardResponse', $operation['responses'][200]['content']['application/json']['schema']['$ref']);
        $this->assertSame('#/components/responses/Unprocessable', $operation['responses'][422]['$ref']);
        $route = app('router')->getRoutes()->match(Request::create('/api/v1/manage/platform-api-requests', 'POST'));
        $this->assertSame('api.v1.adkit.forward', $route->getName());
        $this->assertContains('agent.client', $route->gatherMiddleware());
        $this->assertContains('agent.admit:provider', $route->gatherMiddleware());
        $this->assertSame([], array_values(array_filter($route->gatherMiddleware(), fn (string $middleware): bool => str_starts_with($middleware, 'agent.ability:'))));

        foreach (['CampaignId', 'AdKitCursor', 'AdKitSince', 'AdKitUntil'] as $parameter) {
            $this->assertArrayNotHasKey($parameter, $contract['components']['parameters']);
        }
    }

    public function test_adkit_request_documents_account_scoped_reads_and_endpoint_specific_creates(): void
    {
        $schemas = Yaml::parseFile(base_path('openapi/agent-api-v1.yaml'))['components']['schemas'];
        $this->assertArrayHasKey('AdKitForwardRequest', $schemas);
        $request = $schemas['AdKitForwardRequest'];

        $this->assertFalse($request['additionalProperties']);
        $this->assertSame(['platform', 'endpoint', 'method', 'description', 'payload'], $request['required']);
        $this->assertSame(['platform', 'accountId', 'endpoint', 'method', 'description', 'payload'], array_keys($request['properties']));
        $this->assertSame('meta', $request['properties']['platform']['const']);
        $this->assertSame(['GET', 'POST'], $request['properties']['method']['enum']);
        $this->assertSame('^/?(?:act_[0-9]+(?:/(?:campaigns|insights|adsets|ads|adcreatives|adimages))?|[0-9]+(?:/(?:adsets|ads|insights))?)$', $request['properties']['endpoint']['pattern']);
        $this->assertSame('^act_[0-9]+$', $request['properties']['accountId']['pattern']);
        $this->assertStringContainsString('stored', $request['properties']['accountId']['description']);

        $payload = $schemas['AdKitReadPayload'];
        $this->assertSame('#/components/schemas/AdKitReadPayload', $request['allOf'][0]['then']['properties']['payload']['$ref']);
        $this->assertSame(['accountId'], $request['allOf'][1]['then']['required']);
        $this->assertSame('^/?[0-9]+(?:/(?:adsets|ads|insights))?$', $request['allOf'][1]['if']['properties']['endpoint']['pattern']);
        $this->assertSame('^/?act_[0-9]+/(?:campaigns|adsets|ads|adcreatives|adimages)$', $request['allOf'][2]['then']['properties']['endpoint']['pattern']);
        foreach (['campaigns' => 'Campaign', 'adsets' => 'AdSet', 'ads' => 'Ad', 'adcreatives' => 'Creative', 'adimages' => 'Image'] as $edge => $name) {
            $branch = $request['allOf'][2]['then']['oneOf'][array_search($edge, ['campaigns', 'adsets', 'ads', 'adcreatives', 'adimages'], true)];
            $this->assertSame('^/?act_[0-9]+/'.$edge.'$', $branch['properties']['endpoint']['pattern']);
            $this->assertSame('#/components/schemas/AdKit'.$name.'Payload', $branch['properties']['payload']['$ref']);
            $this->assertFalse($schemas['AdKit'.$name.'Payload']['additionalProperties']);
        }
        $this->assertFalse($payload['additionalProperties']);
        $this->assertSame(['fields', 'limit', 'after', 'time_range', 'date_preset', 'level', 'hashes'], array_keys($payload['properties']));
        $this->assertSame(1, $payload['properties']['limit']['minimum']);
        $this->assertSame(100, $payload['properties']['limit']['maximum']);
        $this->assertSame(4096, $payload['properties']['after']['maxLength']);
        $this->assertSame('string', $payload['properties']['time_range']['type']);
        $this->assertContains('maximum', $payload['properties']['date_preset']['enum']);
        $this->assertSame(['account', 'campaign', 'adset', 'ad'], $payload['properties']['level']['enum']);
        $this->assertSame(['required' => ['time_range', 'date_preset']], $payload['not']);
    }

    public function test_adkit_response_preserves_provider_payload_and_distinguishes_provider_evidence_from_broker_acceptance(): void
    {
        $contract = Yaml::parseFile(base_path('openapi/agent-api-v1.yaml'));
        $schemas = $contract['components']['schemas'];
        $this->assertArrayHasKey('AdKitForwardResponse', $schemas);
        $this->assertSame([
            ['$ref' => '#/components/schemas/AdKitReadResponse'],
            ['$ref' => '#/components/schemas/AdKitPublishedResponse'],
            ['$ref' => '#/components/schemas/AdKitDraftResponse'],
        ], $schemas['AdKitForwardResponse']['oneOf']);
        $response = $schemas['AdKitReadResponse'];

        $this->assertSame(['platform', 'status', 'response', 'results', 'request_id'], $response['required']);
        $this->assertSame($response['required'], array_keys($response['properties']));
        $this->assertSame('meta', $response['properties']['platform']['const']);
        $this->assertSame('executed', $response['properties']['status']['const']);
        $this->assertSame(['type' => 'object', 'additionalProperties' => true], $response['properties']['response']);
        $this->assertSame(['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => true]], $response['properties']['results']);
        $this->assertSame('published', $schemas['AdKitPublishedResponse']['properties']['status']['const']);
        $this->assertSame(['platform', 'status', 'results', 'request_id'], $schemas['AdKitPublishedResponse']['required']);
        $this->assertSame(['draft', 'pending'], $schemas['AdKitDraftResponse']['properties']['status']['enum']);
        $this->assertSame(['platform', 'status', 'draftId', 'request_id'], $schemas['AdKitDraftResponse']['required']);

        $operation = $contract['paths']['/manage/platform-api-requests']['post'];
        $examples = $operation['requestBody']['content']['application/json']['examples'];
        $example = $examples['readInsights']['value'];
        $this->assertSame(['platform', 'endpoint', 'method', 'description', 'payload'], array_keys($example));
        $this->assertSame('act_1185818863112933/insights', $example['endpoint']);
        $this->assertSame('{"since":"2026-08-26","until":"2026-10-26"}', $example['payload']['time_range']);
        $this->assertSame('ad', $example['payload']['level']);
        $this->assertSame(25, $example['payload']['limit']);
        $this->assertStringContainsString('2026-10-26', $operation['description']);
        $this->assertStringContainsString('180.28', $operation['description']);
        $this->assertStringContainsString('curl', $operation['description']);
        $this->assertStringContainsString('not live RWYS forwarding', $operation['description']);
        $this->assertStringContainsString('fake-backed coverage', $operation['description']);
        $this->assertStringContainsString('live curl through the RWYS broker', $operation['description']);
        $this->assertStringContainsString('nine succeeded operation records', $operation['description']);
        $this->assertStringContainsString('same idempotency key', $operation['description']);
        $this->assertStringContainsString('no direct AdKit mutations', $operation['description']);
        $this->assertStringContainsString('PENDING_REVIEW', $operation['description']);
        $this->assertStringNotContainsString('live RWYS writes are not verified', $operation['description']);
        $this->assertStringContainsString('synthetic fixtures', $operation['description']);
        $this->assertStringContainsString('no live activation is approved', $operation['description']);
        $this->assertCount(2, $examples);
        $this->assertSame('POST', $examples['createCampaign']['value']['method']);
        $this->assertSame('PAUSED', $examples['createCampaign']['value']['payload']['status']);
    }

    public function test_adkit_provider_errors_document_the_flat_exception_envelope(): void
    {
        $components = Yaml::parseFile(base_path('openapi/agent-api-v1.yaml'))['components'];
        $error = $components['schemas']['AdKitErrorResponse'];

        $this->assertSame(['error', 'message', 'request_id'], $error['required']);
        $this->assertSame('string', $error['properties']['error']['type']);
        $this->assertSame([
            'adkit_configuration_error', 'adkit_connection_invalid',
            'adkit_mapping_invalid', 'adkit_unreachable', 'adkit_rate_limited',
            'adkit_authentication_failed', 'adkit_invalid_response', 'adkit_response_too_large',
            'adkit_invalid_request', 'adkit_provider_error',
            'adkit_idempotency_conflict', 'adkit_operation_pending',
            'adkit_unconfirmed', 'adkit_draft_unsupported',
            'adkit_receipt_journal_invalid',
        ], $error['properties']['error']['enum']);
        foreach (['AdKitProviderError', 'AdKitConflict'] as $response) {
            $this->assertSame('#/components/schemas/AdKitErrorResponse', $components['responses'][$response]['content']['application/json']['schema']['$ref']);
        }
    }

    public function test_adkit_503_distinguishes_confirmed_receipt_persistence_failure_from_flat_errors(): void
    {
        $contract = Yaml::parseFile(base_path('openapi/agent-api-v1.yaml'));
        $components = $contract['components'];
        $response = $components['responses']['AdKitProviderUnavailable'];
        $error = $components['schemas']['AdKitReceiptPersistenceErrorResponse'];

        $this->assertSame('#/components/responses/AdKitProviderUnavailable', $contract['paths']['/manage/platform-api-requests']['post']['responses'][503]['$ref']);
        $this->assertSame([
            ['$ref' => '#/components/schemas/AdKitErrorResponse'],
            ['$ref' => '#/components/schemas/AdKitReceiptPersistenceErrorResponse'],
        ], $response['content']['application/json']['schema']['oneOf']);
        $this->assertSame(['error', 'message', 'provider_status', 'resource_ids', 'image_hashes', 'request_id'], $error['required']);
        $this->assertSame($error['required'], array_keys($error['properties']));
        $this->assertSame('adkit_receipt_persistence_failed', $error['properties']['error']['const']);
        $this->assertSame('string', $error['properties']['message']['type']);
        $this->assertSame(['published', 'draft', 'pending'], $error['properties']['provider_status']['enum']);
        $this->assertSame(['type' => 'array', 'items' => ['type' => 'string', 'pattern' => '^[0-9]+$']], $error['properties']['resource_ids']);
        $this->assertSame(['type' => 'array', 'items' => ['type' => 'string', 'pattern' => '^[a-fA-F0-9]{32}$']], $error['properties']['image_hashes']);
        $this->assertSame('#/components/schemas/RequestId', $error['properties']['request_id']['$ref']);
        $this->assertSame(['type' => 'string', 'format' => 'uuid'], $response['headers']['X-Operation-Id']['schema']);
        $this->assertStringContainsString('local encrypted receipt journal', $error['description']);
        $this->assertStringContainsString('not a distributed guarantee', $error['description']);
        $this->assertStringContainsString('same idempotency key', $error['description']);
        $this->assertStringNotContainsString('API key', $error['description']);
    }

    public function test_adkit_numeric_child_edges_require_target_account_and_document_parent_checks(): void
    {
        $schemas = Yaml::parseFile(base_path('openapi/agent-api-v1.yaml'))['components']['schemas'];
        $pattern = '~'.$schemas['AdKitForwardRequest']['properties']['endpoint']['pattern'].'~';

        foreach (['adsets', 'ads', 'insights'] as $edge) {
            $body = ['platform' => 'meta', 'method' => 'GET', 'endpoint' => '/123/'.$edge,
                'description' => 'Read numeric child edge', 'accountId' => 'act_456',
                'payload' => $edge === 'insights' ? ['fields' => 'spend', 'date_preset' => 'maximum'] : ['fields' => 'id,name']];
            $this->assertSame(1, preg_match($pattern, $body['endpoint']));
            $request = ForwardAdKitRequest::create('/api/v1/manage/platform-api-requests', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode($body, JSON_THROW_ON_ERROR));
            $validator = Validator::make($request->validationData(), $request->rules())->after($request->after());
            $this->assertTrue($validator->passes(), $validator->errors()->toJson());

            unset($body['accountId']);
            $request = ForwardAdKitRequest::create('/api/v1/manage/platform-api-requests', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode($body, JSON_THROW_ON_ERROR));
            $validator = Validator::make($request->validationData(), $request->rules())->after($request->after());
            $this->assertFalse($validator->passes());
            $this->assertArrayHasKey('accountId', $validator->errors()->toArray());
        }
        foreach (['/123/campaigns', '/123/adcreatives', '/123/adimages', '/123/ads/456'] as $endpoint) {
            $this->assertSame(0, preg_match($pattern, $endpoint));
        }
        $this->assertStringContainsString('campaign_id must match', $schemas['AdKitForwardRequest']['properties']['endpoint']['description']);
        $this->assertStringContainsString('campaign_id or adset_id must match', $schemas['AdKitForwardRequest']['properties']['endpoint']['description']);
    }

    public function test_adkit_writes_document_safe_metadata_and_client_owned_operation_recovery(): void
    {
        $contract = Yaml::parseFile(base_path('openapi/agent-api-v1.yaml'));
        $parameters = $contract['components']['parameters'];
        $operation = $contract['paths']['/manage/platform-api-requests']['post'];

        $this->assertSame(['type' => 'boolean', 'default' => false], $parameters['AdKitPublish']['schema']);
        $this->assertSame('query', $parameters['AdKitPublish']['in']);
        $this->assertFalse($parameters['AdKitIdempotencyKey']['required']);
        $this->assertSame('Idempotency-Key', $parameters['AdKitIdempotencyKey']['name']);
        $this->assertSame('header', $parameters['AdKitIdempotencyKey']['in']);
        $this->assertSame(['type' => 'string', 'minLength' => 8, 'maxLength' => 200, 'pattern' => '^[^\x00-\x1f\x7f]*$'], $parameters['AdKitIdempotencyKey']['schema']);
        $this->assertStringContainsString('Required for method POST', $parameters['AdKitIdempotencyKey']['description']);
        $this->assertStringContainsString('not a credential', $parameters['AdKitIdempotencyKey']['description']);
        $this->assertSame('#/components/responses/AdKitConflict', $operation['responses'][409]['$ref']);
        foreach ([200, 403, 404, 409, 502, 503] as $status) {
            $response = $operation['responses'][$status];
            if (isset($response['$ref'])) {
                $response = $contract['components']['responses'][basename($response['$ref'])];
            }
            $this->assertSame(['type' => 'string', 'format' => 'uuid'], $response['headers']['X-Operation-Id']['schema']);
        }
        $this->assertStringContainsString('/operations/{operation}', $operation['description']);
        $this->assertStringContainsString('ledger kind, not a token permission', $contract['components']['schemas']['Operation']['properties']['capability']['description']);
        $this->assertStringContainsString('no additional token permission', $contract['components']['schemas']['Ability']['description']);
        $this->assertStringContainsString('Every valid Client-bound Bearer key', $operation['description']);
        $this->assertStringContainsString('allowed_account_ids', $operation['description']);
        foreach (['AdKitCampaignPayload', 'AdKitAdSetPayload', 'AdKitAdPayload'] as $schema) {
            $this->assertSame(['ACTIVE', 'PAUSED', 'DELETED', 'ARCHIVED'], $contract['components']['schemas'][$schema]['properties']['status']['enum']);
        }
    }

    public function test_adkit_documented_examples_pass_the_actual_forward_request_validation(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 27));
        $contract = Yaml::parseFile(base_path('openapi/agent-api-v1.yaml'));
        $examples = $contract['paths']['/manage/platform-api-requests']['post']['requestBody']['content']['application/json']['examples'];

        foreach ($examples as $name => $example) {
            $request = ForwardAdKitRequest::create('/api/v1/manage/platform-api-requests', 'POST', server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_IDEMPOTENCY_KEY' => 'contract-example-001',
            ], content: json_encode($example['value'], JSON_THROW_ON_ERROR));
            $validator = Validator::make($request->validationData(), $request->rules())->after($request->after());

            $this->assertTrue($validator->passes(), $name.': '.$validator->errors()->toJson());
        }
    }
}
