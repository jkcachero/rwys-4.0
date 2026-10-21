<?php

namespace App\Http\Controllers;

use App\Http\Requests\AIConnectionRequest;
use App\Models\AIConnection;
use App\Services\AIConnectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AIConnectionController extends Controller
{
    public function __construct(private readonly AIConnectionService $connections) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json(['connections' => $this->connections->listFor($request->user())]);
    }

    public function store(AIConnectionRequest $request): JsonResponse
    {
        $connection = $this->connections->create($request->user(), $request->validated());

        return response()->json(['connection' => $connection->redacted()], 201);
    }

    public function destroy(Request $request, AIConnection $connection): JsonResponse
    {
        $this->connections->revoke($request->user(), $connection);

        return response()->json(['status' => 'revoked']);
    }
}
