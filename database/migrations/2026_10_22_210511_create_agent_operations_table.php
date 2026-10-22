<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_operations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->foreignId('principal_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('personal_access_token_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('provider_account_id')->nullable()->constrained()->nullOnDelete();
            $table->string('capability');
            $table->char('idempotency_key_hash', 64)->nullable();
            $table->char('payload_hash', 64)->nullable();
            $table->text('intent')->nullable();
            $table->string('status');
            $table->text('outcome')->nullable();
            $table->text('recovery_context')->nullable();
            $table->timestamp('requested_at');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['client_id', 'requested_at', 'id']);
            $table->unique(['client_id', 'capability', 'idempotency_key_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_operations');
    }
};
