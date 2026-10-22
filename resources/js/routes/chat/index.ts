import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition } from './../../wayfinder'
import conversations from './conversations'
/**
* @see \App\Http\Controllers\WorkspaceController::models
* @see app/Http/Controllers/WorkspaceController.php:87
* @route '/chat/models'
*/
export const models = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: models.url(options),
    method: 'get',
})

models.definition = {
    methods: ["get","head"],
    url: '/chat/models',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\WorkspaceController::models
* @see app/Http/Controllers/WorkspaceController.php:87
* @route '/chat/models'
*/
models.url = (options?: RouteQueryOptions) => {
    return models.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\WorkspaceController::models
* @see app/Http/Controllers/WorkspaceController.php:87
* @route '/chat/models'
*/
models.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: models.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\WorkspaceController::models
* @see app/Http/Controllers/WorkspaceController.php:87
* @route '/chat/models'
*/
models.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: models.url(options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\WorkspaceController::models
* @see app/Http/Controllers/WorkspaceController.php:87
* @route '/chat/models'
*/
const modelsForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: models.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\WorkspaceController::models
* @see app/Http/Controllers/WorkspaceController.php:87
* @route '/chat/models'
*/
modelsForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: models.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\WorkspaceController::models
* @see app/Http/Controllers/WorkspaceController.php:87
* @route '/chat/models'
*/
modelsForm.head = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: models.url({
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

models.form = modelsForm

const chat = {
    models: Object.assign(models, models),
    conversations: Object.assign(conversations, conversations),
}

export default chat