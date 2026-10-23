<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\ClientAccountPermission;
use App\Models\ProviderAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ClientAccountPermission> */
class ClientAccountPermissionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'client_id' => Client::factory(),
            'provider_account_id' => ProviderAccount::factory(),
            'is_active' => true,
            'can_schedule' => false,
        ];
    }
}
