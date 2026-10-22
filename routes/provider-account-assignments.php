<?php

use App\Http\Controllers\Settings\ProviderAccountAssignmentController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth:web', 'verified', 'password.confirm', 'can:assign-provider-accounts', 'throttle:30,1', 'cache.headers:no_store;private'])
    ->prefix('settings/provider-accounts')->name('provider-accounts.')->group(function (): void {
        Route::get('/', [ProviderAccountAssignmentController::class, 'index'])->name('index');
        Route::get('clients', [ProviderAccountAssignmentController::class, 'clients'])->name('clients');
        Route::get('discover', [ProviderAccountAssignmentController::class, 'discover'])->name('discover');
        Route::post('/', [ProviderAccountAssignmentController::class, 'store'])->name('store');
    });
