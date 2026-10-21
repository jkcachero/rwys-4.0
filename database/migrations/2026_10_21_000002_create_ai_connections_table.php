<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_connections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider');
            $table->string('label');
            $table->string('auth_mode')->default('api_key');
            $table->text('credentials');
            $table->string('model')->nullable();
            $table->string('status')->default('active');
            $table->timestamp('last_validated_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'provider', 'label']);
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_connections');
    }
};
