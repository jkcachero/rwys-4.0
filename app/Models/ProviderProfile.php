<?php

namespace App\Models;

use Database\Factories\ProviderProfileFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class ProviderProfile extends Model
{
    /** @use HasFactory<ProviderProfileFactory> */
    use HasFactory;

    protected $fillable = ['provider_connection_id', 'provider', 'provider_id', 'provider_id_hash', 'name', 'synchronized_at', 'is_active'];

    protected static function booted(): void
    {
        static::creating(function (ProviderProfile $profile): void {
            $profile->public_id ??= (string) Str::uuid();
        });
    }

    /** @return BelongsTo<ProviderConnection, $this> */
    public function providerConnection(): BelongsTo
    {
        return $this->belongsTo(ProviderConnection::class);
    }

    /** @return HasMany<ProviderAccount, $this> */
    public function accounts(): HasMany
    {
        return $this->hasMany(ProviderAccount::class);
    }

    /** @return BelongsToMany<Client, $this> */
    public function clients(): BelongsToMany
    {
        return $this->belongsToMany(Client::class, 'client_provider_profiles');
    }

    protected function casts(): array
    {
        return ['provider_id' => 'encrypted', 'synchronized_at' => 'datetime', 'is_active' => 'boolean'];
    }
}
