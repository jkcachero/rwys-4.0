import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition } from './../../wayfinder'
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

const workspace = {
    read: Object.assign(read, read),
}

export default workspace