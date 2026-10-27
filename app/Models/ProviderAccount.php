<?php

namespace App\Models;

use Database\Factories\ProviderAccountFactory;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
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

    /** @param Builder<ProviderAccount> $query */
    #[Scope]
    protected function assignedAdKitTo(Builder $query, Client $client): void
    {
        $query->where('platform', 'meta')->where('is_active', true)
            ->whereHas('profile', fn ($profile) => $profile->where('provider', 'adkit')->where('is_active', true))
            ->whereHas('permissions', fn ($permission) => $permission->where('client_id', $client->id)->where('is_active', true))
            ->whereNotIn('provider_id_hash', self::query()->select('provider_id_hash')
                ->whereHas('profile', fn ($profile) => $profile->where('provider', 'adkit'))
                ->whereHas('permissions', fn ($permission) => $permission->where('client_id', '!=', $client->id)->where('is_active', true)));
    }

    public function hasUsableAdKitConnection(): bool
    {
        try {
            $profile = $this->profile;
            $connection = $profile?->providerConnection;
            $project = $connection?->client_id;
            $secret = $connection?->access_token;

            return $this->is_active && $this->platform === 'meta'
                && $profile?->provider === 'adkit' && $profile->is_active
                && $connection?->provider === 'adkit'
                && is_string($this->provider_id) && preg_match('/\Aact_[0-9]+\z/', $this->provider_id) === 1
                && is_string($project) && trim($project) !== '' && ! preg_match('/[\r\n]/', $project)
                && $profile->provider_id === $project
                && is_string($connection->subject) && preg_match('/\Aact_[0-9]+\z/', $connection->subject) === 1
                && is_string($secret) && trim($secret) !== '' && ! preg_match('/[\r\n]/', $secret)
                && ($connection->access_token_expires_at === null || $connection->access_token_expires_at->isFuture());
        } catch (DecryptException) {
            return false;
        }
    }

    protected function casts(): array
    {
        return ['provider_id' => 'encrypted', 'synchronized_at' => 'datetime', 'is_active' => 'boolean'];
    }
}
