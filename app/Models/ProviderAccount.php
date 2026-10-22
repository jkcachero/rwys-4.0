<?php

namespace App\Models;

use Database\Factories\ProviderAccountFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class ProviderAccount extends Model
{
    /** @use HasFactory<ProviderAccountFactory> */
    use HasFactory;

    protected $fillable = ['provider_profile_id', 'platform', 'provider_id', 'provider_id_hash', 'name', 'synchronized_at', 'is_active'];

    protected static function booted(): void
    {
        static::creating(function (ProviderAccount $account): void {
            $account->public_id ??= (string) Str::uuid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /** @return BelongsTo<ProviderProfile, $this> */
    public function profile(): BelongsTo
    {
        return $this->belongsTo(ProviderProfile::class, 'provider_profile_id');
    }

    /** @return HasMany<ClientAccountPermission, $this> */
    public function permissions(): HasMany
    {
        return $this->hasMany(ClientAccountPermission::class);
    }

    protected function casts(): array
    {
        return ['provider_id' => 'encrypted', 'synchronized_at' => 'datetime', 'is_active' => 'boolean'];
    }
}
