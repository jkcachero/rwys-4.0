<?php

namespace App\Models;

use Database\Factories\AgentOperationFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentOperation extends Model
{
    /** @use HasFactory<AgentOperationFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'client_id',
        'principal_id',
        'personal_access_token_id',
        'provider_account_id',
        'capability',
        'idempotency_key_hash',
        'payload_hash',
        'intent',
        'status',
        'outcome',
        'recovery_context',
        'requested_at',
        'started_at',
        'completed_at',
    ];

    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    protected function casts(): array
    {
        return [
            'intent' => 'encrypted:array',
            'outcome' => 'encrypted:array',
            'recovery_context' => 'encrypted:array',
            'requested_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** @return BelongsTo<User, $this> */
    public function principal(): BelongsTo
    {
        return $this->belongsTo(User::class, 'principal_id');
    }

    /** @return BelongsTo<PersonalAccessToken, $this> */
    public function token(): BelongsTo
    {
        return $this->belongsTo(PersonalAccessToken::class, 'personal_access_token_id');
    }

    /** @return BelongsTo<ProviderAccount, $this> */
    public function providerAccount(): BelongsTo
    {
        return $this->belongsTo(ProviderAccount::class);
    }
}
