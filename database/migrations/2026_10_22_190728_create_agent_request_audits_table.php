<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_request_audits', function (Blueprint $table) {
            $table->id();
            $table->uuid('request_id')->unique();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('personal_access_token_id')->constrained()->cascadeOnDelete();
            $table->string('method', 10);
            $table->string('route_name');
            $table->unsignedSmallInteger('status');
            $table->unsignedInteger('duration_ms');
            $table->timestamp('created_at')->useCurrent();
            $table->index(['client_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_request_audits');
    }
};
