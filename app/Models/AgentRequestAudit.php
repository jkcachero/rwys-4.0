<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AgentRequestAudit extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'request_id',
        'client_id',
        'personal_access_token_id',
        'method',
        'route_name',
        'status',
        'duration_ms',
    ];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }
}
