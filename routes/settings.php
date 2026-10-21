<?php

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
    Route::post('settings/connections/openai', [ProviderConnectionController::class, 'connectOpenAi'])->middleware('throttle:6,1,openai-authorization')->name('connections.openai');
    Route::get('settings/connections/openai/attempts/{state}', [ProviderConnectionController::class, 'attempt'])->where('state', '[A-Za-z0-9]{64}')->name('connections.attempt');
    Route::post('settings/connections/{provider}/test', [ProviderConnectionController::class, 'test'])->middleware('throttle:6,1,connection-test')->name('connections.test');
    Route::delete('settings/connections/{provider}', [ProviderConnectionController::class, 'disconnect'])->name('connections.disconnect');
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
