import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition } from './../../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Api\V1\AgentContextController::__invoke
* @see app/Http/Controllers/Api/V1/AgentContextController.php:14
* @route '/api/v1/context'
*/
const AgentContextController = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: AgentContextController.url(options),
    method: 'get',
})

AgentContextController.definition = {
    methods: ["get","head"],
    url: '/api/v1/context',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Api\V1\AgentContextController::__invoke
* @see app/Http/Controllers/Api/V1/AgentContextController.php:14
* @route '/api/v1/context'
*/
AgentContextController.url = (options?: RouteQueryOptions) => {
    return AgentContextController.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Api\V1\AgentContextController::__invoke
* @see app/Http/Controllers/Api/V1/AgentContextController.php:14
* @route '/api/v1/context'
*/
AgentContextController.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: AgentContextController.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Api\V1\AgentContextController::__invoke
* @see app/Http/Controllers/Api/V1/AgentContextController.php:14
* @route '/api/v1/context'
*/
AgentContextController.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: AgentContextController.url(options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\Api\V1\AgentContextController::__invoke
* @see app/Http/Controllers/Api/V1/AgentContextController.php:14
* @route '/api/v1/context'
*/
const AgentContextControllerForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: AgentContextController.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Api\V1\AgentContextController::__invoke
* @see app/Http/Controllers/Api/V1/AgentContextController.php:14
* @route '/api/v1/context'
*/
AgentContextControllerForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: AgentContextController.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Api\V1\AgentContextController::__invoke
* @see app/Http/Controllers/Api/V1/AgentContextController.php:14
* @route '/api/v1/context'
*/
AgentContextControllerForm.head = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: AgentContextController.url({
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

AgentContextController.form = AgentContextControllerForm

export default AgentContextController