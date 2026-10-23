<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\PublishZernioPostRequest;
use App\Http\Requests\Api\V1\ScheduleZernioPostRequest;
use App\Models\AgentOperation;
use App\Models\ProviderAccount;
use App\Services\ZernioBrokerWriteService;
use Illuminate\Http\JsonResponse;

class ZernioPostPublishController extends Controller
{
    public function __construct(private readonly ZernioBrokerWriteService $broker) {}

    public function store(PublishZernioPostRequest $request, ProviderAccount $account): JsonResponse
    {
        $validated = $request->validated();
        $operation = $this->broker->publish(
            $request,
            $account,
            $validated['content'],
            $validated['idempotency_key'],
        );

        return response()->json(['data' => $this->serialize($operation)], 202);
    }

    public function schedule(ScheduleZernioPostRequest $request, ProviderAccount $account): JsonResponse
    {
        $validated = $request->validated();
        $operation = $this->broker->schedule(
            $request,
            $account,
            $validated['content'],
            $validated['scheduled_for'],
            $request->file('image'),
            $validated['idempotency_key'],
        );

        return response()->json(['data' => $this->serialize($operation)], 202);
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
            'requested_at' => $operation->requested_at?->toIso8601String(),
            'started_at' => $operation->started_at?->toIso8601String(),
            'completed_at' => $operation->completed_at?->toIso8601String(),
        ];
    }
}
