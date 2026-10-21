<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        if (app()->environment('local') && (bool) env('ENABLE_LOCAL_ADMIN_BOOTSTRAP', false)) {
            $admin = User::query()->firstOrNew(['email' => 'johnkarlo.315@gmail.com']);
            $admin->name = 'John Karlo';
            $admin->role = 'admin';
            $admin->email_verified_at ??= now();

            if (! $admin->exists) {
                $admin->password = env('LOCAL_ADMIN_PASSWORD', 'password');
            }

            $admin->save();
        }
    }
}
