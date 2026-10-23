<?php

namespace Tests\Feature;

use App\Enums\AgentAbility;
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
        ] as $schema) {
            $this->assertFalse($schemas[$schema]['additionalProperties'], $schema.' must reject unknown fields.');
            $this->assertContains('request_id', $schemas[$schema]['required']);
        }
    }
}
