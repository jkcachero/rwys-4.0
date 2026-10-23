import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition } from './../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Api\ZernioWebhookController::__invoke
* @see app/Http/Controllers/Api/ZernioWebhookController.php:17
* @route '/api/webhooks/zernio'
*/
const ZernioWebhookController = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: ZernioWebhookController.url(options),
    method: 'post',
})

ZernioWebhookController.definition = {
    methods: ["post"],
    url: '/api/webhooks/zernio',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Api\ZernioWebhookController::__invoke
* @see app/Http/Controllers/Api/ZernioWebhookController.php:17
* @route '/api/webhooks/zernio'
*/
ZernioWebhookController.url = (options?: RouteQueryOptions) => {
    return ZernioWebhookController.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Api\ZernioWebhookController::__invoke
* @see app/Http/Controllers/Api/ZernioWebhookController.php:17
* @route '/api/webhooks/zernio'
*/
ZernioWebhookController.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: ZernioWebhookController.url(options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Api\ZernioWebhookController::__invoke
* @see app/Http/Controllers/Api/ZernioWebhookController.php:17
* @route '/api/webhooks/zernio'
*/
const ZernioWebhookControllerForm = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: ZernioWebhookController.url(options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Api\ZernioWebhookController::__invoke
* @see app/Http/Controllers/Api/ZernioWebhookController.php:17
* @route '/api/webhooks/zernio'
*/
ZernioWebhookControllerForm.post = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: ZernioWebhookController.url(options),
    method: 'post',
})

ZernioWebhookController.form = ZernioWebhookControllerForm

export default ZernioWebhookController