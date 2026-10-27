<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\IssueAgentTokenRequest;
use App\Http\Requests\Settings\RotateAgentTokenRequest;
use App\Http\Requests\Settings\UpdateAgentPermissionRequest;
use App\Models\Client;
use App\Models\ClientAccountPermission;
use App\Models\PersonalAccessToken;
use App\Models\ProviderAccount;
use App\Models\User;
use App\Services\AgentTokenService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class AgentAccessController extends Controller
{
    public function edit(Request $request): Response
    {
        $validated = $request->validate([
            'client' => ['sometimes', 'uuid'],
            'clients_page' => ['sometimes', 'integer', 'min:1', 'max:100000'],
            'assigned_page' => ['sometimes', 'integer', 'min:1', 'max:100000'],
        ]);
        $user = $request->user();
        $isAdmin = $user->hasVerifiedEmail() && $user->isAdmin();
        $clientQuery = Client::query()
            ->where('is_active', true)
            ->when($isAdmin, fn ($query) => $query->whereHas('users', fn ($owners) => $owners
                ->where('client_user.role', 'owner')
                ->where('client_user.is_active', true)
                ->whereNotNull('users.email_verified_at')))
            ->when(! $isAdmin, fn ($query) => $query->where(function ($query) use ($user): void {
                $query->whereHas('users', fn ($users) => $users
                    ->whereKey($user->id)
                    ->where('client_user.is_active', true))
                    ->orWhereHas('operatorGrants', fn ($grants) => $grants
                        ->where('user_id', $user->id)
                        ->where('is_active', true));
            }))
            ->with('users:id,name,email')
            ->withCount([
                'accountPermissions as social_account_count' => fn ($permissions) => $permissions
                    ->where('is_active', true)
                    ->whereHas('account.profile', fn ($profile) => $profile->where('provider', 'zernio')
                        ->whereHas('clients', fn ($clients) => $clients
                            ->whereColumn('client_provider_profiles.client_id', 'client_account_permissions.client_id'))),
                'accountPermissions as ad_account_count' => fn ($permissions) => $permissions
                    ->where('is_active', true)
                    ->whereHas('account.profile', fn ($profile) => $profile->where('provider', 'adkit')),
            ]);
        $clientPage = (clone $clientQuery)->orderBy('name')->orderBy('id')->simplePaginate(100, ['*'], 'clients_page');
        $clients = $clientPage->getCollection();
        $assignedPage = (clone $clientQuery)
            ->where(fn ($query) => $query
                ->whereHas('providerProfiles', fn ($profiles) => $profiles->where('provider', 'zernio')->where('is_active', true))
                ->orWhereHas('accountPermissions', fn ($permissions) => $permissions->where('is_active', true)
                    ->whereHas('account.profile', fn ($profile) => $profile->where('provider', 'adkit'))))
            ->orderBy('name')->orderBy('id')->simplePaginate(25, ['*'], 'assigned_page');

        $selectedClient = isset($validated['client'])
            ? (clone $clientQuery)->where('public_id', $validated['client'])->firstOrFail()
            : ($assignedPage->getCollection()->first() ?? $clients->first());
        if ($selectedClient !== null && ! $clients->contains('id', $selectedClient->id)) {
            $clients->push($selectedClient);
        }

        $props = [
            'clients' => $clients->map(fn (Client $client): array => $this->clientSummary($client))->values(),
            'can_assign_accounts' => $isAdmin,
            'assigned_users' => $assignedPage->getCollection()->map(fn (Client $client): array => $this->clientSummary($client))->values(),
            'assigned_users_pagination' => $assignedPage->hasMorePages() || $assignedPage->currentPage() > 1
                ? ['page' => $assignedPage->currentPage(), 'has_more' => $assignedPage->hasMorePages()]
                : null,
            'client_pagination' => $clientPage->hasMorePages() || $clientPage->currentPage() > 1
                ? ['page' => $clientPage->currentPage(), 'has_more' => $clientPage->hasMorePages()]
                : null,
            'selectedClient' => $selectedClient === null
                ? null
                : $this->clientDetails($selectedClient, $request),
        ];

        if ($selectedClient !== null && $selectedClient->hasActiveOwner($user)
            && $request->session()->get('agent_token_client_id') === $selectedClient->public_id
            && $request->session()->has('agent_token_plaintext')) {
            $props['token_plaintext'] = $request->session()->get('agent_token_plaintext');
            $props['token_notice'] = $request->session()->get('agent_token_notice');
        }

        return Inertia::render('settings/agent-access', $props);
    }

    public function updatePermission(
        UpdateAgentPermissionRequest $request,
        Client $client,
        ClientAccountPermission $permission,
    ): RedirectResponse {
        $permission->update($request->validated());

        return $this->redirectToClient($client)->with('status', 'Account permissions updated.');
    }

    public function storeToken(
        IssueAgentTokenRequest $request,
        Client $client,
        AgentTokenService $tokens,
    ): RedirectResponse {
        $validated = $request->validated();
        $token = $tokens->issue(
            $request->user(),
            $client,
            $validated['name'],
            Carbon::parse($validated['expires_at']),
            $validated['allowed_account_ids'],
        );

        return $this->redirectToClient($client)
            ->with('agent_token_plaintext', $token->plainTextToken)
            ->with('agent_token_client_id', $client->public_id)
            ->with('agent_token_notice', 'Copy this token now. It will not be shown again.');
    }

    public function rotateToken(
        RotateAgentTokenRequest $request,
        Client $client,
        PersonalAccessToken $token,
        AgentTokenService $tokens,
    ): RedirectResponse {
        abort_unless($token->client_id === $client->id, 404);

        $validated = $request->validated();
        $newToken = $tokens->rotate(
            $request->user(),
            $client,
            $token,
            $validated['name'],
            $validated['allowed_account_ids'],
        );

        return $this->redirectToClient($client)
            ->with('agent_token_plaintext', $newToken->plainTextToken)
            ->with('agent_token_client_id', $client->public_id)
            ->with('agent_token_notice', 'The previous token was revoked. Copy the replacement now.');
    }

    public function destroyToken(
        Request $request,
        Client $client,
        PersonalAccessToken $token,
        AgentTokenService $tokens,
    ): RedirectResponse {
        abort_unless(
            $request->user()->hasVerifiedEmail()
                && $client->is_active
                && $client->hasActiveOwner($request->user())
                && $token->client_id === $client->id,
            403,
        );

        $tokens->revoke($request->user(), $client, $token);

        return $this->redirectToClient($client)->with('status', 'Agent token revoked.');
    }

    /** @return array<string, mixed> */
    private function clientDetails(Client $client, Request $request): array
    {
        $user = $request->user();
        $canManage = $client->hasActiveOwner($user);
        $canAssignAccounts = $user->isAdmin();
        $permissions = $client->accountPermissions()
            ->with('account.profile.providerConnection')
            ->orderBy('id')
            ->get();
        $permissionByAccount = $permissions->keyBy('provider_account_id');
        $adKitPermissions = $permissions->filter(fn ($permission): bool => $permission->is_active
            && $permission->account?->profile?->provider === 'adkit');
        $availableAdKitAccountIds = $adKitPermissions->isNotEmpty()
            ? ProviderAccount::query()->assignedAdKitTo($client)->pluck('id')->all()
            : [];
        $profiles = $client->providerProfiles()
            ->where('provider_profiles.provider', 'zernio')
            ->where('provider_profiles.is_active', true)
            ->with(['accounts' => fn ($query) => $query->orderBy('name')])
            ->orderBy('provider_profiles.name')
            ->get();

        return [
            ...$this->clientSummary($client),
            'can_manage' => $canManage,
            'can_assign_profiles' => $canAssignAccounts,
            'can_assign_adkit_accounts' => $canAssignAccounts,
            'adkit_accounts' => $adKitPermissions
                ->map(fn ($permission): array => [
                    'id' => $permission->account->public_id,
                    'name' => $permission->account->name,
                    'platform' => $permission->account->platform,
                    'available' => in_array($permission->account->id, $availableAdKitAccountIds, true)
                        && $permission->account->hasUsableAdKitConnection(),
                ])->values(),
            'profiles' => $profiles->map(fn ($profile): array => [
                'id' => $profile->public_id,
                'name' => $profile->name,
                'synchronized_at' => $profile->synchronized_at?->toIso8601String(),
                'accounts' => $profile->accounts->map(function ($account) use ($permissionByAccount): array {
                    $permission = $permissionByAccount->get($account->id);

                    return [
                        'id' => $account->public_id,
                        'name' => $account->name,
                        'platform' => $account->platform,
                        'available' => $account->is_active
                            && $permission?->is_active === true,
                    ];
                })->values(),
            ])->values(),
            'tokens' => $canManage
                ? PersonalAccessToken::query()
                    ->where('client_id', $client->id)
                    ->whereNull('revoked_at')
                    ->where('expires_at', '>', now())
                    ->latest()
                    ->get()
                    ->map(fn (PersonalAccessToken $token): array => [
                        'id' => $token->id,
                        'name' => $token->name,
                        'abilities' => $token->abilities ?? [],
                        'allowed_account_ids' => $token->allowed_account_ids ?? [],
                        'last_used_at' => $token->last_used_at?->toIso8601String(),
                        'expires_at' => $token->expires_at?->toIso8601String(),
                        'revoked_at' => $token->revoked_at?->toIso8601String(),
                        'created_at' => $token->created_at?->toIso8601String(),
                    ])->values()
                : [],
        ];
    }

    /** @return array<string, mixed> */
    private function clientSummary(Client $client): array
    {
        $owner = $client->users->first(
            fn (User $member): bool => $member->pivot->role === 'owner'
                && $member->pivot->is_active,
        );

        return [
            'id' => $client->public_id,
            'name' => $client->name,
            'social_account_count' => (int) $client->social_account_count,
            'ad_account_count' => (int) $client->ad_account_count,
            'owner' => $owner === null ? null : [
                'name' => $owner->name,
                'email' => $owner->email,
            ],
        ];
    }

    private function redirectToClient(Client $client): RedirectResponse
    {
        return to_route('settings.agent-access.edit', ['client' => $client->public_id]);
    }
}
