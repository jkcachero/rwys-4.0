<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\PersonalAccessToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ZernioAccountController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate(['limit' => ['sometimes', 'integer', 'min:1', 'max:100']]);
        /** @var Client $client */
        $client = $request->attributes->get('agent_client');
        /** @var PersonalAccessToken $token */
        $token = $request->attributes->get('agent_token');
        $limit = $validated['limit'] ?? 25;

        $accounts = $client->accountPermissions()
            ->where('is_active', true)
            ->whereHas('account', fn ($query) => $query->where('is_active', true))
            ->when($token->allowed_account_ids !== [], fn ($query) => $query
                ->whereHas('account', fn ($accountQuery) => $accountQuery
                    ->whereIn('public_id', $token->allowed_account_ids)))
            ->with('account:id,public_id,name,platform')
            ->orderBy('id')
            ->limit($limit + 1)
            ->get();
        $hasMore = $accounts->count() > $limit;

        return response()->json([
            'data' => $accounts->take($limit)->map(fn ($permission): array => [
                'id' => $permission->account->public_id,
                'name' => $permission->account->name,
                'platform' => $permission->account->platform,
            ])->values(),
            'meta' => ['limit' => $limit, 'has_more' => $hasMore],
        ]);
    }
}
