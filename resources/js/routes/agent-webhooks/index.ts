import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition } from './../../wayfinder'
/**
* @see \App\Http\Controllers\AgentWebhookController::__invoke
* @see app/Http/Controllers/AgentWebhookController.php:15
* @route '/webhooks/zernio'
*/
export const zernio = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: zernio.url(options),
    method: 'post',
})

zernio.definition = {
    methods: ["post"],
    url: '/webhooks/zernio',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\AgentWebhookController::__invoke
* @see app/Http/Controllers/AgentWebhookController.php:15
* @route '/webhooks/zernio'
*/
zernio.url = (options?: RouteQueryOptions) => {
    return zernio.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\AgentWebhookController::__invoke
* @see app/Http/Controllers/AgentWebhookController.php:15
* @route '/webhooks/zernio'
*/
zernio.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: zernio.url(options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\AgentWebhookController::__invoke
* @see app/Http/Controllers/AgentWebhookController.php:15
* @route '/webhooks/zernio'
*/
const zernioForm = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: zernio.url(options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\AgentWebhookController::__invoke
* @see app/Http/Controllers/AgentWebhookController.php:15
* @route '/webhooks/zernio'
*/
zernioForm.post = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: zernio.url(options),
    method: 'post',
})

zernio.form = zernioForm

const agentWebhooks = {
    zernio: Object.assign(zernio, zernio),
}

export default agentWebhooks