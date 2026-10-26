<?php

namespace App\Services;

use App\AdKit\AdKitHttpClient;
use App\Exceptions\AdKit\AdKitRequestException;
use App\Models\Client;
use App\Models\PersonalAccessToken;
use App\Models\ProviderAccount;
use App\Models\User;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class AdKitBrokerService
{
    public function __construct(private readonly AdKitHttpClient $adkit) {}

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    public function forward(Request $request, array $body): array
    {
        $target = explode('/', ltrim($body['endpoint'], '/'))[0];
        [$account, $snapshot] = $this->context($request, $target);
        $connection = $account->profile->providerConnection;
        if (array_key_exists('accountId', $body) && $body['accountId'] !== $connection->subject) {
            throw ValidationException::withMessages(['accountId' => 'The accountId must match the configured connection context.']);
        }
        $body['payload']['limit'] ??= 25;
        $fields = explode(',', $body['payload']['fields']);
        $required = str_ends_with($body['endpoint'], '/insights') ? ['account_id'] : ['id', 'account_id'];
        $injectedFields = array_values(array_diff($required, $fields));
        if ($injectedFields !== []) {
            $body['payload']['fields'] = implode(',', [...$fields, ...$injectedFields]);
        }

        [$account] = $this->context($request, $target, $snapshot);
        $members = $this->adkit->connectedAccounts($account->profile->providerConnection);
        $this->context($request, $target, $snapshot);
        if (! in_array($target, array_column($members, 'id'), true)) {
            throw new AdKitRequestException('adkit_invalid_response');
        }

        [$account] = $this->context($request, $target, $snapshot);
        $envelope = $this->adkit->forward($account->profile, $body);
        $this->context($request, $target, $snapshot);
        if (! is_array($envelope['results'] ?? null) || ! array_is_list($envelope['results'])) {
            throw new AdKitRequestException('adkit_invalid_response');
        }
        foreach ($envelope['results'] as $result) {
            if (! is_array($result) || array_is_list($result)) {
                throw new AdKitRequestException('adkit_invalid_response');
            }
        }
        $envelope = array_intersect_key($envelope, array_flip(['platform', 'status', 'response', 'results']));
        $this->verifyResponse($envelope, $target, $body);
        $envelope = $this->restoreProjection($envelope, $injectedFields, $target, $body);

        try {
            $credentials = [$connection->access_token, $connection->refresh_token, $connection->client_id, $request->bearerToken()];
        } catch (DecryptException) {
            throw new AdKitRequestException('adkit_connection_invalid');
        }
        if (is_string($request->bearerToken()) && str_contains($request->bearerToken(), '|')) {
            $credentials[] = explode('|', $request->bearerToken(), 2)[1];
        }
        $secrets = [];
        foreach ($credentials as $credential) {
            if (is_string($credential) && $credential !== '') {
                array_push($secrets, $credential, rawurlencode($credential), urlencode($credential));
            }
        }

        return $this->sanitize($envelope, $secrets);
    }

    /**
     * @param  array<string, mixed>|null  $snapshot
     * @return array{ProviderAccount, array<string, mixed>}
     */
    private function context(Request $request, string $target, ?array $snapshot = null): array
    {
        $original = $request->attributes->get('agent_token');
        $client = $request->attributes->get('agent_client');
        abort_unless($original instanceof PersonalAccessToken && $client instanceof Client, 403);
        $token = PersonalAccessToken::query()->find($original->id);
        $client = $client->fresh();
        $owner = $token?->tokenable;
        abort_unless($token !== null && $client?->is_active && $token->client_id === $client->id
            && $token->revoked_at === null && ($token->expires_at === null || $token->expires_at->isFuture())
            && $token->can('adkit.read') && $owner instanceof User && $owner->id === $request->user()?->id
            && $token->issued_by === $owner->id && $owner->hasVerifiedEmail() && $client->hasActiveOwner($owner)
            && is_array($token->allowed_account_ids), 403);

        $accounts = ProviderAccount::query()->with('profile.providerConnection')
            ->where('provider_id_hash', hash('sha256', $target))->where('platform', 'meta')->where('is_active', true)
            ->whereHas('permissions', fn ($query) => $query->where('client_id', $client->id)->where('is_active', true))
            ->whereHas('profile', fn ($query) => $query->where('provider', 'adkit')->where('is_active', true)
                ->whereHas('clients', fn ($clients) => $clients->whereKey($client->id)))
            ->when($token->allowed_account_ids !== [], fn ($query) => $query->whereIn('public_id', $token->allowed_account_ids))
            ->limit(2)->get();
        abort_if($accounts->isEmpty(), 404);
        if ($accounts->count() !== 1) {
            throw new AdKitRequestException('adkit_mapping_invalid');
        }
        $account = $accounts->first();
        if (config('services.adkit.enabled', false) !== true) {
            throw new AdKitRequestException('adkit_disabled');
        }
        try {
            $profile = $account->profile;
            $connection = $profile->providerConnection;
            if ($account->provider_id !== $target || $connection === null || $connection->provider !== 'adkit'
                || ! is_string($profile->provider_id) || $profile->provider_id !== $connection->client_id
                || trim($connection->client_id) === '' || preg_match('/[\r\n]/', $connection->client_id)
                || ! is_string($connection->subject) || preg_match('/\Aact_[0-9]+\z/', $connection->subject) !== 1) {
                throw new AdKitRequestException('adkit_mapping_invalid');
            }
            $credential = $connection->access_token;
            if (! is_string($credential) || trim($credential) === '' || preg_match('/[\r\n]/', $credential)
                || ($connection->access_token_expires_at !== null && ! $connection->access_token_expires_at->isFuture())) {
                throw new AdKitRequestException('adkit_connection_invalid');
            }
            $current = [
                'client' => $client->id, 'owner' => $owner->id, 'account' => $account->id, 'uuid' => $account->public_id,
                'target' => $account->provider_id, 'profile' => $profile->id, 'project' => $profile->provider_id,
                'connection' => $connection->id, 'connection_owner' => $connection->user_id, 'subject' => $connection->subject,
                'credential' => hash('sha256', $credential), 'expiry' => $connection->access_token_expires_at?->toIso8601String(),
            ];
        } catch (DecryptException) {
            throw new AdKitRequestException('adkit_mapping_invalid');
        }
        if ($snapshot !== null && $snapshot !== $current) {
            throw new AdKitRequestException('adkit_mapping_invalid');
        }

        return [$account, $current];
    }

    /** @param array<string, mixed> $envelope
     * @param  array<string, mixed>  $body
     */
    private function verifyResponse(array $envelope, string $target, array $body): void
    {
        $response = $envelope['response'];
        $edge = str_contains(ltrim($body['endpoint'], '/'), '/');
        if ($edge) {
            $rows = $response['data'] ?? null;
            if (! is_array($rows) || ! array_is_list($rows) || count($rows) > $body['payload']['limit']) {
                throw new AdKitRequestException('adkit_invalid_response');
            }
        } else {
            $rows = [$response];
            $this->verifyAccountId($response['id'] ?? null, $target);
        }
        foreach ($rows as $row) {
            if (! is_array($row) || array_is_list($row)) {
                throw new AdKitRequestException('adkit_invalid_response');
            }
            $this->verifyAccountId($row['account_id'] ?? null, $target);
            if ($edge && ! str_ends_with($body['endpoint'], '/insights')
                && (! is_string($row['id'] ?? null) || preg_match('/\A[0-9]+\z/', $row['id']) !== 1)) {
                throw new AdKitRequestException('adkit_invalid_response');
            }
        }
        if (array_key_exists('paging', $response)) {
            $paging = $response['paging'];
            if (! is_array($paging) || (array_key_exists('cursors', $paging) && ! is_array($paging['cursors']))) {
                throw new AdKitRequestException('adkit_invalid_response');
            }
            foreach (['before', 'after'] as $key) {
                if (array_key_exists($key, $paging['cursors'] ?? [])
                    && (! is_string($paging['cursors'][$key]) || preg_match('~\A[A-Za-z0-9_+=/\-]{1,4096}\z~', $paging['cursors'][$key]) !== 1)) {
                    throw new AdKitRequestException('adkit_invalid_response');
                }
            }
            foreach (['next', 'previous'] as $key) {
                if (isset($paging[$key]) && (! is_string($paging[$key]) || strlen($paging[$key]) > 16384)) {
                    throw new AdKitRequestException('adkit_invalid_response');
                }
            }
        }
        $this->verifyIdentities($envelope, $target);
    }

    private function verifyAccountId(mixed $value, string $target): void
    {
        if (! is_string($value) || preg_match('/\A(?:act_)?[0-9]+\z/', $value) !== 1
            || preg_replace('/\Aact_/', '', $value) !== substr($target, 4)) {
            throw new AdKitRequestException('adkit_invalid_response');
        }
    }

    /**
     * @param  array<mixed>  $data
     * @param  list<string>  $injectedFields
     * @param  array<string, mixed>  $body
     * @return array<mixed>
     */
    private function restoreProjection(array $data, array $injectedFields, string $target, array $body): array
    {
        foreach ($data as $key => $value) {
            if (! is_array($value)) {
                continue;
            }
            if (in_array($key, ['response', 'raw'], true)) {
                if ($key === 'raw') {
                    $this->verifyResponse(['response' => $value], $target, $body);
                }
                if (str_contains(ltrim($body['endpoint'], '/'), '/')) {
                    foreach ($value['data'] as $index => $row) {
                        $value['data'][$index] = array_diff_key($row, array_flip($injectedFields));
                    }
                } else {
                    $value = array_diff_key($value, array_flip($injectedFields));
                }
                $data[$key] = $value;
            } else {
                $data[$key] = $this->restoreProjection($value, $injectedFields, $target, $body);
            }
        }

        return $data;
    }

    /** @param array<mixed> $data */
    private function verifyIdentities(array $data, string $target): void
    {
        foreach ($data as $key => $value) {
            if ($key === 'account_id') {
                $this->verifyAccountId($value, $target);
            }
            if (is_array($value)) {
                $this->verifyIdentities($value, $target);
            }
        }
    }

    /** @param array<mixed> $data
     * @param  list<string>  $secrets
     * @return array<mixed>
     */
    private function sanitize(array $data, array $secrets): array
    {
        $clean = [];
        foreach ($data as $key => $value) {
            if (is_string($key) && $this->credentialKey($key)) {
                continue;
            }
            if (is_array($value)) {
                $value = $this->sanitize($value, $secrets);
            } elseif (is_string($value)) {
                if (in_array($key, ['next', 'previous'], true)) {
                    $parts = parse_url($value);
                    if ($parts === false || ! in_array($parts['scheme'] ?? null, ['https', 'http'], true)
                        || ! isset($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
                        $value = null;
                    } else {
                        $query = array_filter(explode('&', $parts['query'] ?? ''),
                            fn (string $part): bool => $part !== '' && ! $this->credentialKey(urldecode(explode('=', $part, 2)[0])));
                        $value = $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '')
                            .($parts['path'] ?? '').($query === [] ? '' : '?'.implode('&', $query));
                    }
                }
                if (is_string($value)) {
                    $value = str_replace($secrets, '[redacted]', $value);
                }
            }
            if (is_string($key)) {
                $key = str_replace($secrets, '[redacted]', $key);
            }
            $clean[$key] = $value;
        }

        return $clean;
    }

    private function credentialKey(string $key): bool
    {
        return preg_match('/token|secret|authorization|api.?key|appsecret|project.?id/i', $key) === 1;
    }
}
