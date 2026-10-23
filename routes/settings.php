<?php

use App\Http\Controllers\Settings\AgentAccessController;
use App\Http\Controllers\Settings\AgentProfileAssignmentController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\ProviderConnectionController;
use App\Http\Controllers\Settings\SecurityController;
/* @chisel-password-confirmation */
use Illuminate\Auth\Middleware\RequirePassword;
/* @end-chisel-password-confirmation */
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', '/settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::delete('settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('settings/security', [SecurityController::class, 'edit'])
        /* @chisel-password-confirmation */
        ->middleware(RequirePassword::class)
        /* @end-chisel-password-confirmation */
        ->name('security.edit');

    Route::put('settings/password', [SecurityController::class, 'update'])
        ->middleware('throttle:6,1')
        ->name('user-password.update');

    Route::inertia('settings/appearance', 'settings/appearance')->name('appearance.edit');

    Route::get('settings/connections', [ProviderConnectionController::class, 'index'])->name('connections.edit');
    Route::get('settings/connections/openai', [ProviderConnectionController::class, 'connectOpenAi'])->name('connections.openai');
    Route::put('settings/connections/zernio', [ProviderConnectionController::class, 'connectZernio'])
        ->middleware(RequirePassword::class)
        ->name('connections.zernio');
    Route::post('settings/connections/{provider}/test', [ProviderConnectionController::class, 'test'])->name('connections.test');
    Route::delete('settings/connections/{provider}', [ProviderConnectionController::class, 'disconnect'])->name('connections.disconnect');

    Route::get('settings/agent-access', [AgentAccessController::class, 'edit'])->name('settings.agent-access.edit');
    Route::get('settings/agent-access/{client}/provider-profiles', [AgentProfileAssignmentController::class, 'profiles'])
        ->name('settings.agent-access.assignments.profiles');

    Route::middleware(RequirePassword::class)->group(function () {
        Route::post('settings/agent-access/{client}/provider-profiles', [AgentProfileAssignmentController::class, 'store'])
            ->name('settings.agent-access.assignments.store');
        Route::post('settings/agent-access/{client}/provider-profiles/{profile}/sync', [AgentProfileAssignmentController::class, 'sync'])
            ->name('settings.agent-access.assignments.sync');
        Route::patch('settings/agent-access/{client}/permissions/{permission}', [AgentAccessController::class, 'updatePermission'])
            ->name('settings.agent-access.permissions.update');
        Route::post('settings/agent-access/{client}/tokens', [AgentAccessController::class, 'storeToken'])
            ->name('settings.agent-access.tokens.store');
        Route::post('settings/agent-access/{client}/tokens/{token}/rotate', [AgentAccessController::class, 'rotateToken'])
            ->name('settings.agent-access.tokens.rotate');
        Route::delete('settings/agent-access/{client}/tokens/{token}', [AgentAccessController::class, 'destroyToken'])
            ->name('settings.agent-access.tokens.destroy');
    });
});

Route::get('auth/callback', [ProviderConnectionController::class, 'callback'])->name('connections.callback');

/* @chisel-passkeys */
Route::get('.well-known/passkey-endpoints', function () {
    return response()->json([
        'enroll' => route('security.edit'),
        'manage' => route('security.edit'),
    ]);
})->name('well-known.passkeys');
/* @end-chisel-passkeys */
