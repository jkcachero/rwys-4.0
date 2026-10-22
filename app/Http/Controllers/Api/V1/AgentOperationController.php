<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AgentOperation;
use App\Models\Client;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class AgentOperationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->rejectUnknownQuery($request, ['limit', 'cursor']);
        $validated = $request->validate([
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'cursor' => ['sometimes', 'uuid'],
        ]);
        $client = $request->attributes->get('agent_client');
        abort_unless($client instanceof Client, 403);
        $limit = (int) ($validated['limit'] ?? 25);
        $query = AgentOperation::query()
            ->whereBelongsTo($client)
            ->latest('requested_at')
            ->latest('id');

        if (isset($validated['cursor'])) {
            $cursor = AgentOperation::query()
                ->whereBelongsTo($client)
                ->where('public_id', $validated['cursor'])
                ->firstOrFail();
            $query->where($this->beforeCursor($cursor));
        }

        $operations = $query->limit($limit + 1)->get();
        $hasMore = $operations->count() > $limit;
        $operations = $operations->take($limit)->values();

        return response()->json([
            'data' => $operations->map(fn (AgentOperation $operation): array => $this->serialize($operation)),
            'meta' => [
                'limit' => $limit,
                'next_cursor' => $hasMore ? $operations->last()?->public_id : null,
            ],
        ]);
    }

    public function show(Request $request, AgentOperation $operation): JsonResponse
    {
        $this->rejectUnknownQuery($request, []);
        $client = $request->attributes->get('agent_client');
        abort_unless($client instanceof Client && $operation->client_id === $client->getKey(), 404);

        return response()->json(['data' => $this->serialize($operation)]);
    }

    private function beforeCursor(AgentOperation $cursor): \Closure
    {
        return function (Builder $query) use ($cursor): void {
            $query->where('requested_at', '<', $cursor->requested_at)
                ->orWhere(function (Builder $query) use ($cursor): void {
                    $query->where('requested_at', $cursor->requested_at)
                        ->where('id', '<', $cursor->getKey());
                });
        };
    }

    /** @return array<string, mixed> */
    private function serialize(AgentOperation $operation): array
    {
        return [
            'id' => $operation->public_id,
            'capability' => $operation->capability,
            'status' => $operation->status,
            'account_id' => $operation->providerAccount?->public_id,
            'outcome' => $operation->outcome,
            'requested_at' => $operation->requested_at->toIso8601String(),
            'started_at' => $operation->started_at?->toIso8601String(),
            'completed_at' => $operation->completed_at?->toIso8601String(),
        ];
    }

    /** @param list<string> $allowed */
    private function rejectUnknownQuery(Request $request, array $allowed): void
    {
        $unknown = array_values(array_diff(array_keys($request->query()), $allowed));

        if ($unknown !== []) {
            throw ValidationException::withMessages([
                $unknown[0] => 'This query parameter is not supported.',
            ]);
        }
    }
}
