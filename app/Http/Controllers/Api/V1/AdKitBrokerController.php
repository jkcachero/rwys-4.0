<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ForwardAdKitRequest;
use App\Services\AdKitBrokerService;
use Illuminate\Http\JsonResponse;

class AdKitBrokerController extends Controller
{
    public function __invoke(ForwardAdKitRequest $request, AdKitBrokerService $broker): JsonResponse
    {
        return response()->json($broker->forward($request, $request->validated()));
    }
}
