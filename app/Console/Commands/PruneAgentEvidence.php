<?php

namespace App\Console\Commands;

use App\Models\AgentApiAudit;
use App\Models\AgentWebhookReceipt;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PruneAgentEvidence extends Command
{
    protected $signature = 'agent:prune-evidence {--limit=100 : Maximum rows per evidence type per batch (1-1000)} {--batches=1 : Maximum batches within a ten-second budget (1-10)}';

    protected $description = 'Redact old webhook payloads and prune completed audits without deleting deduplication records';

    public function handle(): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000]]);
        $batches = filter_var($this->option('batches'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 10]]);
        $payloadDays = filter_var(config('agent-api.webhook_payload_retention_days', 7), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $auditDays = filter_var(config('agent-api.audit_retention_days', 90), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($limit === false || $batches === false || $payloadDays === false || $auditDays === false) {
            $this->error('The limit must be an integer between 1 and 1000, batches between 1 and 10; retention days must be positive integers.');

            return self::INVALID;
        }

        $now = now();
        $deadline = $now->copy()->addSeconds(10);
        $totalRedacted = 0;
        $totalDeleted = 0;
        for ($batch = 0; $batch < $batches; $batch++) {
            if ($batch > 0 && now()->gte($deadline)) {
                break;
            }
            $receipts = AgentWebhookReceipt::query()->whereNull('payload_redacted_at')->whereNotNull('raw_body')
                ->where('created_at', '<', $now->copy()->subDays($payloadDays)->format('Y-m-d H:i:s.u'))
                ->where(fn ($query) => $query->whereNull('processing_until')->orWhere('processing_until', '<=', $now->format('Y-m-d H:i:s.u')))
                ->where(fn ($query) => $query->whereIn('status', ['processed', 'ignored', 'expired'])
                    ->orWhere(fn ($pending) => $pending->where('status', 'pending')->where('expires_at', '<=', $now->format('Y-m-d H:i:s.u'))));
            $receiptIds = (clone $receipts)->orderBy('created_at')->orderBy('id')->limit($limit)->pluck('id');
            $redacted = $receipts->whereKey($receiptIds->all())->update([
                'raw_body' => null, 'status' => DB::raw("CASE WHEN status = 'pending' THEN 'ignored' ELSE status END"),
                'payload_redacted_at' => $now->format('Y-m-d H:i:s.u'),
                'processing_until' => null, 'operation_cursor' => null, 'operation_pass_pending' => false, 'last_error' => null,
            ]);

            $audits = AgentApiAudit::query()->whereNotNull('finished_at')->where('finished_at', '<', $now->copy()->subDays($auditDays));
            $auditIds = (clone $audits)->orderBy('finished_at')->orderBy('id')->limit($limit)->pluck('id');
            $deleted = $audits->whereKey($auditIds->all())->delete();
            $totalRedacted += $redacted;
            $totalDeleted += $deleted;
            if ($redacted < $limit && $deleted < $limit) {
                break;
            }
        }
        $this->info("Redacted {$totalRedacted} webhook payload(s); deleted {$totalDeleted} completed audit(s). Deduplication records retained.");

        return self::SUCCESS;
    }
}
