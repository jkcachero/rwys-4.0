<?php

namespace App\Http\Controllers\Settings;

use App\Http\Requests\Settings\StoreAgentTokenRequest;
use App\Models\AgentAccessToken;
use App\Models\Client;
use App\Services\AgentTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Sanctum\NewAccessToken;

class AgentTokenController
{
    public function __construct(private readonly AgentTokenService $tokens) {}

    public function access(Request $request): RedirectResponse
    {
        return redirect()->route($request->user()->can('assign-provider-accounts')
            ? 'provider-accounts.index'
            : 'agent-tokens.index');
    }

    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user->hasVerifiedEmail(), 403);
        $clients = Client::query()->where('owner_id', $user->id)
            ->with(['accounts' => fn ($query) => $query->withOwnedProfile()->where('active', true)->where('provider', 'zernio')->where('environment', 'production')
                ->select(['id', 'client_id', 'platform', 'publishing_enabled'])])
            ->get(['id', 'name', 'active']);

        return Inertia::render('settings/agent-tokens', [
            'clients' => $clients,
            'tokens' => AgentAccessToken::query()->where('tokenable_type', $user->getMorphClass())->where('tokenable_id', $user->id)
                ->whereIn('client_id', $clients->modelKeys())->latest('id')
                ->paginate(25, ['id', 'client_id', 'name', 'abilities', 'allowed_account_ids', 'created_at', 'last_used_at', 'expires_at']),
            'readAbilities' => AgentTokenService::READ_ABILITIES,
            'publishingEnabled' => config('agent-api.zernio.publishing_enabled', false) === true,
            'instagramSchedulingEnabled' => config('agent-api.zernio.instagram_scheduling_enabled', false) === true && config('agent-api.zernio.publishing_enabled', false) === true,
        ]);
    }

    public function store(StoreAgentTokenRequest $request): JsonResponse
    {
        $data = $request->validated();
        $issued = $this->tokens->issue($request->user(), Client::query()->findOrFail($data['client_id']), $data['name'],
            $data['abilities'] ?? AgentTokenService::READ_ABILITIES, $data['allowed_account_ids'] ?? null);

        return $this->issuedResponse($issued);
    }

    public function rotate(Request $request, AgentAccessToken $token): JsonResponse
    {
        $this->rejectInput($request);

        return $this->issuedResponse($this->tokens->rotate($request->user(), $token));
    }

    public function destroy(Request $request, AgentAccessToken $token): JsonResponse
    {
        $this->rejectInput($request);
        $this->tokens->revoke($request->user(), $token);

        return response()->json(null, 204)->header('Cache-Control', 'no-store, private');
    }

    private function issuedResponse(NewAccessToken $issued): JsonResponse
    {
        return response()->json([
            'plain_text_token' => $issued->plainTextToken,
            'token' => $issued->accessToken->only(['id', 'client_id', 'name', 'abilities', 'allowed_account_ids', 'created_at', 'last_used_at', 'expires_at']),
        ], 201)->header('Cache-Control', 'no-store, private');
    }

    private function rejectInput(Request $request): void
    {
        if ($request->all() !== []) {
            throw ValidationException::withMessages(['request' => 'This action does not accept any fields.']);
        }
    }
}
