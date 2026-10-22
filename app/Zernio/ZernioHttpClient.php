<?php

namespace App\Zernio;

use App\Exceptions\Zernio\ZernioRequestException;
use App\Models\ProviderProfile;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

class ZernioHttpClient
{
    public function profile(ProviderProfile $profile): ZernioResponse
    {
        return $this->get($profile, '/v1/profiles/'.rawurlencode($profile->provider_id));
    }

    public function account(ProviderProfile $profile, string $providerAccountId): ZernioResponse
    {
        return $this->get($profile, '/v1/accounts/'.rawurlencode($providerAccountId));
    }

    public function posts(ProviderProfile $profile, string $providerAccountId, int $limit, ?string $cursor): ZernioResponse
    {
        return $this->get($profile, '/v1/posts', array_filter([
            'profileId' => $profile->provider_id,
            'accountId' => $providerAccountId,
            'limit' => $limit,
            'cursor' => $cursor,
        ], static fn (mixed $value): bool => $value !== null));
    }

    public function post(ProviderProfile $profile, string $providerPostId): ZernioResponse
    {
        return $this->get($profile, '/v1/posts/'.rawurlencode($providerPostId));
    }

    public function analytics(ProviderProfile $profile, string $providerAccountId, string $providerPostId): ZernioResponse
    {
        return $this->get($profile, '/v1/analytics', [
            'profileId' => $profile->provider_id,
            'accountId' => $providerAccountId,
            'postId' => $providerPostId,
            'page' => 1,
            'limit' => 1,
        ], [200, 202, 402, 424]);
    }

    /** @param array<string, mixed> $payload */
    public function createPost(ProviderProfile $profile, array $payload, string $idempotencyKey): ZernioResponse
    {
        return $this->write(
            $profile,
            fn (PendingRequest $request): Response => $request
                ->withHeader('Idempotency-Key', $idempotencyKey)
                ->post('/v1/posts', $payload),
            [200, 201, 202],
        );
    }

    public function uploadDirect(ProviderProfile $profile, string $contents, string $filename): ZernioResponse
    {
        return $this->write(
            $profile,
            fn (PendingRequest $request): Response => $request
                ->attach('file', $contents, $filename, ['Content-Type' => 'image/jpeg'])
                ->post('/v1/media/upload-direct'),
            [200, 201],
        );
    }

    /**
     * @param  array<string, scalar>  $query
     * @param  list<int>  $acceptedStatuses
     */
    private function get(
        ProviderProfile $profile,
        string $path,
        array $query = [],
        array $acceptedStatuses = [200],
    ): ZernioResponse {
        if (! config('services.zernio.enabled')) {
            throw new ZernioRequestException('Zernio access is unavailable.', 503, 'provider_unavailable');
        }

        $connection = $profile->providerConnection;

        if ($connection === null || $connection->provider !== 'zernio') {
            throw new ZernioRequestException('Zernio credentials are unavailable.', 503, 'provider_unavailable');
        }

        if ($connection->access_token_expires_at?->isPast()) {
            throw new ZernioRequestException('Zernio credentials have expired.', 503, 'provider_unavailable');
        }

        try {
            $response = $this->request($connection->access_token)->get($path, $query);
        } catch (ZernioRequestException $exception) {
            throw $exception;
        } catch (ConnectionException) {
            throw new ZernioRequestException('Zernio could not be reached.', 503, 'provider_unavailable');
        } catch (Throwable) {
            throw new ZernioRequestException('Zernio request failed.', 502, 'provider_error');
        }

        return $this->decode($response, $acceptedStatuses);
    }

    /**
     * @param  callable(PendingRequest): Response  $send
     * @param  list<int>  $acceptedStatuses
     */
    private function write(ProviderProfile $profile, callable $send, array $acceptedStatuses): ZernioResponse
    {
        if (! config('services.zernio.enabled')) {
            throw new ZernioRequestException('Zernio access is unavailable.', 503, 'provider_unavailable');
        }

        $connection = $profile->providerConnection;

        if ($connection === null || $connection->provider !== 'zernio') {
            throw new ZernioRequestException('Zernio credentials are unavailable.', 503, 'provider_unavailable');
        }

        if ($connection->access_token_expires_at?->isPast()) {
            throw new ZernioRequestException('Zernio credentials have expired.', 503, 'provider_unavailable');
        }

        try {
            $response = $send($this->request($connection->access_token));
        } catch (ZernioRequestException $exception) {
            throw $exception;
        } catch (ConnectionException) {
            throw new ZernioRequestException('Zernio could not be reached.', 503, 'provider_unavailable');
        } catch (Throwable) {
            throw new ZernioRequestException('Zernio request failed.', 502, 'provider_error');
        }

        return $this->decode($response, $acceptedStatuses);
    }

    private function request(string $accessToken): PendingRequest
    {
        $baseUrl = rtrim((string) config('services.zernio.base_url'), '/');

        if (! str_starts_with($baseUrl, 'https://')) {
            throw new ZernioRequestException('Zernio configuration is invalid.', 503, 'provider_unavailable');
        }

        return Http::baseUrl($baseUrl)
            ->acceptJson()
            ->withToken($accessToken)
            ->connectTimeout((int) config('services.zernio.connect_timeout', 5))
            ->timeout((int) config('services.zernio.timeout', 15))
            ->withoutRedirecting()
            ->withOptions(['verify' => true]);
    }

    /** @param list<int> $acceptedStatuses */
    private function decode(Response $response, array $acceptedStatuses): ZernioResponse
    {
        $retryAfter = filter_var($response->header('Retry-After'), FILTER_VALIDATE_INT) ?: null;

        if ($response->status() === 429) {
            throw new ZernioRequestException('Zernio rate limit reached.', 429, 'provider_rate_limited', $retryAfter);
        }

        if (! in_array($response->status(), $acceptedStatuses, true)) {
            throw new ZernioRequestException('Zernio returned an unexpected response.');
        }

        $body = $response->body();

        if (strlen($body) > (int) config('services.zernio.max_response_bytes', 1_048_576)) {
            throw new ZernioRequestException('Zernio response exceeded the allowed size.');
        }

        $data = json_decode($body, true);

        if (! is_array($data)) {
            throw new ZernioRequestException('Zernio returned invalid JSON.');
        }

        return new ZernioResponse($response->status(), $data, $retryAfter);
    }
}
