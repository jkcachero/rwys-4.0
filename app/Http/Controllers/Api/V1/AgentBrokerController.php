<?php

namespace App\Http\Controllers\Api\V1;

use App\AdKit\Services\AdKitReadService;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Zernio\Services\ZernioReadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Throwable;

class AgentBrokerController extends Controller
{
    public function __construct(
        private readonly AdKitReadService $adKit,
        private readonly ZernioReadService $zernio,
    ) {}

    public function accounts(Request $request, Client $client): JsonResponse
    {
        abort_unless($client->user_id === $request->user()->id, 403);
        abort_unless(in_array('clients:read', $request->user()->currentAccessToken()->abilities, true), 403);

        $validated = $request->validate([
            'provider' => ['required', 'string', Rule::in(['adkit', 'zernio'])],
            'scope_id' => ['required', 'string', 'max:100'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        try {
            $accounts = $validated['provider'] === 'adkit'
                ? $this->adKit->accounts($validated['scope_id'], $validated['limit'] ?? 50)
                : $this->zernio->accounts($validated['scope_id'], $validated['limit'] ?? 50);
        } catch (Throwable) {
            return response()->json([
                'error' => [
                    'code' => 'provider_unavailable',
                    'message' => 'The requested provider is unavailable.',
                ],
            ], 503);
        }

        return response()->json([
            'data' => $accounts,
            'meta' => [
                'client' => $client->slug,
                'provider' => $validated['provider'],
                'count' => count($accounts),
            ],
        ]);
    }
}
