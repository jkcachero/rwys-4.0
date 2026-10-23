import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition, applyUrlDefaults } from './../../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Api\V1\ZernioPostController::index
* @see app/Http/Controllers/Api/V1/ZernioPostController.php:15
* @route '/api/v1/zernio/accounts/{account}/posts'
*/
export const index = (args: { account: string | number | { public_id: string | number } } | [account: string | number | { public_id: string | number } ] | string | number | { public_id: string | number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(args, options),
    method: 'get',
})

index.definition = {
    methods: ["get","head"],
    url: '/api/v1/zernio/accounts/{account}/posts',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Api\V1\ZernioPostController::index
* @see app/Http/Controllers/Api/V1/ZernioPostController.php:15
* @route '/api/v1/zernio/accounts/{account}/posts'
*/
index.url = (args: { account: string | number | { public_id: string | number } } | [account: string | number | { public_id: string | number } ] | string | number | { public_id: string | number }, options?: RouteQueryOptions) => {
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

    return index.definition.url
            .replace('{account}', parsedArgs.account.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Api\V1\ZernioPostController::index
* @see app/Http/Controllers/Api/V1/ZernioPostController.php:15
* @route '/api/v1/zernio/accounts/{account}/posts'
*/
index.get = (args: { account: string | number | { public_id: string | number } } | [account: string | number | { public_id: string | number } ] | string | number | { public_id: string | number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Api\V1\ZernioPostController::index
* @see app/Http/Controllers/Api/V1/ZernioPostController.php:15
* @route '/api/v1/zernio/accounts/{account}/posts'
*/
index.head = (args: { account: string | number | { public_id: string | number } } | [account: string | number | { public_id: string | number } ] | string | number | { public_id: string | number }, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: index.url(args, options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\Api\V1\ZernioPostController::index
* @see app/Http/Controllers/Api/V1/ZernioPostController.php:15
* @route '/api/v1/zernio/accounts/{account}/posts'
*/
const indexForm = (args: { account: string | number | { public_id: string | number } } | [account: string | number | { public_id: string | number } ] | string | number | { public_id: string | number }, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: index.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Api\V1\ZernioPostController::index
* @see app/Http/Controllers/Api/V1/ZernioPostController.php:15
* @route '/api/v1/zernio/accounts/{account}/posts'
*/
indexForm.get = (args: { account: string | number | { public_id: string | number } } | [account: string | number | { public_id: string | number } ] | string | number | { public_id: string | number }, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: index.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Api\V1\ZernioPostController::index
* @see app/Http/Controllers/Api/V1/ZernioPostController.php:15
* @route '/api/v1/zernio/accounts/{account}/posts'
*/
indexForm.head = (args: { account: string | number | { public_id: string | number } } | [account: string | number | { public_id: string | number } ] | string | number | { public_id: string | number }, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: index.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

index.form = indexForm

/**
* @see \App\Http\Controllers\Api\V1\ZernioPostController::show
* @see app/Http/Controllers/Api/V1/ZernioPostController.php:37
* @route '/api/v1/zernio/accounts/{account}/posts/{post}'
*/
export const show = (args: { account: string | number | { public_id: string | number }, post: string | number | { public_id: string | number } } | [account: string | number | { public_id: string | number }, post: string | number | { public_id: string | number } ], options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show.url(args, options),
    method: 'get',
})

show.definition = {
    methods: ["get","head"],
    url: '/api/v1/zernio/accounts/{account}/posts/{post}',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Api\V1\ZernioPostController::show
* @see app/Http/Controllers/Api/V1/ZernioPostController.php:37
* @route '/api/v1/zernio/accounts/{account}/posts/{post}'
*/
show.url = (args: { account: string | number | { public_id: string | number }, post: string | number | { public_id: string | number } } | [account: string | number | { public_id: string | number }, post: string | number | { public_id: string | number } ], options?: RouteQueryOptions) => {
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

    return show.definition.url
            .replace('{account}', parsedArgs.account.toString())
            .replace('{post}', parsedArgs.post.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Api\V1\ZernioPostController::show
* @see app/Http/Controllers/Api/V1/ZernioPostController.php:37
* @route '/api/v1/zernio/accounts/{account}/posts/{post}'
*/
show.get = (args: { account: string | number | { public_id: string | number }, post: string | number | { public_id: string | number } } | [account: string | number | { public_id: string | number }, post: string | number | { public_id: string | number } ], options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Api\V1\ZernioPostController::show
* @see app/Http/Controllers/Api/V1/ZernioPostController.php:37
* @route '/api/v1/zernio/accounts/{account}/posts/{post}'
*/
show.head = (args: { account: string | number | { public_id: string | number }, post: string | number | { public_id: string | number } } | [account: string | number | { public_id: string | number }, post: string | number | { public_id: string | number } ], options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: show.url(args, options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\Api\V1\ZernioPostController::show
* @see app/Http/Controllers/Api/V1/ZernioPostController.php:37
* @route '/api/v1/zernio/accounts/{account}/posts/{post}'
*/
const showForm = (args: { account: string | number | { public_id: string | number }, post: string | number | { public_id: string | number } } | [account: string | number | { public_id: string | number }, post: string | number | { public_id: string | number } ], options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: show.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Api\V1\ZernioPostController::show
* @see app/Http/Controllers/Api/V1/ZernioPostController.php:37
* @route '/api/v1/zernio/accounts/{account}/posts/{post}'
*/
showForm.get = (args: { account: string | number | { public_id: string | number }, post: string | number | { public_id: string | number } } | [account: string | number | { public_id: string | number }, post: string | number | { public_id: string | number } ], options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: show.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Api\V1\ZernioPostController::show
* @see app/Http/Controllers/Api/V1/ZernioPostController.php:37
* @route '/api/v1/zernio/accounts/{account}/posts/{post}'
*/
showForm.head = (args: { account: string | number | { public_id: string | number }, post: string | number | { public_id: string | number } } | [account: string | number | { public_id: string | number }, post: string | number | { public_id: string | number } ], options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: show.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

show.form = showForm

const ZernioPostController = { index, show }

export default ZernioPostController