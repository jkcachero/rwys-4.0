<?php

namespace App\Http\Controllers\Settings;

use App\Exceptions\Zernio\ZernioRequestException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\AssignProviderProfileRequest;
use App\Models\Client;
use App\Models\ProviderProfile;
use App\Services\ZernioProfileAssignmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class AgentProfileAssignmentController extends Controller
{
    public function __construct(private ZernioProfileAssignmentService $assignments) {}

    public function profiles(Request $request, Client $client): JsonResponse
    {
        $validated = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        return response()->json($this->assignments->discover(
            $request->user(),
            $client,
            (int) ($validated['page'] ?? 1),
        ));
    }

    public function store(AssignProviderProfileRequest $request, Client $client): RedirectResponse
    {
        try {
            $this->assignments->assign(
                $request->user(),
                $client,
                $request->validated('provider_profile_id'),
            );
        } catch (ZernioRequestException) {
            throw ValidationException::withMessages([
                'provider_profile_id' => 'Zernio could not load this profile. Check the connection and try again.',
            ]);
        }

        return to_route('settings.agent-access.edit', ['client' => $client->public_id])
            ->with('success', 'Zernio profile assigned and accounts imported.');
    }

    public function sync(Request $request, Client $client, ProviderProfile $profile): RedirectResponse
    {
        try {
            $this->assignments->synchronize($request->user(), $client, $profile);
        } catch (ZernioRequestException) {
            throw ValidationException::withMessages([
                'provider_profile_id' => 'Zernio could not synchronize this profile. Check the connection and try again.',
            ]);
        }

        return to_route('settings.agent-access.edit', ['client' => $client->public_id])
            ->with('success', 'Zernio accounts synchronized.');
    }
}
