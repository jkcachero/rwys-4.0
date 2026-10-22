<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\ClientAccount;
use App\Models\ClientProviderProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClientAccount>
 */
class ClientAccountFactory extends Factory
{
    public function configure(): static
    {
        return $this->afterCreating(function (ClientAccount $account): void {
            if ($account->client_provider_profile_id !== null) {
                return;
            }
            $profile = ClientProviderProfile::query()->createOrFirst([
                'provider' => $account->provider,
                'environment' => $account->environment,
                'profile_id' => $account->profile_id,
            ], ['client_id' => $account->client_id, 'name' => null, 'active' => true]);
            $account->forceFill(['client_provider_profile_id' => $profile->id])->saveQuietly();
        });
    }

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'client_id' => Client::factory(),
            'provider' => 'zernio',
            'environment' => 'production',
            'profile_id' => fake()->regexify('[a-f0-9]{24}'),
            'provider_account_id' => fake()->unique()->regexify('[a-f0-9]{24}'),
            'platform' => 'linkedin',
            'active' => true,
            'publishing_enabled' => false,
        ];
    }
}
