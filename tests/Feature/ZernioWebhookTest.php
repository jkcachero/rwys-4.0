<?php

namespace Tests\Feature;

use App\Jobs\ReconcileZernioWebhookReceipt;
use App\Models\AgentOperation;
use App\Models\Client;
use App\Models\ProviderAccount;
use App\Models\ProviderPost;
use App\Models\ZernioWebhookReceipt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class ZernioWebhookTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.zernio.webhook_secret', 'webhook-secret');
        Bus::fake();
    }

    public function test_verified_webhook_is_durable_deduplicated_and_acknowledged(): void
    {
        $payload = json_encode([
            'id' => '019a14fc-cfb8-7a47-9a7f-a4d8f13d34a1',
            'type' => 'post.updated',
            'data' => ['postId' => '0123456789abcdef01234567'],
        ], JSON_THROW_ON_ERROR);
        $signature = hash_hmac('sha256', $payload, 'webhook-secret');

        $this->call('POST', '/api/webhooks/zernio', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_ZERNIO_SIGNATURE' => $signature,
        ], $payload)->assertNoContent();

        $this->call('POST', '/api/webhooks/zernio', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_ZERNIO_SIGNATURE' => $signature,
        ], $payload)->assertNoContent();

        $this->assertDatabaseCount('zernio_webhook_receipts', 1);
        $receipt = ZernioWebhookReceipt::query()->sole();
        $this->assertSame('019a14fc-cfb8-7a47-9a7f-a4d8f13d34a1', $receipt->event_id);
        $this->assertSame('post.updated', $receipt->event_type);
        $this->assertSame($payload, $receipt->raw_payload);
        $this->assertStringNotContainsString('post.updated', (string) $receipt->getRawOriginal('raw_payload'));
        Bus::assertDispatchedTimes(ReconcileZernioWebhookReceipt::class, 1);
    }

    public function test_webhook_rejects_missing_secret_invalid_signatures_and_oversized_bodies(): void
    {
        $payload = json_encode([
            'id' => '019a14fc-cfb8-7a47-9a7f-a4d8f13d34a2',
            'type' => 'post.updated',
        ], JSON_THROW_ON_ERROR);

        config()->set('services.zernio.webhook_secret', null);
        $this->call('POST', '/api/webhooks/zernio', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_ZERNIO_SIGNATURE' => hash_hmac('sha256', $payload, 'webhook-secret'),
        ], $payload)->assertServiceUnavailable();

        config()->set('services.zernio.webhook_secret', 'webhook-secret');
        $this->call('POST', '/api/webhooks/zernio', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_ZERNIO_SIGNATURE' => str_repeat('A', 64),
        ], $payload)->assertUnauthorized();

        $oversizedPayload = str_repeat('x', 1_048_577);
        $this->call('POST', '/api/webhooks/zernio', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_ZERNIO_SIGNATURE' => hash_hmac('sha256', $oversizedPayload, 'webhook-secret'),
        ], $oversizedPayload)->assertStatus(413);

        $this->assertDatabaseCount('zernio_webhook_receipts', 0);
        Bus::assertNothingDispatched();
    }

    public function test_reconciliation_updates_the_canonical_post_and_operation_in_a_bounded_lookup(): void
    {
        $client = Client::factory()->create();
        $account = ProviderAccount::factory()->create();
        $providerPostId = '0123456789abcdef01234567';
        $post = ProviderPost::factory()->create([
            'client_id' => $client->id,
            'provider_account_id' => $account->id,
            'provider_id' => $providerPostId,
            'provider_id_hash' => hash('sha256', $providerPostId),
            'status' => 'scheduled',
        ]);
        $operation = AgentOperation::factory()->create([
            'client_id' => $client->id,
            'provider_account_id' => $account->id,
            'status' => 'provider-pending',
            'outcome' => ['provider_post_id' => $post->public_id],
        ]);
        $payload = json_encode([
            'id' => '019a14fc-cfb8-7a47-9a7f-a4d8f13d34a3',
            'type' => 'post.updated',
            'data' => [
                'postId' => $providerPostId,
                'status' => 'published',
            ],
        ], JSON_THROW_ON_ERROR);
        $receipt = ZernioWebhookReceipt::factory()->create([
            'event_id' => '019a14fc-cfb8-7a47-9a7f-a4d8f13d34a3',
            'raw_payload' => $payload,
            'payload_hash' => hash('sha256', $payload),
        ]);

        (new ReconcileZernioWebhookReceipt($receipt->id))->handle();

        $this->assertSame('published', $post->fresh()->status);
        $this->assertSame('succeeded', $operation->fresh()->status);
        $this->assertNotNull($operation->fresh()->completed_at);
        $this->assertSame('reconciled', $receipt->fresh()->status);
        $this->assertSame(1, $receipt->fresh()->reconciliation_attempts);
        $this->assertNotNull($receipt->fresh()->reconciled_at);
    }
}
