<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Guarded([])]
#[Hidden(['raw_body'])]
class AgentWebhookReceipt extends Model
{
    use HasUuids;

    public const POST_EVENTS = [
        'post.scheduled', 'post.published', 'post.failed', 'post.partial', 'post.cancelled', 'post.recycled',
        'post.platform.published', 'post.platform.failed', 'post.platform.deleted', 'post.tiktok.url_resolved',
    ];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return ['raw_body' => 'encrypted', 'operation_pass_pending' => 'boolean', 'expires_at' => 'immutable_datetime',
            'last_attempted_at' => 'immutable_datetime', 'processing_until' => 'immutable_datetime', 'payload_redacted_at' => 'immutable_datetime'];
    }
}
