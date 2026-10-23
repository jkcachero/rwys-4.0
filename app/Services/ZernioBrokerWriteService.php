<?php

namespace App\Services;

use App\Enums\AgentAbility;
use App\Exceptions\Zernio\ZernioRequestException;
use App\Models\AgentOperation;
use App\Models\Client;
use App\Models\PersonalAccessToken;
use App\Models\ProviderAccount;
use App\Models\ProviderPost;
use App\Models\ProviderProfile;
use App\Zernio\ZernioHttpClient;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

class ZernioBrokerWriteService
{
    public function __construct(private readonly ZernioHttpClient $zernio) {}

    public function publish(
        Request $request,
        ProviderAccount $account,
        string $content,
        string $idempotencyKey,
    ): AgentOperation {
        if (! config('services.zernio.publish_enabled')) {
            throw new ZernioRequestException('Zernio publishing is disabled.', 503, 'publishing_disabled');
        }

        [$client, $token, $account, $profile] = $this->authorizedContext($request, $account, 'can_publish');

        if (! in_array($account->platform, ['linkedin', 'facebook', 'threads', 'bluesky'], true)) {
            throw new ZernioRequestException('This account does not support immediate text publishing.', 422, 'unsupported_platform');
        }

        $intent = [
            'account_id' => $account->public_id,
            'content' => $content,
            'platform' => $account->platform,
        ];
        $operation = $this->existingOperation($client, AgentAbility::PostsPublish, $idempotencyKey, $intent);

        if ($operation !== null) {
            return $operation;
        }

        $operation = $this->createOperation($request, $client, $token, $account, AgentAbility::PostsPublish, $idempotencyKey, $intent);

        if (! $operation->wasRecentlyCreated) {
            return $operation;
        }

        try {
            $this->verifyProfileAndAccount($profile, $account);
            $this->authorizedContext($request, $account, 'can_publish');
            $operation->update(['status' => 'executing', 'started_at' => now()]);
            $response = $this->zernio->createPost($profile, [
                'profileId' => $profile->provider_id,
                'accountIds' => [$account->provider_id],
                'platforms' => [$account->platform],
                'content' => $content,
                'isDraft' => false,
                'publishNow' => true,
            ], hash('sha256', $idempotencyKey));
            $post = $this->postPayload($response->data);
            $this->verifyCreatedPost($post, $account);
            $this->authorizedContext($request, $account, 'can_publish');
            $providerPost = $this->persistPost($client, $account, $post);
            $operation->update([
                'status' => 'succeeded',
                'outcome' => [
                    'provider_post_id' => $providerPost->public_id,
                    'provider_status' => (string) ($post['status'] ?? 'published'),
                ],
                'completed_at' => now(),
            ]);
        } catch (\Throwable $exception) {
            $operation->update([
                'status' => $operation->started_at === null ? 'failed' : 'unknown',
                'outcome' => ['error' => 'provider_write_not_confirmed'],
                'completed_at' => now(),
            ]);

            throw $exception;
        }

        return $operation->refresh();
    }

