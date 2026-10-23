import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition } from './../../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Api\V1\AgentCapabilityController::__invoke
* @see app/Http/Controllers/Api/V1/AgentCapabilityController.php:14
* @route '/api/v1/capabilities'
*/
const AgentCapabilityController = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: AgentCapabilityController.url(options),
    method: 'get',
})

AgentCapabilityController.definition = {
    methods: ["get","head"],
    url: '/api/v1/capabilities',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Api\V1\AgentCapabilityController::__invoke
* @see app/Http/Controllers/Api/V1/AgentCapabilityController.php:14
* @route '/api/v1/capabilities'
*/
AgentCapabilityController.url = (options?: RouteQueryOptions) => {
    return AgentCapabilityController.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Api\V1\AgentCapabilityController::__invoke
* @see app/Http/Controllers/Api/V1/AgentCapabilityController.php:14
* @route '/api/v1/capabilities'
*/
AgentCapabilityController.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: AgentCapabilityController.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Api\V1\AgentCapabilityController::__invoke
* @see app/Http/Controllers/Api/V1/AgentCapabilityController.php:14
* @route '/api/v1/capabilities'
*/
AgentCapabilityController.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: AgentCapabilityController.url(options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\Api\V1\AgentCapabilityController::__invoke
* @see app/Http/Controllers/Api/V1/AgentCapabilityController.php:14
* @route '/api/v1/capabilities'
*/
const AgentCapabilityControllerForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: AgentCapabilityController.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Api\V1\AgentCapabilityController::__invoke
* @see app/Http/Controllers/Api/V1/AgentCapabilityController.php:14
* @route '/api/v1/capabilities'
*/
AgentCapabilityControllerForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: AgentCapabilityController.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Api\V1\AgentCapabilityController::__invoke
* @see app/Http/Controllers/Api/V1/AgentCapabilityController.php:14
* @route '/api/v1/capabilities'
*/
AgentCapabilityControllerForm.head = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: AgentCapabilityController.url({
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

AgentCapabilityController.form = AgentCapabilityControllerForm

export default AgentCapabilityController