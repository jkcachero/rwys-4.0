<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AgentApiAudit extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_write' => 'boolean', 'started_at' => 'datetime', 'finished_at' => 'datetime'];
    }
}
