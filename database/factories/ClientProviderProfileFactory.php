<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\ClientProviderProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ClientProviderProfile> */
class ClientProviderProfileFactory extends Factory
{
    public function definition(): array
    {
        return [
            'client_id' => Client::factory(),
            'provider' => 'zernio',
            'environment' => 'production',
            'profile_id' => fake()->unique()->regexify('[a-f0-9]{24}'),
            'name' => null,
            'active' => true,
            'last_synced_at' => null,
        ];
    }
}
