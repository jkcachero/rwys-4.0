<?php

namespace Database\Factories;

use App\Models\ProviderAccount;
use App\Models\ProviderProfile;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<ProviderAccount> */
class ProviderAccountFactory extends Factory
{
    public function definition(): array
    {
        $providerId = (string) Str::uuid();

        return [
            'provider_profile_id' => ProviderProfile::factory(),
            'platform' => fake()->randomElement(['linkedin', 'facebook', 'threads', 'bluesky', 'instagram']),
            'provider_id' => $providerId,
            'provider_id_hash' => hash('sha256', $providerId),
            'name' => fake()->company(),
            'synchronized_at' => now(),
            'is_active' => true,
        ];
    }
}
