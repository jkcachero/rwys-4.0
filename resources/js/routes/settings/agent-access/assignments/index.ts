import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition, applyUrlDefaults } from './../../../../wayfinder'
/**
* @see \App\Http\Controllers\Settings\AgentProfileAssignmentController::profiles
* @see app/Http/Controllers/Settings/AgentProfileAssignmentController.php:18
* @route '/settings/agent-access/{client}/provider-profiles'
*/
export const profiles = (args: { client: string | number | { public_id: string | number } } | [client: string | number | { public_id: string | number } ] | string | number | { public_id: string | number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: profiles.url(args, options),
    method: 'get',
})

profiles.definition = {
    methods: ["get","head"],
    url: '/settings/agent-access/{client}/provider-profiles',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Settings\AgentProfileAssignmentController::profiles
* @see app/Http/Controllers/Settings/AgentProfileAssignmentController.php:18
* @route '/settings/agent-access/{client}/provider-profiles'
*/
profiles.url = (args: { client: string | number | { public_id: string | number } } | [client: string | number | { public_id: string | number } ] | string | number | { public_id: string | number }, options?: RouteQueryOptions) => {
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

    return profiles.definition.url
            .replace('{client}', parsedArgs.client.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Settings\AgentProfileAssignmentController::profiles
* @see app/Http/Controllers/Settings/AgentProfileAssignmentController.php:18
* @route '/settings/agent-access/{client}/provider-profiles'
*/
profiles.get = (args: { client: string | number | { public_id: string | number } } | [client: string | number | { public_id: string | number } ] | string | number | { public_id: string | number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: profiles.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Settings\AgentProfileAssignmentController::profiles
* @see app/Http/Controllers/Settings/AgentProfileAssignmentController.php:18
* @route '/settings/agent-access/{client}/provider-profiles'
*/
profiles.head = (args: { client: string | number | { public_id: string | number } } | [client: string | number | { public_id: string | number } ] | string | number | { public_id: string | number }, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: profiles.url(args, options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\Settings\AgentProfileAssignmentController::profiles
* @see app/Http/Controllers/Settings/AgentProfileAssignmentController.php:18
* @route '/settings/agent-access/{client}/provider-profiles'
*/
const profilesForm = (args: { client: string | number | { public_id: string | number } } | [client: string | number | { public_id: string | number } ] | string | number | { public_id: string | number }, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: profiles.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Settings\AgentProfileAssignmentController::profiles
* @see app/Http/Controllers/Settings/AgentProfileAssignmentController.php:18
* @route '/settings/agent-access/{client}/provider-profiles'
*/
profilesForm.get = (args: { client: string | number | { public_id: string | number } } | [client: string | number | { public_id: string | number } ] | string | number | { public_id: string | number }, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: profiles.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Settings\AgentProfileAssignmentController::profiles
* @see app/Http/Controllers/Settings/AgentProfileAssignmentController.php:18
* @route '/settings/agent-access/{client}/provider-profiles'
*/
profilesForm.head = (args: { client: string | number | { public_id: string | number } } | [client: string | number | { public_id: string | number } ] | string | number | { public_id: string | number }, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: profiles.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

profiles.form = profilesForm

/**
* @see \App\Http\Controllers\Settings\AgentProfileAssignmentController::store
* @see app/Http/Controllers/Settings/AgentProfileAssignmentController.php:31
* @route '/settings/agent-access/{client}/provider-profiles'
*/
export const store = (args: { client: string | number | { public_id: string | number } } | [client: string | number | { public_id: string | number } ] | string | number | { public_id: string | number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(args, options),
    method: 'post',
})

store.definition = {
    methods: ["post"],
    url: '/settings/agent-access/{client}/provider-profiles',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Settings\AgentProfileAssignmentController::store
* @see app/Http/Controllers/Settings/AgentProfileAssignmentController.php:31
* @route '/settings/agent-access/{client}/provider-profiles'
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
* @see \App\Http\Controllers\Settings\AgentProfileAssignmentController::store
* @see app/Http/Controllers/Settings/AgentProfileAssignmentController.php:31
* @route '/settings/agent-access/{client}/provider-profiles'
*/
store.post = (args: { client: string | number | { public_id: string | number } } | [client: string | number | { public_id: string | number } ] | string | number | { public_id: string | number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Settings\AgentProfileAssignmentController::store
* @see app/Http/Controllers/Settings/AgentProfileAssignmentController.php:31
* @route '/settings/agent-access/{client}/provider-profiles'
*/
const storeForm = (args: { client: string | number | { public_id: string | number } } | [client: string | number | { public_id: string | number } ] | string | number | { public_id: string | number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: store.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Settings\AgentProfileAssignmentController::store
* @see app/Http/Controllers/Settings/AgentProfileAssignmentController.php:31
* @route '/settings/agent-access/{client}/provider-profiles'
*/
storeForm.post = (args: { client: string | number | { public_id: string | number } } | [client: string | number | { public_id: string | number } ] | string | number | { public_id: string | number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: store.url(args, options),
    method: 'post',
})

store.form = storeForm

/**
* @see \App\Http\Controllers\Settings\AgentProfileAssignmentController::sync
* @see app/Http/Controllers/Settings/AgentProfileAssignmentController.php:43
* @route '/settings/agent-access/{client}/provider-profiles/{profile}/sync'
*/
export const sync = (args: { client: string | number | { public_id: string | number }, profile: string | number | { public_id: string | number } } | [client: string | number | { public_id: string | number }, profile: string | number | { public_id: string | number } ], options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: sync.url(args, options),
    method: 'post',
})

sync.definition = {
    methods: ["post"],
    url: '/settings/agent-access/{client}/provider-profiles/{profile}/sync',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Settings\AgentProfileAssignmentController::sync
* @see app/Http/Controllers/Settings/AgentProfileAssignmentController.php:43
* @route '/settings/agent-access/{client}/provider-profiles/{profile}/sync'
*/
sync.url = (args: { client: string | number | { public_id: string | number }, profile: string | number | { public_id: string | number } } | [client: string | number | { public_id: string | number }, profile: string | number | { public_id: string | number } ], options?: RouteQueryOptions) => {
    if (Array.isArray(args)) {
        args = {
            client: args[0],
            profile: args[1],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        client: typeof args.client === 'object'
        ? args.client.public_id
        : args.client,
        profile: typeof args.profile === 'object'
        ? args.profile.public_id
        : args.profile,
    }

    return sync.definition.url
            .replace('{client}', parsedArgs.client.toString())
            .replace('{profile}', parsedArgs.profile.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Settings\AgentProfileAssignmentController::sync
* @see app/Http/Controllers/Settings/AgentProfileAssignmentController.php:43
* @route '/settings/agent-access/{client}/provider-profiles/{profile}/sync'
*/
sync.post = (args: { client: string | number | { public_id: string | number }, profile: string | number | { public_id: string | number } } | [client: string | number | { public_id: string | number }, profile: string | number | { public_id: string | number } ], options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: sync.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Settings\AgentProfileAssignmentController::sync
* @see app/Http/Controllers/Settings/AgentProfileAssignmentController.php:43
* @route '/settings/agent-access/{client}/provider-profiles/{profile}/sync'
*/
const syncForm = (args: { client: string | number | { public_id: string | number }, profile: string | number | { public_id: string | number } } | [client: string | number | { public_id: string | number }, profile: string | number | { public_id: string | number } ], options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: sync.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Settings\AgentProfileAssignmentController::sync
* @see app/Http/Controllers/Settings/AgentProfileAssignmentController.php:43
* @route '/settings/agent-access/{client}/provider-profiles/{profile}/sync'
*/
syncForm.post = (args: { client: string | number | { public_id: string | number }, profile: string | number | { public_id: string | number } } | [client: string | number | { public_id: string | number }, profile: string | number | { public_id: string | number } ], options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: sync.url(args, options),
    method: 'post',
})

sync.form = syncForm

const assignments = {
    profiles: Object.assign(profiles, profiles),
    store: Object.assign(store, store),
    sync: Object.assign(sync, sync),
}

export default assignments