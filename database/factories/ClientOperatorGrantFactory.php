<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\ClientOperatorGrant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ClientOperatorGrant> */
class ClientOperatorGrantFactory extends Factory
{
    public function definition(): array
    {
        return [
            'client_id' => Client::factory(),
            'user_id' => User::factory(),
            'granted_by' => User::factory(),
            'is_active' => true,
        ];
    }
}
