<?php

namespace App\Http\Controllers\Settings;

use App\Exceptions\AdKit\AdKitRequestException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\AssignAdKitAccountRequest;
use App\Models\Client;
use App\Models\ProviderAccount;
use App\Services\AdKitAccountAssignmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class AdKitAccountAssignmentController extends Controller
{
    public function __construct(private AdKitAccountAssignmentService $assignments) {}

    public function index(Request $request, Client $client): JsonResponse
    {
        try {
            return response()->json($this->assignments->discover($request->user(), $client));
        } catch (AdKitRequestException $exception) {
            return response()->json(['error' => $exception->errorCode, 'message' => $exception->getMessage()], $exception->responseStatus);
        }
    }

    public function store(AssignAdKitAccountRequest $request, Client $client): RedirectResponse
    {
        try {
            $this->assignments->assign($request->user(), $client, $request->validated('ad_account_id'));
        } catch (AdKitRequestException) {
            return to_route('settings.agent-access.edit', ['client' => $client->public_id])
                ->withErrors(['ad_account_id' => 'AdKit could not assign this account. Check the connection, account availability, and try again.']);
        }

        return to_route('settings.agent-access.edit', ['client' => $client->public_id])->with('success', 'AdKit ad account assigned.');
    }

    public function destroy(Request $request, Client $client, ProviderAccount $account): RedirectResponse
    {
        $this->assignments->remove($request->user(), $client, $account);

        return to_route('settings.agent-access.edit', ['client' => $client->public_id])->with('success', 'AdKit ad account removed.');
    }
}
