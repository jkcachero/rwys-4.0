<?php

namespace App\Models;

use Database\Factories\AgentOperationFactory;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

#[Guarded([])]
#[Hidden(['key_hash', 'payload_hash', 'mapping_hash', 'intent', 'result', 'recovery_post_id', 'recovery_post_hash', 'reconcile_lease_token', 'reconcile_lease_until'])]
class AgentOperation extends Model
{
    /** @use HasFactory<AgentOperationFactory> */
    use HasFactory, HasUuids;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    public const RECONCILABLE_STATES = ['executing', 'provider_pending', 'partial', 'unknown'];

    public const TERMINAL_STATES = ['succeeded', 'failed', 'cancelled'];

    protected static function booted(): void
    {
        static::saving(function (self $operation): void {
            $operation->recovery_post_hash = $operation->recovery_post_id === null
                ? null : hash('sha256', 'zernio-post:'.$operation->recovery_post_id);
        });
        static::updating(function (self $operation): void {
            if ($operation->isDirty(['client_id', 'client_account_id', 'capability', 'key_hash', 'payload_hash', 'mapping_hash', 'intent'])) {
                throw new LogicException('Operation identity and intent are immutable.');
            }
            if (in_array($operation->getOriginal('state'), self::TERMINAL_STATES, true) && $operation->isDirty('state')) {
                throw new LogicException('Terminal operations cannot change state.');
            }
        });
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(ClientAccount::class, 'client_account_id');
    }

    protected function casts(): array
    {
        return [
            'intent' => 'encrypted:array',
            'result' => 'encrypted:array',
            'recovery_post_id' => 'encrypted',
            'last_reconciled_at' => 'immutable_datetime',
            'reconcile_page' => 'integer',
            'reconcile_lease_until' => 'immutable_datetime',
        ];
    }
}
