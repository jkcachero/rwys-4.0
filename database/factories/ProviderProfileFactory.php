<?php

namespace Database\Factories;

use App\Models\ProviderProfile;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<ProviderProfile> */
class ProviderProfileFactory extends Factory
{
    public function definition(): array
    {
        $providerId = (string) Str::uuid();

        return [
            'provider' => 'zernio',
            'provider_id' => $providerId,
            'provider_id_hash' => hash('sha256', $providerId),
            'name' => fake()->company(),
            'synchronized_at' => now(),
            'is_active' => true,
        ];
    }
}
