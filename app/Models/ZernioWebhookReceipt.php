<?php

namespace App\Models;

use Database\Factories\ZernioWebhookReceiptFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ZernioWebhookReceipt extends Model
{
    /** @use HasFactory<ZernioWebhookReceiptFactory> */
    use HasFactory;

    protected $fillable = [
        'event_id',
        'event_type',
        'payload_hash',
        'raw_payload',
        'status',
        'reconciliation_attempts',
        'received_at',
        'unmatched_until',
        'payload_purge_after',
        'reconciled_at',
    ];

    protected function casts(): array
    {
        return [
            'raw_payload' => 'encrypted',
            'received_at' => 'datetime',
            'unmatched_until' => 'datetime',
            'payload_purge_after' => 'datetime',
            'reconciled_at' => 'datetime',
        ];
    }
}
