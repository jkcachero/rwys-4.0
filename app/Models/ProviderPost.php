<?php

namespace App\Models;

use Database\Factories\ProviderPostFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProviderPost extends Model
{
    /** @use HasFactory<ProviderPostFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'client_id',
        'provider_account_id',
        'provider_id',
        'provider_id_hash',
        'status',
        'scheduled_at',
        'published_at',
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
            'provider_id' => 'encrypted',
            'scheduled_at' => 'datetime',
            'published_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** @return BelongsTo<ProviderAccount, $this> */
    public function providerAccount(): BelongsTo
    {
        return $this->belongsTo(ProviderAccount::class);
    }
}
