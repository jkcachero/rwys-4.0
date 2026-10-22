<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_webhook_receipts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('event_id')->unique();
            $table->string('event', 100);
            $table->longText('raw_body')->nullable();
            $table->string('status', 20)->default('pending');
            $table->timestamp('expires_at', 6);
            $table->timestamp('last_attempted_at', 6)->nullable();
            $table->timestamp('processing_until', 6)->nullable();
            $table->timestamp('payload_redacted_at', 6)->nullable();
            $table->uuid('operation_cursor')->nullable();
            $table->boolean('operation_pass_pending')->default(false);
            $table->string('last_error', 50)->nullable();
            $table->timestamps(6);
            $table->index(['status', 'last_attempted_at', 'id']);
            $table->index(['payload_redacted_at', 'created_at', 'id'], 'agent_webhook_receipts_retention');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_webhook_receipts');
    }
};
