<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('chat_conversations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('title');
            $table->string('provider')->nullable();
            $table->string('auth_mode')->nullable();
            $table->foreignId('connection_id')->nullable()->constrained('ai_connections')->nullOnDelete();
            $table->string('model')->nullable();
            $table->string('thinking_level', 20)->nullable();
            $table->timestamps();
            $table->index(['user_id', 'updated_at', 'id']);
        });

        Schema::create('chat_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('conversation_id')->constrained('chat_conversations')->cascadeOnDelete();
            $table->enum('role', ['user', 'assistant']);
            $table->enum('status', ['pending', 'completed', 'failed']);
            $table->longText('content');
            $table->uuid('request_id')->nullable();
            $table->timestamps();
            $table->unique(['conversation_id', 'request_id', 'role']);
            $table->index(['conversation_id', 'id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('chat_messages');
        Schema::dropIfExists('chat_conversations');
    }
};
