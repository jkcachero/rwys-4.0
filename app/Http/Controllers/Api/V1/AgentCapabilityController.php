<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AgentAbility;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\PersonalAccessToken;
use App\Models\ProviderAccount;
use Illuminate\Contracts\Encryption\DecryptException;
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

        $hasAdKitAccess = config('services.adkit.enabled', false) === true && ProviderAccount::query()
            ->select('id', 'provider_profile_id', 'provider_id')
            ->where('is_active', true)
            ->where('platform', 'meta')
            ->whereHas('permissions', fn ($query) => $query->where('client_id', $client->id)->where('is_active', true))
            ->whereHas('profile', fn ($query) => $query
                ->where('provider', 'adkit')
                ->where('is_active', true)
                ->whereHas('clients', fn ($clients) => $clients->whereKey($client->id))
                ->whereHas('providerConnection', fn ($connection) => $connection
                    ->where('provider', 'adkit')
                    ->whereNotNull('client_id')
                    ->whereNotNull('subject')
                    ->whereNotNull('access_token')
                    ->where(fn ($expiry) => $expiry->whereNull('access_token_expires_at')
                        ->orWhere('access_token_expires_at', '>', now()))))
            ->when($token->allowed_account_ids !== [], fn ($query) => $query->whereIn('public_id', $token->allowed_account_ids))
            ->with([
                'profile:id,provider_connection_id,provider_id',
                'profile.providerConnection:id,provider,client_id,subject,access_token',
            ])
            ->lazyById(100)
            ->contains(function (ProviderAccount $account): bool {
                try {
                    $profile = $account->profile;
                    $connection = $profile->providerConnection;
                    $project = $connection?->client_id;
                    $secret = $connection?->access_token;

                    return $connection?->provider === 'adkit'
                        && is_string($project) && trim($project) !== '' && ! preg_match('/[\r\n]/', $project)
                        && $profile->provider_id === $project
                        && is_string($connection->subject) && preg_match('/\Aact_[0-9]+\z/', $connection->subject) === 1
                        && is_string($secret) && trim($secret) !== '' && ! preg_match('/[\r\n]/', $secret)
                        && is_string($account->provider_id) && preg_match('/\Aact_[0-9]+\z/', $account->provider_id) === 1;
                } catch (DecryptException) {
                    return false;
                }
            });

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
