<?php

namespace App\Http\Controllers\Settings;

use App\Enums\AgentAbility;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\IssueAgentTokenRequest;
use App\Http\Requests\Settings\RotateAgentTokenRequest;
use App\Http\Requests\Settings\UpdateAgentPermissionRequest;
use App\Models\Client;
use App\Models\ClientAccountPermission;
use App\Models\PersonalAccessToken;
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
        $user = $request->user();
        $clients = Client::query()
            ->where('is_active', true)
            ->where(function ($query) use ($user): void {
                $query->whereHas('users', fn ($users) => $users
                    ->whereKey($user->id)
                    ->where('client_user.is_active', true))
                    ->orWhereHas('operatorGrants', fn ($grants) => $grants
                        ->where('user_id', $user->id)
                        ->where('is_active', true));
            })
            ->with(['users' => fn ($users) => $users->whereKey($user->id)])
            ->orderBy('name')
            ->limit(100)
            ->get();

        $selectedClient = $clients->firstWhere('public_id', $request->string('client')->toString())
            ?? $clients->first();

        $props = [
            'clients' => $clients->map(fn (Client $client): array => [
                'id' => $client->public_id,
                'name' => $client->name,
                'role' => $this->accessRole($client),
            ])->values(),
            'selectedClient' => $selectedClient === null
                ? null
                : $this->clientDetails($selectedClient, $request),
            'abilities' => collect(AgentAbility::enabled())->map(fn (string $ability): array => [
                'value' => $ability,
                'label' => str($ability)->replace('.', ' ')->headline()->toString(),
            ])->values(),
        ];

        if ($request->session()->has('agent_token_plaintext')) {
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
            $validated['abilities'],
            Carbon::parse($validated['expires_at']),
            $validated['allowed_account_ids'],
        );

        return $this->redirectToClient($client)
            ->with('agent_token_plaintext', $token->plainTextToken)
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
            $validated['abilities'],
            $validated['allowed_account_ids'],
        );

        return $this->redirectToClient($client)
            ->with('agent_token_plaintext', $newToken->plainTextToken)
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
        $canAssignProfiles = $user->hasVerifiedEmail()
            && $user->isAdmin()
            && $client->users()->wherePivot('role', 'owner')->wherePivot('is_active', true)->exists()
            && $client->operatorGrants()
                ->where('user_id', $user->id)
                ->where('is_active', true)
                ->exists();
        $permissions = $client->accountPermissions()
            ->with('account.profile')
            ->orderBy('id')
            ->get();
        $permissionByAccount = $permissions->keyBy('provider_account_id');
        $profiles = $client->providerProfiles()
            ->where('provider_profiles.is_active', true)
            ->with(['accounts' => fn ($query) => $query->orderBy('name')])
            ->orderBy('provider_profiles.name')
            ->get();

        return [
            'id' => $client->public_id,
            'name' => $client->name,
            'role' => $this->accessRole($client),
            'can_manage' => $canManage,
            'can_assign_profiles' => $canAssignProfiles,
            'approved_origins' => $client->approved_origins,
            'profiles' => $profiles->map(fn ($profile): array => [
                'id' => $profile->public_id,
                'name' => $profile->name,
                'provider' => $profile->provider,
                'synchronized_at' => $profile->synchronized_at?->toIso8601String(),
                'accounts' => $profile->accounts->map(function ($account) use ($permissionByAccount): array {
                    $permission = $permissionByAccount->get($account->id);

                    return [
                        'id' => $account->public_id,
                        'name' => $account->name,
                        'platform' => $account->platform,
                        'is_active' => $account->is_active,
                        'permission' => $permission === null ? null : [
                            'id' => $permission->id,
                            'is_active' => $permission->is_active,
                            'can_read' => $permission->can_read,
                            'can_publish' => $permission->can_publish,
                            'can_schedule' => $permission->can_schedule,
                        ],
                    ];
                })->values(),
            ])->values(),
            'capabilities' => collect(AgentAbility::cases())->mapWithKeys(fn (AgentAbility $ability): array => [
                $ability->value => ['available' => in_array($ability->value, AgentAbility::enabled(), true)],
            ]),
            'tokens' => $canManage
                ? PersonalAccessToken::query()
                    ->where('client_id', $client->id)
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

    private function accessRole(Client $client): string
    {
        return $client->users->first()?->pivot->role ?? 'operator';
    }

    private function redirectToClient(Client $client): RedirectResponse
    {
        return to_route('settings.agent-access.edit', ['client' => $client->public_id]);
    }
}
