import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition } from './../../../../wayfinder'
/**
* @see \App\Http\Controllers\AgentWebhookController::__invoke
* @see app/Http/Controllers/AgentWebhookController.php:15
* @route '/webhooks/zernio'
*/
const AgentWebhookController = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: AgentWebhookController.url(options),
    method: 'post',
})

AgentWebhookController.definition = {
    methods: ["post"],
    url: '/webhooks/zernio',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\AgentWebhookController::__invoke
* @see app/Http/Controllers/AgentWebhookController.php:15
* @route '/webhooks/zernio'
*/
AgentWebhookController.url = (options?: RouteQueryOptions) => {
    return AgentWebhookController.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\AgentWebhookController::__invoke
* @see app/Http/Controllers/AgentWebhookController.php:15
* @route '/webhooks/zernio'
*/
AgentWebhookController.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: AgentWebhookController.url(options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\AgentWebhookController::__invoke
* @see app/Http/Controllers/AgentWebhookController.php:15
* @route '/webhooks/zernio'
*/
const AgentWebhookControllerForm = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: AgentWebhookController.url(options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\AgentWebhookController::__invoke
* @see app/Http/Controllers/AgentWebhookController.php:15
* @route '/webhooks/zernio'
*/
AgentWebhookControllerForm.post = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: AgentWebhookController.url(options),
    method: 'post',
})

AgentWebhookController.form = AgentWebhookControllerForm

export default AgentWebhookController