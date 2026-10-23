import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition, applyUrlDefaults } from './../../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Api\V1\ZernioPostAnalyticsController::__invoke
* @see app/Http/Controllers/Api/V1/ZernioPostAnalyticsController.php:14
* @route '/api/v1/zernio/accounts/{account}/posts/{post}/analytics'
*/
const ZernioPostAnalyticsController = (args: { account: string | number | { public_id: string | number }, post: string | number | { public_id: string | number } } | [account: string | number | { public_id: string | number }, post: string | number | { public_id: string | number } ], options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: ZernioPostAnalyticsController.url(args, options),
    method: 'get',
})

ZernioPostAnalyticsController.definition = {
    methods: ["get","head"],
    url: '/api/v1/zernio/accounts/{account}/posts/{post}/analytics',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Api\V1\ZernioPostAnalyticsController::__invoke
* @see app/Http/Controllers/Api/V1/ZernioPostAnalyticsController.php:14
* @route '/api/v1/zernio/accounts/{account}/posts/{post}/analytics'
*/
ZernioPostAnalyticsController.url = (args: { account: string | number | { public_id: string | number }, post: string | number | { public_id: string | number } } | [account: string | number | { public_id: string | number }, post: string | number | { public_id: string | number } ], options?: RouteQueryOptions) => {
    if (Array.isArray(args)) {
        args = {
            account: args[0],
            post: args[1],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        account: typeof args.account === 'object'
        ? args.account.public_id
        : args.account,
        post: typeof args.post === 'object'
        ? args.post.public_id
        : args.post,
    }

    return ZernioPostAnalyticsController.definition.url
            .replace('{account}', parsedArgs.account.toString())
            .replace('{post}', parsedArgs.post.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Api\V1\ZernioPostAnalyticsController::__invoke
* @see app/Http/Controllers/Api/V1/ZernioPostAnalyticsController.php:14
* @route '/api/v1/zernio/accounts/{account}/posts/{post}/analytics'
*/
ZernioPostAnalyticsController.get = (args: { account: string | number | { public_id: string | number }, post: string | number | { public_id: string | number } } | [account: string | number | { public_id: string | number }, post: string | number | { public_id: string | number } ], options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: ZernioPostAnalyticsController.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Api\V1\ZernioPostAnalyticsController::__invoke
* @see app/Http/Controllers/Api/V1/ZernioPostAnalyticsController.php:14
* @route '/api/v1/zernio/accounts/{account}/posts/{post}/analytics'
*/
ZernioPostAnalyticsController.head = (args: { account: string | number | { public_id: string | number }, post: string | number | { public_id: string | number } } | [account: string | number | { public_id: string | number }, post: string | number | { public_id: string | number } ], options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: ZernioPostAnalyticsController.url(args, options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\Api\V1\ZernioPostAnalyticsController::__invoke
* @see app/Http/Controllers/Api/V1/ZernioPostAnalyticsController.php:14
* @route '/api/v1/zernio/accounts/{account}/posts/{post}/analytics'
*/
const ZernioPostAnalyticsControllerForm = (args: { account: string | number | { public_id: string | number }, post: string | number | { public_id: string | number } } | [account: string | number | { public_id: string | number }, post: string | number | { public_id: string | number } ], options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: ZernioPostAnalyticsController.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Api\V1\ZernioPostAnalyticsController::__invoke
* @see app/Http/Controllers/Api/V1/ZernioPostAnalyticsController.php:14
* @route '/api/v1/zernio/accounts/{account}/posts/{post}/analytics'
*/
ZernioPostAnalyticsControllerForm.get = (args: { account: string | number | { public_id: string | number }, post: string | number | { public_id: string | number } } | [account: string | number | { public_id: string | number }, post: string | number | { public_id: string | number } ], options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: ZernioPostAnalyticsController.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Api\V1\ZernioPostAnalyticsController::__invoke
* @see app/Http/Controllers/Api/V1/ZernioPostAnalyticsController.php:14
* @route '/api/v1/zernio/accounts/{account}/posts/{post}/analytics'
*/
ZernioPostAnalyticsControllerForm.head = (args: { account: string | number | { public_id: string | number }, post: string | number | { public_id: string | number } } | [account: string | number | { public_id: string | number }, post: string | number | { public_id: string | number } ], options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: ZernioPostAnalyticsController.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

ZernioPostAnalyticsController.form = ZernioPostAnalyticsControllerForm

export default ZernioPostAnalyticsController