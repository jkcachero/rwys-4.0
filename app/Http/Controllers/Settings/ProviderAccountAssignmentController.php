<?php

namespace App\Http\Controllers\Settings;

use App\Http\Requests\Settings\AssignProviderAccountRequest;
use App\Models\Client;
use App\Models\ClientProviderProfile;
use App\Services\ProviderAccountAssignmentService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ProviderAccountAssignmentController
{
    public function __construct(private readonly ProviderAccountAssignmentService $assignments) {}

    public function index(Request $request): Response
    {
        $this->assignments->authorizeOperator($request->user());
        $data = $this->validateQuery($request, [
            'client_id' => ['sometimes', 'integer', 'min:1'],
            'clients_page' => ['sometimes', 'integer', 'min:1', 'max:1000'],
        ]);
        $client = isset($data['client_id']) ? $this->assignments->eligibleClient((int) $data['client_id']) : null;
        $key = config('agent-api.zernio.api_key');
        $ready = $this->assignments->ready();

        return Inertia::render('settings/provider-accounts', [
            'clients' => $this->clientPage((int) ($data['clients_page'] ?? 1)),
            'selectedClientId' => $client?->id,
            'selectedClient' => $client === null ? null : [
                'id' => $client->id, 'name' => $client->name, 'owner' => $client->owner->only(['id', 'name', 'email']),
            ],
            'mappings' => $client === null || ! $ready ? [] : ClientProviderProfile::query()->where('client_id', $client->id)
                ->where('provider', 'zernio')->where('environment', 'production')
                ->select(ProviderAccountAssignmentService::PROFILE_FIELDS)->withCount('accounts as account_count')
                ->orderBy('id')->limit(25)->get()->map(fn (ClientProviderProfile $claim): array => [
                    ...$claim->only(ProviderAccountAssignmentService::PROFILE_FIELDS), 'account_count' => $claim->account_count,
                ]),
            'profileAssignmentsReady' => $ready,
            'providerEnabled' => config('agent-api.zernio.enabled') === true && is_string($key) && preg_match('/\A[\x21-\x7e]+\z/', $key) === 1,
        ]);
    }

    public function clients(Request $request): JsonResponse
    {
        $this->assignments->authorizeOperator($request->user());
        $data = $this->validateQuery($request, $this->browseRules());

        return response()->json($this->clientPage((int) ($data['page'] ?? 1), (int) ($data['limit'] ?? 25), $data['search'] ?? null))
            ->header('Cache-Control', 'no-store, private');
    }

    public function discover(Request $request): JsonResponse
    {
        $this->assignments->authorizeOperator($request->user());
        $data = $this->validateQuery($request, ['client_id' => ['required', 'integer', 'min:1'], ...$this->browseRules()]);
        $client = $this->assignments->eligibleClient((int) $data['client_id']);
        $result = $this->assignments->discover($request->user(), $client, (int) ($data['page'] ?? 1), (int) ($data['limit'] ?? 25), $data['search'] ?? null);

        return response()->json(['data' => $result['profiles'], 'pagination' => [
            ...$result['pagination'], 'has_more' => $result['pagination']['page'] < $result['pagination']['pages'],
        ]])->header('Cache-Control', 'no-store, private');
    }

    public function store(AssignProviderAccountRequest $request): JsonResponse
    {
        $data = $request->validated();
        $claim = $this->assignments->assign($request->user(), $this->assignments->eligibleClient($data['client_id']), $data['profile_id']);

        return response()->json(['data' => [
            ...$claim->only(ProviderAccountAssignmentService::PROFILE_FIELDS), 'account_count' => $claim->account_count,
            'accounts' => $claim->accounts->map(fn ($account): array => $account->only(ProviderAccountAssignmentService::MAPPING_FIELDS))->all(),
        ]], $claim->wasRecentlyCreated ? 201 : 200)
            ->header('Cache-Control', 'no-store, private');
    }

    /** @return array<string, list<string>> */
    private function browseRules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1', 'max:1000'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'search' => ['sometimes', 'nullable', 'string', 'max:100', 'regex:/\A[^\x00-\x1f\x7f]*\z/u'],
        ];
    }

    /**
     * @param  array<string, list<string>>  $rules
     * @return array<string, mixed>
     */
    private function validateQuery(Request $request, array $rules): array
    {
        $unknown = array_diff(array_keys($request->all()), array_keys($rules));
        if ($unknown !== []) {
            throw ValidationException::withMessages(['request' => 'Unknown fields are not accepted.']);
        }

        return Validator::make($request->all(), $rules)->validate();
    }

    /** @return array{data: list<array<string, mixed>>, pagination: array<string, int|bool>} */
    private function clientPage(int $page, int $limit = 25, ?string $search = null): array
    {
        $clients = Client::query()->where('active', true)->whereHas('owner', fn ($query) => $query->whereNotNull('email_verified_at'))
            ->with('owner:id,name,email')
            ->when($search !== null, fn (Builder $query): Builder => $query->where(
                fn (Builder $matches): Builder => $matches->whereLike('name', '%'.$search.'%')
                    ->orWhereHas('owner', fn (Builder $owner): Builder => $owner->where(
                        fn (Builder $identity): Builder => $identity->whereLike('name', '%'.$search.'%')
                            ->orWhereLike('email', '%'.$search.'%'),
                    )),
            ))
            ->orderBy('id')->paginate($limit, ['id', 'name', 'owner_id'], 'page', $page);

        return [
            'data' => $clients->getCollection()->map(fn (Client $client): array => [
                'id' => $client->id, 'name' => $client->name, 'owner' => $client->owner->only(['id', 'name', 'email']),
            ])->all(),
            'pagination' => ['page' => $clients->currentPage(), 'limit' => $clients->perPage(), 'total' => $clients->total(),
                'pages' => $clients->lastPage(), 'has_more' => $clients->hasMorePages()],
        ];
    }
}
