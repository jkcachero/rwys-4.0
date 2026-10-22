import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition, applyUrlDefaults } from './../../../../wayfinder'
/**
* @see \App\Http\Controllers\WorkspaceController::__invoke
* @see app/Http/Controllers/WorkspaceController.php:29
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
* @see app/Http/Controllers/WorkspaceController.php:29
* @route '/dashboard'
*/
WorkspaceController.url = (options?: RouteQueryOptions) => {
    return WorkspaceController.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\WorkspaceController::__invoke
* @see app/Http/Controllers/WorkspaceController.php:29
* @route '/dashboard'
*/
WorkspaceController.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: WorkspaceController.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\WorkspaceController::__invoke
* @see app/Http/Controllers/WorkspaceController.php:29
* @route '/dashboard'
*/
WorkspaceController.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: WorkspaceController.url(options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\WorkspaceController::__invoke
* @see app/Http/Controllers/WorkspaceController.php:29
* @route '/dashboard'
*/
const WorkspaceControllerForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: WorkspaceController.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\WorkspaceController::__invoke
* @see app/Http/Controllers/WorkspaceController.php:29
* @route '/dashboard'
*/
WorkspaceControllerForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: WorkspaceController.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\WorkspaceController::__invoke
* @see app/Http/Controllers/WorkspaceController.php:29
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
* @see \App\Http\Controllers\WorkspaceController::chat
* @see app/Http/Controllers/WorkspaceController.php:36
* @route '/chat'
*/
export const chat = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: chat.url(options),
    method: 'get',
})

