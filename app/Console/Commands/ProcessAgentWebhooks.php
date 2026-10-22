<?php

namespace App\Console\Commands;

use App\Jobs\ReconcileAgentWebhook;
use App\Models\AgentWebhookReceipt;
use App\Services\AgentOperationService;
use Illuminate\Console\Command;
use Throwable;

class ProcessAgentWebhooks extends Command
{
    protected $signature = 'agent:process-webhooks {--limit=25 : Maximum receipts to inspect (1-100)}';

    protected $description = 'Process durable agent webhook receipts using provider reads only';

    public function handle(AgentOperationService $service): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100]]);
        if ($limit === false) {
            $this->error('The limit must be an integer between 1 and 100.');

            return self::INVALID;
        }
        $receipts = AgentWebhookReceipt::query()->where('status', 'pending')
            ->where(fn ($query) => $query->whereNull('processing_until')->orWhere('processing_until', '<=', now()->format('Y-m-d H:i:s.u')))
            ->orderByRaw('COALESCE(last_attempted_at, created_at) ASC')
            ->orderBy('id')->limit($limit)->get(['id']);
        $failures = 0;
        foreach ($receipts as $receipt) {
            try {
                (new ReconcileAgentWebhook($receipt->id))->handle($service);
                if ($receipt->fresh()?->last_error !== null) {
                    $failures++;
                }
            } catch (Throwable) {
                $failures++;
            }
        }
        $this->info("Inspected {$receipts->count()} receipt(s); {$failures} failure(s). Pending receipts remain retryable.");

        return self::SUCCESS;
    }
}
