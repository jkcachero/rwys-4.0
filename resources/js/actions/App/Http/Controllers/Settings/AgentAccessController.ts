import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition, applyUrlDefaults } from './../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Settings\AgentAccessController::edit
* @see app/Http/Controllers/Settings/AgentAccessController.php:22
* @route '/settings/agent-access'
*/
export const edit = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: edit.url(options),
    method: 'get',
})

edit.definition = {
    methods: ["get","head"],
    url: '/settings/agent-access',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Settings\AgentAccessController::edit
* @see app/Http/Controllers/Settings/AgentAccessController.php:22
* @route '/settings/agent-access'
*/
edit.url = (options?: RouteQueryOptions) => {
    return edit.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Settings\AgentAccessController::edit
* @see app/Http/Controllers/Settings/AgentAccessController.php:22
* @route '/settings/agent-access'
*/
edit.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: edit.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Settings\AgentAccessController::edit
* @see app/Http/Controllers/Settings/AgentAccessController.php:22
* @route '/settings/agent-access'
*/
edit.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: edit.url(options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\Settings\AgentAccessController::edit
* @see app/Http/Controllers/Settings/AgentAccessController.php:22
* @route '/settings/agent-access'
*/
const editForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: edit.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Settings\AgentAccessController::edit
* @see app/Http/Controllers/Settings/AgentAccessController.php:22
* @route '/settings/agent-access'
*/
editForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: edit.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Settings\AgentAccessController::edit
* @see app/Http/Controllers/Settings/AgentAccessController.php:22
* @route '/settings/agent-access'
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
* @see \App\Http\Controllers\Settings\AgentAccessController::updatePermission
* @see app/Http/Controllers/Settings/AgentAccessController.php:66
* @route '/settings/agent-access/{client}/permissions/{permission}'
*/
export const updatePermission = (args: { client: string | number | { public_id: string | number }, permission: string | number | { id: string | number } } | [client: string | number | { public_id: string | number }, permission: string | number | { id: string | number } ], options?: RouteQueryOptions): RouteDefinition<'patch'> => ({
    url: updatePermission.url(args, options),
    method: 'patch',
})

updatePermission.definition = {
    methods: ["patch"],
    url: '/settings/agent-access/{client}/permissions/{permission}',
} satisfies RouteDefinition<["patch"]>

/**
* @see \App\Http\Controllers\Settings\AgentAccessController::updatePermission
* @see app/Http/Controllers/Settings/AgentAccessController.php:66
* @route '/settings/agent-access/{client}/permissions/{permission}'
*/
updatePermission.url = (args: { client: string | number | { public_id: string | number }, permission: string | number | { id: string | number } } | [client: string | number | { public_id: string | number }, permission: string | number | { id: string | number } ], options?: RouteQueryOptions) => {
    if (Array.isArray(args)) {
        args = {
            client: args[0],
            permission: args[1],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        client: typeof args.client === 'object'
        ? args.client.public_id
        : args.client,
        permission: typeof args.permission === 'object'
        ? args.permission.id
        : args.permission,
    }

    return updatePermission.definition.url
            .replace('{client}', parsedArgs.client.toString())
            .replace('{permission}', parsedArgs.permission.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Settings\AgentAccessController::updatePermission
* @see app/Http/Controllers/Settings/AgentAccessController.php:66
* @route '/settings/agent-access/{client}/permissions/{permission}'
*/
updatePermission.patch = (args: { client: string | number | { public_id: string | number }, permission: string | number | { id: string | number } } | [client: string | number | { public_id: string | number }, permission: string | number | { id: string | number } ], options?: RouteQueryOptions): RouteDefinition<'patch'> => ({
    url: updatePermission.url(args, options),
    method: 'patch',
})

/**
* @see \App\Http\Controllers\Settings\AgentAccessController::updatePermission
* @see app/Http/Controllers/Settings/AgentAccessController.php:66
* @route '/settings/agent-access/{client}/permissions/{permission}'
*/
const updatePermissionForm = (args: { client: string | number | { public_id: string | number }, permission: string | number | { id: string | number } } | [client: string | number | { public_id: string | number }, permission: string | number | { id: string | number } ], options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: updatePermission.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'PATCH',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Settings\AgentAccessController::updatePermission
* @see app/Http/Controllers/Settings/AgentAccessController.php:66
* @route '/settings/agent-access/{client}/permissions/{permission}'
*/
updatePermissionForm.patch = (args: { client: string | number | { public_id: string | number }, permission: string | number | { id: string | number } } | [client: string | number | { public_id: string | number }, permission: string | number | { id: string | number } ], options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: updatePermission.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'PATCH',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'post',
})

updatePermission.form = updatePermissionForm

/**
* @see \App\Http\Controllers\Settings\AgentAccessController::storeToken
* @see app/Http/Controllers/Settings/AgentAccessController.php:76
* @route '/settings/agent-access/{client}/tokens'
*/
export const storeToken = (args: { client: string | number | { public_id: string | number } } | [client: string | number | { public_id: string | number } ] | string | number | { public_id: string | number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: storeToken.url(args, options),
    method: 'post',
})

storeToken.definition = {
    methods: ["post"],
    url: '/settings/agent-access/{client}/tokens',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Settings\AgentAccessController::storeToken
* @see app/Http/Controllers/Settings/AgentAccessController.php:76
* @route '/settings/agent-access/{client}/tokens'
*/
storeToken.url = (args: { client: string | number | { public_id: string | number } } | [client: string | number | { public_id: string | number } ] | string | number | { public_id: string | number }, options?: RouteQueryOptions) => {
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

    return storeToken.definition.url
            .replace('{client}', parsedArgs.client.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Settings\AgentAccessController::storeToken
* @see app/Http/Controllers/Settings/AgentAccessController.php:76
* @route '/settings/agent-access/{client}/tokens'
*/
storeToken.post = (args: { client: string | number | { public_id: string | number } } | [client: string | number | { public_id: string | number } ] | string | number | { public_id: string | number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: storeToken.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Settings\AgentAccessController::storeToken
* @see app/Http/Controllers/Settings/AgentAccessController.php:76
* @route '/settings/agent-access/{client}/tokens'
*/
const storeTokenForm = (args: { client: string | number | { public_id: string | number } } | [client: string | number | { public_id: string | number } ] | string | number | { public_id: string | number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: storeToken.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Settings\AgentAccessController::storeToken
* @see app/Http/Controllers/Settings/AgentAccessController.php:76
* @route '/settings/agent-access/{client}/tokens'
*/
storeTokenForm.post = (args: { client: string | number | { public_id: string | number } } | [client: string | number | { public_id: string | number } ] | string | number | { public_id: string | number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: storeToken.url(args, options),
    method: 'post',
})

storeToken.form = storeTokenForm

/**
* @see \App\Http\Controllers\Settings\AgentAccessController::rotateToken
* @see app/Http/Controllers/Settings/AgentAccessController.php:96
* @route '/settings/agent-access/{client}/tokens/{token}/rotate'
*/
export const rotateToken = (args: { client: string | number | { public_id: string | number }, token: string | number | { id: string | number } } | [client: string | number | { public_id: string | number }, token: string | number | { id: string | number } ], options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: rotateToken.url(args, options),
    method: 'post',
})

rotateToken.definition = {
    methods: ["post"],
    url: '/settings/agent-access/{client}/tokens/{token}/rotate',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Settings\AgentAccessController::rotateToken
* @see app/Http/Controllers/Settings/AgentAccessController.php:96
* @route '/settings/agent-access/{client}/tokens/{token}/rotate'
*/
rotateToken.url = (args: { client: string | number | { public_id: string | number }, token: string | number | { id: string | number } } | [client: string | number | { public_id: string | number }, token: string | number | { id: string | number } ], options?: RouteQueryOptions) => {
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

    return rotateToken.definition.url
            .replace('{client}', parsedArgs.client.toString())
            .replace('{token}', parsedArgs.token.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Settings\AgentAccessController::rotateToken
* @see app/Http/Controllers/Settings/AgentAccessController.php:96
* @route '/settings/agent-access/{client}/tokens/{token}/rotate'
*/
rotateToken.post = (args: { client: string | number | { public_id: string | number }, token: string | number | { id: string | number } } | [client: string | number | { public_id: string | number }, token: string | number | { id: string | number } ], options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: rotateToken.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Settings\AgentAccessController::rotateToken
* @see app/Http/Controllers/Settings/AgentAccessController.php:96
* @route '/settings/agent-access/{client}/tokens/{token}/rotate'
*/
const rotateTokenForm = (args: { client: string | number | { public_id: string | number }, token: string | number | { id: string | number } } | [client: string | number | { public_id: string | number }, token: string | number | { id: string | number } ], options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: rotateToken.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Settings\AgentAccessController::rotateToken
* @see app/Http/Controllers/Settings/AgentAccessController.php:96
* @route '/settings/agent-access/{client}/tokens/{token}/rotate'
*/
rotateTokenForm.post = (args: { client: string | number | { public_id: string | number }, token: string | number | { id: string | number } } | [client: string | number | { public_id: string | number }, token: string | number | { id: string | number } ], options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: rotateToken.url(args, options),
    method: 'post',
})

rotateToken.form = rotateTokenForm

/**
* @see \App\Http\Controllers\Settings\AgentAccessController::destroyToken
* @see app/Http/Controllers/Settings/AgentAccessController.php:119
* @route '/settings/agent-access/{client}/tokens/{token}'
*/
export const destroyToken = (args: { client: string | number | { public_id: string | number }, token: string | number | { id: string | number } } | [client: string | number | { public_id: string | number }, token: string | number | { id: string | number } ], options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: destroyToken.url(args, options),
    method: 'delete',
})

destroyToken.definition = {
    methods: ["delete"],
    url: '/settings/agent-access/{client}/tokens/{token}',
} satisfies RouteDefinition<["delete"]>

/**
* @see \App\Http\Controllers\Settings\AgentAccessController::destroyToken
* @see app/Http/Controllers/Settings/AgentAccessController.php:119
* @route '/settings/agent-access/{client}/tokens/{token}'
*/
destroyToken.url = (args: { client: string | number | { public_id: string | number }, token: string | number | { id: string | number } } | [client: string | number | { public_id: string | number }, token: string | number | { id: string | number } ], options?: RouteQueryOptions) => {
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

    return destroyToken.definition.url
            .replace('{client}', parsedArgs.client.toString())
            .replace('{token}', parsedArgs.token.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Settings\AgentAccessController::destroyToken
* @see app/Http/Controllers/Settings/AgentAccessController.php:119
* @route '/settings/agent-access/{client}/tokens/{token}'
*/
destroyToken.delete = (args: { client: string | number | { public_id: string | number }, token: string | number | { id: string | number } } | [client: string | number | { public_id: string | number }, token: string | number | { id: string | number } ], options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: destroyToken.url(args, options),
    method: 'delete',
})

/**
* @see \App\Http\Controllers\Settings\AgentAccessController::destroyToken
* @see app/Http/Controllers/Settings/AgentAccessController.php:119
* @route '/settings/agent-access/{client}/tokens/{token}'
*/
const destroyTokenForm = (args: { client: string | number | { public_id: string | number }, token: string | number | { id: string | number } } | [client: string | number | { public_id: string | number }, token: string | number | { id: string | number } ], options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: destroyToken.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'DELETE',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Settings\AgentAccessController::destroyToken
* @see app/Http/Controllers/Settings/AgentAccessController.php:119
* @route '/settings/agent-access/{client}/tokens/{token}'
*/
destroyTokenForm.delete = (args: { client: string | number | { public_id: string | number }, token: string | number | { id: string | number } } | [client: string | number | { public_id: string | number }, token: string | number | { id: string | number } ], options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: destroyToken.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'DELETE',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'post',
})

destroyToken.form = destroyTokenForm

const AgentAccessController = { edit, updatePermission, storeToken, rotateToken, destroyToken }

export default AgentAccessController