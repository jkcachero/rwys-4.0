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
    public function forward(ProviderProfile $profile, array $originalBody): array
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

        if (! is_string($originalBody['endpoint'] ?? null)
            || preg_match('~\A/?[A-Za-z0-9_-]+(?:/[A-Za-z0-9_-]+)*\z~', $originalBody['endpoint']) !== 1
            || ($originalBody['platform'] ?? null) !== 'meta'
            || ($originalBody['method'] ?? null) !== 'GET'
            || ! is_string($originalBody['description'] ?? null) || trim($originalBody['description']) === ''
            || mb_strlen($originalBody['description']) > 200
            || ! is_array($originalBody['payload'] ?? null)
            || array_key_exists('publish', $originalBody['payload'])
            || array_diff(array_keys($originalBody), ['platform', 'endpoint', 'method', 'description', 'payload', 'accountId']) !== []
            || (array_key_exists('accountId', $originalBody) && $originalBody['accountId'] !== $connection->subject)) {
            throw new AdKitRequestException('adkit_invalid_request');
        }

        $body = $this->request($connection, '/manage/platform-api-requests', [...$originalBody, 'accountId' => $connection->subject]);

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
    private function request(ProviderConnection $connection, string $path, ?array $payload = null): array
    {
        if (config('services.adkit.enabled', false) !== true) {
            throw new AdKitRequestException('adkit_disabled');
        }

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
        });
    }
}
