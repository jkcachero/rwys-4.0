<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AgentAbility;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\PersonalAccessToken;
use App\Models\ProviderAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AgentCapabilityController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        abort_unless($request->query->count() === 0, 422, 'Query parameters are not supported.');

        $client = $request->attributes->get('agent_client');
        $token = $request->attributes->get('agent_token');
        abort_unless($client instanceof Client && $token instanceof PersonalAccessToken, 403);

        $hasProviderAccess = config('services.zernio.enabled') && $client->accountPermissions()
            ->where('is_active', true)
            ->whereHas('account', function ($query) use ($token): void {
                $query->where('is_active', true)
                    ->whereHas('profile', fn ($profile) => $profile
                        ->where('provider', 'zernio')
                        ->where('is_active', true)
                        ->whereNotNull('provider_connection_id'));

                if ($token->allowed_account_ids !== []) {
                    $query->whereIn('public_id', $token->allowed_account_ids);
                }
            })
            ->exists();

        $hasAdKitAccess = ProviderAccount::query()
            ->select('id', 'provider_profile_id', 'provider_id', 'platform', 'is_active')
            ->assignedAdKitTo($client)
            ->when($token->allowed_account_ids !== [], fn ($query) => $query->whereIn('public_id', $token->allowed_account_ids))
            ->with([
                'profile:id,provider_connection_id,provider_id,provider,is_active',
                'profile.providerConnection:id,provider,client_id,subject,access_token,access_token_expires_at',
            ])
            ->lazyById(100)
            ->contains(fn (ProviderAccount $account): bool => $account->hasUsableAdKitConnection());

        $capabilities = collect(AgentAbility::clientTokenDefaults())
            ->filter(fn (string $ability): bool => $token->can($ability))
            ->filter(fn (string $ability): bool => ! in_array($ability, [
                AgentAbility::PostsRead->value,
                AgentAbility::AnalyticsRead->value,
            ], true) || $hasProviderAccess)
            ->filter(fn (string $ability): bool => $ability !== AgentAbility::AdKitRead->value || $hasAdKitAccess)
            ->values()
            ->all();

        return response()->json(['capabilities' => $capabilities]);
    }
}
