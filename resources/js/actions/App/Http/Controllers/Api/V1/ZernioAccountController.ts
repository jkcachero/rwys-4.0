import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition } from './../../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Api\V1\ZernioAccountController::index
* @see app/Http/Controllers/Api/V1/ZernioAccountController.php:13
* @route '/api/v1/zernio/accounts'
*/
export const index = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(options),
    method: 'get',
})

index.definition = {
    methods: ["get","head"],
    url: '/api/v1/zernio/accounts',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Api\V1\ZernioAccountController::index
* @see app/Http/Controllers/Api/V1/ZernioAccountController.php:13
* @route '/api/v1/zernio/accounts'
*/
index.url = (options?: RouteQueryOptions) => {
    return index.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Api\V1\ZernioAccountController::index
* @see app/Http/Controllers/Api/V1/ZernioAccountController.php:13
* @route '/api/v1/zernio/accounts'
*/
index.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Api\V1\ZernioAccountController::index
* @see app/Http/Controllers/Api/V1/ZernioAccountController.php:13
* @route '/api/v1/zernio/accounts'
*/
index.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: index.url(options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\Api\V1\ZernioAccountController::index
* @see app/Http/Controllers/Api/V1/ZernioAccountController.php:13
* @route '/api/v1/zernio/accounts'
*/
const indexForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: index.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Api\V1\ZernioAccountController::index
* @see app/Http/Controllers/Api/V1/ZernioAccountController.php:13
* @route '/api/v1/zernio/accounts'
*/
indexForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: index.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Api\V1\ZernioAccountController::index
* @see app/Http/Controllers/Api/V1/ZernioAccountController.php:13
* @route '/api/v1/zernio/accounts'
*/
indexForm.head = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: index.url({
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

index.form = indexForm

const ZernioAccountController = { index }

export default ZernioAccountController