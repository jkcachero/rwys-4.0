<?php

namespace App\Http\Controllers;

use App\Http\Middleware\AuthenticateAgentToken;
use App\Http\Requests\ScheduleInstagramPostRequest;
use App\Http\Resources\AgentAccountResource;
use App\Http\Resources\AgentOperationResource;
use App\Models\AgentOperation;
use App\Models\ClientAccount;
use App\Services\AgentOperationService;
use App\Services\AgentTokenService;
use App\Zernio\Services\ZernioApiClient;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AgentApiController extends Controller
{
    public function context(Request $request): JsonResponse
    {
        $this->input($request);
        $client = $request->attributes->get('agent_client');

        return response()->json(['data' => ['client' => ['id' => $client->id], 'capabilities' => $this->rights($request)]]);
    }

    public function capabilities(Request $request): JsonResponse
    {
        $this->input($request);

        return response()->json(['data' => $this->rights($request)]);
    }

    public function accounts(Request $request): JsonResponse
    {
        $input = $this->input($request, true);
        $this->requireProvider();
        $page = (int) ($input['page'] ?? 1);
        $limit = (int) ($input['limit'] ?? 25);
        $accounts = $this->accountQuery($request)->orderBy('id')->paginate($limit, ['id', 'provider', 'environment', 'platform', 'active', 'publishing_enabled'], 'page', $page);

        return response()->json(['data' => AgentAccountResource::collection($accounts->getCollection())->resolve($request), 'pagination' => $this->pagination($page, $limit, $accounts->total())]);
    }

    public function status(Request $request, string $account, ZernioApiClient $provider): JsonResponse
    {
        $this->input($request);
        $account = $this->account($request, $account);
        $this->requireProvider();
        for ($page = 1; $page <= 3; $page++) {
            $result = $provider->accounts($account->profile_id, $page, 25, $account->provider_account_id);
            $account = $this->revalidateAccount($request, $account);
            foreach ($result['accounts'] as $candidate) {
                if ($candidate['id'] === $account->provider_account_id && $candidate['profile_id'] === $account->profile_id && $candidate['platform'] === $account->platform) {
                    return response()->json(['data' => ['id' => $account->id, 'status' => $candidate['is_active'] ? 'connected' : 'disconnected']]);
                }
            }
            if ($result['pagination']['pages'] <= $page) {
                break;
            }
        }
        abort(404);
    }

    public function posts(Request $request, string $account, ZernioApiClient $provider): JsonResponse
    {
        $input = $this->input($request, true);
        $account = $this->account($request, $account);
        $this->requireProvider();
        $result = $provider->posts($account->profile_id, $account->provider_account_id, (int) ($input['page'] ?? 1), (int) ($input['limit'] ?? 25));
        $account = $this->revalidateAccount($request, $account);
        $accounts = $this->targetAccounts($request, $account, $result['posts']);
        $posts = array_values(array_filter($result['posts'], fn (array $post): bool => $this->authorizedPost($post, $account, $accounts)));
        $pagination = $result['pagination'];
        $pagination['has_more'] = $pagination['page'] < $pagination['pages'];
        $pagination['complete'] = $pagination['page'] === 1 && $pagination['pages'] <= 1 && count($posts) === count($result['posts']);
        $pagination['scope'] = 'provider_page';
        $pagination['filtered'] = count($posts) !== count($result['posts']);

        return response()->json(['data' => $posts, 'pagination' => $pagination]);
    }

    public function post(Request $request, string $account, string $post, ZernioApiClient $provider): JsonResponse
    {
        $this->input($request);
        $account = $this->account($request, $account);
        abort_unless(preg_match('/\A[a-f0-9]{24}\z/', $post), 404);
        $this->requireProvider();
        $candidate = $provider->post($post);
        $account = $this->revalidateAccount($request, $account);
        if ($candidate['profile_id'] !== null) {
            abort_unless($this->authorizedPost($candidate, $account, $this->targetAccounts($request, $account, [$candidate])), 404);

            return response()->json(['data' => $candidate]);
        }
        // Without observed membership, only the first 75 scoped posts can prove access.
        for ($page = 1; $page <= 3; $page++) {
            $result = $provider->posts($account->profile_id, $account->provider_account_id, $page, 25);
            $account = $this->revalidateAccount($request, $account);
            foreach ($result['posts'] as $candidate) {
                if ($candidate['id'] === $post) {
                    abort_unless($this->authorizedPost($candidate, $account, $this->targetAccounts($request, $account, [$candidate])), 404);

                    return response()->json(['data' => $candidate]);
                }
            }
            if ($result['pagination']['pages'] <= $page) {
                break;
            }
        }
        abort(404);
    }

    public function analytics(Request $request, string $account, string $post, ZernioApiClient $provider): JsonResponse
    {
        $this->input($request);
        $account = $this->account($request, $account);
        abort_unless(preg_match('/\A[a-f0-9]{24}\z/', $post), 404);
        $this->requireProvider();
        $result = $provider->postAnalytics($account->profile_id, $account->provider_account_id, $post);
        $account = $this->revalidateAccount($request, $account);
        $candidate = ['profile_id' => $result['profile_id'], 'platforms' => $result['platform_analytics']];
        abort_unless($this->authorizedPost($candidate, $account, $this->targetAccounts($request, $account, [$candidate])), 404);
        unset($result['profile_id']);

        return response()->json(['data' => $result], $result['upstream_status']);
    }

    public function publish(Request $request, string $account, AgentOperationService $operations): JsonResponse
    {
        $this->input($request, publish: true);
        $account = $this->account($request, $account);
        $operation = $operations->publish($request->attributes->get('agent_token'), $account, $request->input('content'), $request->header('Idempotency-Key'));

        return (new AgentOperationResource($operation))->response()->setStatusCode(in_array($operation->state, AgentOperation::TERMINAL_STATES, true) ? 200 : 202);
    }

    public function schedule(ScheduleInstagramPostRequest $request, string $account, AgentOperationService $operations): JsonResponse
    {
        $account = $this->account($request, $account);
        $operation = $operations->scheduleInstagramImage($request->attributes->get('agent_token'), $account,
            $request->validated('content'), $request->file('image'), $request->validated('scheduled_for'), $request->header('Idempotency-Key'));

        return (new AgentOperationResource($operation))->response()->setStatusCode(in_array($operation->state, AgentOperation::TERMINAL_STATES, true) ? 200 : 202);
    }

    public function operations(Request $request): JsonResponse
    {
        $input = $this->input($request, cursor: true);
        $limit = (int) ($input['limit'] ?? 25);
        $query = $this->operationQuery($request);
        if (isset($input['after'])) {
            $query->where('agent_operations.id', '>', strtolower($input['after']));
        }
        $operations = $query->orderBy('agent_operations.id')->limit($limit + 1)->get();
        $hasMore = $operations->count() > $limit;
        $operations = $operations->take($limit);

        return response()->json([
            'data' => AgentOperationResource::collection($operations)->resolve($request),
            'pagination' => ['limit' => $limit, 'has_more' => $hasMore, 'next_cursor' => $hasMore ? $operations->last()->id : null, 'complete' => ! isset($input['after']) && ! $hasMore],
        ]);
    }

    public function operation(Request $request, string $operation): AgentOperationResource
    {
        $this->input($request);
        abort_unless(Str::isUuid($operation), 404);

        return new AgentOperationResource($this->operationQuery($request)->whereKey(strtolower($operation))->firstOrFail());
    }

    private function accountQuery(Request $request): Builder
    {
        $query = ClientAccount::query()->withOwnedProfile()->where('client_id', $request->attributes->get('agent_client')->id)
            ->where('active', true)->where('provider', 'zernio')->where('environment', 'production');
        $allowed = $request->attributes->get('agent_token')->allowed_account_ids;
        if ($allowed !== null) {
            $query->whereIn('id', $allowed);
        }

        return $query;
    }

    private function account(Request $request, string $id): ClientAccount
    {
        abort_unless(preg_match('/\A[1-9][0-9]*\z/', $id), 404);

        return $this->accountQuery($request)->whereKey($id)->firstOrFail();
    }

    private function revalidateAccount(Request $request, ClientAccount $account): ClientAccount
    {
        app(AuthenticateAgentToken::class)->authenticate($request);
        $ability = $request->route()->defaults['agent_ability'] ?? null;
        abort_unless(is_string($ability) && in_array($ability, $request->attributes->get('agent_token')->abilities, true), 403);
        $fresh = $this->account($request, (string) $account->id);
        abort_unless($account->mappingHash() === $fresh->mappingHash(), 404);

        return $fresh;
    }

    private function operationQuery(Request $request): Builder
    {
        $accounts = $this->accountQuery($request)->select('client_accounts.id')
            ->whereColumn('client_accounts.id', 'agent_operations.client_account_id')
            ->whereColumn('client_accounts.client_id', 'agent_operations.client_id')
            ->whereColumn('client_accounts.mapping_hash', 'agent_operations.mapping_hash');

        return AgentOperation::query()->select([
            'agent_operations.id', 'agent_operations.client_id', 'agent_operations.client_account_id',
            'agent_operations.capability', 'agent_operations.state', 'agent_operations.provider_post_id',
            'agent_operations.created_at', 'agent_operations.updated_at', 'agent_operations.last_reconciled_at',
        ])->where('agent_operations.client_id', $request->attributes->get('agent_client')->id)
            ->whereExists($accounts->toBase());
    }

    /**
     * @param  list<array<string, mixed>>  $posts
     * @return Collection<int, ClientAccount>
     */
    private function targetAccounts(Request $request, ClientAccount $account, array $posts): Collection
    {
        $ids = collect($posts)->flatMap(fn (array $post): array => array_column($post['platforms'], 'account_id'))->unique()->values();

        return $this->accountQuery($request)->where('profile_id', $account->profile_id)->whereIn('provider_account_id', $ids)
            ->get(['provider_account_id', 'platform']);
    }

    /** @param array<string, mixed> $post
     * @param  Collection<int, ClientAccount>  $accounts
     */
    private function authorizedPost(array $post, ClientAccount $account, Collection $accounts): bool
    {
        if (($post['profile_id'] ?? null) !== $account->profile_id || empty($post['platforms'])
            || ! in_array($account->provider_account_id, array_column($post['platforms'], 'account_id'), true)) {
            return false;
        }
        foreach ($post['platforms'] as $target) {
            if (! $accounts->contains(fn (ClientAccount $mapped): bool => $mapped->provider_account_id === $target['account_id'] && $mapped->platform === $target['platform'])) {
                return false;
            }
        }

        return true;
    }

    private function providerEnabled(): bool
    {
        $key = config('agent-api.zernio.api_key');

        return config('agent-api.zernio.enabled', false) === true && is_string($key) && preg_match('/\A[\x21-\x7e]+\z/', $key) === 1;
    }

    private function requireProvider(): void
    {
        abort_unless($this->providerEnabled(), 503, 'Provider unavailable.');
    }

    /** @return array<string, mixed> */
    private function rights(Request $request): array
    {
        $enabled = $this->providerEnabled();
        $mapped = $this->accountQuery($request)->exists();
        $publishable = $enabled && config('agent-api.zernio.publishing_enabled', false) === true
            && $this->accountQuery($request)->where('publishing_enabled', true)->whereIn('platform', ['linkedin', 'facebook', 'threads', 'bluesky'])->exists();
        $schedulable = $enabled && config('agent-api.zernio.publishing_enabled', false) === true
            && config('agent-api.zernio.instagram_scheduling_enabled', false) === true
            && $this->accountQuery($request)->where('publishing_enabled', true)->where('platform', 'instagram')->exists();
        $abilities = array_values(array_filter(AgentTokenService::ABILITIES, function (string $ability) use ($request, $enabled, $mapped, $publishable, $schedulable): bool {
            if (! in_array($ability, $request->attributes->get('agent_token')->abilities, true)) {
                return false;
            }

            return match ($ability) {
                'zernio.posts.publish' => $publishable,
                'zernio.posts.schedule' => $schedulable,
                'zernio.accounts.read', 'zernio.posts.read', 'zernio.analytics.read' => $enabled && $mapped,
                default => true,
            };
        }));

        return ['abilities' => $abilities, 'zernio' => ['read_enabled' => $enabled && $mapped && (in_array('zernio.accounts.read', $abilities, true) || in_array('zernio.posts.read', $abilities, true) || in_array('zernio.analytics.read', $abilities, true)), 'write_enabled' => in_array('zernio.posts.publish', $abilities, true) || in_array('zernio.posts.schedule', $abilities, true)], 'adkit' => ['status' => 'blocked', 'reason' => 'Not implemented.']];
    }

    /** @return array<string, mixed> */
    private function input(Request $request, bool $paginated = false, bool $publish = false, bool $cursor = false): array
    {
        $rules = $paginated ? ['page' => ['sometimes', 'required', 'integer', 'min:1', 'max:1000'], 'limit' => ['sometimes', 'required', 'integer', 'min:1', 'max:100']] : [];
        if ($cursor) {
            $rules = ['limit' => ['sometimes', 'required', 'integer', 'min:1', 'max:100'], 'after' => ['sometimes', 'required', 'uuid']];
        }
        $query = Validator::make(['query' => $request->query()], ['query' => ['array'.($rules ? ':'.implode(',', array_keys($rules)) : ''), ...($rules ? [] : ['max:0'])], ...collect($rules)->mapWithKeys(fn (array $rule, string $key): array => ['query.'.$key => $rule])->all()])->validate();
        $body = $request->isJson() ? $request->json()->all() : $request->request->all();
        Validator::make(['body' => $body], ['body' => $publish ? ['required', 'array:content'] : ['array', 'max:0'], ...($publish ? ['body.content' => ['required', 'string']] : [])])->validate();
        if ($publish) {
            Validator::make(['idempotency_key' => $request->header('Idempotency-Key')], ['idempotency_key' => ['required', 'string', 'regex:/\A[\x21-\x7e]{1,255}\z/']])->validate();
        }
        if ($request->allFiles() !== []) {
            throw ValidationException::withMessages(['body' => 'Files are not supported.']);
        }

        return $query['query'];
    }

    /** @return array{page: int, limit: int, total: int, pages: int, has_more: bool, complete: bool} */
    private function pagination(int $page, int $limit, int $total): array
    {
        $pages = (int) ceil($total / $limit);

        return ['page' => $page, 'limit' => $limit, 'total' => $total, 'pages' => $pages, 'has_more' => $page < $pages, 'complete' => $page === 1 && $pages <= 1];
    }
}
