import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition, applyUrlDefaults } from './../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Settings\AgentTokenController::access
* @see app/Http/Controllers/Settings/AgentTokenController.php:21
* @route '/settings/agent-access'
*/
export const access = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: access.url(options),
    method: 'get',
})

access.definition = {
    methods: ["get","head"],
    url: '/settings/agent-access',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Settings\AgentTokenController::access
* @see app/Http/Controllers/Settings/AgentTokenController.php:21
* @route '/settings/agent-access'
*/
access.url = (options?: RouteQueryOptions) => {
    return access.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Settings\AgentTokenController::access
* @see app/Http/Controllers/Settings/AgentTokenController.php:21
* @route '/settings/agent-access'
*/
access.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: access.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Settings\AgentTokenController::access
* @see app/Http/Controllers/Settings/AgentTokenController.php:21
* @route '/settings/agent-access'
*/
access.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: access.url(options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\Settings\AgentTokenController::access
* @see app/Http/Controllers/Settings/AgentTokenController.php:21
* @route '/settings/agent-access'
*/
const accessForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: access.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Settings\AgentTokenController::access
* @see app/Http/Controllers/Settings/AgentTokenController.php:21
* @route '/settings/agent-access'
*/
accessForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: access.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Settings\AgentTokenController::access
* @see app/Http/Controllers/Settings/AgentTokenController.php:21
* @route '/settings/agent-access'
*/
accessForm.head = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: access.url({
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

access.form = accessForm

/**
* @see \App\Http\Controllers\Settings\AgentTokenController::index
* @see app/Http/Controllers/Settings/AgentTokenController.php:28
* @route '/settings/agent-tokens'
*/
export const index = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(options),
    method: 'get',
})

index.definition = {
    methods: ["get","head"],
    url: '/settings/agent-tokens',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Settings\AgentTokenController::index
* @see app/Http/Controllers/Settings/AgentTokenController.php:28
* @route '/settings/agent-tokens'
*/
index.url = (options?: RouteQueryOptions) => {
    return index.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Settings\AgentTokenController::index
* @see app/Http/Controllers/Settings/AgentTokenController.php:28
* @route '/settings/agent-tokens'
*/
index.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Settings\AgentTokenController::index
* @see app/Http/Controllers/Settings/AgentTokenController.php:28
* @route '/settings/agent-tokens'
*/
index.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: index.url(options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\Settings\AgentTokenController::index
* @see app/Http/Controllers/Settings/AgentTokenController.php:28
* @route '/settings/agent-tokens'
*/
const indexForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: index.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Settings\AgentTokenController::index
* @see app/Http/Controllers/Settings/AgentTokenController.php:28
* @route '/settings/agent-tokens'
*/
indexForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: index.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Settings\AgentTokenController::index
* @see app/Http/Controllers/Settings/AgentTokenController.php:28
* @route '/settings/agent-tokens'
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
* @see \App\Http\Controllers\Settings\AgentTokenController::store
* @see app/Http/Controllers/Settings/AgentTokenController.php:48
* @route '/settings/agent-tokens'
*/
export const store = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(options),
    method: 'post',
})

store.definition = {
    methods: ["post"],
    url: '/settings/agent-tokens',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Settings\AgentTokenController::store
* @see app/Http/Controllers/Settings/AgentTokenController.php:48
* @route '/settings/agent-tokens'
*/
store.url = (options?: RouteQueryOptions) => {
    return store.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Settings\AgentTokenController::store
* @see app/Http/Controllers/Settings/AgentTokenController.php:48
* @route '/settings/agent-tokens'
*/
store.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Settings\AgentTokenController::store
* @see app/Http/Controllers/Settings/AgentTokenController.php:48
* @route '/settings/agent-tokens'
*/
const storeForm = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: store.url(options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Settings\AgentTokenController::store
* @see app/Http/Controllers/Settings/AgentTokenController.php:48
* @route '/settings/agent-tokens'
*/
storeForm.post = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: store.url(options),
    method: 'post',
})

store.form = storeForm

/**
* @see \App\Http\Controllers\Settings\AgentTokenController::rotate
* @see app/Http/Controllers/Settings/AgentTokenController.php:57
* @route '/settings/agent-tokens/{token}/rotate'
*/
export const rotate = (args: { token: number | { id: number } } | [token: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: rotate.url(args, options),
    method: 'post',
})

rotate.definition = {
    methods: ["post"],
    url: '/settings/agent-tokens/{token}/rotate',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Settings\AgentTokenController::rotate
* @see app/Http/Controllers/Settings/AgentTokenController.php:57
* @route '/settings/agent-tokens/{token}/rotate'
*/
rotate.url = (args: { token: number | { id: number } } | [token: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { token: args }
    }

    if (typeof args === 'object' && !Array.isArray(args) && 'id' in args) {
        args = { token: args.id }
    }

    if (Array.isArray(args)) {
        args = {
            token: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        token: typeof args.token === 'object'
        ? args.token.id
        : args.token,
    }

    return rotate.definition.url
            .replace('{token}', parsedArgs.token.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Settings\AgentTokenController::rotate
* @see app/Http/Controllers/Settings/AgentTokenController.php:57
* @route '/settings/agent-tokens/{token}/rotate'
*/
rotate.post = (args: { token: number | { id: number } } | [token: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: rotate.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Settings\AgentTokenController::rotate
* @see app/Http/Controllers/Settings/AgentTokenController.php:57
* @route '/settings/agent-tokens/{token}/rotate'
*/
const rotateForm = (args: { token: number | { id: number } } | [token: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: rotate.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Settings\AgentTokenController::rotate
* @see app/Http/Controllers/Settings/AgentTokenController.php:57
* @route '/settings/agent-tokens/{token}/rotate'
*/
rotateForm.post = (args: { token: number | { id: number } } | [token: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: rotate.url(args, options),
    method: 'post',
})

rotate.form = rotateForm

/**
* @see \App\Http\Controllers\Settings\AgentTokenController::destroy
* @see app/Http/Controllers/Settings/AgentTokenController.php:64
* @route '/settings/agent-tokens/{token}'
*/
export const destroy = (args: { token: number | { id: number } } | [token: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: destroy.url(args, options),
    method: 'delete',
})

destroy.definition = {
    methods: ["delete"],
    url: '/settings/agent-tokens/{token}',
} satisfies RouteDefinition<["delete"]>

/**
* @see \App\Http\Controllers\Settings\AgentTokenController::destroy
* @see app/Http/Controllers/Settings/AgentTokenController.php:64
* @route '/settings/agent-tokens/{token}'
*/
destroy.url = (args: { token: number | { id: number } } | [token: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { token: args }
    }

    if (typeof args === 'object' && !Array.isArray(args) && 'id' in args) {
        args = { token: args.id }
    }

    if (Array.isArray(args)) {
        args = {
            token: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        token: typeof args.token === 'object'
        ? args.token.id
        : args.token,
    }

    return destroy.definition.url
            .replace('{token}', parsedArgs.token.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Settings\AgentTokenController::destroy
* @see app/Http/Controllers/Settings/AgentTokenController.php:64
* @route '/settings/agent-tokens/{token}'
*/
destroy.delete = (args: { token: number | { id: number } } | [token: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: destroy.url(args, options),
    method: 'delete',
})

/**
* @see \App\Http\Controllers\Settings\AgentTokenController::destroy
* @see app/Http/Controllers/Settings/AgentTokenController.php:64
* @route '/settings/agent-tokens/{token}'
*/
const destroyForm = (args: { token: number | { id: number } } | [token: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: destroy.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'DELETE',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Settings\AgentTokenController::destroy
* @see app/Http/Controllers/Settings/AgentTokenController.php:64
* @route '/settings/agent-tokens/{token}'
*/
destroyForm.delete = (args: { token: number | { id: number } } | [token: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: destroy.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'DELETE',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'post',
})

destroy.form = destroyForm

const AgentTokenController = { access, index, store, rotate, destroy }

export default AgentTokenController