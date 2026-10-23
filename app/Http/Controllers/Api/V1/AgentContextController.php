<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AgentAbility;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\PersonalAccessToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AgentContextController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        /** @var Client $client */
        $client = $request->attributes->get('agent_client');
        /** @var PersonalAccessToken $token */
        $token = $request->attributes->get('agent_token');

        return response()->json([
            'client' => ['id' => $client->public_id, 'name' => $client->name],
            'capabilities' => array_values(array_intersect($token->abilities, AgentAbility::implemented())),
        ]);
    }
}
