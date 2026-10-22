<?php

namespace App\Providers;

use App\Models\ProviderConnection;
use App\Models\User;
use App\Services\AIModelCatalog;
use Carbon\Carbon;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class OpenAiOAuthService
{
    private const STATE_TTL = 600;

    /** @return array{url: string, state: string, expiresAt: string} */
    public function authorization(User $user): array
    {
        $state = Str::random(64);
        $nonce = Str::random(64);
        $verifier = Str::random(96);
        $connection = $user->providerConnections()->where('provider', 'openai')->first();
        $clientId = (string) ($connection?->client_id ?: config('workspace.oauth.client_id'));
        $expiresAt = now()->addSeconds(self::STATE_TTL);
        $hostId = config('workspace.oauth.ext_agent_host_id') ?: Cache::lock('openai-oauth:host-lock', 10)->block(5,
            fn (): string => Cache::rememberForever('openai-oauth:host-id', fn (): string => 'urn:uuid:'.Str::uuid()));

        Cache::put($this->cacheKey($state), [
            'user_id' => $user->getKey(),
            'nonce' => $nonce,
            'verifier' => $verifier,
            'client_id' => $clientId,
            'connection_id' => $connection?->getKey(),
            'subject' => $connection?->subject,
            'redirect_uri' => config('workspace.oauth.redirect_uri'),
            'resource' => config('workspace.oauth.resource'),
            'status' => 'pending',
            'message' => 'Waiting for ChatGPT authorization.',
            'expiresAt' => $expiresAt->toIso8601String(),
        ], self::STATE_TTL);

        $query = http_build_query([
            'client_id' => $clientId,
            'agent_name_hint' => $clientId === 'dynamic_agent_client' ? config('workspace.oauth.agent_name_hint') : null,
            'ext_agent_host_id' => $hostId,
            'response_type' => 'code',
            'redirect_uri' => config('workspace.oauth.redirect_uri'),
            'scope' => implode(' ', config('workspace.oauth.scopes', [])),
            'resource' => config('workspace.oauth.resource'),
            'state' => $state,
            'nonce' => $nonce,
            'code_challenge' => $this->challenge($verifier),
            'code_challenge_method' => 'S256',
        ]);

        return [
            'state' => $state,
            'url' => config('workspace.oauth.authorization_endpoint').'?'.$query,
            'expiresAt' => $expiresAt->toIso8601String(),
        ];
    }

    /** @return array{status: string, message: string, expiresAt: string}|null */
    public function attempt(User $user, string $state): ?array
    {
        $data = Cache::get($this->cacheKey($state));

        if (! is_array($data) || $data['user_id'] !== $user->getKey()) {
            return null;
        }

        return array_intersect_key($data, array_flip(['status', 'message', 'expiresAt']));
    }

    public function deny(string $state, string $error = 'access_denied'): void
    {
        $data = $this->claim($state);
        $this->finish($state, $data, 'error', $error === 'access_denied'
            ? 'ChatGPT authorization was declined. Please try again to grant access.'
            : 'ChatGPT authorization could not be completed. Please try again.');
    }

    /** @return array<string, mixed> */
    private function claim(string $state): array
    {
        $stateData = Cache::get($this->cacheKey($state));

        if (! is_array($stateData) || $stateData['status'] !== 'pending'
            || ! Cache::add($this->cacheKey($state).':claimed', true, self::STATE_TTL)) {
            throw new RuntimeException('The OAuth authorization has expired or was already used.');
        }

        return $stateData;
    }

    /** @param array<string, mixed> $data */
    private function finish(string $state, array $data, string $status, string $message): void
    {
        unset($data['nonce'], $data['verifier']);
        $data['status'] = $status;
        $data['message'] = $message;
        Cache::put($this->cacheKey($state), $data, Carbon::parse($data['expiresAt']));
    }

    public function complete(string $code, string $state, ?string $issuedClientId = null): ProviderConnection
    {
        $data = $this->claim($state);

        try {
            $owner = User::query()->find($data['user_id']);
            if ($owner === null || ! $owner->hasVerifiedEmail()) {
                throw new RuntimeException('The authorization owner is no longer eligible.');
            }
            $clientId = $data['client_id'];
            if ($clientId === 'dynamic_agent_client') {
                if (! is_string($issuedClientId) || ! preg_match('/^oaiapp_[A-Za-z0-9_-]+$/D', $issuedClientId)) {
                    throw new RuntimeException('The issued client ID is missing or invalid.');
                }
                $clientId = $issuedClientId;
            } elseif ($issuedClientId !== null && $issuedClientId !== $clientId) {
                throw new RuntimeException('The returning client ID did not match.');
            }

            $token = $this->token([
                'grant_type' => 'authorization_code',
                'client_id' => $clientId,
                'code' => $code,
                'redirect_uri' => $data['redirect_uri'],
                'code_verifier' => $data['verifier'],
                'resource' => $data['resource'],
            ]);
            if (! is_string($token['id_token'] ?? null)) {
                throw new RuntimeException('The signed identity is missing.');
            }
            $claims = $this->validateIdentityToken($token['id_token'], $clientId, $data['nonce']);
            $scopes = $this->scopes($token['scope'] ?? '');

            $connection = Cache::lock('openai-oauth:owner:'.$data['user_id'], 60)->block(5, function () use ($data, $claims, $clientId, $token, $scopes): ProviderConnection {
                $user = User::query()->findOrFail($data['user_id']);
                $current = $user->providerConnections()->where('provider', 'openai')->first();
                if ($current?->getKey() !== $data['connection_id']
                    || ($current !== null && ($current->client_id !== $clientId || $current->subject !== $claims['sub'] || $current->subject !== $data['subject']))) {
                    throw new RuntimeException('The selected account no longer matches.');
                }

                return $user->providerConnections()->updateOrCreate(['provider' => 'openai'], [
                    'client_id' => $clientId,
                    'subject' => $claims['sub'],
                    'access_token' => $token['access_token'],
                    'refresh_token' => $token['refresh_token'] ?? null,
                    'access_token_expires_at' => now()->addSeconds((int) $token['expires_in']),
                    'scopes' => $scopes,
                ]);
            });
            $this->finish($state, $data, 'connected', 'ChatGPT is connected for inference.');

            return $connection;
        } catch (Throwable) {
            $message = 'ChatGPT authorization could not be completed. Please start a new authorization.';
            $this->finish($state, $data, 'error', $message);
            throw new RuntimeException($message);
        }
    }

    public function accessToken(ProviderConnection $connection): string
    {
        return Cache::lock('openai-oauth:owner:'.$connection->user_id, 60)->block(5, function () use ($connection): string {
            $connection->refresh();
            $this->scopes(implode(' ', $connection->scopes ?? []));

            if ($connection->access_token_expires_at?->isAfter(now()->addMinute())) {
                return (string) $connection->access_token;
            }

            if (! filled($connection->refresh_token) || ! filled($connection->client_id)) {
                throw new RuntimeException('The OpenAI connection must be re-authorized.');
            }

            $token = $this->token([
                'grant_type' => 'refresh_token',
                'client_id' => $connection->client_id,
                'refresh_token' => $connection->refresh_token,
                'resource' => config('workspace.oauth.resource'),
            ]);
            $scopes = array_key_exists('scope', $token) ? $this->scopes($token['scope']) : $connection->scopes;

            $connection->update([
                'access_token' => $token['access_token'],
                'refresh_token' => $token['refresh_token'] ?? $connection->refresh_token,
                'access_token_expires_at' => now()->addSeconds((int) $token['expires_in']),
                'scopes' => $scopes,
            ]);

            return (string) $connection->access_token;
        });
    }

    public function disconnect(User $user): void
    {
        Cache::lock('openai-oauth:owner:'.$user->getKey(), 60)->block(5, function () use ($user): void {
            $connection = $user->providerConnections()->where('provider', 'openai')->first();
            if ($connection === null) {
                return;
            }
            try {
                $discovery = $this->http()->get(config('workspace.oauth.discovery_endpoint'));
                $endpoint = $discovery->json('revocation_endpoint');
                if (! $discovery->successful() || ! is_string($endpoint)
                    || parse_url($endpoint, PHP_URL_SCHEME) !== 'https'
                    || parse_url($endpoint, PHP_URL_HOST) !== 'auth.openai.com'
                    || ! filled($connection->refresh_token) || ! filled($connection->client_id)) {
                    throw new RuntimeException('Revocation unavailable.');
                }
                $response = $this->http()->asForm()->post($endpoint, [
                    'token' => $connection->refresh_token,
                    'token_type_hint' => 'refresh_token',
                    'client_id' => $connection->client_id,
                ]);
                if ($response->status() !== 200) {
                    throw new RuntimeException('Revocation not confirmed.');
                }
            } catch (Throwable) {
                throw new RuntimeException('Remote revocation was not confirmed. The connection was retained; retry or disconnect the app in ChatGPT Settings.');
            }
            $connection->delete();
        });
    }

    /** @return list<array{slug: string, displayName: string, thinkingLevels: list<string>}> */
    public function models(ProviderConnection $connection, bool $refresh = false): array
    {
        $token = $this->accessToken($connection);
        $key = 'openai-oauth:models:'.$connection->user_id.':'.$connection->getKey().':'.hash('sha256', json_encode([
            AIModelCatalog::CACHE_VERSION, $connection->client_id, $connection->subject, $token, config('workspace.models.openai', []),
        ], JSON_THROW_ON_ERROR));

        if ($refresh) {
            Cache::forget($key);
        }

        return Cache::remember($key, 60, function () use ($token): array {
            try {
                $response = $this->http()->withToken($token)->get(config('workspace.oauth.models_endpoint'));
                $models = $response->json('models');
                if (! $response->successful() || ! is_array($models) || ! array_is_list($models)) {
                    throw new RuntimeException('Invalid catalog.');
                }
                foreach ($models as $model) {
                    if (! is_array($model) || ! is_string($model['slug'] ?? null) || $model['slug'] === ''
                        || ! is_string($model['display_name'] ?? null) || $model['display_name'] === ''
                        || ! is_string($model['visibility'] ?? null)) {
                        throw new RuntimeException('Invalid catalog entry.');
                    }
                }

                return collect($models)->filter(fn (array $model): bool => $model['visibility'] === 'list')
                    ->map(fn (array $model): array => [
                        'slug' => $model['slug'], 'displayName' => $model['display_name'],
                        'thinkingLevels' => AIModelCatalog::thinkingLevels($model),
                    ])->values()->all();
            } catch (Throwable) {
                throw new RuntimeException('The ChatGPT model catalog could not be loaded.');
            }
        });
    }

    /** @param array<string, string> $parameters
     * @return array<string, mixed>
     */
    private function token(array $parameters): array
    {
        try {
            $response = $this->http()->asForm()->post(config('workspace.oauth.token_endpoint'), $parameters);
            $token = $response->json();
            if (! $response->successful() || ! is_array($token) || ! is_string($token['access_token'] ?? null)
                || $token['access_token'] === '' || ! is_numeric($token['expires_in'] ?? null) || $token['expires_in'] <= 0
                || (isset($token['token_type']) && strcasecmp($token['token_type'], 'Bearer') !== 0)
                || (isset($token['refresh_token']) && (! is_string($token['refresh_token']) || $token['refresh_token'] === ''))) {
                throw new RuntimeException('Invalid token response.');
            }

            return $token;
        } catch (Throwable) {
            throw new RuntimeException('ChatGPT credentials could not be obtained. Please re-authorize the connection.');
        }
    }

    /** @return list<string> */
    private function scopes(mixed $scope): array
    {
        $scopes = is_string($scope) ? array_values(array_filter(preg_split('/\s+/', $scope) ?: [])) : [];
        if (array_diff(['openid', 'profile', 'email', 'offline_access', 'resource.invoke', 'chatgpt.tokens.use.direct'], $scopes) !== []) {
            throw new RuntimeException('The account was not granted the required ChatGPT permissions.');
        }

        return $scopes;
    }

    private function http(): PendingRequest
    {
        return Http::acceptJson()->connectTimeout(5)->timeout(15)->withoutRedirecting();
    }

    private function cacheKey(string $state): string
    {
        return 'openai-oauth:'.$state;
    }

    private function challenge(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    /** @return array<string, mixed> */
    private function validateIdentityToken(string $token, string $clientId, string $nonce): array
    {
        $parts = explode('.', $token);

        if (count($parts) !== 3) {
            throw new RuntimeException('OpenAI returned an invalid identity token.');
        }

        $header = json_decode($this->decode($parts[0]), true);
        $claims = json_decode($this->decode($parts[1]), true);

        if (! is_array($header) || ! is_array($claims) || ($header['alg'] ?? null) !== 'RS256') {
            throw new RuntimeException('OpenAI returned an unsupported identity token.');
        }

        $key = collect($this->http()->get(config('workspace.oauth.jwks_uri'))->throw()->json('keys', []))
            ->firstWhere('kid', $header['kid'] ?? null);

        if (! is_array($key) || ! is_string($key['n'] ?? null) || ! is_string($key['e'] ?? null)) {
            throw new RuntimeException('OpenAI identity signing key was not found.');
        }

        $pem = $this->jwkToPem($key['n'], $key['e']);
        $signature = $this->decodeBinary($parts[2]);

        if (openssl_verify($parts[0].'.'.$parts[1], $signature, $pem, OPENSSL_ALGO_SHA256) !== 1) {
            throw new RuntimeException('OpenAI identity token signature validation failed.');
        }

        if (($claims['iss'] ?? null) !== config('workspace.oauth.issuer')
            || ! in_array($clientId, (array) ($claims['aud'] ?? []), true)
            || ($claims['nonce'] ?? null) !== $nonce
            || ! is_numeric($claims['exp'] ?? null) || $claims['exp'] <= time()
            || (isset($claims['nbf']) && $claims['nbf'] > time())
            || (count((array) ($claims['aud'] ?? [])) > 1 && ($claims['azp'] ?? null) !== $clientId)
            || (isset($claims['azp']) && $claims['azp'] !== $clientId)
            || ! is_string($claims['sub'] ?? null) || $claims['sub'] === '') {
            throw new RuntimeException('OpenAI identity token claims were rejected.');
        }

        return $claims;
    }

    private function decode(string $value): string
    {
        return $this->decodeBinary($value);
    }

    private function decodeBinary(string $value): string
    {
        return base64_decode(strtr($value, '-_', '+/').str_repeat('=', (4 - strlen($value) % 4) % 4), true) ?: '';
    }

    private function jwkToPem(string $modulus, string $exponent): string
    {
        $modulus = $this->derInteger($this->decodeBinary($modulus));
        $exponent = $this->derInteger($this->decodeBinary($exponent));
        $rsa = "\x30".$this->derLength(strlen($modulus.$exponent)).$modulus.$exponent;
        $bitString = "\x03".$this->derLength(strlen($rsa) + 1)."\x00".$rsa;
        $sequence = "\x30".$this->derLength(strlen("\x30\x0d\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00".$bitString))."\x30\x0d\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00".$bitString;

        return "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($sequence), 64, "\n")."-----END PUBLIC KEY-----\n";
    }

    private function derInteger(string $value): string
    {
        $value = ltrim($value, "\x00");

        if ($value !== '' && (ord($value[0]) & 0x80) !== 0) {
            $value = "\x00".$value;
        }

        return "\x02".$this->derLength(strlen($value)).$value;
    }

    private function derLength(int $length): string
    {
        if ($length < 128) {
            return chr($length);
        }

        $encoded = ltrim(pack('N', $length), "\x00");

        return chr(0x80 | strlen($encoded)).$encoded;
    }
}
