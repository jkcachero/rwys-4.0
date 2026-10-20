<?php

use App\Http\Controllers\WorkspaceController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', WorkspaceController::class)->name('dashboard');
    Route::post('dashboard/prompt', [WorkspaceController::class, 'prompt'])->name('dashboard.prompt');
});

require __DIR__.'/settings.php';
