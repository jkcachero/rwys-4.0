import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition } from './../../wayfinder'
/**
* @see \App\Http\Controllers\WorkspaceController::prompt
* @see app/Http/Controllers/WorkspaceController.php:135
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
* @see app/Http/Controllers/WorkspaceController.php:135
* @route '/dashboard/prompt'
*/
prompt.url = (options?: RouteQueryOptions) => {
    return prompt.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\WorkspaceController::prompt
* @see app/Http/Controllers/WorkspaceController.php:135
* @route '/dashboard/prompt'
*/
prompt.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: prompt.url(options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\WorkspaceController::prompt
* @see app/Http/Controllers/WorkspaceController.php:135
* @route '/dashboard/prompt'
*/
const promptForm = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: prompt.url(options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\WorkspaceController::prompt
* @see app/Http/Controllers/WorkspaceController.php:135
* @route '/dashboard/prompt'
*/
promptForm.post = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: prompt.url(options),
    method: 'post',
})

prompt.form = promptForm

const dashboard = {
    prompt: Object.assign(prompt, prompt),
}

export default dashboard