    public function schedule(
        Request $request,
        ProviderAccount $account,
        string $content,
        string $scheduledFor,
        UploadedFile $image,
        string $idempotencyKey,
    ): AgentOperation {
        if (! config('services.zernio.schedule_enabled')) {
            throw new ZernioRequestException('Zernio scheduling is disabled.', 503, 'scheduling_disabled');
        }

        [$client, $token, $account, $profile] = $this->authorizedContext($request, $account, 'can_schedule');

        if ($account->platform !== 'instagram') {
            throw new ZernioRequestException('This account does not support scheduled image posts.', 422, 'unsupported_platform');
        }

        $contents = file_get_contents($image->getRealPath());

        if (! is_string($contents)) {
            throw new ZernioRequestException('The scheduled image could not be read.', 422, 'invalid_image');
        }

        $imageHash = hash('sha256', $contents);
        $payload = [
            'account_id' => $account->public_id,
            'content' => $content,
            'scheduled_for' => $scheduledFor,
            'image_hash' => $imageHash,
        ];
        $operation = $this->existingOperation($client, AgentAbility::PostsSchedule, $idempotencyKey, $payload);

        if ($operation !== null) {
            return $operation;
        }

        $schedule = Carbon::createFromFormat('Y-m-d\\TH:i:s\\Z', $scheduledFor, 'UTC');

        if ($schedule->lt(now()->addHours(2)) || $schedule->gt(now()->addHours(24))) {
            throw new ZernioRequestException('The scheduled time must be between 2 and 24 hours from now.', 422, 'invalid_schedule');
        }

        $mediaPath = Storage::disk('local')->putFileAs(
            'agent-operations/'.$client->public_id,
            $image,
            $imageHash.'.jpg',
        );

        if (! is_string($mediaPath)) {
            throw new ZernioRequestException('The scheduled image could not be stored.', 503, 'storage_unavailable');
        }

        $intent = [...$payload, 'media_path' => $mediaPath];
        $operation = $this->createOperation(
            $request,
            $client,
            $token,
            $account,
            AgentAbility::PostsSchedule,
            $idempotencyKey,
            $intent,
            $payload,
        );

        if (! $operation->wasRecentlyCreated) {
            return $operation;
        }

        try {
            $this->verifyProfileAndAccount($profile, $account);
            $this->authorizedContext($request, $account, 'can_schedule');
            $operation->update(['status' => 'executing', 'started_at' => now()]);
            $upload = $this->zernio->uploadDirect($profile, $contents, $imageHash.'.jpg');
            $mediaUrl = Arr::get($upload->data, 'url');

            if (! is_string($mediaUrl) || ! str_starts_with($mediaUrl, 'https://')) {
                throw new ZernioRequestException('Zernio returned an invalid media reference.');
            }

            $response = $this->zernio->createPost($profile, [
                'profileId' => $profile->provider_id,
                'accountIds' => [$account->provider_id],
                'platforms' => ['instagram'],
                'content' => $content,
                'mediaItems' => [['url' => $mediaUrl]],
                'scheduledFor' => $scheduledFor,
                'timezone' => 'UTC',
                'isDraft' => false,
                'publishNow' => false,
            ], hash('sha256', $idempotencyKey));
            $post = $this->postPayload($response->data);
            $this->verifyCreatedPost($post, $account);
            $this->authorizedContext($request, $account, 'can_schedule');
            $providerPost = $this->persistPost($client, $account, $post);
            $operation->update([
                'status' => 'provider-pending',
                'outcome' => [
                    'provider_post_id' => $providerPost->public_id,
                    'provider_status' => (string) ($post['status'] ?? 'scheduled'),
                ],
            ]);
        } catch (\Throwable $exception) {
            $operation->update([
                'status' => $operation->started_at === null ? 'failed' : 'unknown',
                'outcome' => ['error' => 'provider_write_not_confirmed'],
                'completed_at' => now(),
            ]);

            throw $exception;
        }

        return $operation->refresh();
    }

