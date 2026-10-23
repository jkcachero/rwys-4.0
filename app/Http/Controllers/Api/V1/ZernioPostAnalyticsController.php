<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ProviderAccount;
use App\Models\ProviderPost;
use App\Services\ZernioBrokerReadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ZernioPostAnalyticsController extends Controller
{
    public function __invoke(
        Request $request,
        ProviderAccount $account,
        ProviderPost $post,
        ZernioBrokerReadService $zernio,
    ): JsonResponse {
        abort_unless($request->query->count() === 0, 422, 'Query parameters are not supported.');
        $data = $zernio->analytics($request, $account, $post);
        $status = $data['state'] === 'pending' ? 202 : 200;

        return response()->json(['data' => $data], $status);
    }
}
