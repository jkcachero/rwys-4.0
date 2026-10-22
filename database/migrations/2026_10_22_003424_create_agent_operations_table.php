<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_operations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            // Retain deduplication records even if the client or mapping is removed.
            $table->unsignedBigInteger('client_id');
            $table->unsignedBigInteger('client_account_id');
            $table->string('capability', 40);
            $table->char('key_hash', 64);
            $table->char('payload_hash', 64);
            $table->char('mapping_hash', 64);
            $table->string('state', 24)->default('pending');
            $table->longText('intent');
            $table->longText('result')->nullable();
            $table->text('recovery_post_id')->nullable();
            $table->char('recovery_post_hash', 64)->nullable()->index();
            $table->string('provider_post_id')->nullable();
            $table->unsignedSmallInteger('reconcile_page')->default(1);
            $table->uuid('reconcile_lease_token')->nullable();
            $table->timestamp('reconcile_lease_until', 6)->nullable();
            $table->timestamp('last_reconciled_at', 6)->nullable();
            $table->timestamps(6);
            $table->unique(['client_id', 'capability', 'key_hash'], 'agent_operations_dedup');
            $table->index(['state', 'last_reconciled_at', 'id'], 'agent_operations_reconcile');
            $table->index(['client_id', 'client_account_id', 'mapping_hash', 'id'], 'agent_operations_mapping');
            $table->index(['capability', 'provider_post_id', 'id'], 'agent_operations_provider_post');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_operations');
    }
};
