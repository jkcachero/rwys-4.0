import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition, applyUrlDefaults } from './../../../wayfinder'
/**
* @see \App\Http\Controllers\AgentApiController::index
* @see app/Http/Controllers/AgentApiController.php:39
* @route '/api/v1/zernio/accounts'
*/
export const index = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(options),
    method: 'get',
})

index.definition = {
    methods: ["get","head"],
    url: '/api/v1/zernio/accounts',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\AgentApiController::index
* @see app/Http/Controllers/AgentApiController.php:39
* @route '/api/v1/zernio/accounts'
*/
index.url = (options?: RouteQueryOptions) => {
    return index.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\AgentApiController::index
* @see app/Http/Controllers/AgentApiController.php:39
* @route '/api/v1/zernio/accounts'
*/
index.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\AgentApiController::index
* @see app/Http/Controllers/AgentApiController.php:39
* @route '/api/v1/zernio/accounts'
*/
index.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: index.url(options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\AgentApiController::index
* @see app/Http/Controllers/AgentApiController.php:39
* @route '/api/v1/zernio/accounts'
*/
const indexForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: index.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\AgentApiController::index
* @see app/Http/Controllers/AgentApiController.php:39
* @route '/api/v1/zernio/accounts'
*/
indexForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: index.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\AgentApiController::index
* @see app/Http/Controllers/AgentApiController.php:39
* @route '/api/v1/zernio/accounts'
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
* @see \App\Http\Controllers\AgentApiController::status
* @see app/Http/Controllers/AgentApiController.php:50
* @route '/api/v1/zernio/accounts/{account}/status'
*/
export const status = (args: { account: string | number } | [account: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: status.url(args, options),
    method: 'get',
})

status.definition = {
    methods: ["get","head"],
    url: '/api/v1/zernio/accounts/{account}/status',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\AgentApiController::status
* @see app/Http/Controllers/AgentApiController.php:50
* @route '/api/v1/zernio/accounts/{account}/status'
*/
status.url = (args: { account: string | number } | [account: string | number ] | string | number, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { account: args }
    }

    if (Array.isArray(args)) {
        args = {
            account: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        account: args.account,
    }

    return status.definition.url
            .replace('{account}', parsedArgs.account.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\AgentApiController::status
* @see app/Http/Controllers/AgentApiController.php:50
* @route '/api/v1/zernio/accounts/{account}/status'
*/
status.get = (args: { account: string | number } | [account: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: status.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\AgentApiController::status
* @see app/Http/Controllers/AgentApiController.php:50
* @route '/api/v1/zernio/accounts/{account}/status'
*/
status.head = (args: { account: string | number } | [account: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: status.url(args, options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\AgentApiController::status
* @see app/Http/Controllers/AgentApiController.php:50
* @route '/api/v1/zernio/accounts/{account}/status'
*/
const statusForm = (args: { account: string | number } | [account: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: status.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\AgentApiController::status
* @see app/Http/Controllers/AgentApiController.php:50
* @route '/api/v1/zernio/accounts/{account}/status'
*/
statusForm.get = (args: { account: string | number } | [account: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: status.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\AgentApiController::status
* @see app/Http/Controllers/AgentApiController.php:50
* @route '/api/v1/zernio/accounts/{account}/status'
*/
statusForm.head = (args: { account: string | number } | [account: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: status.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

status.form = statusForm

const accounts = {
    index: Object.assign(index, index),
    status: Object.assign(status, status),
}

export default accounts