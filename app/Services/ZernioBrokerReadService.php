<?php

namespace App\Services;

use App\Exceptions\Zernio\ZernioRequestException;
use App\Models\Client;
use App\Models\PersonalAccessToken;
use App\Models\ProviderAccount;
use App\Models\ProviderPost;
use App\Models\ProviderProfile;
use App\Zernio\ZernioHttpClient;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class ZernioBrokerReadService
{
    public function __construct(
        private readonly ZernioHttpClient $zernio,
        private readonly ZernioAccountLookupService $accounts,
    ) {}

    /** @return array<string, mixed> */
    public function accountStatus(Request $request, ProviderAccount $account): array
    {
        [$client, $token, $account, $profile] = $this->authorizedContext($request, $account);
        $this->verifyProfile($profile);
        $providerAccount = $this->accounts->find($profile, $account);
        $this->authorizedContext($request, $account);

        return [
            'id' => $account->public_id,
            'platform' => $account->platform,
            'name' => $account->name,
            'active' => (bool) Arr::get($providerAccount, 'isActive', false),
            'status' => $this->safeString(Arr::get($providerAccount, 'status')),
            'requires_reconnect' => (bool) Arr::get($providerAccount, 'requiresReconnect', false),
            'rate_limited' => (bool) Arr::get($providerAccount, 'isRateLimited', false),
            'client_id' => $client->public_id,
            'restricted' => $token->allowed_account_ids !== [],
        ];
    }

    /** @return array{data: list<array<string, mixed>>, next_cursor: ?string} */
    public function posts(Request $request, ProviderAccount $account, int $limit, ?string $cursor): array
    {
        [, , $account, $profile] = $this->authorizedContext($request, $account);
        $this->verifyProfileAndAccount($profile, $account);
        $response = $this->zernio->posts($profile, $account->provider_id, $limit, $cursor);
        $posts = Arr::get($response->data, 'posts');

        if (! is_array($posts)) {
            throw new ZernioRequestException('Zernio posts response was invalid.');
        }

        $normalized = [];

        foreach (array_slice(array_values($posts), 0, $limit) as $post) {
            if (! is_array($post)) {
                throw new ZernioRequestException('Zernio posts response was invalid.');
            }

            $this->verifyPostAccount($post, $account);
            $normalized[] = $this->persistAndNormalizePost($request, $account, $post);
        }

        $this->authorizedContext($request, $account);
        $nextCursor = $this->safeString(Arr::get($response->data, 'pagination.nextCursor'));

        return ['data' => $normalized, 'next_cursor' => $nextCursor];
    }

    /** @return array<string, mixed> */
    public function post(Request $request, ProviderAccount $account, ProviderPost $post): array
    {
        $this->assertLocalPost($request, $account, $post);
        [, , $account, $profile] = $this->authorizedContext($request, $account);
        $this->verifyProfileAndAccount($profile, $account);
        $payload = $this->postPayload($this->zernio->post($profile, $post->provider_id)->data);
        $this->verifyPostAccount($payload, $account);
        $this->verifyPostIdentity($payload, $post);
        $this->authorizedContext($request, $account);

        return $this->persistAndNormalizePost($request, $account, $payload);
    }

    /** @return array<string, mixed> */
    public function analytics(Request $request, ProviderAccount $account, ProviderPost $post): array
    {
        $this->assertLocalPost($request, $account, $post);
        [, , $account, $profile] = $this->authorizedContext($request, $account);
        $this->verifyProfileAndAccount($profile, $account);
        $response = $this->zernio->analytics($profile, $account->provider_id, $post->provider_id);

        if ($response->status === 402) {
            $this->authorizedContext($request, $account);

            return [
                'post_id' => $post->public_id,
                'state' => 'unavailable',
                'reason' => 'analytics_entitlement_required',
                'metrics' => [],
            ];
        }

        if ($response->status === 424) {
            $this->authorizedContext($request, $account);

            return [
                'post_id' => $post->public_id,
                'state' => 'unavailable',
                'reason' => 'provider_publication_failed',
                'metrics' => [],
            ];
        }

        $payload = $this->analyticsPayload($response->data);
        $this->verifyAnalyticsIdentity($payload, $account, $post);
        $this->authorizedContext($request, $account);

        return [
            'post_id' => $post->public_id,
            'state' => $response->status === 202
                ? 'pending'
                : $this->safeString(Arr::get($payload, 'syncStatus'), 'unavailable'),
            'message' => $this->safeString(Arr::get($payload, 'statusMessage')),
            'metrics' => $this->metrics(Arr::get($payload, 'metrics', Arr::get($payload, 'analytics', []))),
            'platforms' => $this->platformAnalytics(Arr::get($payload, 'platforms', [])),
        ];
    }

    /** @return array{Client, PersonalAccessToken, ProviderAccount, ProviderProfile} */
    private function authorizedContext(Request $request, ProviderAccount $account): array
    {
        $client = $request->attributes->get('agent_client');
        $token = $request->attributes->get('agent_token');

        if (! $client instanceof Client || ! $token instanceof PersonalAccessToken) {
            abort(403);
        }

        $account = ProviderAccount::query()
            ->with('profile')
            ->whereKey($account->getKey())
            ->where('is_active', true)
            ->whereHas('permissions', fn ($query) => $query
                ->where('client_id', $client->getKey())
                ->where('is_active', true))
            ->whereHas('profile', fn ($query) => $query
                ->where('provider', 'zernio')
                ->where('is_active', true)
                ->whereHas('clients', fn ($clients) => $clients->whereKey($client->getKey())))
            ->firstOrFail();

        if ($token->allowed_account_ids !== [] && ! in_array($account->public_id, $token->allowed_account_ids, true)) {
            abort(403);
        }

        return [$client, $token, $account, $account->profile];
    }

    private function verifyProfileAndAccount(ProviderProfile $profile, ProviderAccount $account): void
    {
        $this->verifyProfile($profile);
        $this->accounts->find($profile, $account);
    }

    private function verifyProfile(ProviderProfile $profile): void
    {
        $payload = $this->profilePayload($this->zernio->profile($profile)->data);

        if ($this->referenceId(Arr::get($payload, '_id', Arr::get($payload, 'id'))) !== $profile->provider_id) {
            throw new ZernioRequestException('Zernio profile identity could not be verified.');
        }
    }

    /** @param array<string, mixed> $payload */
    private function verifyPostAccount(array $payload, ProviderAccount $account): void
    {
        $accountIds = [];
        $direct = Arr::get($payload, 'accountId');

        if ($direct !== null) {
            $accountIds[] = $this->referenceId($direct);
        }

        foreach ((array) Arr::get($payload, 'accountIds', []) as $reference) {
            $accountIds[] = $this->referenceId($reference);
        }

        foreach ((array) Arr::get($payload, 'platforms', []) as $platform) {
            if (is_array($platform)) {
                $accountIds[] = $this->referenceId(Arr::get($platform, 'accountId', Arr::get($platform, 'account')));
            }
        }

        if (! in_array($account->provider_id, array_filter($accountIds), true)) {
            throw new ZernioRequestException('Zernio post ownership could not be verified.');
        }
    }

    /** @param array<string, mixed> $payload */
    private function verifyPostIdentity(array $payload, ProviderPost $post): void
    {
        if ($this->referenceId(Arr::get($payload, '_id', Arr::get($payload, 'id'))) !== $post->provider_id) {
            throw new ZernioRequestException('Zernio post identity could not be verified.');
        }
    }

    /** @param array<string, mixed> $payload */
    private function verifyAnalyticsIdentity(array $payload, ProviderAccount $account, ProviderPost $post): void
    {
        $postId = $this->referenceId(Arr::get($payload, 'postId', Arr::get($payload, '_id')));

        if ($postId !== null && $postId !== $post->provider_id) {
            throw new ZernioRequestException('Zernio analytics identity could not be verified.');
        }

        $accountReference = Arr::get($payload, 'accountId');

        if ($accountReference !== null && $this->referenceId($accountReference) !== $account->provider_id) {
            throw new ZernioRequestException('Zernio analytics ownership could not be verified.');
        }
    }

    /** @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function persistAndNormalizePost(Request $request, ProviderAccount $account, array $payload): array
    {
        $client = $request->attributes->get('agent_client');
        $providerPostId = $this->referenceId(Arr::get($payload, '_id', Arr::get($payload, 'id')));

        if (! $client instanceof Client || $providerPostId === null || ! preg_match('/^[a-f0-9]{24}$/', $providerPostId)) {
            throw new ZernioRequestException('Zernio post identity was invalid.');
        }

        $post = ProviderPost::query()->updateOrCreate([
            'client_id' => $client->getKey(),
            'provider_account_id' => $account->getKey(),
            'provider_id_hash' => hash('sha256', $providerPostId),
        ], [
            'provider_id' => $providerPostId,
            'status' => $this->safeString(Arr::get($payload, 'status')),
            'scheduled_at' => $this->safeDate(Arr::get($payload, 'scheduledAt')),
            'published_at' => $this->safeDate(Arr::get($payload, 'publishedAt')),
        ]);

        return [
            'id' => $post->public_id,
            'account_id' => $account->public_id,
            'status' => $post->status,
            'scheduled_at' => $post->scheduled_at?->toIso8601String(),
            'published_at' => $post->published_at?->toIso8601String(),
        ];
    }

    private function assertLocalPost(Request $request, ProviderAccount $account, ProviderPost $post): void
    {
        $client = $request->attributes->get('agent_client');

        if (! $client instanceof Client
            || $post->client_id !== $client->getKey()
            || $post->provider_account_id !== $account->getKey()) {
            abort(404);
        }
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function profilePayload(array $data): array
    {
        $payload = Arr::get($data, 'profile', $data);

        if (! is_array($payload)) {
            throw new ZernioRequestException('Zernio profile response was invalid.');
        }

        return $payload;
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function postPayload(array $data): array
    {
        $payload = Arr::get($data, 'post', $data);

        if (! is_array($payload)) {
            throw new ZernioRequestException('Zernio post response was invalid.');
        }

        return $payload;
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function analyticsPayload(array $data): array
    {
        $payload = Arr::get($data, 'post', Arr::get($data, 'posts.0', $data));

        if (! is_array($payload)) {
            throw new ZernioRequestException('Zernio analytics response was invalid.');
        }

        return $payload;
    }

    private function referenceId(mixed $reference): ?string
    {
        if (is_string($reference)) {
            return $reference;
        }

        if (is_array($reference)) {
            return $this->safeString(Arr::get($reference, '_id', Arr::get($reference, 'id')));
        }

        return null;
    }

    private function safeString(mixed $value, ?string $default = null): ?string
    {
        if (! is_string($value) || $value === '') {
            return $default;
        }

        return Str::limit($value, 200, '');
    }

    private function safeDate(mixed $value): ?Carbon
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array<string, int|float|null> */
    private function metrics(mixed $metrics): array
    {
        if (! is_array($metrics)) {
            return [];
        }

        $allowed = ['likes', 'comments', 'shares', 'views', 'impressions', 'reach', 'clicks', 'saves'];
        $normalized = [];

        foreach ($allowed as $name) {
            if (array_key_exists($name, $metrics) && (is_numeric($metrics[$name]) || $metrics[$name] === null)) {
                $normalized[$name] = $metrics[$name] === null ? null : $metrics[$name] + 0;
            }
        }

        return $normalized;
    }

    /** @return list<array<string, mixed>> */
    private function platformAnalytics(mixed $platforms): array
    {
        if (! is_array($platforms)) {
            return [];
        }

        $normalized = [];

        foreach ($platforms as $platform) {
            if (! is_array($platform)) {
                continue;
            }

            $normalized[] = [
                'platform' => $this->safeString(Arr::get($platform, 'platform')),
                'state' => $this->safeString(Arr::get($platform, 'syncStatus', Arr::get($platform, 'status'))),
                'metrics' => $this->metrics(Arr::get($platform, 'metrics', Arr::get($platform, 'analytics', []))),
            ];
        }

        return $normalized;
    }
}
