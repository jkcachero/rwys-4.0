import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition } from './../../wayfinder'
/**
* @see \App\Http\Controllers\Settings\ProviderAccountAssignmentController::index
* @see app/Http/Controllers/Settings/ProviderAccountAssignmentController.php:21
* @route '/settings/provider-accounts'
*/
export const index = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(options),
    method: 'get',
})

index.definition = {
    methods: ["get","head"],
    url: '/settings/provider-accounts',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Settings\ProviderAccountAssignmentController::index
* @see app/Http/Controllers/Settings/ProviderAccountAssignmentController.php:21
* @route '/settings/provider-accounts'
*/
index.url = (options?: RouteQueryOptions) => {
    return index.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Settings\ProviderAccountAssignmentController::index
* @see app/Http/Controllers/Settings/ProviderAccountAssignmentController.php:21
* @route '/settings/provider-accounts'
*/
index.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Settings\ProviderAccountAssignmentController::index
* @see app/Http/Controllers/Settings/ProviderAccountAssignmentController.php:21
* @route '/settings/provider-accounts'
*/
index.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: index.url(options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\Settings\ProviderAccountAssignmentController::index
* @see app/Http/Controllers/Settings/ProviderAccountAssignmentController.php:21
* @route '/settings/provider-accounts'
*/
const indexForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: index.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Settings\ProviderAccountAssignmentController::index
* @see app/Http/Controllers/Settings/ProviderAccountAssignmentController.php:21
* @route '/settings/provider-accounts'
*/
indexForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: index.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Settings\ProviderAccountAssignmentController::index
* @see app/Http/Controllers/Settings/ProviderAccountAssignmentController.php:21
* @route '/settings/provider-accounts'
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
* @see \App\Http\Controllers\Settings\ProviderAccountAssignmentController::clients
* @see app/Http/Controllers/Settings/ProviderAccountAssignmentController.php:49
* @route '/settings/provider-accounts/clients'
*/
export const clients = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: clients.url(options),
    method: 'get',
})

clients.definition = {
    methods: ["get","head"],
    url: '/settings/provider-accounts/clients',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Settings\ProviderAccountAssignmentController::clients
* @see app/Http/Controllers/Settings/ProviderAccountAssignmentController.php:49
* @route '/settings/provider-accounts/clients'
*/
clients.url = (options?: RouteQueryOptions) => {
    return clients.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Settings\ProviderAccountAssignmentController::clients
* @see app/Http/Controllers/Settings/ProviderAccountAssignmentController.php:49
* @route '/settings/provider-accounts/clients'
*/
clients.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: clients.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Settings\ProviderAccountAssignmentController::clients
* @see app/Http/Controllers/Settings/ProviderAccountAssignmentController.php:49
* @route '/settings/provider-accounts/clients'
*/
clients.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: clients.url(options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\Settings\ProviderAccountAssignmentController::clients
* @see app/Http/Controllers/Settings/ProviderAccountAssignmentController.php:49
* @route '/settings/provider-accounts/clients'
*/
const clientsForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: clients.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Settings\ProviderAccountAssignmentController::clients
* @see app/Http/Controllers/Settings/ProviderAccountAssignmentController.php:49
* @route '/settings/provider-accounts/clients'
*/
clientsForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: clients.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Settings\ProviderAccountAssignmentController::clients
* @see app/Http/Controllers/Settings/ProviderAccountAssignmentController.php:49
* @route '/settings/provider-accounts/clients'
*/
clientsForm.head = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: clients.url({
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

clients.form = clientsForm

/**
* @see \App\Http\Controllers\Settings\ProviderAccountAssignmentController::discover
* @see app/Http/Controllers/Settings/ProviderAccountAssignmentController.php:58
* @route '/settings/provider-accounts/discover'
*/
export const discover = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: discover.url(options),
    method: 'get',
})

discover.definition = {
    methods: ["get","head"],
    url: '/settings/provider-accounts/discover',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Settings\ProviderAccountAssignmentController::discover
* @see app/Http/Controllers/Settings/ProviderAccountAssignmentController.php:58
* @route '/settings/provider-accounts/discover'
*/
discover.url = (options?: RouteQueryOptions) => {
    return discover.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Settings\ProviderAccountAssignmentController::discover
* @see app/Http/Controllers/Settings/ProviderAccountAssignmentController.php:58
* @route '/settings/provider-accounts/discover'
*/
discover.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: discover.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Settings\ProviderAccountAssignmentController::discover
* @see app/Http/Controllers/Settings/ProviderAccountAssignmentController.php:58
* @route '/settings/provider-accounts/discover'
*/
discover.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: discover.url(options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\Settings\ProviderAccountAssignmentController::discover
* @see app/Http/Controllers/Settings/ProviderAccountAssignmentController.php:58
* @route '/settings/provider-accounts/discover'
*/
const discoverForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: discover.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Settings\ProviderAccountAssignmentController::discover
* @see app/Http/Controllers/Settings/ProviderAccountAssignmentController.php:58
* @route '/settings/provider-accounts/discover'
*/
discoverForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: discover.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Settings\ProviderAccountAssignmentController::discover
* @see app/Http/Controllers/Settings/ProviderAccountAssignmentController.php:58
* @route '/settings/provider-accounts/discover'
*/
discoverForm.head = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: discover.url({
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

discover.form = discoverForm

/**
* @see \App\Http\Controllers\Settings\ProviderAccountAssignmentController::store
* @see app/Http/Controllers/Settings/ProviderAccountAssignmentController.php:70
* @route '/settings/provider-accounts'
*/
export const store = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(options),
    method: 'post',
})

store.definition = {
    methods: ["post"],
    url: '/settings/provider-accounts',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Settings\ProviderAccountAssignmentController::store
* @see app/Http/Controllers/Settings/ProviderAccountAssignmentController.php:70
* @route '/settings/provider-accounts'
*/
store.url = (options?: RouteQueryOptions) => {
    return store.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Settings\ProviderAccountAssignmentController::store
* @see app/Http/Controllers/Settings/ProviderAccountAssignmentController.php:70
* @route '/settings/provider-accounts'
*/
store.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Settings\ProviderAccountAssignmentController::store
* @see app/Http/Controllers/Settings/ProviderAccountAssignmentController.php:70
* @route '/settings/provider-accounts'
*/
const storeForm = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: store.url(options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Settings\ProviderAccountAssignmentController::store
* @see app/Http/Controllers/Settings/ProviderAccountAssignmentController.php:70
* @route '/settings/provider-accounts'
*/
storeForm.post = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: store.url(options),
    method: 'post',
})

store.form = storeForm

const providerAccounts = {
    index: Object.assign(index, index),
    clients: Object.assign(clients, clients),
    discover: Object.assign(discover, discover),
    store: Object.assign(store, store),
}

export default providerAccounts