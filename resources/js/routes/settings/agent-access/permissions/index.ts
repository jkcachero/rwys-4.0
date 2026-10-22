import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition, applyUrlDefaults } from './../../../../wayfinder'
/**
* @see \App\Http\Controllers\Settings\AgentAccessController::update
* @see app/Http/Controllers/Settings/AgentAccessController.php:56
* @route '/settings/agent-access/{client}/permissions/{permission}'
*/
export const update = (args: { client: string | number | { public_id: string | number }, permission: string | number | { id: string | number } } | [client: string | number | { public_id: string | number }, permission: string | number | { id: string | number } ], options?: RouteQueryOptions): RouteDefinition<'patch'> => ({
    url: update.url(args, options),
    method: 'patch',
})

update.definition = {
    methods: ["patch"],
    url: '/settings/agent-access/{client}/permissions/{permission}',
} satisfies RouteDefinition<["patch"]>

/**
* @see \App\Http\Controllers\Settings\AgentAccessController::update
* @see app/Http/Controllers/Settings/AgentAccessController.php:56
* @route '/settings/agent-access/{client}/permissions/{permission}'
*/
update.url = (args: { client: string | number | { public_id: string | number }, permission: string | number | { id: string | number } } | [client: string | number | { public_id: string | number }, permission: string | number | { id: string | number } ], options?: RouteQueryOptions) => {
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

    return update.definition.url
            .replace('{client}', parsedArgs.client.toString())
            .replace('{permission}', parsedArgs.permission.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Settings\AgentAccessController::update
* @see app/Http/Controllers/Settings/AgentAccessController.php:56
* @route '/settings/agent-access/{client}/permissions/{permission}'
*/
update.patch = (args: { client: string | number | { public_id: string | number }, permission: string | number | { id: string | number } } | [client: string | number | { public_id: string | number }, permission: string | number | { id: string | number } ], options?: RouteQueryOptions): RouteDefinition<'patch'> => ({
    url: update.url(args, options),
    method: 'patch',
})

/**
* @see \App\Http\Controllers\Settings\AgentAccessController::update
* @see app/Http/Controllers/Settings/AgentAccessController.php:56
* @route '/settings/agent-access/{client}/permissions/{permission}'
*/
const updateForm = (args: { client: string | number | { public_id: string | number }, permission: string | number | { id: string | number } } | [client: string | number | { public_id: string | number }, permission: string | number | { id: string | number } ], options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: update.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'PATCH',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Settings\AgentAccessController::update
* @see app/Http/Controllers/Settings/AgentAccessController.php:56
* @route '/settings/agent-access/{client}/permissions/{permission}'
*/
updateForm.patch = (args: { client: string | number | { public_id: string | number }, permission: string | number | { id: string | number } } | [client: string | number | { public_id: string | number }, permission: string | number | { id: string | number } ], options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: update.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'PATCH',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'post',
})

update.form = updateForm

const permissions = {
    update: Object.assign(update, update),
}

export default permissions