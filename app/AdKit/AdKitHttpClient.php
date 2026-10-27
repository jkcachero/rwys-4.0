<?php

namespace App\AdKit;

use App\Exceptions\AdKit\AdKitRequestException;
use App\Models\ProviderConnection;
use App\Models\ProviderProfile;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use JsonException;
use RuntimeException;

class AdKitHttpClient
{
    /** @return list<array{id: string, platform: string, name: string, currency: string, status: string|int}> */
    public function connectedAccounts(ProviderConnection $connection): array
    {
        $body = $this->request($connection, '/manage/meta/accounts');
        $accounts = $body['accounts'] ?? null;

        if (! is_array($accounts) || ! array_is_list($accounts)) {
            throw new AdKitRequestException('adkit_invalid_response');
        }

        foreach ($accounts as $account) {
            if (! is_array($account)
                || ($account['platform'] ?? null) !== 'meta'
                || ! is_string($account['id'] ?? null)
                || preg_match('/\Aact_[0-9]+\z/', $account['id']) !== 1
                || ! is_string($account['name'] ?? null)
                || ! is_string($account['currency'] ?? null)
                || (! is_string($account['status'] ?? null) && ! is_int($account['status'] ?? null))) {
                throw new AdKitRequestException('adkit_invalid_response');
            }
        }

        return $accounts;
    }

    /** @return list<array{id: string, name: string, picture?: mixed}> */
    public function pages(ProviderConnection $connection, string $targetAccountId): array
    {
        if (preg_match('/\Aact_[0-9]+\z/', $targetAccountId) !== 1) {
            throw new AdKitRequestException('adkit_invalid_request');
        }
        $body = $this->request($connection, '/manage/meta/accounts/'.$targetAccountId.'/pages');
        $pages = $body['pages'] ?? null;
        if (! is_array($pages) || ! array_is_list($pages)) {
            throw new AdKitRequestException('adkit_invalid_response');
        }
        foreach ($pages as &$page) {
            if (! is_array($page) || ! is_string($page['id'] ?? null) || preg_match('/\A[0-9]+\z/', $page['id']) !== 1
                || ! is_string($page['name'] ?? null) || trim($page['name']) === '') {
                throw new AdKitRequestException('adkit_invalid_response');
            }
            $page = $this->withoutCredentials(array_intersect_key($page, array_flip(['id', 'name', 'picture'])));
        }
        unset($page);

        return $pages;
    }

