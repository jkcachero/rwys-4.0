<?php

use App\Http\Controllers\AIConnectionController;
use App\Http\Controllers\WorkspaceController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', WorkspaceController::class)->name('dashboard');
    Route::get('chat', [WorkspaceController::class, 'chat'])->name('chat');
    Route::get('chat/models', [WorkspaceController::class, 'models'])->middleware('throttle:30,1,chat-models')->name('chat.models');
    Route::get('chat/conversations', [WorkspaceController::class, 'conversationIndex'])->name('chat.conversations.index');
    Route::post('chat/conversations', [WorkspaceController::class, 'conversationStore'])->name('chat.conversations.store');
    Route::get('chat/conversations/{conversation}', [WorkspaceController::class, 'conversationShow'])->whereUuid('conversation')->name('chat.conversations.show');
    Route::delete('chat/conversations/{conversation}', [WorkspaceController::class, 'conversationDestroy'])->whereUuid('conversation')->name('chat.conversations.destroy');
    Route::post('dashboard/prompt', [WorkspaceController::class, 'prompt'])->middleware('throttle:20,1,personal-inference')->name('dashboard.prompt');
    Route::post('workspace/read', [WorkspaceController::class, 'read'])->name('workspace.read');
    Route::get('workspace/connections', [AIConnectionController::class, 'index'])->name('workspace.connections.index');
    Route::post('workspace/connections', [AIConnectionController::class, 'store'])->name('workspace.connections.store');
    Route::delete('workspace/connections/{connection}', [AIConnectionController::class, 'destroy'])->name('workspace.connections.destroy');
});

require __DIR__.'/settings.php';
