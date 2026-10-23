<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_account_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('provider_account_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_active')->default(true)->index();
            $table->boolean('can_read')->default(true);
            $table->boolean('can_publish')->default(false);
            $table->boolean('can_schedule')->default(false);
            $table->timestamps();
            $table->unique(['client_id', 'provider_account_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_account_permissions');
    }
};
