import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition } from './../../../wayfinder'
/**
* @see \App\Http\Controllers\Api\ZernioWebhookController::__invoke
* @see app/Http/Controllers/Api/ZernioWebhookController.php:17
* @route '/api/webhooks/zernio'
*/
export const zernio = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: zernio.url(options),
    method: 'post',
})

zernio.definition = {
    methods: ["post"],
    url: '/api/webhooks/zernio',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Api\ZernioWebhookController::__invoke
* @see app/Http/Controllers/Api/ZernioWebhookController.php:17
* @route '/api/webhooks/zernio'
*/
zernio.url = (options?: RouteQueryOptions) => {
    return zernio.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Api\ZernioWebhookController::__invoke
* @see app/Http/Controllers/Api/ZernioWebhookController.php:17
* @route '/api/webhooks/zernio'
*/
zernio.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: zernio.url(options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Api\ZernioWebhookController::__invoke
* @see app/Http/Controllers/Api/ZernioWebhookController.php:17
* @route '/api/webhooks/zernio'
*/
const zernioForm = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: zernio.url(options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Api\ZernioWebhookController::__invoke
* @see app/Http/Controllers/Api/ZernioWebhookController.php:17
* @route '/api/webhooks/zernio'
*/
zernioForm.post = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: zernio.url(options),
    method: 'post',
})

zernio.form = zernioForm

const webhooks = {
    zernio: Object.assign(zernio, zernio),
}

export default webhooks