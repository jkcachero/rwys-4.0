<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ProviderAccount;
use App\Services\ZernioBrokerReadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ZernioAccountStatusController extends Controller
{
    public function __invoke(
        Request $request,
        ProviderAccount $account,
        ZernioBrokerReadService $zernio,
    ): JsonResponse {
        abort_unless($request->query->count() === 0, 422, 'Query parameters are not supported.');

        return response()->json(['data' => $zernio->accountStatus($request, $account)]);
    }
}
