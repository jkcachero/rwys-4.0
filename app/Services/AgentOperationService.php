<?php

namespace App\Services;

use App\Models\AgentAccessToken;
use App\Models\AgentOperation;
use App\Models\Client;
use App\Models\ClientAccount;
use App\Models\User;
use App\Zernio\Exceptions\ZernioApiException;
use App\Zernio\Services\ZernioApiClient;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class AgentOperationService
{
    private const TEXT_LIMITS = ['linkedin' => 3000, 'facebook' => 63206, 'threads' => 500, 'bluesky' => 300];

    public function __construct(private ZernioApiClient $provider) {}

    public function scheduleInstagramImage(AgentAccessToken $token, ClientAccount $account, string $content, UploadedFile $image, string $scheduledFor, string $idempotencyKey): AgentOperation
    {
        $capability = 'zernio.posts.schedule';
        $account = $this->authorize($token, $account, $capability);
        if (trim($content) === '' || ! mb_check_encoding($content, 'UTF-8') || mb_strlen($content, 'UTF-8') > 2200 || preg_match('/\p{Cc}/u', $content)) {
            throw ValidationException::withMessages(['content' => 'A valid caption of at most 2200 characters without controls is required.']);
        }
        if (! preg_match('/\A[\x21-\x7e]{1,255}\z/', $idempotencyKey)) {
            throw ValidationException::withMessages(['idempotency_key' => 'An ASCII idempotency key of at most 255 bytes is required.']);
        }
        $schedule = preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/', $scheduledFor)
            ? DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $scheduledFor, new DateTimeZone('UTC')) : false;
        if (! $schedule || $schedule->format('Y-m-d\TH:i:s\Z') !== $scheduledFor) {
            throw ValidationException::withMessages(['scheduled_for' => 'An exact UTC timestamp YYYY-MM-DDTHH:mm:ssZ is required.']);
        }
        $size = $image->isValid() ? filesize($image->getPathname()) : false;
        $dimensions = $size !== false && $size >= 4 && $size <= 2097152 ? @getimagesize($image->getPathname()) : false;
        if (! $dimensions || $dimensions[2] !== IMAGETYPE_JPEG || $image->getMimeType() !== 'image/jpeg'
            || file_get_contents($image->getPathname(), false, null, 0, 2) !== "\xff\xd8"
            || file_get_contents($image->getPathname(), false, null, $size - 2, 2) !== "\xff\xd9"
            || $dimensions[0] <= 0 || $dimensions[1] <= 0 || $dimensions[0] > 4096 || $dimensions[1] > 4096
            || $dimensions[0] * $dimensions[1] > 16000000 || $dimensions[0] / $dimensions[1] < 0.5625 || $dimensions[0] / $dimensions[1] > 1.91) {
            throw ValidationException::withMessages(['image' => 'A JPEG up to 2 MiB, 4096 pixels per side, 16 million pixels, and aspect ratio 0.5625 to 1.91 is required.']);
        }
        $intent = ['mapping' => $this->mapping($account), 'content' => $content, 'scheduled_for' => $scheduledFor,
            'image_sha256' => hash_file('sha256', $image->getPathname()), 'image_size' => $size, 'image_mime' => 'image/jpeg'];
        $hash = hash('sha256', json_encode($intent, JSON_THROW_ON_ERROR));
        $identity = ['client_id' => $account->client_id, 'capability' => $capability, 'key_hash' => hash('sha256', $idempotencyKey)];
        $operation = AgentOperation::query()->where($identity)->first();
        if ($operation) {
            abort_unless(hash_equals($operation->payload_hash, $hash), 409, 'Idempotency key conflicts with the immutable operation payload.');

            return $operation;
        }
        $delay = (float) $schedule->format('U.u') - (float) now()->format('U.u');
        if ($delay < 120 || $delay > 86400) {
            throw ValidationException::withMessages(['scheduled_for' => 'Schedule between two minutes and 24 hours in the future.']);
        }
        $operation = AgentOperation::query()->createOrFirst($identity, [
            'client_account_id' => $account->id, 'payload_hash' => $hash, 'mapping_hash' => $account->mappingHash(), 'intent' => $intent, 'state' => 'pending',
        ]);
        abort_unless(hash_equals($operation->payload_hash, $hash), 409, 'Idempotency key conflicts with the immutable operation payload.');
        if (! $operation->wasRecentlyCreated) {
            return $operation;
        }
        try {
            $this->preflight($account);
        } catch (Throwable) {
            return $this->transition($operation, ['state' => 'failed']);
        }
        $account = $this->authorize($token, $account, $capability);
        abort_unless($this->mapping($account) === $intent['mapping'], 409, 'Account mapping changed.');
        if (AgentOperation::query()->whereKey($operation->id)->where('state', 'pending')->update(['state' => 'executing']) !== 1) {
            return $operation->refresh();
        }
        $operation->refresh();
        $account = $this->authorize($token, $account, $capability);
        abort_unless($this->mapping($account) === $intent['mapping'], 409, 'Account mapping changed.');
        try {
            $mediaUrl = $this->provider->uploadInstagramImage($image, $operation->id.'.jpg');
        } catch (ZernioApiException $exception) {
            return $this->transition($operation, ['state' => $exception->ambiguous ? 'unknown' : 'failed']);
        } catch (Throwable) {
            return $this->transition($operation, ['state' => 'unknown']);
        }
        // Never create a post unless the upload's execution metadata is durably stored.
        $operation = $this->transition($operation, ['result' => ['media_url' => $mediaUrl]]);
        if ($operation->state !== 'executing' || ($operation->result['media_url'] ?? null) !== $mediaUrl) {
            return $operation;
        }
        $account = $this->authorize($token, $account, $capability);
        abort_unless($this->mapping($account) === $intent['mapping'], 409, 'Account mapping changed.');
        if ((float) $schedule->format('U.u') - (float) now()->format('U.u') < 60) {
            return $this->transition($operation, ['state' => 'failed']);
        }
        try {
            $post = $this->provider->scheduleInstagramImage($account->profile_id, $account->provider_account_id, $content, $mediaUrl, $scheduledFor, $operation->id);
        } catch (ZernioApiException $exception) {
            return $this->transition($operation, ['state' => $exception->ambiguous ? 'unknown' : 'failed', 'recovery_post_id' => $exception->recoveryPostId]);
        } catch (Throwable) {
            return $this->transition($operation, ['state' => 'unknown']);
        }

        return $this->recordPost($operation, $post);
    }

    public function publish(AgentAccessToken $token, ClientAccount $account, string $content, string $idempotencyKey): AgentOperation
    {
        $account = $this->authorize($token, $account);
        $limit = self::TEXT_LIMITS[$account->platform] ?? null;
        if ($limit === null || trim($content) === '' || ! mb_check_encoding($content, 'UTF-8') || mb_strlen($content) > $limit
            || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $content)) {
            throw ValidationException::withMessages(['content' => 'A supported platform and valid text within its character limit are required.']);
        }
        if ($idempotencyKey === '' || strlen($idempotencyKey) > 255) {
            throw ValidationException::withMessages(['idempotency_key' => 'An idempotency key of at most 255 bytes is required.']);
        }

        $intent = ['mapping' => $this->mapping($account), 'content' => $content];
        $hash = hash('sha256', json_encode($intent, JSON_THROW_ON_ERROR));
        $operation = AgentOperation::query()->createOrFirst([
            'client_id' => $account->client_id,
            'capability' => 'zernio.posts.publish',
            'key_hash' => hash('sha256', $idempotencyKey),
        ], [
            'client_account_id' => $account->id,
            'payload_hash' => $hash,
            'mapping_hash' => $account->mappingHash(),
            'intent' => $intent,
            'state' => 'pending',
        ]);
        abort_unless(hash_equals($operation->payload_hash, $hash), 409, 'Idempotency key conflicts with the immutable operation payload.');

        // A persisted operation is replay-only, including an abandoned pending operation.
        if (! $operation->wasRecentlyCreated) {
            return $operation;
        }

        try {
            $this->preflight($account);
        } catch (Throwable) {
            return $this->transition($operation, ['state' => 'failed']);
        }

        $account = $this->authorize($token, $account);
        abort_unless($this->mapping($account) === $intent['mapping'], 409, 'Account mapping changed.');
        if (AgentOperation::query()->whereKey($operation->id)->where('state', 'pending')->update(['state' => 'executing']) !== 1) {
            return $operation->refresh();
        }
        $operation->refresh();

        try {
            $post = $this->provider->publishText($account->profile_id, $account->provider_account_id, $account->platform, $content, $operation->id);
        } catch (ZernioApiException $exception) {
            return $this->transition($operation, [
                'state' => $exception->ambiguous ? 'unknown' : 'failed',
                'recovery_post_id' => $exception->recoveryPostId,
            ]);
        } catch (Throwable) {
            return $this->transition($operation, ['state' => 'unknown']);
        }

        return $this->recordPost($operation, $post);
    }

    public function reconcile(AgentOperation $operation): AgentOperation
    {
        $operation->refresh();
        if (! in_array($operation->state, AgentOperation::RECONCILABLE_STATES, true)) {
            return $operation;
        }

        $leaseToken = (string) Str::uuid();
        $attempted = AgentOperation::query()->whereKey($operation->id)
            ->whereIn('state', AgentOperation::RECONCILABLE_STATES)
            ->where(fn (Builder $query): Builder => $query->whereNull('reconcile_lease_until')->orWhere('reconcile_lease_until', '<=', now()->format($operation->getDateFormat())))
            ->update([
                'last_reconciled_at' => now()->format($operation->getDateFormat()),
                'reconcile_lease_token' => $leaseToken,
                'reconcile_lease_until' => now()->addSeconds(90)->format($operation->getDateFormat()),
            ]);
        $operation->refresh();
        if ($attempted !== 1) {
            return $operation;
        }
        try {
            $account = ClientAccount::find($operation->client_account_id);
            $client = Client::find($operation->client_id);
            $scheduling = $operation->capability === 'zernio.posts.schedule';
            if ((! $scheduling && (! config('agent-api.zernio.enabled', false) || ! $account?->publishing_enabled)) || ! $account || ! $client?->active || ! $account->active
                || ! $account->hasOwnedProfile()
                || $account->client_id !== $operation->client_id || $account->mappingHash() !== $operation->mapping_hash
                || $this->mapping($account) !== ($operation->intent['mapping'] ?? null)) {
                return $operation;
            }
            $candidate = $operation->provider_post_id ?? $operation->recovery_post_id;
            if (! is_string($candidate) || $candidate === '' || ! $this->ownsLease($operation, $leaseToken)) {
                return $operation;
            }

            try {
                $post = $scheduling ? $this->provider->post($candidate, true) : $this->provider->post($candidate);
            } catch (Throwable) {
                return $operation->refresh();
            }
            // The single-post endpoint may omit profile membership; prove it with a scoped read.
            if (($post['profile_id'] ?? null) === null) {
                $page = max(1, min(1000, $operation->reconcile_page));
                for ($read = 0; $read < 3; $read++) {
                    if (! $this->ownsLease($operation, $leaseToken)) {
                        return $operation->refresh();
                    }
                    try {
                        $response = $scheduling ? $this->provider->posts($account->profile_id, $account->provider_account_id, $page, 25, true)
                            : $this->provider->posts($account->profile_id, $account->provider_account_id, $page, 25);
                    } catch (Throwable) {
                        return $operation->refresh();
                    }
                    foreach ($response['posts'] as $scopedPost) {
                        if (($scopedPost['id'] ?? null) === $candidate) {
                            return $this->recordPost($operation, $scopedPost, $candidate, $leaseToken);
                        }
                    }
                    $atEnd = ($response['pagination']['pages'] ?? 0) <= $page || $page >= 1000;
                    $page = $atEnd ? 1 : $page + 1;
                    $operation = $this->transition($operation, ['reconcile_page' => $page], $leaseToken);
                    if ($atEnd) {
                        break;
                    }
                }

                return $operation->refresh();
            }

            return $this->recordPost($operation, $post, $candidate, $leaseToken);
        } finally {
            AgentOperation::query()->whereKey($operation->id)->where('reconcile_lease_token', $leaseToken)
                ->update(['reconcile_lease_token' => null, 'reconcile_lease_until' => null]);
            $operation->refresh();
        }
    }

    private function ownsLease(AgentOperation $operation, string $leaseToken): bool
    {
        return AgentOperation::query()->whereKey($operation->id)->where('reconcile_lease_token', $leaseToken)
            ->where('reconcile_lease_until', '>', now()->format($operation->getDateFormat()))
            ->whereIn('state', AgentOperation::RECONCILABLE_STATES)->exists();
    }

    private function authorize(AgentAccessToken $token, ClientAccount $account, string $capability = 'zernio.posts.publish'): ClientAccount
    {
        $fresh = AgentAccessToken::find($token->getKey());
        $account = ClientAccount::find($account->getKey());
        $client = $fresh ? Client::find($fresh->client_id) : null;
        $owner = $fresh?->tokenable;
        $abilities = $fresh?->abilities ?? [];
        $allowed = $fresh?->allowed_account_ids;
        abort_unless($fresh && $account && $client && $owner instanceof User
            && $owner->getKey() === $client->owner_id && $owner->hasVerifiedEmail()
            && $client->active && $account->client_id === $client->id && $account->active && $account->publishing_enabled && $account->hasOwnedProfile()
            && $fresh->expires_at && $fresh->expires_at->isFuture()
            && $fresh->created_at && $fresh->created_at->lte(now())
            && $fresh->expires_at->lte(now()->addDays(30))
            && $fresh->expires_at->lte($fresh->created_at->copy()->addDays(30))
            && (! config('sanctum.expiration') || $fresh->created_at->gt(now()->subMinutes(config('sanctum.expiration'))))
            && is_array($abilities) && array_is_list($abilities)
            && in_array($capability, $abilities, true)
            && ! collect($abilities)->contains(fn (mixed $ability): bool => ! in_array($ability, AgentTokenService::ABILITIES, true))
            && ($allowed === null || (is_array($allowed) && in_array($account->id, $allowed, true))), 403, 'Publishing is not authorized.');
        abort_unless(config('agent-api.zernio.enabled', false) && config('agent-api.zernio.publishing_enabled', false)
            && $account->provider === 'zernio' && $account->environment === 'production', 403, 'Publishing is disabled.');
        abort_unless(in_array($capability, ['zernio.posts.publish', 'zernio.posts.schedule'], true)
            && ($capability !== 'zernio.posts.schedule' || (config('agent-api.zernio.instagram_scheduling_enabled', false) === true && $account->platform === 'instagram')), 403, 'Scheduling is disabled.');

        return $account;
    }

    /** @return array{local_id: int, client_id: int, provider: string, environment: string, profile_id: string, account_id: string, platform: string} */
    private function mapping(ClientAccount $account): array
    {
        return [
            'local_id' => $account->id,
            'client_id' => $account->client_id,
            'provider' => $account->provider,
            'environment' => $account->environment,
            'profile_id' => $account->profile_id,
            'account_id' => $account->provider_account_id,
            'platform' => $account->platform,
        ];
    }

    private function preflight(ClientAccount $account): void
    {
        for ($page = 1; $page <= 3; $page++) {
            $response = $this->provider->accounts($account->profile_id, $page, 25, $account->provider_account_id);
            foreach ($response['accounts'] ?? [] as $candidate) {
                if (($candidate['id'] ?? null) === $account->provider_account_id
                    && ($candidate['profile_id'] ?? null) === $account->profile_id
                    && ($candidate['platform'] ?? null) === $account->platform
                    && ($candidate['is_active'] ?? null) === true) {
                    return;
                }
            }
            if (($response['pagination']['pages'] ?? 0) <= $page) {
                break;
            }
        }
        abort(422, 'The exact connected account could not be verified.');
    }

    /** @param array<string, mixed> $post */
    private function recordPost(AgentOperation $operation, array $post, ?string $expectedId = null, ?string $leaseToken = null): AgentOperation
    {
        $mapping = $operation->intent['mapping'];
        $scheduling = $operation->capability === 'zernio.posts.schedule';
        $account = ClientAccount::find($operation->client_account_id);
        if (! $account || ! $account->active || (! $scheduling && ! $account->publishing_enabled) || ! $account->hasOwnedProfile()
            || ! Client::whereKey($operation->client_id)->where('active', true)->exists()
            || $account->mappingHash() !== $operation->mapping_hash || $this->mapping($account) !== $mapping) {
            return $operation->refresh();
        }
        $targets = $post['platforms'] ?? null;
        $id = $post['id'] ?? null;
        $matchesSchedule = true;
        if ($scheduling) {
            $mediaUrl = $operation->result['media_url'] ?? null;
            $mediaItems = $post['media_items'] ?? null;
            $observedSchedule = $post['scheduled_for'] ?? null;
            try {
                $matchesSchedule = is_string($observedSchedule)
                    && (new DateTimeImmutable($observedSchedule))->format('U.u') === (new DateTimeImmutable($operation->intent['scheduled_for']))->format('U.u');
            } catch (Throwable) {
                $matchesSchedule = false;
            }
            $matchesSchedule = $matchesSchedule && is_string($mediaUrl) && $mediaUrl !== ''
                && is_array($mediaItems) && array_is_list($mediaItems) && count($mediaItems) === 1
                && ($mediaItems[0]['type'] ?? null) === 'image' && is_string($mediaItems[0]['url'] ?? null)
                && ZernioApiClient::isSameInstagramMediaUrl($mediaUrl, $mediaItems[0]['url'])
                && in_array($post['timezone'] ?? null, [null, 'UTC'], true);
        }
        if (! $matchesSchedule || ! is_string($id) || $id === '' || ($expectedId !== null && $id !== $expectedId)
            || ($post['profile_id'] ?? null) !== $mapping['profile_id'] || ! is_array($targets) || count($targets) !== 1
            || ! array_is_list($targets)
            || ($targets[0]['account_id'] ?? null) !== $mapping['account_id'] || ($targets[0]['platform'] ?? null) !== $mapping['platform']
            || ($post['content'] ?? null) !== $operation->intent['content']) {
            return $this->transition($operation, ['state' => 'unknown', 'recovery_post_id' => is_string($id) && $id !== '' ? $id : $operation->recovery_post_id], $leaseToken);
        }

        $status = $targets[0]['status'] ?? null;
        $aggregate = $post['status'] ?? null;
        $state = match (true) {
            $aggregate === 'published' && $status === 'published' => 'succeeded',
            $aggregate === 'failed' && $status === 'failed' => 'failed',
            $aggregate === 'cancelled' && $status === 'cancelled' => 'cancelled',
            $aggregate === 'partial' && in_array($status, ['published', 'failed', 'pending', 'processing', 'uploading'], true) => 'partial',
            in_array($aggregate, ['scheduled', 'publishing'], true) && in_array($status, ['pending', 'processing', 'uploading'], true) => 'provider_pending',
            default => 'unknown',
        };
        if ($scheduling && (($state === 'succeeded' && ! $this->isInstagramPostUrl($targets[0]['url'] ?? null))
            || ($expectedId === null && ! ($aggregate === 'scheduled' && $status === 'pending')))) {
            $state = 'unknown';
        }
        if ($state === 'unknown') {
            return $this->transition($operation, ['state' => 'unknown', 'recovery_post_id' => $id], $leaseToken);
        }

        return $this->transition($operation, [
            'state' => $state,
            'provider_post_id' => $id,
            'recovery_post_id' => null,
            'result' => ($scheduling ? ['media_url' => $operation->result['media_url']] : []) + ['id' => $id, 'status' => $post['status'] ?? null, 'platforms' => $targets],
        ], $leaseToken);
    }

    private function isInstagramPostUrl(mixed $url): bool
    {
        return is_string($url) && filter_var($url, FILTER_VALIDATE_URL) !== false
            && parse_url($url, PHP_URL_SCHEME) === 'https'
            && in_array(parse_url($url, PHP_URL_HOST), ['instagram.com', 'www.instagram.com'], true)
            && parse_url($url, PHP_URL_USER) === null && parse_url($url, PHP_URL_PASS) === null
            && parse_url($url, PHP_URL_PORT) === null
            && preg_match('~\A/(?:p|reel)/[A-Za-z0-9_-]+/?\z~', parse_url($url, PHP_URL_PATH) ?? '') === 1;
    }

    /** @param array<string, mixed> $attributes */
    private function transition(AgentOperation $operation, array $attributes, ?string $leaseToken = null): AgentOperation
    {
        if (array_key_exists('recovery_post_id', $attributes)) {
            $attributes['recovery_post_hash'] = $attributes['recovery_post_id'] === null
                ? null : hash('sha256', 'zernio-post:'.$attributes['recovery_post_id']);
        }
        $update = new AgentOperation;
        $update->forceFill($attributes);
        AgentOperation::query()->whereKey($operation->id)->where('state', $operation->state)
            ->when($leaseToken !== null, fn (Builder $query): Builder => $query->where('reconcile_lease_token', $leaseToken)
                ->where('reconcile_lease_until', '>', now()->format($operation->getDateFormat())))
            ->whereNotIn('state', AgentOperation::TERMINAL_STATES)->update($update->getAttributes());

        return $operation->refresh();
    }
}
