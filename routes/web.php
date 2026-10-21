<?php

use App\Http\Controllers\AIConnectionController;
use App\Http\Controllers\WorkspaceController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', WorkspaceController::class)->name('dashboard');
    Route::post('workspace/read', [WorkspaceController::class, 'read'])->name('workspace.read');
    Route::get('workspace/connections', [AIConnectionController::class, 'index'])->name('workspace.connections.index');
    Route::post('workspace/connections', [AIConnectionController::class, 'store'])->name('workspace.connections.store');
    Route::delete('workspace/connections/{connection}', [AIConnectionController::class, 'destroy'])->name('workspace.connections.destroy');
});

require __DIR__.'/settings.php';
