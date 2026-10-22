<?php

use App\Http\Controllers\Settings\AgentTokenController;
use Illuminate\Support\Facades\Route;

Route::get('settings/agent-access', [AgentTokenController::class, 'access'])
    ->middleware(['web', 'auth:web', 'verified', 'password.confirm', 'cache.headers:no_store;private'])
    ->name('agent-access.index');

Route::middleware(['web', 'auth:web', 'verified', 'password.confirm', 'throttle:30,1', 'cache.headers:no_store;private'])
    ->prefix('settings/agent-tokens')->name('agent-tokens.')->group(function (): void {
        Route::get('/', [AgentTokenController::class, 'index'])->name('index');
        Route::post('/', [AgentTokenController::class, 'store'])->name('store');
        Route::post('{token}/rotate', [AgentTokenController::class, 'rotate'])->name('rotate');
        Route::delete('{token}', [AgentTokenController::class, 'destroy'])->name('destroy');
    });
