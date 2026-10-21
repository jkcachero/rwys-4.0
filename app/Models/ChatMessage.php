<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChatMessage extends Model
{
    protected $fillable = ['role', 'status', 'content', 'request_id'];

    protected function casts(): array
    {
        return ['content' => 'encrypted'];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(ChatConversation::class, 'conversation_id');
    }

    /** @return array{id: int, role: string, content: string, status: string, requestId: ?string, createdAt: ?string} */
    public function message(): array
    {
        return [
            'id' => $this->id, 'role' => $this->role, 'content' => $this->content,
            'status' => $this->status, 'createdAt' => $this->created_at?->toIso8601String(),
            'requestId' => $this->request_id,
        ];
    }
}
