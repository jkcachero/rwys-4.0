<?php

namespace App\Jobs;

use App\Models\AgentOperation;
use App\Models\AgentWebhookReceipt;
use App\Services\AgentOperationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ReconcileAgentWebhook implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $receiptId) {}

    public function handle(AgentOperationService $service): void
    {
        $attemptedAt = now();
        $lease = $attemptedAt->copy()->addMinutes(10)->format('Y-m-d H:i:s.u');
        $claimed = AgentWebhookReceipt::query()->whereKey($this->receiptId)->where('status', 'pending')
            ->where(fn ($query) => $query->whereNull('processing_until')->orWhere('processing_until', '<=', $attemptedAt->format('Y-m-d H:i:s.u')))
            ->update(['last_attempted_at' => $attemptedAt->format('Y-m-d H:i:s.u'), 'processing_until' => $lease]);
        if ($claimed !== 1) {
            return;
        }

        $attributes = ['processing_until' => null, 'last_error' => null];
        try {
            $receipt = AgentWebhookReceipt::findOrFail($this->receiptId);
            if ($receipt->expires_at->lte($attemptedAt)) {
                $attributes['status'] = 'expired';

                return;
            }
            if (! config('agent-api.zernio.enabled', false)) {
                return;
            }
            $payload = json_decode($receipt->raw_body, true, 512, JSON_THROW_ON_ERROR);
            $postId = $payload['post']['id'];

            $candidates = AgentOperation::query()->where('capability', 'zernio.posts.publish')
                ->where(fn ($query) => $query->where('provider_post_id', $postId)
                    ->orWhere('recovery_post_hash', hash('sha256', 'zernio-post:'.$postId)))
                ->when($receipt->operation_cursor, fn ($query, $cursor) => $query->where('id', '>', $cursor))
                ->orderBy('id');
            $ids = (clone $candidates)->limit(6)->pluck('id');
            $hasMore = $ids->count() > 5;
            $operations = (clone $candidates)->whereKey($ids->take(5)->all())->limit(5)->get();
            $attributes['operation_cursor'] = $hasMore ? $ids[4] : null;

            $complete = $operations->isNotEmpty() && ! $receipt->operation_pass_pending;
            foreach ($operations as $operation) {
                try {
                    // A recovery hash narrows the lookup, but never authorizes a provider read.
                    if (($operation->provider_post_id ?? $operation->recovery_post_id) !== $postId) {
                        $complete = false;

                        continue;
                    }
                    $operation = $service->reconcile($operation);
                    if (! in_array($operation->state, AgentOperation::TERMINAL_STATES, true)) {
                        $complete = false;
                    }
                } catch (Throwable) {
                    $complete = false;
                    $attributes['last_error'] = 'reconciliation_failed';
                }
            }
            $attributes['operation_pass_pending'] = $hasMore && ! $complete;
            if ($complete && ! $hasMore) {
                $attributes['status'] = 'processed';
            }
        } catch (Throwable) {
            $attributes['operation_cursor'] = null;
            $attributes['operation_pass_pending'] = false;
            $attributes['last_error'] = 'reconciliation_failed';
        } finally {
            AgentWebhookReceipt::query()->whereKey($this->receiptId)->where('processing_until', $lease)
                ->update($attributes);
        }
    }
}
