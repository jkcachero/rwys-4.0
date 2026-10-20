<?php

use App\Http\Controllers\WorkspaceController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', WorkspaceController::class)->name('dashboard');
    Route::post('workspace/read', [WorkspaceController::class, 'read'])->name('workspace.read');
});

require __DIR__.'/settings.php';
