import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition, applyUrlDefaults } from './../../../../wayfinder'
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

/**
* @see \App\Http\Controllers\AgentApiController::accounts
* @see app/Http/Controllers/AgentApiController.php:39
* @route '/api/v1/zernio/accounts'
*/
export const accounts = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: accounts.url(options),
    method: 'get',
})

accounts.definition = {
    methods: ["get","head"],
    url: '/api/v1/zernio/accounts',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\AgentApiController::accounts
* @see app/Http/Controllers/AgentApiController.php:39
* @route '/api/v1/zernio/accounts'
*/
accounts.url = (options?: RouteQueryOptions) => {
    return accounts.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\AgentApiController::accounts
* @see app/Http/Controllers/AgentApiController.php:39
* @route '/api/v1/zernio/accounts'
*/
accounts.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: accounts.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\AgentApiController::accounts
* @see app/Http/Controllers/AgentApiController.php:39
* @route '/api/v1/zernio/accounts'
*/
accounts.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: accounts.url(options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\AgentApiController::accounts
* @see app/Http/Controllers/AgentApiController.php:39
* @route '/api/v1/zernio/accounts'
*/
const accountsForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: accounts.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\AgentApiController::accounts
* @see app/Http/Controllers/AgentApiController.php:39
* @route '/api/v1/zernio/accounts'
*/
accountsForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: accounts.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\AgentApiController::accounts
* @see app/Http/Controllers/AgentApiController.php:39
* @route '/api/v1/zernio/accounts'
*/
accountsForm.head = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: accounts.url({
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

accounts.form = accountsForm

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

/**
* @see \App\Http\Controllers\AgentApiController::posts
* @see app/Http/Controllers/AgentApiController.php:70
* @route '/api/v1/zernio/accounts/{account}/posts'
*/
export const posts = (args: { account: string | number } | [account: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: posts.url(args, options),
    method: 'get',
})

posts.definition = {
    methods: ["get","head"],
    url: '/api/v1/zernio/accounts/{account}/posts',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\AgentApiController::posts
* @see app/Http/Controllers/AgentApiController.php:70
* @route '/api/v1/zernio/accounts/{account}/posts'
*/
posts.url = (args: { account: string | number } | [account: string | number ] | string | number, options?: RouteQueryOptions) => {
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

    return posts.definition.url
            .replace('{account}', parsedArgs.account.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\AgentApiController::posts
* @see app/Http/Controllers/AgentApiController.php:70
* @route '/api/v1/zernio/accounts/{account}/posts'
*/
posts.get = (args: { account: string | number } | [account: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: posts.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\AgentApiController::posts
* @see app/Http/Controllers/AgentApiController.php:70
* @route '/api/v1/zernio/accounts/{account}/posts'
*/
posts.head = (args: { account: string | number } | [account: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: posts.url(args, options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\AgentApiController::posts
* @see app/Http/Controllers/AgentApiController.php:70
* @route '/api/v1/zernio/accounts/{account}/posts'
*/
const postsForm = (args: { account: string | number } | [account: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: posts.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\AgentApiController::posts
* @see app/Http/Controllers/AgentApiController.php:70
* @route '/api/v1/zernio/accounts/{account}/posts'
*/
postsForm.get = (args: { account: string | number } | [account: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: posts.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\AgentApiController::posts
* @see app/Http/Controllers/AgentApiController.php:70
* @route '/api/v1/zernio/accounts/{account}/posts'
*/
postsForm.head = (args: { account: string | number } | [account: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: posts.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

posts.form = postsForm

/**
* @see \App\Http\Controllers\AgentApiController::post
* @see app/Http/Controllers/AgentApiController.php:88
* @route '/api/v1/zernio/accounts/{account}/posts/{post}'
*/
export const post = (args: { account: string | number, post: string | number } | [account: string | number, post: string | number ], options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: post.url(args, options),
    method: 'get',
})

post.definition = {
    methods: ["get","head"],
    url: '/api/v1/zernio/accounts/{account}/posts/{post}',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\AgentApiController::post
* @see app/Http/Controllers/AgentApiController.php:88
* @route '/api/v1/zernio/accounts/{account}/posts/{post}'
*/
post.url = (args: { account: string | number, post: string | number } | [account: string | number, post: string | number ], options?: RouteQueryOptions) => {
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

    return post.definition.url
            .replace('{account}', parsedArgs.account.toString())
            .replace('{post}', parsedArgs.post.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\AgentApiController::post
* @see app/Http/Controllers/AgentApiController.php:88
* @route '/api/v1/zernio/accounts/{account}/posts/{post}'
*/
post.get = (args: { account: string | number, post: string | number } | [account: string | number, post: string | number ], options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: post.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\AgentApiController::post
* @see app/Http/Controllers/AgentApiController.php:88
* @route '/api/v1/zernio/accounts/{account}/posts/{post}'
*/
post.head = (args: { account: string | number, post: string | number } | [account: string | number, post: string | number ], options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: post.url(args, options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\AgentApiController::post
* @see app/Http/Controllers/AgentApiController.php:88
* @route '/api/v1/zernio/accounts/{account}/posts/{post}'
*/
const postForm = (args: { account: string | number, post: string | number } | [account: string | number, post: string | number ], options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: post.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\AgentApiController::post
* @see app/Http/Controllers/AgentApiController.php:88
* @route '/api/v1/zernio/accounts/{account}/posts/{post}'
*/
postForm.get = (args: { account: string | number, post: string | number } | [account: string | number, post: string | number ], options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: post.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\AgentApiController::post
* @see app/Http/Controllers/AgentApiController.php:88
* @route '/api/v1/zernio/accounts/{account}/posts/{post}'
*/
postForm.head = (args: { account: string | number, post: string | number } | [account: string | number, post: string | number ], options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: post.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

post.form = postForm

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
* @see \App\Http\Controllers\AgentApiController::publish
* @see app/Http/Controllers/AgentApiController.php:134
* @route '/api/v1/zernio/accounts/{account}/posts'
*/
export const publish = (args: { account: string | number } | [account: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: publish.url(args, options),
    method: 'post',
})

publish.definition = {
    methods: ["post"],
    url: '/api/v1/zernio/accounts/{account}/posts',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\AgentApiController::publish
* @see app/Http/Controllers/AgentApiController.php:134
* @route '/api/v1/zernio/accounts/{account}/posts'
*/
publish.url = (args: { account: string | number } | [account: string | number ] | string | number, options?: RouteQueryOptions) => {
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

    return publish.definition.url
            .replace('{account}', parsedArgs.account.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\AgentApiController::publish
* @see app/Http/Controllers/AgentApiController.php:134
* @route '/api/v1/zernio/accounts/{account}/posts'
*/
publish.post = (args: { account: string | number } | [account: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: publish.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\AgentApiController::publish
* @see app/Http/Controllers/AgentApiController.php:134
* @route '/api/v1/zernio/accounts/{account}/posts'
*/
const publishForm = (args: { account: string | number } | [account: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: publish.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\AgentApiController::publish
* @see app/Http/Controllers/AgentApiController.php:134
* @route '/api/v1/zernio/accounts/{account}/posts'
*/
publishForm.post = (args: { account: string | number } | [account: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: publish.url(args, options),
    method: 'post',
})

publish.form = publishForm

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

/**
* @see \App\Http\Controllers\AgentApiController::operations
* @see app/Http/Controllers/AgentApiController.php:152
* @route '/api/v1/operations'
*/
export const operations = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: operations.url(options),
    method: 'get',
})

operations.definition = {
    methods: ["get","head"],
    url: '/api/v1/operations',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\AgentApiController::operations
* @see app/Http/Controllers/AgentApiController.php:152
* @route '/api/v1/operations'
*/
operations.url = (options?: RouteQueryOptions) => {
    return operations.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\AgentApiController::operations
* @see app/Http/Controllers/AgentApiController.php:152
* @route '/api/v1/operations'
*/
operations.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: operations.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\AgentApiController::operations
* @see app/Http/Controllers/AgentApiController.php:152
* @route '/api/v1/operations'
*/
operations.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: operations.url(options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\AgentApiController::operations
* @see app/Http/Controllers/AgentApiController.php:152
* @route '/api/v1/operations'
*/
const operationsForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: operations.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\AgentApiController::operations
* @see app/Http/Controllers/AgentApiController.php:152
* @route '/api/v1/operations'
*/
operationsForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: operations.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\AgentApiController::operations
* @see app/Http/Controllers/AgentApiController.php:152
* @route '/api/v1/operations'
*/
operationsForm.head = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: operations.url({
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

operations.form = operationsForm

/**
* @see \App\Http\Controllers\AgentApiController::operation
* @see app/Http/Controllers/AgentApiController.php:170
* @route '/api/v1/operations/{operation}'
*/
export const operation = (args: { operation: string | number } | [operation: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: operation.url(args, options),
    method: 'get',
})

operation.definition = {
    methods: ["get","head"],
    url: '/api/v1/operations/{operation}',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\AgentApiController::operation
* @see app/Http/Controllers/AgentApiController.php:170
* @route '/api/v1/operations/{operation}'
*/
operation.url = (args: { operation: string | number } | [operation: string | number ] | string | number, options?: RouteQueryOptions) => {
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

    return operation.definition.url
            .replace('{operation}', parsedArgs.operation.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\AgentApiController::operation
* @see app/Http/Controllers/AgentApiController.php:170
* @route '/api/v1/operations/{operation}'
*/
operation.get = (args: { operation: string | number } | [operation: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: operation.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\AgentApiController::operation
* @see app/Http/Controllers/AgentApiController.php:170
* @route '/api/v1/operations/{operation}'
*/
operation.head = (args: { operation: string | number } | [operation: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: operation.url(args, options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\AgentApiController::operation
* @see app/Http/Controllers/AgentApiController.php:170
* @route '/api/v1/operations/{operation}'
*/
const operationForm = (args: { operation: string | number } | [operation: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: operation.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\AgentApiController::operation
* @see app/Http/Controllers/AgentApiController.php:170
* @route '/api/v1/operations/{operation}'
*/
operationForm.get = (args: { operation: string | number } | [operation: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: operation.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\AgentApiController::operation
* @see app/Http/Controllers/AgentApiController.php:170
* @route '/api/v1/operations/{operation}'
*/
operationForm.head = (args: { operation: string | number } | [operation: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: operation.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

operation.form = operationForm

const AgentApiController = { context, capabilities, accounts, status, posts, post, analytics, publish, schedule, operations, operation }

export default AgentApiController