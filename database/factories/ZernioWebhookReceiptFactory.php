<?php

namespace Database\Factories;

use App\Models\ZernioWebhookReceipt;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ZernioWebhookReceipt>
 */
class ZernioWebhookReceiptFactory extends Factory
{
    public function definition(): array
    {
        $payload = json_encode([
            'id' => (string) Str::uuid(),
            'type' => 'post.updated',
        ], JSON_THROW_ON_ERROR);

        return [
            'event_id' => (string) Str::uuid(),
            'event_type' => 'post.updated',
            'payload_hash' => hash('sha256', $payload),
            'raw_payload' => $payload,
            'status' => 'received',
            'reconciliation_attempts' => 0,
            'received_at' => now(),
            'unmatched_until' => now()->addDay(),
            'payload_purge_after' => now()->addDays(7),
        ];
    }
}
