<?php

use App\Enums\AgentAbility;
use App\Http\Controllers\Api\V1\AdKitBrokerController;
use App\Http\Controllers\Api\V1\AgentCapabilityController;
use App\Http\Controllers\Api\V1\AgentContextController;
use App\Http\Controllers\Api\V1\AgentOperationController;
use App\Http\Controllers\Api\V1\ZernioAccountController;
use App\Http\Controllers\Api\V1\ZernioAccountStatusController;
use App\Http\Controllers\Api\V1\ZernioPostAnalyticsController;
use App\Http\Controllers\Api\V1\ZernioPostController;
use App\Http\Controllers\Api\V1\ZernioPostPublishController;
use App\Http\Controllers\Api\ZernioWebhookController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::post('/webhooks/zernio', ZernioWebhookController::class)
    ->name('api.webhooks.zernio');

Route::prefix('v1')->middleware(['agent.response', 'auth:sanctum'])->group(function (): void {
    Route::get('/context', AgentContextController::class)
        ->middleware(['agent.ability:'.AgentAbility::ContextRead->value, 'agent.admit', 'agent.audit'])
        ->name('api.v1.context');
    Route::get('/capabilities', AgentCapabilityController::class)
        ->middleware(['agent.ability:'.AgentAbility::ContextRead->value, 'agent.admit', 'agent.audit'])
        ->name('api.v1.capabilities');
    Route::post('/manage/platform-api-requests', AdKitBrokerController::class)
        ->middleware(['agent.client', 'agent.admit:provider', 'agent.audit'])
        ->name('api.v1.adkit.forward');
    Route::get('/zernio/accounts', [ZernioAccountController::class, 'index'])
        ->middleware(['agent.ability:'.AgentAbility::AccountsRead->value, 'agent.admit', 'agent.audit'])
        ->name('api.v1.zernio.accounts.index');
    Route::get('/zernio/accounts/{account}/status', ZernioAccountStatusController::class)
        ->middleware(['agent.ability:'.AgentAbility::AccountsRead->value, 'agent.admit', 'agent.audit'])
        ->name('api.v1.zernio.accounts.status');
    Route::get('/zernio/accounts/{account}/posts', [ZernioPostController::class, 'index'])
        ->middleware(['agent.ability:'.AgentAbility::PostsRead->value, 'agent.admit', 'agent.audit'])
        ->name('api.v1.zernio.posts.index');
    Route::get('/zernio/accounts/{account}/posts/{post}', [ZernioPostController::class, 'show'])
        ->middleware(['agent.ability:'.AgentAbility::PostsRead->value, 'agent.admit', 'agent.audit'])
        ->name('api.v1.zernio.posts.show');
    Route::get('/zernio/accounts/{account}/posts/{post}/analytics', ZernioPostAnalyticsController::class)
        ->middleware(['agent.ability:'.AgentAbility::AnalyticsRead->value, 'agent.admit', 'agent.audit'])
        ->name('api.v1.zernio.posts.analytics');
    Route::post('/zernio/accounts/{account}/posts', [ZernioPostPublishController::class, 'store'])
        ->middleware(['agent.ability:'.AgentAbility::PostsPublish->value, 'agent.admit', 'agent.audit'])
        ->name('api.v1.zernio.posts.store');
    Route::post('/zernio/accounts/{account}/scheduled-posts', [ZernioPostPublishController::class, 'schedule'])
        ->middleware(['agent.ability:'.AgentAbility::PostsSchedule->value, 'agent.admit', 'agent.audit'])
        ->name('api.v1.zernio.scheduled-posts.store');
    Route::get('/operations', [AgentOperationController::class, 'index'])
        ->middleware(['agent.ability:'.AgentAbility::OperationsRead->value, 'agent.admit', 'agent.audit'])
        ->name('api.v1.operations.index');
    Route::get('/operations/{operation}', [AgentOperationController::class, 'show'])
        ->middleware(['agent.ability:'.AgentAbility::OperationsRead->value, 'agent.admit', 'agent.audit'])
        ->name('api.v1.operations.show');
});
