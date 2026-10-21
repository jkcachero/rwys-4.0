import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition } from './../../../../wayfinder'
/**
* @see \App\Http\Controllers\WorkspaceController::__invoke
* @see app/Http/Controllers/WorkspaceController.php:23
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
* @see app/Http/Controllers/WorkspaceController.php:23
* @route '/dashboard'
*/
WorkspaceController.url = (options?: RouteQueryOptions) => {
    return WorkspaceController.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\WorkspaceController::__invoke
* @see app/Http/Controllers/WorkspaceController.php:23
* @route '/dashboard'
*/
WorkspaceController.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: WorkspaceController.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\WorkspaceController::__invoke
* @see app/Http/Controllers/WorkspaceController.php:23
* @route '/dashboard'
*/
WorkspaceController.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: WorkspaceController.url(options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\WorkspaceController::__invoke
* @see app/Http/Controllers/WorkspaceController.php:23
* @route '/dashboard'
*/
const WorkspaceControllerForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: WorkspaceController.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\WorkspaceController::__invoke
* @see app/Http/Controllers/WorkspaceController.php:23
* @route '/dashboard'
*/
WorkspaceControllerForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: WorkspaceController.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\WorkspaceController::__invoke
* @see app/Http/Controllers/WorkspaceController.php:23
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
* @see \App\Http\Controllers\WorkspaceController::prompt
* @see app/Http/Controllers/WorkspaceController.php:49
* @route '/dashboard/prompt'
*/
export const prompt = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: prompt.url(options),
    method: 'post',
})

prompt.definition = {
    methods: ["post"],
    url: '/dashboard/prompt',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\WorkspaceController::prompt
* @see app/Http/Controllers/WorkspaceController.php:49
* @route '/dashboard/prompt'
*/
prompt.url = (options?: RouteQueryOptions) => {
    return prompt.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\WorkspaceController::prompt
* @see app/Http/Controllers/WorkspaceController.php:49
* @route '/dashboard/prompt'
*/
prompt.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: prompt.url(options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\WorkspaceController::prompt
* @see app/Http/Controllers/WorkspaceController.php:49
* @route '/dashboard/prompt'
*/
const promptForm = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: prompt.url(options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\WorkspaceController::prompt
* @see app/Http/Controllers/WorkspaceController.php:49
* @route '/dashboard/prompt'
*/
promptForm.post = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: prompt.url(options),
    method: 'post',
})

prompt.form = promptForm

/**
* @see \App\Http\Controllers\WorkspaceController::read
* @see app/Http/Controllers/WorkspaceController.php:30
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
* @see app/Http/Controllers/WorkspaceController.php:30
* @route '/workspace/read'
*/
read.url = (options?: RouteQueryOptions) => {
    return read.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\WorkspaceController::read
* @see app/Http/Controllers/WorkspaceController.php:30
* @route '/workspace/read'
*/
read.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: read.url(options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\WorkspaceController::read
* @see app/Http/Controllers/WorkspaceController.php:30
* @route '/workspace/read'
*/
const readForm = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: read.url(options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\WorkspaceController::read
* @see app/Http/Controllers/WorkspaceController.php:30
* @route '/workspace/read'
*/
readForm.post = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: read.url(options),
    method: 'post',
})

read.form = readForm

WorkspaceController.prompt = prompt
WorkspaceController.read = read

export default WorkspaceController