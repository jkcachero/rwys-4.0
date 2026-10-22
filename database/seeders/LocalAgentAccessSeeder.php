<?php

namespace Database\Seeders;

use App\Models\Client;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class LocalAgentAccessSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local')) {
            return;
        }

        DB::transaction(function (): void {
            $clientOwner = null;
            foreach ([
                'test@admin.com' => ['name' => 'RWYS Admin', 'can_assign_provider_accounts' => true],
                'johnkarlo.315@gmail.com' => ['name' => 'John Karlo', 'can_assign_provider_accounts' => false],
            ] as $email => $attributes) {
                $user = User::query()->firstOrNew(['email' => $email]);

                if (! $user->exists) {
                    $user->forceFill([
                        ...$attributes,
                        'role' => 'admin',
                        'email_verified_at' => now(),
                        'password' => Hash::make('password'),
                    ])->save();
                }

                if ($email === 'johnkarlo.315@gmail.com') {
                    $clientOwner = $user;
                }
            }

            if ($clientOwner?->hasVerifiedEmail()) {
                Client::query()->firstOrCreate(
                    ['owner_id' => $clientOwner->id],
                    ['name' => 'John Karlo', 'active' => true],
                );
            }
        });
    }
}
