<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_account_permissions', function (Blueprint $table) {
            $table->dropColumn(['can_read', 'can_publish']);
        });
    }

    public function down(): void
    {
        Schema::table('client_account_permissions', function (Blueprint $table) {
            $table->boolean('can_read')->default(true)->after('is_active');
            $table->boolean('can_publish')->default(false)->after('can_read');
        });
    }
};
