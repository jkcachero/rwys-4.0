<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Sanctum\PersonalAccessToken;

class AgentAccessToken extends PersonalAccessToken
{
    protected $table = 'personal_access_tokens';

    protected function casts(): array
    {
        return [...parent::casts(), 'client_id' => 'integer', 'allowed_account_ids' => 'array'];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
