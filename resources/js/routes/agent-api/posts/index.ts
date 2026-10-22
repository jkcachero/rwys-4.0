import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition, applyUrlDefaults } from './../../../wayfinder'
/**
* @see \App\Http\Controllers\AgentApiController::index
* @see app/Http/Controllers/AgentApiController.php:70
* @route '/api/v1/zernio/accounts/{account}/posts'
*/
export const index = (args: { account: string | number } | [account: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(args, options),
    method: 'get',
})

index.definition = {
    methods: ["get","head"],
    url: '/api/v1/zernio/accounts/{account}/posts',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\AgentApiController::index
* @see app/Http/Controllers/AgentApiController.php:70
* @route '/api/v1/zernio/accounts/{account}/posts'
*/
index.url = (args: { account: string | number } | [account: string | number ] | string | number, options?: RouteQueryOptions) => {
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

    return index.definition.url
            .replace('{account}', parsedArgs.account.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\AgentApiController::index
* @see app/Http/Controllers/AgentApiController.php:70
* @route '/api/v1/zernio/accounts/{account}/posts'
*/
index.get = (args: { account: string | number } | [account: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\AgentApiController::index
* @see app/Http/Controllers/AgentApiController.php:70
* @route '/api/v1/zernio/accounts/{account}/posts'
*/
index.head = (args: { account: string | number } | [account: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: index.url(args, options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\AgentApiController::index
* @see app/Http/Controllers/AgentApiController.php:70
* @route '/api/v1/zernio/accounts/{account}/posts'
*/
const indexForm = (args: { account: string | number } | [account: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: index.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\AgentApiController::index
* @see app/Http/Controllers/AgentApiController.php:70
* @route '/api/v1/zernio/accounts/{account}/posts'
*/
indexForm.get = (args: { account: string | number } | [account: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: index.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\AgentApiController::index
* @see app/Http/Controllers/AgentApiController.php:70
* @route '/api/v1/zernio/accounts/{account}/posts'
*/
indexForm.head = (args: { account: string | number } | [account: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
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
* @see \App\Http\Controllers\AgentApiController::show
* @see app/Http/Controllers/AgentApiController.php:88
* @route '/api/v1/zernio/accounts/{account}/posts/{post}'
*/
export const show = (args: { account: string | number, post: string | number } | [account: string | number, post: string | number ], options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show.url(args, options),
    method: 'get',
})

show.definition = {
    methods: ["get","head"],
    url: '/api/v1/zernio/accounts/{account}/posts/{post}',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\AgentApiController::show
* @see app/Http/Controllers/AgentApiController.php:88
* @route '/api/v1/zernio/accounts/{account}/posts/{post}'
*/
show.url = (args: { account: string | number, post: string | number } | [account: string | number, post: string | number ], options?: RouteQueryOptions) => {
    if (Array.isArray(args)) {
        args = {
            account: args[0],
            post: args[1],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        account: args.account,
        post: args.post,
    }

    return show.definition.url
            .replace('{account}', parsedArgs.account.toString())
            .replace('{post}', parsedArgs.post.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\AgentApiController::show
* @see app/Http/Controllers/AgentApiController.php:88
* @route '/api/v1/zernio/accounts/{account}/posts/{post}'
*/
show.get = (args: { account: string | number, post: string | number } | [account: string | number, post: string | number ], options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\AgentApiController::show
* @see app/Http/Controllers/AgentApiController.php:88
* @route '/api/v1/zernio/accounts/{account}/posts/{post}'
*/
show.head = (args: { account: string | number, post: string | number } | [account: string | number, post: string | number ], options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: show.url(args, options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\AgentApiController::show
* @see app/Http/Controllers/AgentApiController.php:88
* @route '/api/v1/zernio/accounts/{account}/posts/{post}'
*/
const showForm = (args: { account: string | number, post: string | number } | [account: string | number, post: string | number ], options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: show.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\AgentApiController::show
* @see app/Http/Controllers/AgentApiController.php:88
* @route '/api/v1/zernio/accounts/{account}/posts/{post}'
*/
showForm.get = (args: { account: string | number, post: string | number } | [account: string | number, post: string | number ], options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: show.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\AgentApiController::show
* @see app/Http/Controllers/AgentApiController.php:88
* @route '/api/v1/zernio/accounts/{account}/posts/{post}'
*/
showForm.head = (args: { account: string | number, post: string | number } | [account: string | number, post: string | number ], options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: show.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

show.form = showForm

/**
* @see \App\Http\Controllers\AgentApiController::analytics
* @see app/Http/Controllers/AgentApiController.php:119
* @route '/api/v1/zernio/accounts/{account}/posts/{post}/analytics'
*/
export const analytics = (args: { account: string | number, post: string | number } | [account: string | number, post: string | number ], options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: analytics.url(args, options),
    method: 'get',
})

analytics.definition = {
    methods: ["get","head"],
    url: '/api/v1/zernio/accounts/{account}/posts/{post}/analytics',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\AgentApiController::analytics
* @see app/Http/Controllers/AgentApiController.php:119
* @route '/api/v1/zernio/accounts/{account}/posts/{post}/analytics'
*/
analytics.url = (args: { account: string | number, post: string | number } | [account: string | number, post: string | number ], options?: RouteQueryOptions) => {
    if (Array.isArray(args)) {
        args = {
            account: args[0],
            post: args[1],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        account: args.account,
        post: args.post,
    }

    return analytics.definition.url
            .replace('{account}', parsedArgs.account.toString())
            .replace('{post}', parsedArgs.post.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\AgentApiController::analytics
* @see app/Http/Controllers/AgentApiController.php:119
* @route '/api/v1/zernio/accounts/{account}/posts/{post}/analytics'
*/
analytics.get = (args: { account: string | number, post: string | number } | [account: string | number, post: string | number ], options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: analytics.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\AgentApiController::analytics
* @see app/Http/Controllers/AgentApiController.php:119
* @route '/api/v1/zernio/accounts/{account}/posts/{post}/analytics'
*/
analytics.head = (args: { account: string | number, post: string | number } | [account: string | number, post: string | number ], options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: analytics.url(args, options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\AgentApiController::analytics
* @see app/Http/Controllers/AgentApiController.php:119
* @route '/api/v1/zernio/accounts/{account}/posts/{post}/analytics'
*/
const analyticsForm = (args: { account: string | number, post: string | number } | [account: string | number, post: string | number ], options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: analytics.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\AgentApiController::analytics
* @see app/Http/Controllers/AgentApiController.php:119
* @route '/api/v1/zernio/accounts/{account}/posts/{post}/analytics'
*/
analyticsForm.get = (args: { account: string | number, post: string | number } | [account: string | number, post: string | number ], options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: analytics.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\AgentApiController::analytics
* @see app/Http/Controllers/AgentApiController.php:119
* @route '/api/v1/zernio/accounts/{account}/posts/{post}/analytics'
*/
analyticsForm.head = (args: { account: string | number, post: string | number } | [account: string | number, post: string | number ], options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: analytics.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

analytics.form = analyticsForm

/**
* @see \App\Http\Controllers\AgentApiController::store
* @see app/Http/Controllers/AgentApiController.php:134
* @route '/api/v1/zernio/accounts/{account}/posts'
*/
export const store = (args: { account: string | number } | [account: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(args, options),
    method: 'post',
})

store.definition = {
    methods: ["post"],
    url: '/api/v1/zernio/accounts/{account}/posts',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\AgentApiController::store
* @see app/Http/Controllers/AgentApiController.php:134
* @route '/api/v1/zernio/accounts/{account}/posts'
*/
store.url = (args: { account: string | number } | [account: string | number ] | string | number, options?: RouteQueryOptions) => {
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

    return store.definition.url
            .replace('{account}', parsedArgs.account.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\AgentApiController::store
* @see app/Http/Controllers/AgentApiController.php:134
* @route '/api/v1/zernio/accounts/{account}/posts'
*/
store.post = (args: { account: string | number } | [account: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\AgentApiController::store
* @see app/Http/Controllers/AgentApiController.php:134
* @route '/api/v1/zernio/accounts/{account}/posts'
*/
const storeForm = (args: { account: string | number } | [account: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: store.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\AgentApiController::store
* @see app/Http/Controllers/AgentApiController.php:134
* @route '/api/v1/zernio/accounts/{account}/posts'
*/
storeForm.post = (args: { account: string | number } | [account: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: store.url(args, options),
    method: 'post',
})

store.form = storeForm

/**
* @see \App\Http\Controllers\AgentApiController::schedule
* @see app/Http/Controllers/AgentApiController.php:143
* @route '/api/v1/zernio/accounts/{account}/scheduled-posts'
*/
export const schedule = (args: { account: string | number } | [account: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: schedule.url(args, options),
    method: 'post',
})

schedule.definition = {
    methods: ["post"],
    url: '/api/v1/zernio/accounts/{account}/scheduled-posts',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\AgentApiController::schedule
* @see app/Http/Controllers/AgentApiController.php:143
* @route '/api/v1/zernio/accounts/{account}/scheduled-posts'
*/
schedule.url = (args: { account: string | number } | [account: string | number ] | string | number, options?: RouteQueryOptions) => {
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

    return schedule.definition.url
            .replace('{account}', parsedArgs.account.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\AgentApiController::schedule
* @see app/Http/Controllers/AgentApiController.php:143
* @route '/api/v1/zernio/accounts/{account}/scheduled-posts'
*/
schedule.post = (args: { account: string | number } | [account: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: schedule.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\AgentApiController::schedule
* @see app/Http/Controllers/AgentApiController.php:143
* @route '/api/v1/zernio/accounts/{account}/scheduled-posts'
*/
const scheduleForm = (args: { account: string | number } | [account: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: schedule.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\AgentApiController::schedule
* @see app/Http/Controllers/AgentApiController.php:143
* @route '/api/v1/zernio/accounts/{account}/scheduled-posts'
*/
scheduleForm.post = (args: { account: string | number } | [account: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: schedule.url(args, options),
    method: 'post',
})

schedule.form = scheduleForm

const posts = {
    index: Object.assign(index, index),
    show: Object.assign(show, show),
    analytics: Object.assign(analytics, analytics),
    store: Object.assign(store, store),
    schedule: Object.assign(schedule, schedule),
}

export default posts