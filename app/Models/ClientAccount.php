<?php

namespace App\Models;

use Database\Factories\ClientAccountFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClientAccount extends Model
{
    /** @use HasFactory<ClientAccountFactory> */
    use HasFactory;

    protected $guarded = [];

    protected static function booted(): void
    {
        static::saving(function (ClientAccount $account): void {
            if ($account->exists) {
                $account->mapping_hash = $account->mappingHash();
            }
        });
        static::created(function (ClientAccount $account): void {
            $account->forceFill(['mapping_hash' => $account->mappingHash()])->saveQuietly();
        });
    }

    public function mappingHash(): string
    {
        return hash('sha256', json_encode([
            'local_id' => $this->id, 'client_id' => $this->client_id, 'provider' => $this->provider,
            'environment' => $this->environment, 'profile_id' => $this->profile_id,
            'account_id' => $this->provider_account_id, 'platform' => $this->platform,
        ], JSON_THROW_ON_ERROR));
    }

    protected function casts(): array
    {
        return ['active' => 'boolean', 'publishing_enabled' => 'boolean'];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function providerProfile(): BelongsTo
    {
        return $this->belongsTo(ClientProviderProfile::class, 'client_provider_profile_id');
    }

    public function scopeWithOwnedProfile(Builder $query): Builder
    {
        return $query->whereHas('providerProfile', function (Builder $profile): void {
            $profile->where('active', true);
            foreach (['client_id', 'provider', 'environment', 'profile_id'] as $column) {
                $profile->whereColumn('client_provider_profiles.'.$column, 'client_accounts.'.$column);
            }
        });
    }

    public function hasOwnedProfile(): bool
    {
        return $this->exists && static::query()->whereKey($this->getKey())->withOwnedProfile()->exists();
    }
}
