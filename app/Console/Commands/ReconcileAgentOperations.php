<?php

namespace App\Console\Commands;

use App\Models\AgentOperation;
use App\Services\AgentOperationService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Throwable;

class ReconcileAgentOperations extends Command
{
    protected $signature = 'agent:reconcile-operations {--limit=25 : Maximum operations to inspect (1-100)} {--operation= : Reconcile only this operation UUID}';

    protected $description = 'Reconcile unresolved agent operations using provider reads only';

    public function handle(AgentOperationService $service): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100]]);
        if ($limit === false) {
            $this->error('The limit must be an integer between 1 and 100.');

            return self::INVALID;
        }
        $operationId = $this->option('operation');
        if (($operationId !== null || $this->input->hasParameterOption('--operation')) && ! Str::isUuid($operationId)) {
            $this->error('The operation must be a valid UUID.');

            return self::INVALID;
        }

        $operations = AgentOperation::query()->whereIn('state', AgentOperation::RECONCILABLE_STATES)
            ->when($operationId !== null, fn (Builder $query): Builder => $query->whereKey($operationId))
            ->where(fn (Builder $query): Builder => $query->whereNull('reconcile_lease_until')->orWhere('reconcile_lease_until', '<=', now()->format('Y-m-d H:i:s.u')))
            ->orderByRaw('COALESCE(last_reconciled_at, created_at) ASC')
            ->orderBy('id')->limit($limit)->get();

        foreach ($operations as $operation) {
            try {
                $service->reconcile($operation);
            } catch (Throwable) {
                $this->warn("Could not reconcile operation {$operation->id}.");
            }
        }
        $this->info("Inspected {$operations->count()} operation(s).");

        return self::SUCCESS;
    }
}
