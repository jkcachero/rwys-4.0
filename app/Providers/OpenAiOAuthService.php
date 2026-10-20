<?php

namespace App\Providers;

use App\Models\ProviderConnection;
use App\Models\User;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

final class OpenAiOAuthService
{
    private const STATE_TTL = 600;

    /** @return array{url: string, state: string} */
    public function authorization(User $user): array
    {
        $state = Str::random(64);
        $nonce = Str::random(64);
        $verifier = Str::random(96);
        $clientId = (string) ($user->providerConnections()
            ->where('provider', 'openai')
            ->value('client_id') ?: config('workspace.oauth.client_id'));

        Cache::put($this->cacheKey($state), [
            'user_id' => $user->getKey(),
            'nonce' => $nonce,
            'verifier' => $verifier,
            'client_id' => $clientId,
        ], self::STATE_TTL);

        $query = http_build_query([
            'client_id' => $clientId,
            'agent_name_hint' => config('workspace.oauth.agent_name_hint'),
            'ext_agent_host_id' => config('workspace.oauth.ext_agent_host_id'),
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
        ];
    }

    public function complete(string $code, string $state): ProviderConnection
    {
        $stateData = Cache::pull($this->cacheKey($state));

        if (! is_array($stateData)) {
            throw new RuntimeException('The OAuth authorization has expired or was already used.');
        }

        $token = $this->http()->asForm()->post(config('workspace.oauth.token_endpoint'), [
            'grant_type' => 'authorization_code',
            'client_id' => $stateData['client_id'],
            'code' => $code,
            'redirect_uri' => config('workspace.oauth.redirect_uri'),
            'code_verifier' => $stateData['verifier'],
        ])->throw()->json();

        if (! is_string($token['access_token'] ?? null) || ! is_string($token['id_token'] ?? null)) {
            throw new RuntimeException('OpenAI did not return the required signed identity and access tokens.');
        }

        $claims = $this->validateIdentityToken($token['id_token'], $stateData['client_id'], $stateData['nonce']);
        $scopes = preg_split('/\s+/', (string) ($token['scope'] ?? $claims['scope'] ?? '')) ?: [];

        if (! in_array('chatgpt.tokens.use.direct', $scopes, true)) {
            throw new RuntimeException('The account was not granted direct inference access.');
        }

        $user = User::query()->findOrFail($stateData['user_id']);

        return ProviderConnection::query()->updateOrCreate(
            ['user_id' => $user->getKey(), 'provider' => 'openai'],
            [
                'client_id' => $stateData['client_id'],
                'subject' => (string) ($claims['sub'] ?? ''),
                'access_token' => $token['access_token'],
                'refresh_token' => $token['refresh_token'] ?? null,
                'access_token_expires_at' => now()->addSeconds((int) ($token['expires_in'] ?? 3600)),
                'scopes' => array_values(array_filter($scopes)),
            ],
        );
    }

    public function accessToken(ProviderConnection $connection): string
    {
        if ($connection->access_token_expires_at?->isAfter(now()->addMinute())) {
            return (string) $connection->access_token;
        }

        if (! filled($connection->refresh_token) || ! filled($connection->client_id)) {
            throw new RuntimeException('The OpenAI connection must be re-authorized.');
        }

        return Cache::lock('openai-oauth:refresh:'.$connection->getKey(), 30)->block(5, function () use ($connection): string {
            $connection->refresh();

            if ($connection->access_token_expires_at?->isAfter(now()->addMinute())) {
                return (string) $connection->access_token;
            }

            $token = $this->http()->asForm()->post(config('workspace.oauth.token_endpoint'), [
                'grant_type' => 'refresh_token',
                'client_id' => $connection->client_id,
                'refresh_token' => $connection->refresh_token,
            ])->throw()->json();

            if (! is_string($token['access_token'] ?? null)) {
                throw new RuntimeException('OpenAI did not return a refreshed access token.');
            }

            $connection->update([
                'access_token' => $token['access_token'],
                'refresh_token' => $token['refresh_token'] ?? $connection->refresh_token,
                'access_token_expires_at' => now()->addSeconds((int) ($token['expires_in'] ?? 3600)),
            ]);

            return (string) $connection->access_token;
        });
    }

    public function disconnect(User $user): void
    {
        $user->providerConnections()->where('provider', 'openai')->delete();
    }

    private function http(): PendingRequest
    {
        return Http::acceptJson()->timeout(15)->retry(2, 100);
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
            || ($claims['exp'] ?? 0) < time()
            || ! filled($claims['sub'] ?? null)) {
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
        return base64_decode(strtr($value, '-_', '+/').'='.str_repeat('=', (4 - strlen($value) % 4) % 4), true) ?: '';
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
