<?php

use App\Http\Controllers\AgentWebhookController;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Route;

Route::post('webhooks/zernio', AgentWebhookController::class)
    ->withoutMiddleware(['web', 'auth', 'auth:sanctum', PreventRequestForgery::class])
    ->name('agent-webhooks.zernio');
