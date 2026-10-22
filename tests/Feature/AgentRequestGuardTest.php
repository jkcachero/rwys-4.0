<?php

namespace Tests\Feature;

use App\Models\AgentApiAudit;
use App\Models\Client;
use App\Models\User;
use App\Services\AgentTokenService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AgentRequestGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function beforeRefreshingDatabase(): void
    {
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->assertSame('sqlite', config('database.default'));
        $this->assertEmpty(config('database.connections.sqlite.url'));
    }

    public function test_requests_have_correlated_safe_errors_without_accept_header(): void
    {
        Http::preventStrayRequests();
        $response = $this->get('/api/v1/context')->assertUnauthorized()->assertJsonStructure(['error' => ['code', 'message'], 'request_id']);
        $this->assertSame($response->json('request_id'), $response->headers->get('X-Request-ID'));
        $response->assertHeader('Cache-Control', 'no-store, private')->assertDontSee('exception');
        Http::assertNothingSent();
    }

    public function test_client_budget_cannot_be_bypassed_by_issuing_another_token(): void
    {
        config(['agent-api.reads_per_minute' => 1]);
        $user = User::factory()->create();
        $client = Client::factory()->create(['owner_id' => $user->id]);
        $tokens = app(AgentTokenService::class);
        $one = $tokens->issue($user, $client, 'One', ['context.read']);
        $two = $tokens->issue($user, $client, 'Two', ['context.read']);
        $this->withToken($one->plainTextToken)->getJson('/api/v1/context')->assertOk();
        $this->withToken($two->plainTextToken)->getJson('/api/v1/context')->assertTooManyRequests()->assertHeader('Retry-After');
        $this->assertSame(1, AgentApiAudit::where('client_id', $client->id)->whereNotNull('finished_at')->count());
        $this->travel(61)->seconds();
        $this->getJson('/api/v1/context')->assertOk();
    }

    public function test_two_unfinished_requests_block_concurrency_until_lease_expires(): void
    {
        $user = User::factory()->create();
        $client = Client::factory()->create(['owner_id' => $user->id]);
        $issued = app(AgentTokenService::class)->issue($user, $client, 'Agent', ['context.read']);
        foreach (range(1, 2) as $unused) {
            AgentApiAudit::forceCreate(['request_id' => fake()->uuid(), 'client_id' => $client->id, 'token_id' => $issued->accessToken->id, 'capability' => 'context.read', 'is_write' => false, 'started_at' => now(), 'finished_at' => null]);
        }
        $this->withToken($issued->plainTextToken)->getJson('/api/v1/context')->assertTooManyRequests();
        $this->travel(121)->seconds();
        $this->getJson('/api/v1/context')->assertOk();
    }

    public function test_audit_failure_prevents_dispatch_and_does_not_leak_sql_or_credentials(): void
    {
        $user = User::factory()->create();
        $client = Client::factory()->create(['owner_id' => $user->id]);
        $issued = app(AgentTokenService::class)->issue($user, $client, 'Agent', ['context.read']);
        AgentApiAudit::creating(function (): void {
            throw new \RuntimeException('private provider-secret SQL password');
        });
        try {
            $this->withToken($issued->plainTextToken)->getJson('/api/v1/context')->assertServiceUnavailable()->assertDontSee('provider-secret')->assertDontSee('password');
        } finally {
            AgentApiAudit::flushEventListeners();
        }
    }

    public function test_session_and_legacy_tokens_do_not_gain_broker_authority(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->getJson('/api/v1/context')->assertUnauthorized();
        $legacy = $user->createToken('legacy', ['*']);
        $this->withToken($legacy->plainTextToken)->getJson('/api/v1/context')->assertUnauthorized();
    }

    public function test_disabled_provider_stops_recovery_but_not_existing_evidence_cleanup(): void
    {
        $events = collect(app(Schedule::class)->events())->filter(fn ($event): bool => str_contains($event->command ?? '', 'agent:'));
        $this->assertCount(3, $events);
        config(['agent-api.zernio.enabled' => false]);
        foreach ($events as $event) {
            $this->assertSame(str_contains($event->command, 'agent:prune-evidence'), $event->filtersPass($this->app));
        }
        config(['agent-api.zernio.enabled' => true]);
        foreach ($events as $event) {
            $this->assertTrue($event->filtersPass($this->app));
        }
    }

    public function test_api_input_is_not_silently_trimmed(): void
    {
        $user = User::factory()->create();
        $client = Client::factory()->create(['owner_id' => $user->id]);
        $issued = app(AgentTokenService::class)->issue($user, $client, 'Agent', ['operations.read']);
        $this->withToken($issued->plainTextToken)->getJson('/api/v1/operations?after=%20'.fake()->uuid().'%20')->assertUnprocessable();
    }
}
