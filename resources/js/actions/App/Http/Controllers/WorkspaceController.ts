import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition } from './../../../../wayfinder'
/**
* @see \App\Http\Controllers\WorkspaceController::__invoke
* @see app/Http/Controllers/WorkspaceController.php:16
* @route '/dashboard'
*/
const WorkspaceController = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: WorkspaceController.url(options),
    method: 'get',
})

WorkspaceController.definition = {
    methods: ["get","head"],
    url: '/dashboard',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\WorkspaceController::__invoke
* @see app/Http/Controllers/WorkspaceController.php:16
* @route '/dashboard'
*/
WorkspaceController.url = (options?: RouteQueryOptions) => {
    return WorkspaceController.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\WorkspaceController::__invoke
* @see app/Http/Controllers/WorkspaceController.php:16
* @route '/dashboard'
*/
WorkspaceController.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: WorkspaceController.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\WorkspaceController::__invoke
* @see app/Http/Controllers/WorkspaceController.php:16
* @route '/dashboard'
*/
WorkspaceController.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: WorkspaceController.url(options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\WorkspaceController::__invoke
* @see app/Http/Controllers/WorkspaceController.php:16
* @route '/dashboard'
*/
const WorkspaceControllerForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: WorkspaceController.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\WorkspaceController::__invoke
* @see app/Http/Controllers/WorkspaceController.php:16
* @route '/dashboard'
*/
WorkspaceControllerForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: WorkspaceController.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\WorkspaceController::__invoke
* @see app/Http/Controllers/WorkspaceController.php:16
* @route '/dashboard'
*/
WorkspaceControllerForm.head = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: WorkspaceController.url({
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

WorkspaceController.form = WorkspaceControllerForm

/**
* @see \App\Http\Controllers\WorkspaceController::read
* @see app/Http/Controllers/WorkspaceController.php:23
* @route '/workspace/read'
*/
export const read = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: read.url(options),
    method: 'post',
})

read.definition = {
    methods: ["post"],
    url: '/workspace/read',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\WorkspaceController::read
* @see app/Http/Controllers/WorkspaceController.php:23
* @route '/workspace/read'
*/
read.url = (options?: RouteQueryOptions) => {
    return read.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\WorkspaceController::read
* @see app/Http/Controllers/WorkspaceController.php:23
* @route '/workspace/read'
*/
read.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: read.url(options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\WorkspaceController::read
* @see app/Http/Controllers/WorkspaceController.php:23
* @route '/workspace/read'
*/
const readForm = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: read.url(options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\WorkspaceController::read
* @see app/Http/Controllers/WorkspaceController.php:23
* @route '/workspace/read'
*/
readForm.post = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: read.url(options),
    method: 'post',
})

read.form = readForm

WorkspaceController.read = read

export default WorkspaceController