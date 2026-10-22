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
        Schema::create('agent_api_audits', function (Blueprint $table) {
            $table->id();
            $table->uuid('request_id')->unique();
            $table->unsignedBigInteger('client_id');
            $table->unsignedBigInteger('token_id');
            $table->string('capability', 80);
            $table->boolean('is_write');
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->unsignedSmallInteger('status')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamps();
            $table->index(['client_id', 'started_at', 'is_write']);
            $table->index(['token_id', 'started_at', 'is_write']);
            $table->index(['client_id', 'finished_at', 'started_at']);
            $table->index(['finished_at', 'id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('agent_api_audits');
    }
};
