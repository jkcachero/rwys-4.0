import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition, applyUrlDefaults } from './../../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Api\V1\ZernioAccountStatusController::__invoke
* @see app/Http/Controllers/Api/V1/ZernioAccountStatusController.php:13
* @route '/api/v1/zernio/accounts/{account}/status'
*/
const ZernioAccountStatusController = (args: { account: string | number | { public_id: string | number } } | [account: string | number | { public_id: string | number } ] | string | number | { public_id: string | number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: ZernioAccountStatusController.url(args, options),
    method: 'get',
})

ZernioAccountStatusController.definition = {
    methods: ["get","head"],
    url: '/api/v1/zernio/accounts/{account}/status',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Api\V1\ZernioAccountStatusController::__invoke
* @see app/Http/Controllers/Api/V1/ZernioAccountStatusController.php:13
* @route '/api/v1/zernio/accounts/{account}/status'
*/
ZernioAccountStatusController.url = (args: { account: string | number | { public_id: string | number } } | [account: string | number | { public_id: string | number } ] | string | number | { public_id: string | number }, options?: RouteQueryOptions) => {
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

    return ZernioAccountStatusController.definition.url
            .replace('{account}', parsedArgs.account.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Api\V1\ZernioAccountStatusController::__invoke
* @see app/Http/Controllers/Api/V1/ZernioAccountStatusController.php:13
* @route '/api/v1/zernio/accounts/{account}/status'
*/
ZernioAccountStatusController.get = (args: { account: string | number | { public_id: string | number } } | [account: string | number | { public_id: string | number } ] | string | number | { public_id: string | number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: ZernioAccountStatusController.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Api\V1\ZernioAccountStatusController::__invoke
* @see app/Http/Controllers/Api/V1/ZernioAccountStatusController.php:13
* @route '/api/v1/zernio/accounts/{account}/status'
*/
ZernioAccountStatusController.head = (args: { account: string | number | { public_id: string | number } } | [account: string | number | { public_id: string | number } ] | string | number | { public_id: string | number }, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: ZernioAccountStatusController.url(args, options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\Api\V1\ZernioAccountStatusController::__invoke
* @see app/Http/Controllers/Api/V1/ZernioAccountStatusController.php:13
* @route '/api/v1/zernio/accounts/{account}/status'
*/
const ZernioAccountStatusControllerForm = (args: { account: string | number | { public_id: string | number } } | [account: string | number | { public_id: string | number } ] | string | number | { public_id: string | number }, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: ZernioAccountStatusController.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Api\V1\ZernioAccountStatusController::__invoke
* @see app/Http/Controllers/Api/V1/ZernioAccountStatusController.php:13
* @route '/api/v1/zernio/accounts/{account}/status'
*/
ZernioAccountStatusControllerForm.get = (args: { account: string | number | { public_id: string | number } } | [account: string | number | { public_id: string | number } ] | string | number | { public_id: string | number }, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: ZernioAccountStatusController.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Api\V1\ZernioAccountStatusController::__invoke
* @see app/Http/Controllers/Api/V1/ZernioAccountStatusController.php:13
* @route '/api/v1/zernio/accounts/{account}/status'
*/
ZernioAccountStatusControllerForm.head = (args: { account: string | number | { public_id: string | number } } | [account: string | number | { public_id: string | number } ] | string | number | { public_id: string | number }, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: ZernioAccountStatusController.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

ZernioAccountStatusController.form = ZernioAccountStatusControllerForm

export default ZernioAccountStatusController