    /** @return array{Client, PersonalAccessToken, ProviderAccount, ProviderProfile} */
    private function authorizedContext(Request $request, ProviderAccount $account, string $permission): array
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
                ->where('is_active', true)
                ->where('can_read', true)
                ->where($permission, true))
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
        $profilePayload = Arr::get($this->zernio->profile($profile)->data, 'profile');

        if (! is_array($profilePayload) || Arr::get($profilePayload, '_id') !== $profile->provider_id) {
            throw new ZernioRequestException('Zernio profile identity could not be verified.');
        }

        $accountPayload = Arr::get($this->zernio->account($profile, $account->provider_id)->data, 'account');
        $profileId = Arr::get($accountPayload, 'profileId._id', Arr::get($accountPayload, 'profileId'));

        if (! is_array($accountPayload)
            || Arr::get($accountPayload, '_id') !== $account->provider_id
            || $profileId !== $profile->provider_id
            || Arr::get($accountPayload, 'isActive') !== true) {
            throw new ZernioRequestException('Zernio account identity could not be verified.');
        }
    }

    /** @param array<string, mixed> $data */
    private function postPayload(array $data): array
    {
        $post = Arr::get($data, 'post');

        if (! is_array($post)) {
            throw new ZernioRequestException('Zernio post response was invalid.');
        }

        return $post;
    }

    /** @param array<string, mixed> $post */
    private function verifyCreatedPost(array $post, ProviderAccount $account): void
    {
        $providerPostId = Arr::get($post, '_id');
        $accountIds = Arr::get($post, 'accountIds', []);

        if (! is_string($providerPostId)
            || preg_match('/^[a-f0-9]{24}$/', $providerPostId) !== 1
            || ! is_array($accountIds)
            || ! in_array($account->provider_id, $accountIds, true)) {
            throw new ZernioRequestException('Zernio post identity could not be verified.');
        }
    }

    /** @param array<string, mixed> $post */
    private function persistPost(Client $client, ProviderAccount $account, array $post): ProviderPost
    {
        $providerPostId = (string) $post['_id'];

        return ProviderPost::query()->updateOrCreate(
            [
                'client_id' => $client->getKey(),
                'provider_account_id' => $account->getKey(),
                'provider_id_hash' => hash('sha256', $providerPostId),
            ],
            [
                'provider_id' => $providerPostId,
                'status' => (string) ($post['status'] ?? 'unknown'),
                'scheduled_at' => isset($post['scheduledFor']) ? Carbon::parse($post['scheduledFor']) : null,
                'published_at' => isset($post['publishedAt']) ? Carbon::parse($post['publishedAt']) : null,
            ],
        );
    }

    /** @param array<string, mixed> $intent */
    private function existingOperation(
        Client $client,
        AgentAbility $ability,
        string $idempotencyKey,
        array $intent,
    ): ?AgentOperation {
        $operation = AgentOperation::query()
            ->whereBelongsTo($client)
            ->where('capability', $ability->value)
            ->where('idempotency_key_hash', hash('sha256', $idempotencyKey))
            ->first();

        if ($operation === null) {
            return null;
        }

        if (! hash_equals((string) $operation->payload_hash, $this->payloadHash($intent))) {
            throw new ZernioRequestException('The idempotency key was already used for a different request.', 409, 'idempotency_conflict');
        }

        return $operation;
    }

    /**
     * @param  array<string, mixed>  $intent
     * @param  array<string, mixed>|null  $hashPayload
     */
    private function createOperation(
        Request $request,
        Client $client,
        PersonalAccessToken $token,
        ProviderAccount $account,
        AgentAbility $ability,
        string $idempotencyKey,
        array $intent,
        ?array $hashPayload = null,
    ): AgentOperation {
        $payloadHash = $this->payloadHash($hashPayload ?? $intent);
        $operation = AgentOperation::query()->createOrFirst(
            [
                'client_id' => $client->getKey(),
                'capability' => $ability->value,
                'idempotency_key_hash' => hash('sha256', $idempotencyKey),
            ],
            [
                'principal_id' => $request->user()?->getAuthIdentifier(),
                'personal_access_token_id' => $token->getKey(),
                'provider_account_id' => $account->getKey(),
                'payload_hash' => $payloadHash,
                'intent' => $intent,
                'status' => 'pending',
                'requested_at' => now(),
            ],
        );

        if (! hash_equals((string) $operation->payload_hash, $payloadHash)) {
            throw new ZernioRequestException('The idempotency key was already used for a different request.', 409, 'idempotency_conflict');
        }

        return $operation;
    }

    /** @param array<string, mixed> $intent */
    private function payloadHash(array $intent): string
    {
        ksort($intent);

        return hash('sha256', json_encode($intent, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }
}
