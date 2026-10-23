<?php

namespace Database\Seeders;

use App\Models\Client;
use App\Models\ClientOperatorGrant;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class LocalAgentAccessSeeder extends Seeder
{
    public const CLIENT_PUBLIC_ID = '00000000-0000-4000-8000-000000000025';

    public function run(): void
    {
        if (! app()->environment('local')) {
            return;
        }

        DB::transaction(function (): void {
            $operator = User::query()->firstOrCreate(
                ['email' => 'test@admin.com'],
                [
                    'name' => 'Test Admin',
                    'password' => Hash::make('password'),
                    'email_verified_at' => now(),
                    'role' => 'admin',
                ],
            );
            $owner = User::query()->firstOrCreate(
                ['email' => 'johnkarlo.315@gmail.com'],
                [
                    'name' => 'John Karlo',
                    'password' => Hash::make('password'),
                    'email_verified_at' => now(),
                    'role' => 'admin',
                ],
            );
            $client = Client::query()->where('public_id', self::CLIENT_PUBLIC_ID)->first();

            if ($client === null) {
                $client = new Client([
                    'name' => 'John Karlo Client',
                    'approved_origins' => [],
                    'is_active' => true,
                ]);
                $client->public_id = self::CLIENT_PUBLIC_ID;
                $client->save();
            }

            if (! $client->users()->whereKey($owner->id)->exists()) {
                $client->users()->attach($owner, [
                    'role' => 'owner',
                    'is_active' => true,
                ]);
            }

            ClientOperatorGrant::query()->firstOrCreate(
                [
                    'client_id' => $client->id,
                    'user_id' => $operator->id,
                ],
                [
                    'granted_by' => $owner->id,
                    'is_active' => true,
                ],
            );
        });
    }
}
