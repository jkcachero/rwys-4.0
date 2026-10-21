import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition, applyUrlDefaults } from './../../../wayfinder'
/**
* @see \App\Http\Controllers\AIConnectionController::index
* @see app/Http/Controllers/AIConnectionController.php:15
* @route '/workspace/connections'
*/
export const index = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(options),
    method: 'get',
})

index.definition = {
    methods: ["get","head"],
    url: '/workspace/connections',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\AIConnectionController::index
* @see app/Http/Controllers/AIConnectionController.php:15
* @route '/workspace/connections'
*/
index.url = (options?: RouteQueryOptions) => {
    return index.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\AIConnectionController::index
* @see app/Http/Controllers/AIConnectionController.php:15
* @route '/workspace/connections'
*/
index.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\AIConnectionController::index
* @see app/Http/Controllers/AIConnectionController.php:15
* @route '/workspace/connections'
*/
index.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: index.url(options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\AIConnectionController::index
* @see app/Http/Controllers/AIConnectionController.php:15
* @route '/workspace/connections'
*/
const indexForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: index.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\AIConnectionController::index
* @see app/Http/Controllers/AIConnectionController.php:15
* @route '/workspace/connections'
*/
indexForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: index.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\AIConnectionController::index
* @see app/Http/Controllers/AIConnectionController.php:15
* @route '/workspace/connections'
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
* @see \App\Http\Controllers\AIConnectionController::store
* @see app/Http/Controllers/AIConnectionController.php:20
* @route '/workspace/connections'
*/
export const store = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(options),
    method: 'post',
})

store.definition = {
    methods: ["post"],
    url: '/workspace/connections',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\AIConnectionController::store
* @see app/Http/Controllers/AIConnectionController.php:20
* @route '/workspace/connections'
*/
store.url = (options?: RouteQueryOptions) => {
    return store.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\AIConnectionController::store
* @see app/Http/Controllers/AIConnectionController.php:20
* @route '/workspace/connections'
*/
store.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\AIConnectionController::store
* @see app/Http/Controllers/AIConnectionController.php:20
* @route '/workspace/connections'
*/
const storeForm = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: store.url(options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\AIConnectionController::store
* @see app/Http/Controllers/AIConnectionController.php:20
* @route '/workspace/connections'
*/
storeForm.post = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: store.url(options),
    method: 'post',
})

store.form = storeForm

/**
* @see \App\Http\Controllers\AIConnectionController::destroy
* @see app/Http/Controllers/AIConnectionController.php:27
* @route '/workspace/connections/{connection}'
*/
export const destroy = (args: { connection: number | { id: number } } | [connection: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: destroy.url(args, options),
    method: 'delete',
})

destroy.definition = {
    methods: ["delete"],
    url: '/workspace/connections/{connection}',
} satisfies RouteDefinition<["delete"]>

/**
* @see \App\Http\Controllers\AIConnectionController::destroy
* @see app/Http/Controllers/AIConnectionController.php:27
* @route '/workspace/connections/{connection}'
*/
destroy.url = (args: { connection: number | { id: number } } | [connection: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { connection: args }
    }

    if (typeof args === 'object' && !Array.isArray(args) && 'id' in args) {
        args = { connection: args.id }
    }

    if (Array.isArray(args)) {
        args = {
            connection: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        connection: typeof args.connection === 'object'
        ? args.connection.id
        : args.connection,
    }

    return destroy.definition.url
            .replace('{connection}', parsedArgs.connection.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\AIConnectionController::destroy
* @see app/Http/Controllers/AIConnectionController.php:27
* @route '/workspace/connections/{connection}'
*/
destroy.delete = (args: { connection: number | { id: number } } | [connection: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: destroy.url(args, options),
    method: 'delete',
})

/**
* @see \App\Http\Controllers\AIConnectionController::destroy
* @see app/Http/Controllers/AIConnectionController.php:27
* @route '/workspace/connections/{connection}'
*/
const destroyForm = (args: { connection: number | { id: number } } | [connection: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: destroy.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'DELETE',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'post',
})

/**
* @see \App\Http\Controllers\AIConnectionController::destroy
* @see app/Http/Controllers/AIConnectionController.php:27
* @route '/workspace/connections/{connection}'
*/
destroyForm.delete = (args: { connection: number | { id: number } } | [connection: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: destroy.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'DELETE',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'post',
})

destroy.form = destroyForm

const connections = {
    index: Object.assign(index, index),
    store: Object.assign(store, store),
    destroy: Object.assign(destroy, destroy),
}

export default connections