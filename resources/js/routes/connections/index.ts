import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition, applyUrlDefaults } from './../../wayfinder'
/**
* @see \App\Http\Controllers\Settings\ProviderConnectionController::edit
* @see app/Http/Controllers/Settings/ProviderConnectionController.php:26
* @route '/settings/connections'
*/
export const edit = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: edit.url(options),
    method: 'get',
})

edit.definition = {
    methods: ["get","head"],
    url: '/settings/connections',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Settings\ProviderConnectionController::edit
* @see app/Http/Controllers/Settings/ProviderConnectionController.php:26
* @route '/settings/connections'
*/
edit.url = (options?: RouteQueryOptions) => {
    return edit.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Settings\ProviderConnectionController::edit
* @see app/Http/Controllers/Settings/ProviderConnectionController.php:26
* @route '/settings/connections'
*/
edit.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: edit.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Settings\ProviderConnectionController::edit
* @see app/Http/Controllers/Settings/ProviderConnectionController.php:26
* @route '/settings/connections'
*/
edit.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: edit.url(options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\Settings\ProviderConnectionController::edit
* @see app/Http/Controllers/Settings/ProviderConnectionController.php:26
* @route '/settings/connections'
*/
const editForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: edit.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Settings\ProviderConnectionController::edit
* @see app/Http/Controllers/Settings/ProviderConnectionController.php:26
* @route '/settings/connections'
*/
editForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: edit.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Settings\ProviderConnectionController::edit
* @see app/Http/Controllers/Settings/ProviderConnectionController.php:26
* @route '/settings/connections'
*/
editForm.head = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: edit.url({
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

edit.form = editForm

/**
* @see \App\Http\Controllers\Settings\ProviderConnectionController::openai
* @see app/Http/Controllers/Settings/ProviderConnectionController.php:50
* @route '/settings/connections/openai'
*/
export const openai = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: openai.url(options),
    method: 'get',
})

openai.definition = {
    methods: ["get","head"],
    url: '/settings/connections/openai',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Settings\ProviderConnectionController::openai
* @see app/Http/Controllers/Settings/ProviderConnectionController.php:50
* @route '/settings/connections/openai'
*/
openai.url = (options?: RouteQueryOptions) => {
    return openai.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Settings\ProviderConnectionController::openai
* @see app/Http/Controllers/Settings/ProviderConnectionController.php:50
* @route '/settings/connections/openai'
*/
openai.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: openai.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Settings\ProviderConnectionController::openai
* @see app/Http/Controllers/Settings/ProviderConnectionController.php:50
* @route '/settings/connections/openai'
*/
openai.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: openai.url(options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\Settings\ProviderConnectionController::openai
* @see app/Http/Controllers/Settings/ProviderConnectionController.php:50
* @route '/settings/connections/openai'
*/
const openaiForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: openai.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Settings\ProviderConnectionController::openai
* @see app/Http/Controllers/Settings/ProviderConnectionController.php:50
* @route '/settings/connections/openai'
*/
openaiForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: openai.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Settings\ProviderConnectionController::openai
* @see app/Http/Controllers/Settings/ProviderConnectionController.php:50
* @route '/settings/connections/openai'
*/
openaiForm.head = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: openai.url({
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

openai.form = openaiForm

/**
* @see \App\Http\Controllers\Settings\ProviderConnectionController::zernio
* @see app/Http/Controllers/Settings/ProviderConnectionController.php:59
* @route '/settings/connections/zernio'
*/
export const zernio = (options?: RouteQueryOptions): RouteDefinition<'put'> => ({
    url: zernio.url(options),
    method: 'put',
})

zernio.definition = {
    methods: ["put"],
    url: '/settings/connections/zernio',
} satisfies RouteDefinition<["put"]>

/**
* @see \App\Http\Controllers\Settings\ProviderConnectionController::zernio
* @see app/Http/Controllers/Settings/ProviderConnectionController.php:59
* @route '/settings/connections/zernio'
*/
zernio.url = (options?: RouteQueryOptions) => {
    return zernio.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Settings\ProviderConnectionController::zernio
* @see app/Http/Controllers/Settings/ProviderConnectionController.php:59
* @route '/settings/connections/zernio'
*/
zernio.put = (options?: RouteQueryOptions): RouteDefinition<'put'> => ({
    url: zernio.url(options),
    method: 'put',
})

/**
* @see \App\Http\Controllers\Settings\ProviderConnectionController::zernio
* @see app/Http/Controllers/Settings/ProviderConnectionController.php:59
* @route '/settings/connections/zernio'
*/
const zernioForm = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: zernio.url({
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'PUT',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Settings\ProviderConnectionController::zernio
* @see app/Http/Controllers/Settings/ProviderConnectionController.php:59
* @route '/settings/connections/zernio'
*/
zernioForm.put = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: zernio.url({
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'PUT',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'post',
})

zernio.form = zernioForm

/**
* @see \App\Http\Controllers\Settings\ProviderConnectionController::test
* @see app/Http/Controllers/Settings/ProviderConnectionController.php:106
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
* @see app/Http/Controllers/Settings/ProviderConnectionController.php:106
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
* @see app/Http/Controllers/Settings/ProviderConnectionController.php:106
* @route '/settings/connections/{provider}/test'
*/
test.post = (args: { provider: string | number } | [provider: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: test.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Settings\ProviderConnectionController::test
* @see app/Http/Controllers/Settings/ProviderConnectionController.php:106
* @route '/settings/connections/{provider}/test'
*/
const testForm = (args: { provider: string | number } | [provider: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: test.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Settings\ProviderConnectionController::test
* @see app/Http/Controllers/Settings/ProviderConnectionController.php:106
* @route '/settings/connections/{provider}/test'
*/
testForm.post = (args: { provider: string | number } | [provider: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: test.url(args, options),
    method: 'post',
})

test.form = testForm

/**
* @see \App\Http\Controllers\Settings\ProviderConnectionController::disconnect
* @see app/Http/Controllers/Settings/ProviderConnectionController.php:132
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
* @see app/Http/Controllers/Settings/ProviderConnectionController.php:132
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
* @see app/Http/Controllers/Settings/ProviderConnectionController.php:132
* @route '/settings/connections/{provider}'
*/
disconnect.delete = (args: { provider: string | number } | [provider: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: disconnect.url(args, options),
    method: 'delete',
})

/**
* @see \App\Http\Controllers\Settings\ProviderConnectionController::disconnect
* @see app/Http/Controllers/Settings/ProviderConnectionController.php:132
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
* @see app/Http/Controllers/Settings/ProviderConnectionController.php:132
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
* @see app/Http/Controllers/Settings/ProviderConnectionController.php:90
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
* @see app/Http/Controllers/Settings/ProviderConnectionController.php:90
* @route '/auth/callback'
*/
callback.url = (options?: RouteQueryOptions) => {
    return callback.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Settings\ProviderConnectionController::callback
* @see app/Http/Controllers/Settings/ProviderConnectionController.php:90
* @route '/auth/callback'
*/
callback.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: callback.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Settings\ProviderConnectionController::callback
* @see app/Http/Controllers/Settings/ProviderConnectionController.php:90
* @route '/auth/callback'
*/
callback.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: callback.url(options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\Settings\ProviderConnectionController::callback
* @see app/Http/Controllers/Settings/ProviderConnectionController.php:90
* @route '/auth/callback'
*/
const callbackForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: callback.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Settings\ProviderConnectionController::callback
* @see app/Http/Controllers/Settings/ProviderConnectionController.php:90
* @route '/auth/callback'
*/
callbackForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: callback.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Settings\ProviderConnectionController::callback
* @see app/Http/Controllers/Settings/ProviderConnectionController.php:90
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

const connections = {
    edit: Object.assign(edit, edit),
    openai: Object.assign(openai, openai),
    zernio: Object.assign(zernio, zernio),
    test: Object.assign(test, test),
    disconnect: Object.assign(disconnect, disconnect),
    callback: Object.assign(callback, callback),
}

export default connections