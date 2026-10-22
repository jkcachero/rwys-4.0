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
        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name', 100);
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['owner_id', 'name']);
        });
        Schema::create('client_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('clients')->restrictOnDelete();
            $table->string('provider', 30);
            $table->string('environment', 30)->default('production');
            $table->string('profile_id', 100);
            $table->string('provider_account_id', 100);
            $table->string('platform', 30);
            $table->boolean('active')->default(true);
            $table->boolean('publishing_enabled')->default(false);
            $table->char('mapping_hash', 64)->nullable()->index();
            $table->timestamps();
            $table->unique(['provider', 'environment', 'provider_account_id']);
            $table->index(['client_id', 'active']);
        });
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->foreignId('client_id')->nullable()->constrained('clients')->restrictOnDelete();
            $table->json('allowed_account_ids')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->dropConstrainedForeignId('client_id');
            $table->dropColumn('allowed_account_ids');
        });
        Schema::dropIfExists('client_accounts');
        Schema::dropIfExists('clients');
    }
};
