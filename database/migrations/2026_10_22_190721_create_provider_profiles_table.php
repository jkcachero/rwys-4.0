<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_profiles', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('provider_connection_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider')->index();
            $table->text('provider_id');
            $table->char('provider_id_hash', 64);
            $table->string('name');
            $table->timestamp('synchronized_at')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
            $table->unique(['provider', 'provider_id_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_profiles');
    }
};
