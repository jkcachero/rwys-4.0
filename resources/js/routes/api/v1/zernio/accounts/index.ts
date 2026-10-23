import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition, applyUrlDefaults } from './../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Api\V1\ZernioAccountController::index
* @see app/Http/Controllers/Api/V1/ZernioAccountController.php:13
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
* @see \App\Http\Controllers\Api\V1\ZernioAccountController::index
* @see app/Http/Controllers/Api/V1/ZernioAccountController.php:13
* @route '/api/v1/zernio/accounts'
*/
index.url = (options?: RouteQueryOptions) => {
    return index.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Api\V1\ZernioAccountController::index
* @see app/Http/Controllers/Api/V1/ZernioAccountController.php:13
* @route '/api/v1/zernio/accounts'
*/
index.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Api\V1\ZernioAccountController::index
* @see app/Http/Controllers/Api/V1/ZernioAccountController.php:13
* @route '/api/v1/zernio/accounts'
*/
index.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: index.url(options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\Api\V1\ZernioAccountController::index
* @see app/Http/Controllers/Api/V1/ZernioAccountController.php:13
* @route '/api/v1/zernio/accounts'
*/
const indexForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: index.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Api\V1\ZernioAccountController::index
* @see app/Http/Controllers/Api/V1/ZernioAccountController.php:13
* @route '/api/v1/zernio/accounts'
*/
indexForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: index.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Api\V1\ZernioAccountController::index
* @see app/Http/Controllers/Api/V1/ZernioAccountController.php:13
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
* @see \App\Http\Controllers\Api\V1\ZernioAccountStatusController::__invoke
* @see app/Http/Controllers/Api/V1/ZernioAccountStatusController.php:13
* @route '/api/v1/zernio/accounts/{account}/status'
*/
export const status = (args: { account: string | number | { public_id: string | number } } | [account: string | number | { public_id: string | number } ] | string | number | { public_id: string | number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: status.url(args, options),
    method: 'get',
})

status.definition = {
    methods: ["get","head"],
    url: '/api/v1/zernio/accounts/{account}/status',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Api\V1\ZernioAccountStatusController::__invoke
* @see app/Http/Controllers/Api/V1/ZernioAccountStatusController.php:13
* @route '/api/v1/zernio/accounts/{account}/status'
*/
status.url = (args: { account: string | number | { public_id: string | number } } | [account: string | number | { public_id: string | number } ] | string | number | { public_id: string | number }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { account: args }
    }

    if (typeof args === 'object' && !Array.isArray(args) && 'public_id' in args) {
        args = { account: args.public_id }
    }

    if (Array.isArray(args)) {
        args = {
            account: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        account: typeof args.account === 'object'
        ? args.account.public_id
        : args.account,
    }

    return status.definition.url
            .replace('{account}', parsedArgs.account.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Api\V1\ZernioAccountStatusController::__invoke
* @see app/Http/Controllers/Api/V1/ZernioAccountStatusController.php:13
* @route '/api/v1/zernio/accounts/{account}/status'
*/
status.get = (args: { account: string | number | { public_id: string | number } } | [account: string | number | { public_id: string | number } ] | string | number | { public_id: string | number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: status.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Api\V1\ZernioAccountStatusController::__invoke
* @see app/Http/Controllers/Api/V1/ZernioAccountStatusController.php:13
* @route '/api/v1/zernio/accounts/{account}/status'
*/
status.head = (args: { account: string | number | { public_id: string | number } } | [account: string | number | { public_id: string | number } ] | string | number | { public_id: string | number }, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: status.url(args, options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\Api\V1\ZernioAccountStatusController::__invoke
* @see app/Http/Controllers/Api/V1/ZernioAccountStatusController.php:13
* @route '/api/v1/zernio/accounts/{account}/status'
*/
const statusForm = (args: { account: string | number | { public_id: string | number } } | [account: string | number | { public_id: string | number } ] | string | number | { public_id: string | number }, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: status.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Api\V1\ZernioAccountStatusController::__invoke
* @see app/Http/Controllers/Api/V1/ZernioAccountStatusController.php:13
* @route '/api/v1/zernio/accounts/{account}/status'
*/
statusForm.get = (args: { account: string | number | { public_id: string | number } } | [account: string | number | { public_id: string | number } ] | string | number | { public_id: string | number }, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: status.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Api\V1\ZernioAccountStatusController::__invoke
* @see app/Http/Controllers/Api/V1/ZernioAccountStatusController.php:13
* @route '/api/v1/zernio/accounts/{account}/status'
*/
statusForm.head = (args: { account: string | number | { public_id: string | number } } | [account: string | number | { public_id: string | number } ] | string | number | { public_id: string | number }, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
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