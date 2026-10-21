import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition, applyUrlDefaults } from './../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Settings\ProviderConnectionController::index
* @see app/Http/Controllers/Settings/ProviderConnectionController.php:20
* @route '/settings/connections'
*/
export const index = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(options),
    method: 'get',
})

index.definition = {
    methods: ["get","head"],
    url: '/settings/connections',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Settings\ProviderConnectionController::index
* @see app/Http/Controllers/Settings/ProviderConnectionController.php:20
* @route '/settings/connections'
*/
index.url = (options?: RouteQueryOptions) => {
    return index.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Settings\ProviderConnectionController::index
* @see app/Http/Controllers/Settings/ProviderConnectionController.php:20
* @route '/settings/connections'
*/
index.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Settings\ProviderConnectionController::index
* @see app/Http/Controllers/Settings/ProviderConnectionController.php:20
* @route '/settings/connections'
*/
index.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: index.url(options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\Settings\ProviderConnectionController::index
* @see app/Http/Controllers/Settings/ProviderConnectionController.php:20
* @route '/settings/connections'
*/
const indexForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: index.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Settings\ProviderConnectionController::index
* @see app/Http/Controllers/Settings/ProviderConnectionController.php:20
* @route '/settings/connections'
*/
indexForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: index.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Settings\ProviderConnectionController::index
* @see app/Http/Controllers/Settings/ProviderConnectionController.php:20
* @route '/settings/connections'
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
* @see \App\Http\Controllers\Settings\ProviderConnectionController::connectOpenAi
* @see app/Http/Controllers/Settings/ProviderConnectionController.php:44
* @route '/settings/connections/openai'
*/
export const connectOpenAi = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: connectOpenAi.url(options),
    method: 'get',
})

connectOpenAi.definition = {
    methods: ["get","head"],
    url: '/settings/connections/openai',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Settings\ProviderConnectionController::connectOpenAi
* @see app/Http/Controllers/Settings/ProviderConnectionController.php:44
* @route '/settings/connections/openai'
*/
connectOpenAi.url = (options?: RouteQueryOptions) => {
    return connectOpenAi.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Settings\ProviderConnectionController::connectOpenAi
* @see app/Http/Controllers/Settings/ProviderConnectionController.php:44
* @route '/settings/connections/openai'
*/
connectOpenAi.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: connectOpenAi.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Settings\ProviderConnectionController::connectOpenAi
* @see app/Http/Controllers/Settings/ProviderConnectionController.php:44
* @route '/settings/connections/openai'
*/
connectOpenAi.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: connectOpenAi.url(options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\Settings\ProviderConnectionController::connectOpenAi
* @see app/Http/Controllers/Settings/ProviderConnectionController.php:44
* @route '/settings/connections/openai'
*/
const connectOpenAiForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: connectOpenAi.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Settings\ProviderConnectionController::connectOpenAi
* @see app/Http/Controllers/Settings/ProviderConnectionController.php:44
* @route '/settings/connections/openai'
*/
connectOpenAiForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: connectOpenAi.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Settings\ProviderConnectionController::connectOpenAi
* @see app/Http/Controllers/Settings/ProviderConnectionController.php:44
* @route '/settings/connections/openai'
*/
connectOpenAiForm.head = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: connectOpenAi.url({
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

connectOpenAi.form = connectOpenAiForm

/**
* @see \App\Http\Controllers\Settings\ProviderConnectionController::test
* @see app/Http/Controllers/Settings/ProviderConnectionController.php:69
* @route '/settings/connections/{provider}/test'
*/
export const test = (args: { provider: string | number } | [provider: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: test.url(args, options),
    method: 'post',
})

test.definition = {
    methods: ["post"],
    url: '/settings/connections/{provider}/test',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Settings\ProviderConnectionController::test
* @see app/Http/Controllers/Settings/ProviderConnectionController.php:69
* @route '/settings/connections/{provider}/test'
*/
test.url = (args: { provider: string | number } | [provider: string | number ] | string | number, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { provider: args }
    }

    if (Array.isArray(args)) {
        args = {
            provider: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        provider: args.provider,
    }

    return test.definition.url
            .replace('{provider}', parsedArgs.provider.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Settings\ProviderConnectionController::test
* @see app/Http/Controllers/Settings/ProviderConnectionController.php:69
* @route '/settings/connections/{provider}/test'
*/
test.post = (args: { provider: string | number } | [provider: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: test.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Settings\ProviderConnectionController::test
* @see app/Http/Controllers/Settings/ProviderConnectionController.php:69
* @route '/settings/connections/{provider}/test'
*/
const testForm = (args: { provider: string | number } | [provider: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: test.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Settings\ProviderConnectionController::test
* @see app/Http/Controllers/Settings/ProviderConnectionController.php:69
* @route '/settings/connections/{provider}/test'
*/
testForm.post = (args: { provider: string | number } | [provider: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: test.url(args, options),
    method: 'post',
})

test.form = testForm

/**
* @see \App\Http\Controllers\Settings\ProviderConnectionController::disconnect
* @see app/Http/Controllers/Settings/ProviderConnectionController.php:88
* @route '/settings/connections/{provider}'
*/
export const disconnect = (args: { provider: string | number } | [provider: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: disconnect.url(args, options),
    method: 'delete',
})

disconnect.definition = {
    methods: ["delete"],
    url: '/settings/connections/{provider}',
} satisfies RouteDefinition<["delete"]>

/**
* @see \App\Http\Controllers\Settings\ProviderConnectionController::disconnect
* @see app/Http/Controllers/Settings/ProviderConnectionController.php:88
* @route '/settings/connections/{provider}'
*/
disconnect.url = (args: { provider: string | number } | [provider: string | number ] | string | number, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { provider: args }
    }

    if (Array.isArray(args)) {
        args = {
            provider: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        provider: args.provider,
    }

    return disconnect.definition.url
            .replace('{provider}', parsedArgs.provider.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Settings\ProviderConnectionController::disconnect
* @see app/Http/Controllers/Settings/ProviderConnectionController.php:88
* @route '/settings/connections/{provider}'
*/
disconnect.delete = (args: { provider: string | number } | [provider: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: disconnect.url(args, options),
    method: 'delete',
})

/**
* @see \App\Http\Controllers\Settings\ProviderConnectionController::disconnect
* @see app/Http/Controllers/Settings/ProviderConnectionController.php:88
* @route '/settings/connections/{provider}'
*/
const disconnectForm = (args: { provider: string | number } | [provider: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: disconnect.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'DELETE',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Settings\ProviderConnectionController::disconnect
* @see app/Http/Controllers/Settings/ProviderConnectionController.php:88
* @route '/settings/connections/{provider}'
*/
disconnectForm.delete = (args: { provider: string | number } | [provider: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: disconnect.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'DELETE',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'post',
})

disconnect.form = disconnectForm

/**
* @see \App\Http\Controllers\Settings\ProviderConnectionController::callback
* @see app/Http/Controllers/Settings/ProviderConnectionController.php:53
* @route '/auth/callback'
*/
export const callback = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: callback.url(options),
    method: 'get',
})

callback.definition = {
    methods: ["get","head"],
    url: '/auth/callback',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Settings\ProviderConnectionController::callback
* @see app/Http/Controllers/Settings/ProviderConnectionController.php:53
* @route '/auth/callback'
*/
callback.url = (options?: RouteQueryOptions) => {
    return callback.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Settings\ProviderConnectionController::callback
* @see app/Http/Controllers/Settings/ProviderConnectionController.php:53
* @route '/auth/callback'
*/
callback.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: callback.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Settings\ProviderConnectionController::callback
* @see app/Http/Controllers/Settings/ProviderConnectionController.php:53
* @route '/auth/callback'
*/
callback.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: callback.url(options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\Settings\ProviderConnectionController::callback
* @see app/Http/Controllers/Settings/ProviderConnectionController.php:53
* @route '/auth/callback'
*/
const callbackForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: callback.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Settings\ProviderConnectionController::callback
* @see app/Http/Controllers/Settings/ProviderConnectionController.php:53
* @route '/auth/callback'
*/
callbackForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: callback.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Settings\ProviderConnectionController::callback
* @see app/Http/Controllers/Settings/ProviderConnectionController.php:53
* @route '/auth/callback'
*/
callbackForm.head = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: callback.url({
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

callback.form = callbackForm

const ProviderConnectionController = { index, connectOpenAi, test, disconnect, callback }

export default ProviderConnectionController