chat.definition = {
    methods: ["get","head"],
    url: '/chat',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\WorkspaceController::chat
* @see app/Http/Controllers/WorkspaceController.php:36
* @route '/chat'
*/
chat.url = (options?: RouteQueryOptions) => {
    return chat.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\WorkspaceController::chat
* @see app/Http/Controllers/WorkspaceController.php:36
* @route '/chat'
*/
chat.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: chat.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\WorkspaceController::chat
* @see app/Http/Controllers/WorkspaceController.php:36
* @route '/chat'
*/
chat.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: chat.url(options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\WorkspaceController::chat
* @see app/Http/Controllers/WorkspaceController.php:36
* @route '/chat'
*/
const chatForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: chat.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\WorkspaceController::chat
* @see app/Http/Controllers/WorkspaceController.php:36
* @route '/chat'
*/
chatForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: chat.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\WorkspaceController::chat
* @see app/Http/Controllers/WorkspaceController.php:36
* @route '/chat'
*/
chatForm.head = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: chat.url({
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

chat.form = chatForm

/**
* @see \App\Http\Controllers\WorkspaceController::models
* @see app/Http/Controllers/WorkspaceController.php:87
* @route '/chat/models'
*/
export const models = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: models.url(options),
    method: 'get',
})

models.definition = {
    methods: ["get","head"],
    url: '/chat/models',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\WorkspaceController::models
* @see app/Http/Controllers/WorkspaceController.php:87
* @route '/chat/models'
*/
models.url = (options?: RouteQueryOptions) => {
    return models.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\WorkspaceController::models
* @see app/Http/Controllers/WorkspaceController.php:87
* @route '/chat/models'
*/
models.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: models.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\WorkspaceController::models
* @see app/Http/Controllers/WorkspaceController.php:87
* @route '/chat/models'
*/
models.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: models.url(options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\WorkspaceController::models
* @see app/Http/Controllers/WorkspaceController.php:87
* @route '/chat/models'
*/
const modelsForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: models.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\WorkspaceController::models
* @see app/Http/Controllers/WorkspaceController.php:87
* @route '/chat/models'
*/
modelsForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: models.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\WorkspaceController::models
* @see app/Http/Controllers/WorkspaceController.php:87
* @route '/chat/models'
*/
modelsForm.head = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: models.url({
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

models.form = modelsForm

/**
* @see \App\Http\Controllers\WorkspaceController::conversationIndex
* @see app/Http/Controllers/WorkspaceController.php:55
* @route '/chat/conversations'
*/
export const conversationIndex = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: conversationIndex.url(options),
    method: 'get',
})

conversationIndex.definition = {
    methods: ["get","head"],
    url: '/chat/conversations',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\WorkspaceController::conversationIndex
* @see app/Http/Controllers/WorkspaceController.php:55
* @route '/chat/conversations'
*/
conversationIndex.url = (options?: RouteQueryOptions) => {
    return conversationIndex.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\WorkspaceController::conversationIndex
* @see app/Http/Controllers/WorkspaceController.php:55
* @route '/chat/conversations'
*/
conversationIndex.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: conversationIndex.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\WorkspaceController::conversationIndex
* @see app/Http/Controllers/WorkspaceController.php:55
* @route '/chat/conversations'
*/
conversationIndex.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: conversationIndex.url(options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\WorkspaceController::conversationIndex
* @see app/Http/Controllers/WorkspaceController.php:55
* @route '/chat/conversations'
*/
const conversationIndexForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: conversationIndex.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\WorkspaceController::conversationIndex
* @see app/Http/Controllers/WorkspaceController.php:55
* @route '/chat/conversations'
*/
conversationIndexForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: conversationIndex.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\WorkspaceController::conversationIndex
* @see app/Http/Controllers/WorkspaceController.php:55
* @route '/chat/conversations'
*/
conversationIndexForm.head = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: conversationIndex.url({
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

conversationIndex.form = conversationIndexForm

/**
* @see \App\Http\Controllers\WorkspaceController::conversationStore
* @see app/Http/Controllers/WorkspaceController.php:62
* @route '/chat/conversations'
*/
export const conversationStore = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: conversationStore.url(options),
    method: 'post',
})

conversationStore.definition = {
    methods: ["post"],
    url: '/chat/conversations',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\WorkspaceController::conversationStore
* @see app/Http/Controllers/WorkspaceController.php:62
* @route '/chat/conversations'
*/
conversationStore.url = (options?: RouteQueryOptions) => {
    return conversationStore.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\WorkspaceController::conversationStore
* @see app/Http/Controllers/WorkspaceController.php:62
* @route '/chat/conversations'
*/
conversationStore.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: conversationStore.url(options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\WorkspaceController::conversationStore
* @see app/Http/Controllers/WorkspaceController.php:62
* @route '/chat/conversations'
*/
const conversationStoreForm = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: conversationStore.url(options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\WorkspaceController::conversationStore
* @see app/Http/Controllers/WorkspaceController.php:62
* @route '/chat/conversations'
*/
conversationStoreForm.post = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: conversationStore.url(options),
    method: 'post',
})

conversationStore.form = conversationStoreForm

/**
* @see \App\Http\Controllers\WorkspaceController::conversationShow
* @see app/Http/Controllers/WorkspaceController.php:69
* @route '/chat/conversations/{conversation}'
*/
export const conversationShow = (args: { conversation: string | number } | [conversation: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: conversationShow.url(args, options),
    method: 'get',
})

conversationShow.definition = {
    methods: ["get","head"],
    url: '/chat/conversations/{conversation}',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\WorkspaceController::conversationShow
* @see app/Http/Controllers/WorkspaceController.php:69
* @route '/chat/conversations/{conversation}'
*/
conversationShow.url = (args: { conversation: string | number } | [conversation: string | number ] | string | number, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { conversation: args }
    }

    if (Array.isArray(args)) {
        args = {
            conversation: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        conversation: args.conversation,
    }

    return conversationShow.definition.url
            .replace('{conversation}', parsedArgs.conversation.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\WorkspaceController::conversationShow
* @see app/Http/Controllers/WorkspaceController.php:69
* @route '/chat/conversations/{conversation}'
*/
conversationShow.get = (args: { conversation: string | number } | [conversation: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: conversationShow.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\WorkspaceController::conversationShow
* @see app/Http/Controllers/WorkspaceController.php:69
* @route '/chat/conversations/{conversation}'
*/
conversationShow.head = (args: { conversation: string | number } | [conversation: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: conversationShow.url(args, options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\WorkspaceController::conversationShow
* @see app/Http/Controllers/WorkspaceController.php:69
* @route '/chat/conversations/{conversation}'
*/
const conversationShowForm = (args: { conversation: string | number } | [conversation: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: conversationShow.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\WorkspaceController::conversationShow
* @see app/Http/Controllers/WorkspaceController.php:69
* @route '/chat/conversations/{conversation}'
*/
conversationShowForm.get = (args: { conversation: string | number } | [conversation: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: conversationShow.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\WorkspaceController::conversationShow
* @see app/Http/Controllers/WorkspaceController.php:69
* @route '/chat/conversations/{conversation}'
*/
conversationShowForm.head = (args: { conversation: string | number } | [conversation: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: conversationShow.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

conversationShow.form = conversationShowForm

/**
* @see \App\Http\Controllers\WorkspaceController::conversationDestroy
* @see app/Http/Controllers/WorkspaceController.php:76
* @route '/chat/conversations/{conversation}'
*/
export const conversationDestroy = (args: { conversation: string | number } | [conversation: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: conversationDestroy.url(args, options),
    method: 'delete',
})

conversationDestroy.definition = {
    methods: ["delete"],
    url: '/chat/conversations/{conversation}',
} satisfies RouteDefinition<["delete"]>

/**
* @see \App\Http\Controllers\WorkspaceController::conversationDestroy
* @see app/Http/Controllers/WorkspaceController.php:76
* @route '/chat/conversations/{conversation}'
*/
conversationDestroy.url = (args: { conversation: string | number } | [conversation: string | number ] | string | number, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { conversation: args }
    }

    if (Array.isArray(args)) {
        args = {
            conversation: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        conversation: args.conversation,
    }

    return conversationDestroy.definition.url
            .replace('{conversation}', parsedArgs.conversation.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\WorkspaceController::conversationDestroy
* @see app/Http/Controllers/WorkspaceController.php:76
* @route '/chat/conversations/{conversation}'
*/
conversationDestroy.delete = (args: { conversation: string | number } | [conversation: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: conversationDestroy.url(args, options),
    method: 'delete',
})

/**
* @see \App\Http\Controllers\WorkspaceController::conversationDestroy
* @see app/Http/Controllers/WorkspaceController.php:76
* @route '/chat/conversations/{conversation}'
*/
const conversationDestroyForm = (args: { conversation: string | number } | [conversation: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: conversationDestroy.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'DELETE',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'post',
})

/**
* @see \App\Http\Controllers\WorkspaceController::conversationDestroy
* @see app/Http/Controllers/WorkspaceController.php:76
* @route '/chat/conversations/{conversation}'
*/
conversationDestroyForm.delete = (args: { conversation: string | number } | [conversation: string | number ] | string | number, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: conversationDestroy.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'DELETE',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'post',
})

conversationDestroy.form = conversationDestroyForm

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

/**
* @see \App\Http\Controllers\WorkspaceController::read
* @see app/Http/Controllers/WorkspaceController.php:113
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
* @see app/Http/Controllers/WorkspaceController.php:113
* @route '/workspace/read'
*/
read.url = (options?: RouteQueryOptions) => {
    return read.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\WorkspaceController::read
* @see app/Http/Controllers/WorkspaceController.php:113
* @route '/workspace/read'
*/
read.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: read.url(options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\WorkspaceController::read
* @see app/Http/Controllers/WorkspaceController.php:113
* @route '/workspace/read'
*/
const readForm = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: read.url(options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\WorkspaceController::read
* @see app/Http/Controllers/WorkspaceController.php:113
* @route '/workspace/read'
*/
readForm.post = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: read.url(options),
    method: 'post',
})

read.form = readForm

WorkspaceController.chat = chat
WorkspaceController.models = models
WorkspaceController.conversationIndex = conversationIndex
WorkspaceController.conversationStore = conversationStore
WorkspaceController.conversationShow = conversationShow
WorkspaceController.conversationDestroy = conversationDestroy
WorkspaceController.prompt = prompt
WorkspaceController.read = read

export default WorkspaceController