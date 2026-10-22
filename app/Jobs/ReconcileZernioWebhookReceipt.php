<?php

namespace App\Jobs;

use App\Models\AgentOperation;
use App\Models\ProviderPost;
use App\Models\ZernioWebhookReceipt;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

class ReconcileZernioWebhookReceipt implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 30;

    public function __construct(public int $receiptId) {}

    public function handle(): void
    {
        $lock = Cache::lock("zernio-webhook-receipt:{$this->receiptId}", 120);

        if (! $lock->get()) {
            $this->release(5);

            return;
        }

        try {
            $receipt = ZernioWebhookReceipt::query()->find($this->receiptId);

            if ($receipt === null || $receipt->reconciled_at !== null) {
                return;
            }

            $receipt->increment('reconciliation_attempts');
            $payload = json_decode($receipt->raw_payload, true);
            $providerPostId = is_array($payload) ? data_get($payload, 'data.postId') : null;

            if (! is_string($providerPostId) || $providerPostId === '') {
                $this->markUnmatched($receipt);

                return;
            }

            $posts = ProviderPost::query()
                ->where('provider_id_hash', hash('sha256', $providerPostId))
                ->limit(2)
                ->get();

            if ($posts->count() !== 1) {
                $this->markUnmatched($receipt);

                return;
            }

            $post = $posts->sole();
            $providerStatus = data_get($payload, 'data.status');

            if (is_string($providerStatus) && $providerStatus !== '' && strlen($providerStatus) <= 64) {
                $post->update(['status' => $providerStatus]);
            }

            $operation = AgentOperation::query()
                ->where('client_id', $post->client_id)
                ->where('provider_account_id', $post->provider_account_id)
                ->whereIn('status', ['executing', 'provider-pending', 'unknown'])
                ->latest('requested_at')
                ->limit(25)
                ->get()
                ->first(fn (AgentOperation $candidate): bool => data_get($candidate->outcome, 'provider_post_id') === $post->public_id);

            if ($operation !== null && is_string($providerStatus)) {
                $operationStatus = match ($providerStatus) {
                    'published', 'completed', 'succeeded' => 'succeeded',
                    'failed' => 'failed',
                    'canceled', 'cancelled' => 'cancelled',
                    default => 'provider-pending',
                };

                $operation->update([
                    'status' => $operationStatus,
                    'completed_at' => in_array($operationStatus, ['succeeded', 'failed', 'cancelled'], true)
                        ? now()
                        : null,
                ]);
            }

            $receipt->update([
                'status' => 'reconciled',
                'reconciled_at' => now(),
            ]);
        } finally {
            $lock->release();
        }
    }

    private function markUnmatched(ZernioWebhookReceipt $receipt): void
    {
        $receipt->update(['status' => 'unmatched']);
    }
}
