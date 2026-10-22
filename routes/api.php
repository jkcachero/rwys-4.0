<?php

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;

require __DIR__.'/agent-api.php';

Route::post('/sanctum/token', function (Request $request) {
    $request->validate([
        'email' => ['required', 'email'],
        'password' => ['required'],
        'device_name' => ['required', 'string'],
    ]);

    $user = User::where('email', $request->email)->first();

    if (! $user || ! Hash::check($request->password, $user->password)) {
        throw ValidationException::withMessages([
            'email' => ['The provided credentials are incorrect.'],
        ]);
    }

    return [
        'token' => $user->createToken($request->device_name)->plainTextToken,
    ];
});

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');
