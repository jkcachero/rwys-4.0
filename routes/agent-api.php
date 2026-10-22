<?php

use App\Http\Controllers\AgentApiController;
use App\Http\Middleware\AuthenticateAgentToken;
use App\Http\Middleware\GuardAgentRequest;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->name('agent-api.')->middleware([AuthenticateAgentToken::class, GuardAgentRequest::class])->group(function (): void {
    Route::get('context', [AgentApiController::class, 'context'])->name('context')->defaults('agent_ability', 'context.read');
    Route::get('capabilities', [AgentApiController::class, 'capabilities'])->name('capabilities')->defaults('agent_ability', 'context.read');
    Route::get('zernio/accounts', [AgentApiController::class, 'accounts'])->name('accounts.index')->defaults('agent_ability', 'zernio.accounts.read');
    Route::get('zernio/accounts/{account}/status', [AgentApiController::class, 'status'])->name('accounts.status')->defaults('agent_ability', 'zernio.accounts.read');
    Route::get('zernio/accounts/{account}/posts', [AgentApiController::class, 'posts'])->name('posts.index')->defaults('agent_ability', 'zernio.posts.read');
    Route::get('zernio/accounts/{account}/posts/{post}', [AgentApiController::class, 'post'])->name('posts.show')->defaults('agent_ability', 'zernio.posts.read');
    Route::get('zernio/accounts/{account}/posts/{post}/analytics', [AgentApiController::class, 'analytics'])->name('posts.analytics')->defaults('agent_ability', 'zernio.analytics.read')->defaults('agent_write', false);
    Route::post('zernio/accounts/{account}/posts', [AgentApiController::class, 'publish'])->name('posts.store')->defaults('agent_ability', 'zernio.posts.publish')->defaults('agent_write', true);
    Route::post('zernio/accounts/{account}/scheduled-posts', [AgentApiController::class, 'schedule'])->name('posts.schedule')->defaults('agent_ability', 'zernio.posts.schedule')->defaults('agent_write', true);
    Route::get('operations', [AgentApiController::class, 'operations'])->name('operations.index')->defaults('agent_ability', 'operations.read');
    Route::get('operations/{operation}', [AgentApiController::class, 'operation'])->name('operations.show')->defaults('agent_ability', 'operations.read');
});
