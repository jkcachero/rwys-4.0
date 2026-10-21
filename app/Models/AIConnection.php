<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AIConnection extends Model
{
    use HasFactory;

    protected $table = 'ai_connections';

    protected $fillable = ['provider', 'label', 'auth_mode', 'credentials', 'model', 'status', 'last_validated_at'];

    protected function casts(): array
    {
        return [
            'credentials' => 'encrypted:array',
            'last_validated_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return array<string, mixed> */
    public function redacted(): array
    {
        return [
            'id' => $this->id,
            'provider' => $this->provider,
            'label' => $this->label,
            'authMode' => $this->auth_mode,
            'model' => $this->model,
            'status' => $this->status,
            'lastValidatedAt' => $this->last_validated_at?->toIso8601String(),
        ];
    }
}
