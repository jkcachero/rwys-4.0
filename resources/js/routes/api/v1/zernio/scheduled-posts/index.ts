import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition, applyUrlDefaults } from './../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Api\V1\ZernioPostPublishController::store
* @see app/Http/Controllers/Api/V1/ZernioPostPublishController.php:30
* @route '/api/v1/zernio/accounts/{account}/scheduled-posts'
*/
export const store = (args: { account: string | number | { public_id: string | number } } | [account: string | number | { public_id: string | number } ] | string | number | { public_id: string | number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(args, options),
    method: 'post',
})

store.definition = {
    methods: ["post"],
    url: '/api/v1/zernio/accounts/{account}/scheduled-posts',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Api\V1\ZernioPostPublishController::store
* @see app/Http/Controllers/Api/V1/ZernioPostPublishController.php:30
* @route '/api/v1/zernio/accounts/{account}/scheduled-posts'
*/
store.url = (args: { account: string | number | { public_id: string | number } } | [account: string | number | { public_id: string | number } ] | string | number | { public_id: string | number }, options?: RouteQueryOptions) => {
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

    return store.definition.url
            .replace('{account}', parsedArgs.account.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Api\V1\ZernioPostPublishController::store
* @see app/Http/Controllers/Api/V1/ZernioPostPublishController.php:30
* @route '/api/v1/zernio/accounts/{account}/scheduled-posts'
*/
store.post = (args: { account: string | number | { public_id: string | number } } | [account: string | number | { public_id: string | number } ] | string | number | { public_id: string | number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Api\V1\ZernioPostPublishController::store
* @see app/Http/Controllers/Api/V1/ZernioPostPublishController.php:30
* @route '/api/v1/zernio/accounts/{account}/scheduled-posts'
*/
const storeForm = (args: { account: string | number | { public_id: string | number } } | [account: string | number | { public_id: string | number } ] | string | number | { public_id: string | number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: store.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Api\V1\ZernioPostPublishController::store
* @see app/Http/Controllers/Api/V1/ZernioPostPublishController.php:30
* @route '/api/v1/zernio/accounts/{account}/scheduled-posts'
*/
storeForm.post = (args: { account: string | number | { public_id: string | number } } | [account: string | number | { public_id: string | number } ] | string | number | { public_id: string | number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: store.url(args, options),
    method: 'post',
})

store.form = storeForm

const scheduledPosts = {
    store: Object.assign(store, store),
}

export default scheduledPosts