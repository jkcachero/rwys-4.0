<?php

namespace Database\Factories;

use App\Enums\AgentAbility;
use App\Models\AgentOperation;
use App\Models\Client;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AgentOperation> */
class AgentOperationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'client_id' => Client::factory(),
            'capability' => AgentAbility::PostsPublish->value,
            'status' => 'pending',
            'requested_at' => now(),
        ];
    }
}
