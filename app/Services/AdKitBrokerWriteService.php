<?php

namespace App\Services;

use App\AdKit\AdKitHttpClient;
use App\Exceptions\AdKit\AdKitRequestException;
use App\Models\AgentOperation;
use App\Models\ProviderAccount;
use Closure;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class AdKitBrokerWriteService
{
    public function __construct(private readonly AdKitHttpClient $adkit) {}

    /**
     * @param  array<string, mixed>  $body
     * @param  Closure(): ProviderAccount  $authorize
     * @param  Closure(array<string, mixed>): array<string, mixed>  $sanitize
     * @return array<string, mixed>
     */
    public function forward(Request $request, ProviderAccount $account, array $body, bool $publish, Closure $authorize, Closure $sanitize): array
    {
        $account = $authorize();
        $key = $request->header('Idempotency-Key');
        if (! is_string($key) || strlen($key) < 8 || strlen($key) > 200 || preg_match('/[\x00-\x1f\x7f]/', $key)) {
            throw ValidationException::withMessages(['Idempotency-Key' => 'An Idempotency-Key header of 8 to 200 characters is required.']);
        }
        $connection = $account->profile->providerConnection;
        $clientId = $request->attributes->get('agent_client')->public_id;
        $intentBody = $body;
        unset($intentBody['accountId']);
        $intent = $this->canonical([
            'body' => $intentBody, 'publish' => $publish, 'target' => $account->provider_id,
            'account' => $account->public_id, 'profile' => $account->profile->id,
            'connection' => $connection->id, 'subject' => $connection->subject,
            'project_hash' => hash('sha256', $connection->client_id),
        ]);
        $payloadHash = hash('sha256', json_encode($intent, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        [$operation, $claimed] = DB::transaction(function () use ($request, $account, $key, $intent, $payloadHash): array {
            $operation = AgentOperation::query()->createOrFirst([
                'client_id' => $request->attributes->get('agent_client')->id,
                'capability' => 'adkit.write', 'idempotency_key_hash' => hash('sha256', $key),
            ], [
                'principal_id' => $request->user()->id,
                'personal_access_token_id' => $request->attributes->get('agent_token')->id,
                'provider_account_id' => $account->id, 'payload_hash' => $payloadHash,
                'intent' => $intent, 'status' => 'accepted', 'requested_at' => now(),
            ]);
            $request->attributes->set('agent_operation_id', $operation->public_id);
            app('request')->attributes->set('agent_operation_id', $operation->public_id);
            $operation = AgentOperation::query()->whereKey($operation->id)->lockForUpdate()->firstOrFail();
            if (! hash_equals($operation->payload_hash, $payloadHash)) {
                throw new AdKitRequestException('adkit_idempotency_conflict');
            }
            $claimed = $operation->status === 'accepted';
            if ($claimed) {
                $operation->update(['status' => 'executing']);
            }

            return [$operation, $claimed];
        });

        if (! $claimed) {
            $authorize();
            if (in_array($operation->status, ['succeeded', 'draft'], true) && is_array($operation->outcome)) {
                return $sanitize($operation->outcome);
            }
            if (in_array($operation->status, ['executing', 'unknown'], true)) {
                $path = $this->receiptPath($operation, $clientId);
                try {
                    $disk = Storage::disk('local');
                    $exists = $disk->exists($path);
                    $journal = $exists
                        ? json_decode(Crypt::decryptString($disk->get($path)), true, 512, JSON_THROW_ON_ERROR) : null;
                    if ($exists) {
                        foreach (['operation_id' => $operation->public_id, 'client_id' => $clientId,
                            'account_id' => $account->public_id, 'payload_hash' => $payloadHash] as $field => $value) {
                            if (! is_array($journal) || ($journal[$field] ?? null) !== $value) {
                                throw new RuntimeException('Invalid receipt binding.');
                            }
                        }
                        if (! is_array($journal['receipt'] ?? null)) {
                            throw new RuntimeException('Invalid receipt.');
                        }
                        $this->verifyReceipt($journal['receipt'], $body, $publish, $account->provider_id);
                    }
                } catch (Throwable) {
                    throw new HttpResponseException(response()->json([
                        'error' => 'adkit_receipt_journal_invalid',
                        'message' => 'The receipt journal could not be verified. Do not redispatch this operation.',
                    ], 503));
                }
                if ($journal !== null) {
                    $authorize();

                    return $this->persistReceipt($operation, $sanitize($journal['receipt']), $publish, $account, $clientId, $authorize, true);
                }
            }
            throw new AdKitRequestException(match ($operation->status) {
                'unknown' => 'adkit_unconfirmed',
                'failed' => $operation->outcome['error'] ?? 'adkit_provider_error',
                default => 'adkit_operation_pending',
            });
        }

        $dispatched = false;
        try {
            $timeout = config('services.adkit.timeout');
            if (! is_int($timeout) || $timeout < 1 || $timeout > 10) {
                throw new AdKitRequestException('adkit_configuration_error');
            }
            $started = hrtime(true);
            $calls = 0;
            // Five bounded preflights plus one non-retried write fit the 120-second admission lease.
            $preflight = function (Closure $call) use ($authorize, &$calls, $started, $timeout): mixed {
                if (++$calls > 5 || (hrtime(true) - $started) / 1_000_000_000 > 100 - 2 * $timeout) {
                    throw new AdKitRequestException('adkit_unconfirmed');
                }
                $fresh = $authorize();
                $result = $call($fresh);
                $authorize();

                return $result;
            };
            $members = $preflight(fn (ProviderAccount $fresh): array => $this->adkit->connectedAccounts($fresh->profile->providerConnection));
            if (! in_array($account->provider_id, array_column($members, 'id'), true)) {
                throw new AdKitRequestException('adkit_invalid_response');
            }
            $this->verifyParents($account, $body, $preflight);
            $account = $authorize();
            if ((hrtime(true) - $started) / 1_000_000_000 >= 100) {
                throw new AdKitRequestException('adkit_unconfirmed');
            }
            $operation->update(['started_at' => now()]);
            $dispatched = true;
            $envelope = $this->adkit->forward($account->profile, $body, $publish);
            $this->verifyReceipt($envelope, $body, $publish, $account->provider_id);
            $safe = $sanitize($envelope);
        } catch (Throwable $exception) {
            $knownFailure = $exception instanceof AdKitRequestException
                && is_int($exception->upstreamStatus ?? null) && $exception->upstreamStatus >= 400 && $exception->upstreamStatus < 500
                && ! in_array($exception->upstreamStatus, [408, 425, 429], true);
            $unknown = $dispatched && ! $knownFailure;
            $operation->update([
                'status' => $unknown ? 'unknown' : 'failed',
                'outcome' => ['error' => $unknown ? 'adkit_unconfirmed'
                    : ($exception instanceof AdKitRequestException ? $exception->errorCode : 'adkit_provider_error')],
                'completed_at' => now(),
            ]);
            if ($unknown) {
                throw new AdKitRequestException('adkit_unconfirmed');
            }
            throw $exception;
        }

        return $this->persistReceipt($operation, $safe, $publish, $account, $clientId, $authorize);
    }

    private function receiptPath(AgentOperation $operation, string $clientId): string
    {
        return 'agent-operations/'.$clientId.'/adkit-receipts/'.$operation->public_id;
    }

    /**
     * @param  array<string, mixed>  $receipt
     * @param  Closure(): ProviderAccount  $authorize
     * @return array<string, mixed>
     */
    private function persistReceipt(AgentOperation $operation, array $receipt, bool $publish, ProviderAccount $account, string $clientId, Closure $authorize, bool $journaled = false): array
    {
        $stored = false;
        try {
            $stored = $operation->update(['status' => $publish ? 'succeeded' : 'draft', 'outcome' => $receipt, 'completed_at' => now()]);
        } catch (Throwable) {
            // A confirmed provider receipt must never enter the ambiguous-dispatch failure handler.
        }
        $path = $this->receiptPath($operation, $clientId);
        if ($stored) {
            if ($journaled) {
                try {
                    Storage::disk('local')->delete($path);
                } catch (Throwable) {
                    // The ledger is authoritative; a leftover encrypted journal is safe to retain.
                }
            }
            $authorize();

            return $receipt;
        }

        if (! $journaled) {
            try {
                $journaled = Storage::disk('local')->put($path, Crypt::encryptString(json_encode([
                    'operation_id' => $operation->public_id, 'client_id' => $clientId, 'account_id' => $account->public_id,
                    'payload_hash' => $operation->payload_hash, 'receipt' => $receipt,
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)), 'private');
            } catch (Throwable) {
                $journaled = false;
            }
        }
        $resourceIds = [];
        $imageHashes = [];
        foreach ($receipt['results'] ?? [] as $result) {
            $id = $result['raw']['id'] ?? null;
            if (is_string($id) && preg_match('/\A[0-9]+\z/', $id) === 1) {
                $resourceIds[] = $id;
            }
            $images = $result['raw']['images'] ?? [];
            foreach (is_array($images) ? $images : [] as $image) {
                if (is_string($image['hash'] ?? null) && preg_match('/\A[a-fA-F0-9]{32}\z/', $image['hash']) === 1) {
                    $imageHashes[] = $image['hash'];
                }
            }
        }
        if (! $journaled) {
            try {
                Log::critical('AdKit confirmed receipt could not be persisted.', [
                    'operation_id' => $operation->public_id, 'client_id' => $clientId, 'account_id' => $account->public_id,
                    'resource_ids' => $resourceIds, 'image_hashes' => $imageHashes,
                ]);
            } catch (Throwable) {
                // The safe error response retains recovery identifiers even if logging also fails.
            }
        }
        // Preserve the receipt before checking permission changes that happened during dispatch.
        $authorize();
        throw new HttpResponseException(response()->json([
            'error' => 'adkit_receipt_persistence_failed',
            'message' => 'AdKit confirmed the operation, but the receipt could not be saved to the ledger. Replay only with the same idempotency key.',
            'provider_status' => $receipt['status'], 'resource_ids' => $resourceIds, 'image_hashes' => $imageHashes,
        ], 503));
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  Closure(Closure): mixed  $preflight
     */
    private function verifyParents(ProviderAccount $account, array $body, Closure $preflight): void
    {
        $payload = $body['payload'];
        $target = $account->provider_id;
        $read = function (string $id, string $fields) use ($preflight): array {
            return $preflight(fn (ProviderAccount $fresh): array => $this->adkit->forward($fresh->profile, [
                'platform' => 'meta', 'accountId' => $fresh->profile->providerConnection->subject,
                'endpoint' => '/'.$id, 'method' => 'GET', 'description' => 'Verify write reference ownership',
                'payload' => ['fields' => $fields],
            ]));
        };
        $references = [];
        $creative = isset($payload['creative']) ? json_decode($payload['creative'], true, 16, JSON_THROW_ON_ERROR) : [];
        foreach (['campaign_id', 'adset_id', 'creative_id'] as $key) {
            $id = $payload[$key] ?? ($creative[$key] ?? null);
            if ($id !== null) {
                if ((! is_string($id) && ! is_int($id)) || preg_match('/\A[0-9]+\z/', (string) $id) !== 1) {
                    throw new AdKitRequestException('adkit_invalid_request');
                }
                $references[$id] = $key === 'adset_id' || str_contains($references[$id] ?? '', 'campaign_id')
                    ? 'id,account_id,campaign_id' : 'id,account_id';
            }
        }
        foreach ($references as $id => $fields) {
            $id = (string) $id;
            $metadata = $read($id, $fields);
            $row = $metadata['response'] ?? [];
            abort_unless(($row['id'] ?? null) === $id && in_array($row['account_id'] ?? null, [$target, substr($target, 4)], true), 404);
            $this->verifyIdentities($metadata, $target);
            if (str_contains($fields, 'campaign_id') && (! is_string($row['campaign_id'] ?? null) || preg_match('/\A[0-9]+\z/', $row['campaign_id']) !== 1)) {
                throw new AdKitRequestException('adkit_invalid_response');
            }
        }

        $story = isset($payload['object_story_spec']) ? json_decode($payload['object_story_spec'], true, 16, JSON_THROW_ON_ERROR) : [];
        $promoted = isset($payload['promoted_object']) ? json_decode($payload['promoted_object'], true, 16, JSON_THROW_ON_ERROR) : [];
        $pageIds = [];
        foreach ([$story, $promoted] as $spec) {
            if (isset($spec['page_id'])) {
                $id = $spec['page_id'];
                abort_unless((is_string($id) || is_int($id)) && preg_match('/\A[0-9]+\z/', (string) $id) === 1, 404);
                $pageIds[] = (string) $id;
            }
        }
        if ($pageIds !== []) {
            $pages = $preflight(fn (ProviderAccount $fresh): array => $this->adkit->pages($fresh->profile->providerConnection, $target));
            foreach (array_unique($pageIds) as $pageId) {
                abort_unless(in_array($pageId, array_column($pages, 'id'), true), 404);
            }
        }
        if ($story === []) {
            return;
        }
        $pageId = $story['page_id'] ?? null;
        abort_unless((is_string($pageId) || is_int($pageId)) && preg_match('/\A[0-9]+\z/', (string) $pageId) === 1, 404);
        $pageId = (string) $pageId;
        if (isset($story['instagram_user_id'])) {
            $metadata = $read($pageId, 'id,instagram_business_account');
            abort_unless(($metadata['response']['id'] ?? null) === $pageId
                && ($metadata['response']['instagram_business_account']['id'] ?? null) === (string) $story['instagram_user_id'], 404);
            $this->verifyIdentities($metadata, $target);
        }
        $hash = $story['link_data']['image_hash'] ?? $story['photo_data']['image_hash'] ?? null;
        if ($hash !== null) {
            $metadata = $preflight(fn (ProviderAccount $fresh): array => $this->adkit->forward($fresh->profile, [
                'platform' => 'meta', 'accountId' => $fresh->profile->providerConnection->subject,
                'endpoint' => '/'.$target.'/adimages', 'method' => 'GET', 'description' => 'Verify account image hash',
                'payload' => ['hashes' => json_encode([$hash], JSON_THROW_ON_ERROR), 'fields' => 'hash', 'limit' => 1],
            ]));
            $rows = $metadata['response']['data'] ?? null;
            abort_unless(is_array($rows) && array_is_list($rows) && count($rows) === 1 && ($rows[0]['hash'] ?? null) === $hash, 404);
            $this->verifyIdentities($metadata, $target);
        }
    }

    /** @param array<string, mixed> $data */
    private function verifyIdentities(array $data, string $target): void
    {
        foreach ($data as $key => $value) {
            if ($key === 'account_id' && ! in_array($value, [$target, substr($target, 4)], true)) {
                throw new AdKitRequestException('adkit_invalid_response');
            }
            if (is_array($value)) {
                $this->verifyIdentities($value, $target);
            }
        }
    }

    /** @param array<string, mixed> $envelope
     * @param  array<string, mixed>  $body
     */
    private function verifyReceipt(array $envelope, array $body, bool $publish, string $target): void
    {
        if (! $publish) {
            if (($envelope['platform'] ?? null) !== 'meta' || ! in_array($envelope['status'] ?? null, ['draft', 'pending'], true)
                || ! is_string($envelope['draftId'] ?? null) || trim($envelope['draftId']) === '') {
                throw new AdKitRequestException('adkit_draft_unsupported');
            }
            $this->verifyIdentities($envelope, $target);

            return;
        }
        if (($envelope['platform'] ?? null) !== 'meta' || ($envelope['status'] ?? null) !== 'published'
            || ! is_array($envelope['results'] ?? null) || ! array_is_list($envelope['results']) || $envelope['results'] === []) {
            throw new AdKitRequestException('adkit_invalid_response');
        }
        foreach ($envelope['results'] as $result) {
            if (! is_array($result) || array_is_list($result) || ! is_array($result['raw'] ?? null) || isset($result['raw']['error'])) {
                throw new AdKitRequestException('adkit_invalid_response');
            }
            if (str_ends_with($body['endpoint'], '/adimages')) {
                $images = $result['raw']['images'] ?? null;
                if (! is_array($images) || count($images) !== 1 || ! is_array($image = reset($images))
                    || ! is_string($image['hash'] ?? null) || preg_match('/\A[a-fA-F0-9]{32}\z/', $image['hash']) !== 1) {
                    throw new AdKitRequestException('adkit_invalid_response');
                }
            } elseif (! is_string($result['raw']['id'] ?? null) || preg_match('/\A[0-9]+\z/', $result['raw']['id']) !== 1
                || (isset($result['platformId']) && $result['platformId'] !== $result['raw']['id'])) {
                throw new AdKitRequestException('adkit_invalid_response');
            }
        }
        $this->verifyIdentities($envelope, $target);
    }

    /** @param array<mixed> $data
     * @return array<mixed>
     */
    private function canonical(array $data): array
    {
        if (! array_is_list($data)) {
            ksort($data);
        }
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->canonical($value);
            } elseif ($key === 'bytes' && is_string($value)) {
                $data[$key] = ['sha256' => hash('sha256', $value)];
            }
        }

        return $data;
    }
}
