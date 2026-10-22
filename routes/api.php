<?php

use App\Http\Controllers\Api\V1\AgentBrokerController;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;

Route::post('/sanctum/token', function (Request $request) {
    $validated = $request->validate([
        'email' => ['required', 'email'],
        'password' => ['required'],
        'device_name' => ['required', 'string'],
        'client_id' => ['nullable', 'integer'],
    ]);

    $user = User::where('email', $validated['email'])->first();

    if (! $user || ! Hash::check($validated['password'], $user->password)) {
        throw ValidationException::withMessages([
            'email' => ['The provided credentials are incorrect.'],
        ]);
    }

    $client = $validated['client_id'] === null
        ? null
        : $user->clients()->findOrFail($validated['client_id']);

    return [
        'token' => $user->createToken(
            $validated['device_name'],
            $client === null ? [] : ['clients:read'],
        )->plainTextToken,
    ];
});

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::middleware('auth:sanctum')->prefix('v1/agents')->group(function (): void {
    Route::get('/clients/{client}/accounts', [AgentBrokerController::class, 'accounts']);
});
