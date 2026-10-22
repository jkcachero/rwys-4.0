<?php

namespace App\Models;

use Database\Factories\ClientFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Client extends Model
{
    /** @use HasFactory<ClientFactory> */
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function accounts(): HasMany
    {
        return $this->hasMany(ClientAccount::class);
    }

    public function providerProfiles(): HasMany
    {
        return $this->hasMany(ClientProviderProfile::class);
    }
}
