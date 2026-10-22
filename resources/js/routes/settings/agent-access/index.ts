import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition } from './../../../wayfinder'
import permissions from './permissions'
import tokens from './tokens'
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

const agentAccess = {
    edit: Object.assign(edit, edit),
    permissions: Object.assign(permissions, permissions),
    tokens: Object.assign(tokens, tokens),
}

export default agentAccess