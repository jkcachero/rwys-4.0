<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\ProviderAccount;
use App\Models\ProviderPost;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ProviderPost> */
class ProviderPostFactory extends Factory
{
    public function definition(): array
    {
        $providerId = fake()->regexify('[a-f0-9]{24}');

        return [
            'client_id' => Client::factory(),
            'provider_account_id' => ProviderAccount::factory(),
            'provider_id' => $providerId,
            'provider_id_hash' => hash('sha256', $providerId),
            'status' => fake()->randomElement(['scheduled', 'published', 'partial', 'failed']),
            'scheduled_at' => now()->addDay(),
            'published_at' => null,
        ];
    }
}
