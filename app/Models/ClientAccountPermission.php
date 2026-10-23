<?php

namespace App\Models;

use Database\Factories\ClientAccountPermissionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClientAccountPermission extends Model
{
    /** @use HasFactory<ClientAccountPermissionFactory> */
    use HasFactory;

    protected $fillable = ['client_id', 'provider_account_id', 'is_active', 'can_read', 'can_publish', 'can_schedule'];

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** @return BelongsTo<ProviderAccount, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(ProviderAccount::class, 'provider_account_id');
    }

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'can_read' => 'boolean', 'can_publish' => 'boolean', 'can_schedule' => 'boolean'];
    }
}
