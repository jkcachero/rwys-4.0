<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\AssignProviderProfileRequest;
use App\Models\Client;
use App\Models\ProviderProfile;
use App\Services\ZernioProfileAssignmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

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
        $this->assignments->assign(
            $request->user(),
            $client,
            $request->validated('provider_profile_id'),
        );

        return to_route('settings.agent-access.edit', ['client' => $client->public_id])
            ->with('success', 'Zernio profile assigned and accounts imported.');
    }

    public function sync(Request $request, Client $client, ProviderProfile $profile): RedirectResponse
    {
        $this->assignments->synchronize($request->user(), $client, $profile);

        return to_route('settings.agent-access.edit', ['client' => $client->public_id])
            ->with('success', 'Zernio accounts synchronized.');
    }
}
