<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('zernio_webhook_receipts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('event_id')->unique();
            $table->string('event_type', 120);
            $table->char('payload_hash', 64);
            $table->longText('raw_payload')->nullable();
            $table->string('status', 32)->default('received');
            $table->unsignedSmallInteger('reconciliation_attempts')->default(0);
            $table->timestamp('received_at');
            $table->timestamp('unmatched_until');
            $table->timestamp('payload_purge_after');
            $table->timestamp('reconciled_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'received_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('zernio_webhook_receipts');
    }
};
