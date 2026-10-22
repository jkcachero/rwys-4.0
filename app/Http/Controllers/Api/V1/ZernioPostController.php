<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ProviderAccount;
use App\Models\ProviderPost;
use App\Services\ZernioBrokerReadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ZernioPostController extends Controller
{
    public function index(
        Request $request,
        ProviderAccount $account,
        ZernioBrokerReadService $zernio,
    ): JsonResponse {
        $this->rejectUnknownQuery($request, ['limit', 'cursor']);
        $validated = $request->validate([
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'cursor' => ['sometimes', 'string', 'max:512'],
        ]);
        $limit = (int) ($validated['limit'] ?? 25);
        $result = $zernio->posts($request, $account, $limit, $validated['cursor'] ?? null);

        return response()->json([
            'data' => $result['data'],
            'meta' => [
                'limit' => $limit,
                'next_cursor' => $result['next_cursor'],
            ],
        ]);
    }

    public function show(
        Request $request,
        ProviderAccount $account,
        ProviderPost $post,
        ZernioBrokerReadService $zernio,
    ): JsonResponse {
        $this->rejectUnknownQuery($request, []);

        return response()->json(['data' => $zernio->post($request, $account, $post)]);
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
