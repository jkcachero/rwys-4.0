<?php

namespace App\Models;

use Database\Factories\ClientProviderProfileFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ClientProviderProfile extends Model
{
    /** @use HasFactory<ClientProviderProfileFactory> */
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'last_synced_at' => 'datetime', 'sync_version' => 'integer'];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function accounts(): HasMany
    {
        return $this->hasMany(ClientAccount::class);
    }
}
