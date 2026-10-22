<?php

namespace App\Models;

use Database\Factories\ClientOperatorGrantFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClientOperatorGrant extends Model
{
    /** @use HasFactory<ClientOperatorGrantFactory> */
    use HasFactory;

    protected $fillable = ['client_id', 'user_id', 'granted_by', 'is_active'];

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
