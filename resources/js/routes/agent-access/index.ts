import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition } from './../../wayfinder'
/**
* @see \App\Http\Controllers\Settings\AgentTokenController::index
* @see app/Http/Controllers/Settings/AgentTokenController.php:21
* @route '/settings/agent-access'
*/
export const index = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(options),
    method: 'get',
})

index.definition = {
    methods: ["get","head"],
    url: '/settings/agent-access',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Settings\AgentTokenController::index
* @see app/Http/Controllers/Settings/AgentTokenController.php:21
* @route '/settings/agent-access'
*/
index.url = (options?: RouteQueryOptions) => {
    return index.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Settings\AgentTokenController::index
* @see app/Http/Controllers/Settings/AgentTokenController.php:21
* @route '/settings/agent-access'
*/
index.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Settings\AgentTokenController::index
* @see app/Http/Controllers/Settings/AgentTokenController.php:21
* @route '/settings/agent-access'
*/
index.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: index.url(options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\Settings\AgentTokenController::index
* @see app/Http/Controllers/Settings/AgentTokenController.php:21
* @route '/settings/agent-access'
*/
const indexForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: index.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Settings\AgentTokenController::index
* @see app/Http/Controllers/Settings/AgentTokenController.php:21
* @route '/settings/agent-access'
*/
indexForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: index.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Settings\AgentTokenController::index
* @see app/Http/Controllers/Settings/AgentTokenController.php:21
* @route '/settings/agent-access'
*/
indexForm.head = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: index.url({
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

index.form = indexForm

const agentAccess = {
    index: Object.assign(index, index),
}

export default agentAccess