    /** @param array<array-key, mixed> $data
     * @return array<array-key, mixed>
     */
    private function withoutCredentials(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && preg_match('/token|secret|authorization|api.?key/i', $key)) {
                unset($data[$key]);
            } elseif (is_array($value)) {
                $data[$key] = $this->withoutCredentials($value);
            } elseif (is_string($value) && in_array($key, ['url', 'picture'], true)) {
                $parts = parse_url($value);
                if ($parts === false || ($parts['scheme'] ?? null) !== 'https' || ! isset($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
                    $data[$key] = null;

                    continue;
                }
                $query = array_filter(explode('&', $parts['query'] ?? ''), fn (string $part): bool => $part !== ''
                    && preg_match('/token|secret|authorization|api.?key/i', urldecode(explode('=', $part, 2)[0])) !== 1);
                $data[$key] = 'https://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '')
                    .($parts['path'] ?? '').($query === [] ? '' : '?'.implode('&', $query));
            }
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<array-key, mixed>
     */
    public function read(ProviderProfile $profile, string $endpoint, array $payload, string $description): array
    {
        return $this->forward($profile, [
            'platform' => 'meta', 'accountId' => $profile->providerConnection?->subject,
            'endpoint' => $endpoint, 'method' => 'GET',
            'description' => $description, 'payload' => $payload,
        ])['response'];
    }

    /**
     * @param  array<string, mixed>  $originalBody
     * @return array<string, mixed>
     */
    public function forward(ProviderProfile $profile, array $originalBody, bool $publish = false): array
    {
        try {
            $connection = $profile->providerConnection;

            if ($profile->provider !== 'adkit'
                || ! $connection instanceof ProviderConnection
                || $connection->provider !== 'adkit'
                || ! is_string($profile->provider_id)
                || $profile->provider_id !== $connection->client_id
                || ! is_string($connection->subject)
                || preg_match('/\Aact_[0-9]+\z/', $connection->subject) !== 1) {
                throw new AdKitRequestException('adkit_mapping_invalid');
            }
        } catch (DecryptException) {
            throw new AdKitRequestException('adkit_mapping_invalid');
        }

        $write = ($originalBody['method'] ?? null) === 'POST';
        $numeric = is_string($originalBody['endpoint'] ?? null) && ctype_digit(explode('/', ltrim($originalBody['endpoint'], '/'))[0]);
        if (! is_string($originalBody['endpoint'] ?? null)
            || preg_match($write ? '~\A/?act_[0-9]+/(?:campaigns|adsets|ads|adcreatives|adimages)\z~'
                : '~\A/?(?:act_[0-9]+(?:/(?:campaigns|adsets|ads|insights|adcreatives|adimages))?|[0-9]+(?:/(?:adsets|ads|insights))?)\z~', $originalBody['endpoint']) !== 1
            || ($originalBody['platform'] ?? null) !== 'meta'
            || ! in_array($originalBody['method'] ?? null, ['GET', 'POST'], true)
            || (! $write && $publish)
            || ! is_string($originalBody['description'] ?? null) || trim($originalBody['description']) === ''
            || mb_strlen($originalBody['description']) > 200
            || ! is_array($originalBody['payload'] ?? null)
            || array_key_exists('publish', $originalBody['payload'])
            || array_diff(array_keys($originalBody), ['platform', 'endpoint', 'method', 'description', 'payload', 'accountId']) !== []
            || (array_key_exists('accountId', $originalBody) && (! is_string($originalBody['accountId'])
                || preg_match('/\Aact_[0-9]+\z/', $originalBody['accountId']) !== 1
                || (! $numeric && $originalBody['accountId'] !== $connection->subject)))) {
            throw new AdKitRequestException('adkit_invalid_request');
        }

        $body = $this->request($connection, '/manage/platform-api-requests', [...$originalBody, 'accountId' => $originalBody['accountId'] ?? $connection->subject], $publish);

        if ($write) {
            if (! $publish) {
                if (($body['platform'] ?? null) !== 'meta' || ! in_array($body['status'] ?? null, ['draft', 'pending'], true)
                    || ! is_string($body['draftId'] ?? null) || trim($body['draftId']) === '') {
                    throw new AdKitRequestException('adkit_draft_unsupported');
                }

                return $body;
            }
            if (($body['platform'] ?? null) !== 'meta' || ($body['status'] ?? null) !== 'published'
                || ! is_array($body['results'] ?? null) || ! array_is_list($body['results']) || $body['results'] === []) {
                throw new AdKitRequestException('adkit_unconfirmed');
            }
            foreach ($body['results'] as $result) {
                if (! is_array($result) || array_is_list($result) || array_key_exists('error', $result)
                    || ! is_array($result['raw'] ?? null) || array_key_exists('error', $result['raw'])) {
                    throw new AdKitRequestException('adkit_unconfirmed');
                }
                if (str_ends_with($originalBody['endpoint'], '/adimages')) {
                    $images = $result['raw']['images'] ?? null;
                    if (! is_array($images) || array_is_list($images) || $images === []) {
                        throw new AdKitRequestException('adkit_unconfirmed');
                    }
                    foreach ($images as $image) {
                        if (! is_array($image) || array_key_exists('error', $image)
                            || ! is_string($image['hash'] ?? null) || preg_match('/\A[a-fA-F0-9]{32}\z/', $image['hash']) !== 1) {
                            throw new AdKitRequestException('adkit_unconfirmed');
                        }
                        foreach (['width', 'height'] as $dimension) {
                            if (array_key_exists($dimension, $image) && (! is_int($image[$dimension]) || $image[$dimension] < 1)) {
                                throw new AdKitRequestException('adkit_unconfirmed');
                            }
                        }
                    }
                } elseif (! is_string($result['raw']['id'] ?? null) || preg_match('/\A[0-9]+\z/', $result['raw']['id']) !== 1) {
                    throw new AdKitRequestException('adkit_unconfirmed');
                }
            }

            return $body;
        }

        if (($body['platform'] ?? null) !== 'meta'
            || ($body['status'] ?? null) !== 'executed'
            || ! is_array($body['response'] ?? null)
            || array_key_exists('error', $body['response'])) {
            throw new AdKitRequestException('adkit_invalid_response');
        }

        return $body;
    }

    /**
     * @param  array<string, mixed>|null  $payload
     * @return array<string, mixed>
     */
    private function request(ProviderConnection $connection, string $path, ?array $payload = null, bool $publish = false): array
    {
        $baseUrl = config('services.adkit.base_url');
        $timeout = config('services.adkit.timeout');
        $connectTimeout = config('services.adkit.connect_timeout');
        $maxBytes = config('services.adkit.max_response_bytes');

        if (! is_string($baseUrl)
            || rtrim($baseUrl, '/') !== 'https://app.adkit.so/api/v1'
            || ! is_int($timeout) || $timeout < 1
            || ! is_int($connectTimeout) || $connectTimeout < 1
            || ! is_int($maxBytes) || $maxBytes < 1 || $maxBytes === PHP_INT_MAX) {
            throw new AdKitRequestException('adkit_configuration_error');
        }

        try {
            $token = $connection->access_token;
        } catch (DecryptException) {
            throw new AdKitRequestException('adkit_connection_invalid');
        }

        if ($connection->provider !== 'adkit'
            || ! is_string($connection->client_id) || trim($connection->client_id) === ''
            || preg_match('/[\r\n]/', $connection->client_id)
            || ! is_string($token) || trim($token) === '' || preg_match('/[\r\n]/', $token)
            || ($connection->access_token_expires_at !== null && $connection->access_token_expires_at->isPast())) {
            throw new AdKitRequestException('adkit_connection_invalid');
        }

        $deadline = $this->monotonicSeconds() + $timeout;

        try {
            $request = Http::acceptJson()->asJson()->withToken($token)
                ->withHeader('x-adkit-project-id', $connection->client_id)
                ->timeout($timeout)->connectTimeout($connectTimeout)
                ->withOptions(['verify' => true, 'allow_redirects' => false, 'stream' => true, 'read_timeout' => $timeout]);
            if ($publish) {
                $request->withQueryParameters(['publish' => 'true']);
            }
            $response = $payload === null
                ? $request->get(rtrim($baseUrl, '/').$path)
                : $request->post(rtrim($baseUrl, '/').$path, $payload);
        } catch (ConnectionException) {
            throw new AdKitRequestException('adkit_unreachable');
        } catch (RequestException $exception) {
            throw $this->httpFailure($exception->response->status());
        }

        $stream = $response->toPsrResponse()->getBody();

        try {
            if (! $response->successful()) {
                throw $this->httpFailure($response->status());
            }

            $body = '';
            while (true) {
                if ($this->monotonicSeconds() >= $deadline) {
                    throw new AdKitRequestException('adkit_unreachable');
                }
                if ($stream->eof()) {
                    break;
                }

                $chunk = $stream->read(min(8192, $maxBytes - strlen($body) + 1));
                // A blocking PSR read cannot be preempted here, including the final chunk.
                if ($this->monotonicSeconds() >= $deadline) {
                    throw new AdKitRequestException('adkit_unreachable');
                }
                if ($chunk === '' && ! $stream->eof()) {
                    throw new AdKitRequestException('adkit_unreachable');
                }
                $body .= $chunk;
                if (strlen($body) > $maxBytes) {
                    throw new AdKitRequestException('adkit_response_too_large');
                }
            }
        } catch (RuntimeException $exception) {
            if ($exception instanceof AdKitRequestException) {
                throw $exception;
            }

            throw new AdKitRequestException('adkit_unreachable');
        } finally {
            $stream->close();
        }

        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new AdKitRequestException('adkit_invalid_response');
        }

        if (! is_array($decoded) || array_is_list($decoded) || array_key_exists('error', $decoded)) {
            throw new AdKitRequestException('adkit_invalid_response');
        }

        return $decoded;
    }

    protected function monotonicSeconds(): float
    {
        return hrtime(true) / 1_000_000_000;
    }

    private function httpFailure(int $status): AdKitRequestException
    {
        return new AdKitRequestException(match ($status) {
            401, 403 => 'adkit_authentication_failed',
            429 => 'adkit_rate_limited',
            default => 'adkit_provider_error',
        }, $status);
    }
}
