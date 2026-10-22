import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition } from './../../wayfinder'
import accounts from './accounts'
import posts from './posts'
import operations from './operations'
/**
* @see \App\Http\Controllers\AgentApiController::context
* @see app/Http/Controllers/AgentApiController.php:24
* @route '/api/v1/context'
*/
export const context = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: context.url(options),
    method: 'get',
})

context.definition = {
    methods: ["get","head"],
    url: '/api/v1/context',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\AgentApiController::context
* @see app/Http/Controllers/AgentApiController.php:24
* @route '/api/v1/context'
*/
context.url = (options?: RouteQueryOptions) => {
    return context.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\AgentApiController::context
* @see app/Http/Controllers/AgentApiController.php:24
* @route '/api/v1/context'
*/
context.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: context.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\AgentApiController::context
* @see app/Http/Controllers/AgentApiController.php:24
* @route '/api/v1/context'
*/
context.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: context.url(options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\AgentApiController::context
* @see app/Http/Controllers/AgentApiController.php:24
* @route '/api/v1/context'
*/
const contextForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: context.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\AgentApiController::context
* @see app/Http/Controllers/AgentApiController.php:24
* @route '/api/v1/context'
*/
contextForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: context.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\AgentApiController::context
* @see app/Http/Controllers/AgentApiController.php:24
* @route '/api/v1/context'
*/
contextForm.head = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: context.url({
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

context.form = contextForm

/**
* @see \App\Http\Controllers\AgentApiController::capabilities
* @see app/Http/Controllers/AgentApiController.php:32
* @route '/api/v1/capabilities'
*/
export const capabilities = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: capabilities.url(options),
    method: 'get',
})

capabilities.definition = {
    methods: ["get","head"],
    url: '/api/v1/capabilities',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\AgentApiController::capabilities
* @see app/Http/Controllers/AgentApiController.php:32
* @route '/api/v1/capabilities'
*/
capabilities.url = (options?: RouteQueryOptions) => {
    return capabilities.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\AgentApiController::capabilities
* @see app/Http/Controllers/AgentApiController.php:32
* @route '/api/v1/capabilities'
*/
capabilities.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: capabilities.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\AgentApiController::capabilities
* @see app/Http/Controllers/AgentApiController.php:32
* @route '/api/v1/capabilities'
*/
capabilities.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: capabilities.url(options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\AgentApiController::capabilities
* @see app/Http/Controllers/AgentApiController.php:32
* @route '/api/v1/capabilities'
*/
const capabilitiesForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: capabilities.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\AgentApiController::capabilities
* @see app/Http/Controllers/AgentApiController.php:32
* @route '/api/v1/capabilities'
*/
capabilitiesForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: capabilities.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\AgentApiController::capabilities
* @see app/Http/Controllers/AgentApiController.php:32
* @route '/api/v1/capabilities'
*/
capabilitiesForm.head = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: capabilities.url({
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

capabilities.form = capabilitiesForm

const agentApi = {
    context: Object.assign(context, context),
    capabilities: Object.assign(capabilities, capabilities),
    accounts: Object.assign(accounts, accounts),
    posts: Object.assign(posts, posts),
    operations: Object.assign(operations, operations),
}

export default agentApi