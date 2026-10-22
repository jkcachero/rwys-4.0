<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Schema;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

$agentRecoveryEnabled = fn (): bool => config('agent-api.zernio.enabled', false) === true
    && Schema::hasTable('agent_operations') && Schema::hasTable('clients')
    && Schema::hasTable('agent_webhook_receipts') && Schema::hasTable('agent_api_audits');

Schedule::command('agent:process-webhooks --limit=25')->everyMinute()->when($agentRecoveryEnabled)->withoutOverlapping(60);
Schedule::command('agent:reconcile-operations --limit=25')->everyMinute()->when($agentRecoveryEnabled)->withoutOverlapping(60);
Schedule::command('agent:prune-evidence --limit=1000 --batches=10')->everyMinute()
    ->when(fn (): bool => Schema::hasTable('agent_webhook_receipts') && Schema::hasTable('agent_api_audits'))
    ->withoutOverlapping(5);
