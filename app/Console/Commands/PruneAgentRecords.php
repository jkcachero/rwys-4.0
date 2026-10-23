<?php

namespace App\Console\Commands;

use App\Models\AgentRequestAudit;
use App\Models\ZernioWebhookReceipt;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('agent:prune-records {--limit=100 : Maximum rows to process per retention category}')]
#[Description('Prune expired agent API audits and Zernio webhook data in bounded batches')]
class PruneAgentRecords extends Command
{
    public function handle(): int
    {
        $limit = max(1, min((int) $this->option('limit'), 1000));

        $expiredUnmatchedIds = ZernioWebhookReceipt::query()
            ->where('status', 'unmatched')
            ->where('unmatched_until', '<=', now())
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');
        $deletedReceipts = ZernioWebhookReceipt::query()
            ->whereIn('id', $expiredUnmatchedIds)
            ->delete();

        $expiredPayloadIds = ZernioWebhookReceipt::query()
            ->whereNotNull('raw_payload')
            ->where('payload_purge_after', '<=', now())
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');
        $purgedPayloads = ZernioWebhookReceipt::query()
            ->whereIn('id', $expiredPayloadIds)
            ->update(['raw_payload' => null]);

        $expiredAuditIds = AgentRequestAudit::query()
            ->where('created_at', '<=', now()->subDays(90))
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');
        $deletedAudits = AgentRequestAudit::query()
            ->whereIn('id', $expiredAuditIds)
            ->delete();

        $this->components->info(
            "Deleted {$deletedReceipts} unmatched receipts, purged {$purgedPayloads} payloads, and deleted {$deletedAudits} request audits.",
        );

        return self::SUCCESS;
    }
}
