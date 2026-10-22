<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $groups = DB::table('client_accounts')->select('provider', 'environment', 'profile_id')
            ->groupBy('provider', 'environment', 'profile_id');
        if ((clone $groups)->havingRaw('COUNT(DISTINCT client_id) > 1')->exists()) {
            throw new RuntimeException('Provider profile ownership conflicts must be resolved before migration.');
        }

        Schema::create('client_provider_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('client_id')->constrained('clients')->restrictOnDelete();
            $table->string('provider', 30);
            $table->string('environment', 30);
            $table->string('profile_id', 100);
            $table->text('name')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamp('last_synced_at')->nullable();
            $table->unsignedBigInteger('sync_version')->default(0);
            $table->timestamps();
            $table->unique(['provider', 'environment', 'profile_id'], 'client_provider_profiles_identity_unique');
        });
        Schema::table('client_accounts', function (Blueprint $table): void {
            $table->foreignId('client_provider_profile_id')->nullable()->constrained('client_provider_profiles')->restrictOnDelete();
        });

        foreach ($groups->selectRaw('MIN(client_id) AS client_id')
            ->orderBy('provider')->orderBy('environment')->orderBy('profile_id')->lazy(100) as $group) {
            $identity = ['provider' => $group->provider, 'environment' => $group->environment, 'profile_id' => $group->profile_id];
            $profileId = DB::table('client_provider_profiles')->insertGetId([
                ...$identity, 'client_id' => $group->client_id, 'name' => null, 'active' => true,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('client_accounts')->where($identity)->update(['client_provider_profile_id' => $profileId]);
        }
    }

    public function down(): void
    {
        Schema::table('client_accounts', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('client_provider_profile_id');
        });
        Schema::dropIfExists('client_provider_profiles');
    }
};
