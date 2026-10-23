<?php

namespace App\Models;

use Database\Factories\ClientFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Client extends Model
{
    /** @use HasFactory<ClientFactory> */
    use HasFactory;

    protected $fillable = ['name', 'approved_origins', 'is_active'];

    protected static function booted(): void
    {
        static::creating(function (Client $client): void {
            $client->public_id ??= (string) Str::uuid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /** @return BelongsToMany<User, $this> */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot(['role', 'is_active'])->withTimestamps();
    }

    /** @return BelongsToMany<ProviderProfile, $this> */
    public function providerProfiles(): BelongsToMany
    {
        return $this->belongsToMany(ProviderProfile::class, 'client_provider_profiles')
            ->withPivot(['assigned_by', 'assigned_at'])->withTimestamps();
    }

    /** @return HasMany<ClientAccountPermission, $this> */
    public function accountPermissions(): HasMany
    {
        return $this->hasMany(ClientAccountPermission::class);
    }

    /** @return HasMany<ClientOperatorGrant, $this> */
    public function operatorGrants(): HasMany
    {
        return $this->hasMany(ClientOperatorGrant::class);
    }

    public function hasActiveMember(User $user): bool
    {
        return $this->users()->whereKey($user->getKey())->wherePivot('is_active', true)->exists();
    }

    public function hasActiveOwner(User $user): bool
    {
        return $this->users()->whereKey($user->getKey())->wherePivot('is_active', true)->wherePivot('role', 'owner')->exists();
    }

    protected function approvedOrigins(): Attribute
    {
        return Attribute::make(get: fn (mixed $value): array => json_decode($value ?? '[]', true), set: fn (array $value): string => json_encode(array_values($value)));
    }

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
