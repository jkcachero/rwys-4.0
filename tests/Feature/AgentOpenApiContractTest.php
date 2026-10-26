<?php

namespace Tests\Feature;

use App\Enums\AgentAbility;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
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
                    'ability' => str($abilityMiddleware)->after('agent.ability:')->toString(),
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
            'AdKitForwardResponse',
            'AdKitErrorResponse',
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
        $this->assertSame('adkit.read', $operation['x-agent-ability']);
        $this->assertSame([['bearerAuth' => []]], $contract['security']);
        $this->assertSame($contract['security'], $operation['security'] ?? $contract['security']);
        $this->assertSame(['bearerAuth'], array_keys($contract['components']['securitySchemes']));
        $this->assertSame('bearer', $contract['components']['securitySchemes']['bearerAuth']['scheme']);
        $this->assertSame([
            'context.read', 'zernio.accounts.read', 'zernio.posts.read', 'zernio.analytics.read',
            'operations.read', 'adkit.read', 'zernio.posts.publish',
        ], $contract['components']['schemas']['Ability']['enum']);
        $this->assertSame([], $operation['parameters'] ?? []);
        $this->assertSame('#/components/schemas/AdKitForwardRequest', $operation['requestBody']['content']['application/json']['schema']['$ref']);
        $this->assertTrue($operation['requestBody']['required']);
        $this->assertSame('#/components/schemas/AdKitForwardResponse', $operation['responses'][200]['content']['application/json']['schema']['$ref']);
        $this->assertSame('#/components/responses/Unprocessable', $operation['responses'][422]['$ref']);
        $this->assertSame('api.v1.adkit.forward', app('router')->getRoutes()->match(Request::create('/api/v1/manage/platform-api-requests', 'POST'))->getName());

        foreach (['CampaignId', 'AdKitCursor', 'AdKitSince', 'AdKitUntil'] as $parameter) {
            $this->assertArrayNotHasKey($parameter, $contract['components']['parameters']);
        }
    }

    public function test_adkit_request_documents_only_account_scoped_reads_and_whitelisted_payload(): void
    {
        $schemas = Yaml::parseFile(base_path('openapi/agent-api-v1.yaml'))['components']['schemas'];
        $this->assertArrayHasKey('AdKitForwardRequest', $schemas);
        $request = $schemas['AdKitForwardRequest'];

        $this->assertFalse($request['additionalProperties']);
        $this->assertSame(['platform', 'endpoint', 'method', 'description', 'payload'], $request['required']);
        $this->assertSame(['platform', 'accountId', 'endpoint', 'method', 'description', 'payload'], array_keys($request['properties']));
        $this->assertSame('meta', $request['properties']['platform']['const']);
        $this->assertSame('GET', $request['properties']['method']['const']);
        $this->assertSame('^/?act_[0-9]+(?:/(?:campaigns|insights|adsets|ads))?$', $request['properties']['endpoint']['pattern']);
        $this->assertSame('^act_[0-9]+$', $request['properties']['accountId']['pattern']);
        $this->assertStringContainsString('stored', $request['properties']['accountId']['description']);

        $payload = $schemas['AdKitReadPayload'];
        $this->assertSame('#/components/schemas/AdKitReadPayload', $request['properties']['payload']['$ref']);
        $this->assertFalse($payload['additionalProperties']);
        $this->assertSame(['fields', 'limit', 'after', 'time_range', 'date_preset', 'level'], array_keys($payload['properties']));
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
        $response = $schemas['AdKitForwardResponse'];

        $this->assertSame(['platform', 'status', 'response', 'results', 'request_id'], $response['required']);
        $this->assertSame($response['required'], array_keys($response['properties']));
        $this->assertSame('meta', $response['properties']['platform']['const']);
        $this->assertSame('executed', $response['properties']['status']['const']);
        $this->assertSame(['type' => 'object', 'additionalProperties' => true], $response['properties']['response']);
        $this->assertSame(['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => true]], $response['properties']['results']);
        $this->assertSame(['AdKitForwardRequest', 'AdKitReadPayload', 'AdKitForwardResponse', 'AdKitErrorResponse'], array_values(array_filter(array_keys($schemas), fn (string $name): bool => str_starts_with($name, 'AdKit'))));

        $operation = $contract['paths']['/manage/platform-api-requests']['post'];
        $example = $operation['requestBody']['content']['application/json']['example'];
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
    }

    public function test_adkit_provider_errors_document_the_flat_exception_envelope(): void
    {
        $components = Yaml::parseFile(base_path('openapi/agent-api-v1.yaml'))['components'];
        $error = $components['schemas']['AdKitErrorResponse'];

        $this->assertSame(['error', 'message', 'request_id'], $error['required']);
        $this->assertSame('string', $error['properties']['error']['type']);
        $this->assertSame([
            'adkit_disabled', 'adkit_configuration_error', 'adkit_connection_invalid',
            'adkit_mapping_invalid', 'adkit_unreachable', 'adkit_rate_limited',
            'adkit_authentication_failed', 'adkit_invalid_response', 'adkit_response_too_large',
            'adkit_invalid_request', 'adkit_provider_error',
        ], $error['properties']['error']['enum']);
        foreach (['AdKitProviderError', 'AdKitProviderUnavailable'] as $response) {
            $this->assertSame('#/components/schemas/AdKitErrorResponse', $components['responses'][$response]['content']['application/json']['schema']['$ref']);
        }
    }
}
