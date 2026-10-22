<?php

namespace Tests\Feature;

use App\Models\AgentRequestAudit;
use App\Models\Client;
use App\Models\User;
use App\Models\ZernioWebhookReceipt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class AgentRetentionTest extends TestCase
{
    use RefreshDatabase;

    public function test_retention_runs_in_bounded_batches_even_when_zernio_is_disabled(): void
    {
        config()->set('services.zernio.enabled', false);

        $expiredUnmatched = ZernioWebhookReceipt::factory()->create([
            'status' => 'unmatched',
            'unmatched_until' => now()->subSecond(),
            'payload_purge_after' => now()->addDays(6),
        ]);
        $expiredPayload = ZernioWebhookReceipt::factory()->create([
            'status' => 'reconciled',
            'payload_purge_after' => now()->subSecond(),
        ]);
        $freshReceipt = ZernioWebhookReceipt::factory()->create([
            'status' => 'received',
            'payload_purge_after' => now()->addDays(7),
        ]);

        $client = Client::factory()->create();
        $user = User::factory()->create();
        $tokenId = DB::table('personal_access_tokens')->insertGetId([
            'tokenable_type' => User::class,
            'tokenable_id' => $user->id,
            'client_id' => $client->id,
            'issued_by' => $user->id,
            'name' => 'retention-test',
            'token' => hash('sha256', 'retention-test-token'),
            'abilities' => json_encode(['context:read'], JSON_THROW_ON_ERROR),
            'allowed_account_ids' => '[]',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach (range(1, 101) as $index) {
            DB::table('agent_request_audits')->insert([
                'request_id' => (string) Str::uuid(),
                'client_id' => $client->id,
                'personal_access_token_id' => $tokenId,
                'method' => 'GET',
                'route_name' => 'api.v1.context',
                'status' => 200,
                'duration_ms' => $index,
                'created_at' => now()->subDays(91),
            ]);
        }

        AgentRequestAudit::query()->create([
            'request_id' => (string) Str::uuid(),
            'client_id' => $client->id,
            'personal_access_token_id' => $tokenId,
            'method' => 'GET',
            'route_name' => 'api.v1.context',
            'status' => 200,
            'duration_ms' => 1,
        ]);

        $this->artisan('agent:prune-records --limit=100')->assertSuccessful();

        $this->assertModelMissing($expiredUnmatched);
        $this->assertNull($expiredPayload->fresh()->raw_payload);
        $this->assertNotNull($freshReceipt->fresh()->raw_payload);
        $this->assertSame(1, AgentRequestAudit::query()->where('created_at', '<=', now()->subDays(90))->count());
        $this->assertSame(1, AgentRequestAudit::query()->where('created_at', '>', now()->subDays(90))->count());

        $this->artisan('agent:prune-records --limit=100')->assertSuccessful();

        $this->assertSame(0, AgentRequestAudit::query()->where('created_at', '<=', now()->subDays(90))->count());
    }
}
