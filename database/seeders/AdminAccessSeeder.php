<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminAccessSeeder extends Seeder
{
    public function run(): void
    {
        $email = config('admin.email');
        $password = config('admin.password');

        if (blank($email) || blank($password)) {
            return;
        }

        $user = User::firstOrNew(['email' => $email]);
        $user->name ??= config('admin.name');
        $user->role = 'admin';
        $user->email_verified_at ??= now();

        if (! $user->exists) {
            $user->password = Hash::make($password);
        }

        $user->save();
    }
}
