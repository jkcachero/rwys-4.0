<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->foreignId('client_id')->nullable()->after('tokenable_id')->constrained()->cascadeOnDelete();
            $table->foreignId('issued_by')->nullable()->after('client_id')->constrained('users')->nullOnDelete();
            $table->foreignId('rotated_from_id')->nullable()->after('issued_by')->constrained('personal_access_tokens')->nullOnDelete();
            $table->json('allowed_account_ids')->after('abilities')->default('[]');
            $table->timestamp('revoked_at')->nullable()->after('last_used_at')->index();
        });
    }

    public function down(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->dropIndex(['revoked_at']);
            $table->dropConstrainedForeignId('rotated_from_id');
            $table->dropConstrainedForeignId('client_id');
            $table->dropConstrainedForeignId('issued_by');
            $table->dropColumn(['allowed_account_ids', 'revoked_at']);
        });
    }
};
