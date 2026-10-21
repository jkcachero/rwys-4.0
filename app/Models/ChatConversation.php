<?php

namespace App\Models;

use Database\Factories\ChatConversationFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChatConversation extends Model
{
    /** @use HasFactory<ChatConversationFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['title', 'provider', 'auth_mode', 'connection_id', 'model', 'thinking_level'];

    protected function casts(): array
    {
        return ['title' => 'encrypted', 'connection_id' => 'integer'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ChatMessage::class, 'conversation_id');
    }

    /** @return array{id: string, title: string, provider: ?string, authMode: ?string, connectionId: ?int, model: ?string, thinkingLevel: ?string, updatedAt: ?string} */
    public function metadata(): array
    {
        return [
            'id' => $this->id, 'title' => $this->title, 'provider' => $this->provider,
            'authMode' => $this->auth_mode, 'connectionId' => $this->connection_id,
            'model' => $this->model, 'thinkingLevel' => $this->thinking_level,
            'updatedAt' => $this->updated_at?->toIso8601String(),
        ];
    }
}
