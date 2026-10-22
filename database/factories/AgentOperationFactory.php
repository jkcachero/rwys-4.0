<?php

namespace Database\Factories;

use App\Models\AgentOperation;
use App\Models\ClientAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AgentOperation> */
class AgentOperationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'client_account_id' => ClientAccount::factory(),
            'client_id' => fn (array $attributes): int => ClientAccount::findOrFail($attributes['client_account_id'])->client_id,
            'mapping_hash' => fn (array $attributes): string => ClientAccount::findOrFail($attributes['client_account_id'])->mappingHash(),
            'capability' => 'zernio.posts.publish',
            'key_hash' => hash('sha256', fake()->uuid()),
            'payload_hash' => hash('sha256', fake()->uuid()),
            'state' => 'pending',
            'intent' => [],
        ];
    }
}
