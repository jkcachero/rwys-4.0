import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition, applyUrlDefaults } from './../../../wayfinder'
/**
* @see \App\Http\Controllers\AgentApiController::index
* @see app/Http/Controllers/AgentApiController.php:152
* @route '/api/v1/operations'
*/
export const index = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(options),
    method: 'get',
})

index.definition = {
    methods: ["get","head"],
    url: '/api/v1/operations',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\AgentApiController::index
* @see app/Http/Controllers/AgentApiController.php:152
* @route '/api/v1/operations'
*/
index.url = (options?: RouteQueryOptions) => {
    return index.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\AgentApiController::index
* @see app/Http/Controllers/AgentApiController.php:152
* @route '/api/v1/operations'
*/
index.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\AgentApiController::index
* @see app/Http/Controllers/AgentApiController.php:152
* @route '/api/v1/operations'
*/
index.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: index.url(options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\AgentApiController::index
* @see app/Http/Controllers/AgentApiController.php:152
* @route '/api/v1/operations'
*/
const indexForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: index.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\AgentApiController::index
* @see app/Http/Controllers/AgentApiController.php:152
* @route '/api/v1/operations'
*/
indexForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: index.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\AgentApiController::index
* @see app/Http/Controllers/AgentApiController.php:152
* @route '/api/v1/operations'
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

/**
* @see \App\Http\Controllers\AgentApiController::show
* @see app/Http/Controllers/AgentApiController.php:170
* @route '/api/v1/operations/{operation}'
*/
export const show = (args: { operation: string | number } | [operation: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show.url(args, options),
    method: 'get',
})

show.definition = {
    methods: ["get","head"],
    url: '/api/v1/operations/{operation}',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\AgentApiController::show
* @see app/Http/Controllers/AgentApiController.php:170
* @route '/api/v1/operations/{operation}'
*/
show.url = (args: { operation: string | number } | [operation: string | number ] | string | number, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { operation: args }
    }

    if (Array.isArray(args)) {
        args = {
            operation: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        operation: args.operation,
    }

    return show.definition.url
            .replace('{operation}', parsedArgs.operation.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\AgentApiController::show
* @see app/Http/Controllers/AgentApiController.php:170
* @route '/api/v1/operations/{operation}'
*/
show.get = (args: { operation: string | number } | [operation: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\AgentApiController::show
* @see app/Http/Controllers/AgentApiController.php:170
* @route '/api/v1/operations/{operation}'
*/
show.head = (args: { operation: string | number } | [operation: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: show.url(args, options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\AgentApiController::show
* @see app/Http/Controllers/AgentApiController.php:170
* @route '/api/v1/operations/{operation}'
*/
const showForm = (args: { operation: string | number } | [operation: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: show.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\AgentApiController::show
* @see app/Http/Controllers/AgentApiController.php:170
* @route '/api/v1/operations/{operation}'
*/
showForm.get = (args: { operation: string | number } | [operation: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: show.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\AgentApiController::show
* @see app/Http/Controllers/AgentApiController.php:170
* @route '/api/v1/operations/{operation}'
*/
showForm.head = (args: { operation: string | number } | [operation: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: show.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

show.form = showForm

const operations = {
    index: Object.assign(index, index),
    show: Object.assign(show, show),
}

export default operations