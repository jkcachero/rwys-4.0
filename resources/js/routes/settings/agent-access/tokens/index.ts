import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition, applyUrlDefaults } from './../../../../wayfinder'
/**
* @see \App\Http\Controllers\Settings\AgentAccessController::store
* @see app/Http/Controllers/Settings/AgentAccessController.php:66
* @route '/settings/agent-access/{client}/tokens'
*/
export const store = (args: { client: string | number | { public_id: string | number } } | [client: string | number | { public_id: string | number } ] | string | number | { public_id: string | number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(args, options),
    method: 'post',
})

store.definition = {
    methods: ["post"],
    url: '/settings/agent-access/{client}/tokens',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Settings\AgentAccessController::store
* @see app/Http/Controllers/Settings/AgentAccessController.php:66
* @route '/settings/agent-access/{client}/tokens'
*/
store.url = (args: { client: string | number | { public_id: string | number } } | [client: string | number | { public_id: string | number } ] | string | number | { public_id: string | number }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { client: args }
    }

    if (typeof args === 'object' && !Array.isArray(args) && 'public_id' in args) {
        args = { client: args.public_id }
    }

    if (Array.isArray(args)) {
        args = {
            client: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        client: typeof args.client === 'object'
        ? args.client.public_id
        : args.client,
    }

    return store.definition.url
            .replace('{client}', parsedArgs.client.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Settings\AgentAccessController::store
* @see app/Http/Controllers/Settings/AgentAccessController.php:66
* @route '/settings/agent-access/{client}/tokens'
*/
store.post = (args: { client: string | number | { public_id: string | number } } | [client: string | number | { public_id: string | number } ] | string | number | { public_id: string | number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Settings\AgentAccessController::store
* @see app/Http/Controllers/Settings/AgentAccessController.php:66
* @route '/settings/agent-access/{client}/tokens'
*/
const storeForm = (args: { client: string | number | { public_id: string | number } } | [client: string | number | { public_id: string | number } ] | string | number | { public_id: string | number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: store.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Settings\AgentAccessController::store
* @see app/Http/Controllers/Settings/AgentAccessController.php:66
* @route '/settings/agent-access/{client}/tokens'
*/
storeForm.post = (args: { client: string | number | { public_id: string | number } } | [client: string | number | { public_id: string | number } ] | string | number | { public_id: string | number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: store.url(args, options),
    method: 'post',
})

store.form = storeForm

/**
* @see \App\Http\Controllers\Settings\AgentAccessController::rotate
* @see app/Http/Controllers/Settings/AgentAccessController.php:86
* @route '/settings/agent-access/{client}/tokens/{token}/rotate'
*/
export const rotate = (args: { client: string | number | { public_id: string | number }, token: string | number | { id: string | number } } | [client: string | number | { public_id: string | number }, token: string | number | { id: string | number } ], options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: rotate.url(args, options),
    method: 'post',
})

rotate.definition = {
    methods: ["post"],
    url: '/settings/agent-access/{client}/tokens/{token}/rotate',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Settings\AgentAccessController::rotate
* @see app/Http/Controllers/Settings/AgentAccessController.php:86
* @route '/settings/agent-access/{client}/tokens/{token}/rotate'
*/
rotate.url = (args: { client: string | number | { public_id: string | number }, token: string | number | { id: string | number } } | [client: string | number | { public_id: string | number }, token: string | number | { id: string | number } ], options?: RouteQueryOptions) => {
    if (Array.isArray(args)) {
        args = {
            client: args[0],
            token: args[1],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        client: typeof args.client === 'object'
        ? args.client.public_id
        : args.client,
        token: typeof args.token === 'object'
        ? args.token.id
        : args.token,
    }

    return rotate.definition.url
            .replace('{client}', parsedArgs.client.toString())
            .replace('{token}', parsedArgs.token.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Settings\AgentAccessController::rotate
* @see app/Http/Controllers/Settings/AgentAccessController.php:86
* @route '/settings/agent-access/{client}/tokens/{token}/rotate'
*/
rotate.post = (args: { client: string | number | { public_id: string | number }, token: string | number | { id: string | number } } | [client: string | number | { public_id: string | number }, token: string | number | { id: string | number } ], options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: rotate.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Settings\AgentAccessController::rotate
* @see app/Http/Controllers/Settings/AgentAccessController.php:86
* @route '/settings/agent-access/{client}/tokens/{token}/rotate'
*/
const rotateForm = (args: { client: string | number | { public_id: string | number }, token: string | number | { id: string | number } } | [client: string | number | { public_id: string | number }, token: string | number | { id: string | number } ], options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: rotate.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Settings\AgentAccessController::rotate
* @see app/Http/Controllers/Settings/AgentAccessController.php:86
* @route '/settings/agent-access/{client}/tokens/{token}/rotate'
*/
rotateForm.post = (args: { client: string | number | { public_id: string | number }, token: string | number | { id: string | number } } | [client: string | number | { public_id: string | number }, token: string | number | { id: string | number } ], options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: rotate.url(args, options),
    method: 'post',
})

rotate.form = rotateForm

/**
* @see \App\Http\Controllers\Settings\AgentAccessController::destroy
* @see app/Http/Controllers/Settings/AgentAccessController.php:109
* @route '/settings/agent-access/{client}/tokens/{token}'
*/
export const destroy = (args: { client: string | number | { public_id: string | number }, token: string | number | { id: string | number } } | [client: string | number | { public_id: string | number }, token: string | number | { id: string | number } ], options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: destroy.url(args, options),
    method: 'delete',
})

destroy.definition = {
    methods: ["delete"],
    url: '/settings/agent-access/{client}/tokens/{token}',
} satisfies RouteDefinition<["delete"]>

/**
* @see \App\Http\Controllers\Settings\AgentAccessController::destroy
* @see app/Http/Controllers/Settings/AgentAccessController.php:109
* @route '/settings/agent-access/{client}/tokens/{token}'
*/
destroy.url = (args: { client: string | number | { public_id: string | number }, token: string | number | { id: string | number } } | [client: string | number | { public_id: string | number }, token: string | number | { id: string | number } ], options?: RouteQueryOptions) => {
    if (Array.isArray(args)) {
        args = {
            client: args[0],
            token: args[1],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        client: typeof args.client === 'object'
        ? args.client.public_id
        : args.client,
        token: typeof args.token === 'object'
        ? args.token.id
        : args.token,
    }

    return destroy.definition.url
            .replace('{client}', parsedArgs.client.toString())
            .replace('{token}', parsedArgs.token.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Settings\AgentAccessController::destroy
* @see app/Http/Controllers/Settings/AgentAccessController.php:109
* @route '/settings/agent-access/{client}/tokens/{token}'
*/
destroy.delete = (args: { client: string | number | { public_id: string | number }, token: string | number | { id: string | number } } | [client: string | number | { public_id: string | number }, token: string | number | { id: string | number } ], options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: destroy.url(args, options),
    method: 'delete',
})

/**
* @see \App\Http\Controllers\Settings\AgentAccessController::destroy
* @see app/Http/Controllers/Settings/AgentAccessController.php:109
* @route '/settings/agent-access/{client}/tokens/{token}'
*/
const destroyForm = (args: { client: string | number | { public_id: string | number }, token: string | number | { id: string | number } } | [client: string | number | { public_id: string | number }, token: string | number | { id: string | number } ], options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: destroy.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'DELETE',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Settings\AgentAccessController::destroy
* @see app/Http/Controllers/Settings/AgentAccessController.php:109
* @route '/settings/agent-access/{client}/tokens/{token}'
*/
destroyForm.delete = (args: { client: string | number | { public_id: string | number }, token: string | number | { id: string | number } } | [client: string | number | { public_id: string | number }, token: string | number | { id: string | number } ], options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: destroy.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'DELETE',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'post',
})

destroy.form = destroyForm

const tokens = {
    store: Object.assign(store, store),
    rotate: Object.assign(rotate, rotate),
    destroy: Object.assign(destroy, destroy),
}

export default tokens