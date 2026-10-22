<?php

namespace App\Zernio\Services;

use App\Zernio\Exceptions\ZernioApiException;
use DateTimeImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use JsonException;
use Psr\Http\Message\ResponseInterface;
use stdClass;
use Throwable;

/**
 * @phpstan-type Pagination array{page: int, limit: int, total: int, pages: int}
 * @phpstan-type Profile array{id: string, name: string, account_count: ?int}
 * @phpstan-type Account array{id: string, profile_id: string, platform: string, username: ?string, display_name: ?string, is_active: bool}
 * @phpstan-type Target array{account_id: string, platform: string, status: string, url: ?string}
 * @phpstan-type Post array{id: string, profile_id: ?string, content: ?string, status: string, scheduled_for: ?string, created_at: ?string, updated_at: ?string, platforms: list<Target>, media_items?: list<array{type: string, url: string}>, timezone?: ?string}
 * @phpstan-type AnalyticsTarget array{account_id: string, platform: string, status: string, sync_status: string, analytics: ?stdClass, error_code: ?string, is_owner: ?bool}
 * @phpstan-type Analytics array{post_id: string, zernio_post_id: ?string, status: string, sync_status: string, analytics: ?stdClass, platform_analytics: list<AnalyticsTarget>, upstream_status: int, source: 'zernio', profile_id: string}
 */
final class ZernioApiClient
{
    private const BASE_URL = 'https://zernio.com/api/v1';

    private const MAX_BYTES = 1048576;

    private const MAX_IMAGE_BYTES = 2097152;

    private const UUID_PATTERN = '[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}';

    public const TEXT_LIMITS = ['linkedin' => 3000, 'facebook' => 63206, 'threads' => 500, 'bluesky' => 300];

    /** @return array{profiles: list<Profile>, pagination: Pagination} */
    public function profiles(int $page = 1, int $limit = 25, ?string $name = null): array
    {
        $this->inputPagination($page, $limit);
        if ($name !== null) {
            if (! mb_check_encoding($name, 'UTF-8') || preg_match('/\p{Cc}/u', $name)) {
                throw new ZernioApiException('invalid_input');
            }
            $name = trim($name);
            if ($name === '' || mb_strlen($name, 'UTF-8') > 100) {
                throw new ZernioApiException('invalid_input');
            }
        }
        $skip = ($page - 1) * $limit;
        $data = $this->request('GET', '/profiles', array_filter(compact('limit', 'skip', 'name'), static fn (mixed $value): bool => $value !== null));
        $profiles = $this->list($data['profiles'] ?? null);
        foreach (['total', 'skip', 'limit'] as $field) {
            if (! is_int($data[$field] ?? null) || $data[$field] < 0) {
                throw new ZernioApiException('malformed_response');
            }
        }
        if ($data['skip'] !== $skip || $data['limit'] !== $limit || count($profiles) > $limit) {
            throw new ZernioApiException('malformed_response');
        }
        $normalized = [];
        foreach ($profiles as $profile) {
            $normalized[] = $this->normalizeProfile($profile);
        }

        return ['profiles' => $normalized, 'pagination' => [
            'page' => $page, 'limit' => $limit, 'total' => $data['total'],
            'pages' => intdiv($data['total'], $limit) + ($data['total'] % $limit === 0 ? 0 : 1),
        ]];
    }

    /** @return Profile */
    public function profile(string $id): array
    {
        $this->inputId($id);
        $data = $this->request('GET', '/profiles/'.$id);
        $profile = $this->normalizeProfile($data['profile'] ?? null);
        if ($profile['id'] !== $id) {
            throw new ZernioApiException('malformed_response');
        }

        return $profile;
    }

    /**
     * account_count is provider metadata, not evidence of a complete account import.
     *
     * @return Profile
     */
    private function normalizeProfile(mixed $profile): array
    {
        if (! $profile instanceof stdClass || ! is_string($profile->_id ?? null)
            || ! is_string($profile->name ?? null) || trim($profile->name) === ''
            || strlen($profile->name) > 1024 || ! mb_check_encoding($profile->name, 'UTF-8')
            || preg_match('/\p{Cc}/u', $profile->name)
            || (isset($profile->accountCount) && (! is_int($profile->accountCount) || $profile->accountCount < 0))) {
            throw new ZernioApiException('malformed_response');
        }

        return ['id' => $this->responseId($profile->_id), 'name' => $profile->name, 'account_count' => $profile->accountCount ?? null];
    }

    /** @return array{accounts: list<Account>, pagination: Pagination} */
    public function accounts(string $profileId, int $page = 1, int $limit = 25, ?string $search = null): array
    {
        $this->inputId($profileId);

        return $this->accountPage($profileId, $page, $limit, $search);
    }

    /**
     * Cross-profile discovery is restricted to authorized operators by the calling service.
     *
     * @return array{accounts: list<Account>, pagination: Pagination}
     */
    public function discoverAccounts(int $page = 1, int $limit = 25, ?string $search = null): array
    {
        return $this->accountPage(null, $page, $limit, $search);
    }

    /** @return array{accounts: list<Account>, pagination: Pagination} */
    private function accountPage(?string $profileId, int $page, int $limit, ?string $search): array
    {
        $this->inputPagination($page, $limit);
        if ($search !== null && ($search === '' || ! mb_check_encoding($search, 'UTF-8') || mb_strlen($search) > 100 || preg_match('/[\x00-\x1f\x7f]/', $search))) {
            throw new ZernioApiException('invalid_input');
        }
        $data = $this->request('GET', '/accounts', array_filter(compact('profileId', 'page', 'limit', 'search'), static fn (mixed $value): bool => $value !== null));
        $accounts = $this->list($data['accounts'] ?? null);
        if (! is_bool($data['hasAnalyticsAccess'] ?? null) || count($accounts) > $limit) {
            throw new ZernioApiException('malformed_response');
        }
        $normalized = [];
        foreach ($accounts as $account) {
            if (! $account instanceof stdClass || ! is_string($account->_id ?? null) || ! is_bool($account->isActive ?? null)) {
                throw new ZernioApiException('malformed_response');
            }
            $account = (array) $account;
            $accountProfileId = $this->responseId($account['profileId'] ?? null);
            if ($profileId !== null && $accountProfileId !== $profileId) {
                throw new ZernioApiException('scope_mismatch');
            }
            $normalized[] = [
                'id' => $this->responseId($account['_id'] ?? null),
                'profile_id' => $accountProfileId,
                'platform' => $this->platform($account['platform'] ?? null),
                'username' => $this->text($account['username'] ?? null, 1024),
                'display_name' => $this->text($account['displayName'] ?? null, 1024),
                'is_active' => $account['isActive'],
            ];
        }

        return ['accounts' => $normalized, 'pagination' => $this->pagination($data['pagination'] ?? null, $page, $limit)];
    }

    /** @return array{posts: list<Post>, pagination: Pagination} */
    public function posts(string $profileId, string $accountId, int $page = 1, int $limit = 25, bool $includeMedia = false): array
    {
        $this->inputId($profileId);
        $this->inputId($accountId);
        $this->inputPagination($page, $limit);
        $data = $this->request('GET', '/posts', compact('profileId', 'accountId', 'page', 'limit'));
        $posts = $this->list($data['posts'] ?? null);
        if (count($posts) > $limit) {
            throw new ZernioApiException('malformed_response');
        }
        $normalized = [];
        foreach ($posts as $post) {
            $post = $this->normalizePost($post, $profileId, $includeMedia);
            if (! in_array($accountId, array_column($post['platforms'], 'account_id'), true)) {
                throw new ZernioApiException('scope_mismatch');
            }
            $normalized[] = $post;
        }

        return ['posts' => $normalized, 'pagination' => $this->pagination($data['pagination'] ?? null, $page, $limit)];
    }

    /** @return Post */
    public function post(string $postId, bool $includeMedia = false): array
    {
        $this->inputId($postId);
        $data = $this->request('GET', '/posts/'.$postId);
        $post = $this->normalizePost($data['post'] ?? null, includeMedia: $includeMedia);
        if ($post['id'] !== $postId) {
            throw new ZernioApiException('malformed_response');
        }

        return $post;
    }

    /**
     * The caller must authorize every returned account before exposing aggregate metrics.
     * profile_id is the internal request scope, not proof of provider-side authorization.
     *
     * @return Analytics
     */
    public function postAnalytics(string $profileId, string $accountId, string $postId): array
    {
        $this->inputId($profileId);
        $this->inputId($accountId);
        $this->inputId($postId);
        $data = $this->request('GET', '/analytics', compact('profileId', 'accountId', 'postId'), allowedStatuses: [200, 202, 424]);
        if (! is_string($data['postId'] ?? null)
            || (isset($data['latePostId']) && ! is_string($data['latePostId']))
            || (array_key_exists('profileId', $data) && ! is_string($data['profileId']))
            || ! in_array($data['status'] ?? null, ['published', 'failed', 'partial', 'scheduled', 'publishing', 'draft', 'cancelled'], true)
            || ! in_array($data['syncStatus'] ?? null, ['synced', 'pending', 'partial', 'unavailable'], true)) {
            throw new ZernioApiException('malformed_response');
        }
        $observedPostId = $this->responseId($data['postId']);
        $zernioPostId = isset($data['latePostId']) ? $this->responseId($data['latePostId']) : null;
        if (($observedPostId !== $postId && $zernioPostId !== $postId)
            || (array_key_exists('profileId', $data) && $this->responseId($data['profileId']) !== $profileId)) {
            throw new ZernioApiException('scope_mismatch');
        }
        $targets = $this->list($data['platformAnalytics'] ?? null);
        if (count($targets) > 100) {
            throw new ZernioApiException('malformed_response');
        }
        $normalized = [];
        foreach ($targets as $target) {
            if (! $target instanceof stdClass || ! is_string($target->accountId ?? null)
                || ! in_array($target->status ?? null, ['published', 'failed'], true)
                || ! in_array($target->syncStatus ?? null, ['synced', 'pending', 'unavailable'], true)
                || ! in_array($target->errorCode ?? null, [null, 'post_not_found', 'not_post_owner', 'permission_missing'], true)
                || (isset($target->isOwner) && ! is_bool($target->isOwner))) {
                throw new ZernioApiException('malformed_response');
            }
            $normalized[] = [
                'account_id' => $this->responseId($target->accountId), 'platform' => $this->platform($target->platform ?? null),
                'status' => $target->status, 'sync_status' => $target->syncStatus,
                'analytics' => $this->analyticsMetrics($target->analytics ?? null),
                'error_code' => $target->errorCode ?? null, 'is_owner' => $target->isOwner ?? null,
            ];
        }
        if (! in_array($accountId, array_column($normalized, 'account_id'), true)) {
            throw new ZernioApiException('scope_mismatch');
        }

        return [
            'post_id' => $observedPostId, 'zernio_post_id' => $zernioPostId,
            'status' => $data['status'], 'sync_status' => $data['syncStatus'],
            'analytics' => $this->analyticsMetrics($data['analytics'] ?? null), 'platform_analytics' => $normalized,
            'upstream_status' => $data['_upstream_status'], 'source' => 'zernio', 'profile_id' => $profileId,
        ];
    }

    /** @return Post */
    public function publishText(string $profileId, string $accountId, string $platform, string $content, string $idempotencyKey): array
    {
        $this->inputId($profileId);
        $this->inputId($accountId);
        if (! isset(self::TEXT_LIMITS[$platform])
            || trim($content) === '' || strlen($content) > 252824 || ! mb_check_encoding($content, 'UTF-8')
            || mb_strlen($content, 'UTF-8') > self::TEXT_LIMITS[$platform]
            || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $content)
            || ! preg_match('/\A[\x21-\x7e]{1,255}\z/', $idempotencyKey)) {
            throw new ZernioApiException('invalid_input');
        }
        $data = $this->request('POST', '/posts', [
            'content' => $content, 'publishNow' => true,
            'platforms' => [['platform' => $platform, 'accountId' => $accountId]],
        ], $idempotencyKey, [200, 201]);
        $recoveryPostId = $this->recoveryId($data);
        try {
            $post = $this->normalizePost($data['post'] ?? null, $profileId);
            if ($post['content'] !== $content || $post['status'] !== 'published' || count($post['platforms']) !== 1
                || $post['platforms'][0]['account_id'] !== $accountId || $post['platforms'][0]['platform'] !== $platform
                || $post['platforms'][0]['status'] !== 'published') {
                throw new ZernioApiException('publish_incomplete');
            }
        } catch (ZernioApiException $exception) {
            throw new ZernioApiException($exception->reason, true, $recoveryPostId);
        }

        return $post;
    }

    /**
     * Validates MIME, image headers and truncation markers, not a full bitmap decode.
     */
    public function uploadInstagramImage(UploadedFile $image, string $filename): string
    {
        $this->checkInstagramConfiguration();
        if (! preg_match('/\A'.self::UUID_PATTERN.'\.jpg\z/i', $filename) || ! $image->isValid()) {
            throw new ZernioApiException('invalid_input');
        }
        try {
            $path = $image->getPathname();
            $size = filesize($path);
            if ($size === false || $size < 4 || $size > self::MAX_IMAGE_BYTES) {
                throw new ZernioApiException('invalid_input');
            }
            if (file_get_contents($path, false, null, 0, 2) !== "\xff\xd8"
                || file_get_contents($path, false, null, $size - 2, 2) !== "\xff\xd9"
                || (new \finfo(FILEINFO_MIME_TYPE))->file($path) !== 'image/jpeg') {
                throw new ZernioApiException('invalid_input');
            }
            $dimensions = @getimagesize($path);
            if ($dimensions === false || $dimensions[2] !== IMAGETYPE_JPEG
                || $dimensions[0] < 1 || $dimensions[1] < 1 || $dimensions[0] > 4096 || $dimensions[1] > 4096
                || $dimensions[0] * $dimensions[1] > 16000000
                || $dimensions[0] / $dimensions[1] < 0.5625 || $dimensions[0] / $dimensions[1] > 1.91) {
                throw new ZernioApiException('invalid_input');
            }
        } catch (Throwable) {
            throw new ZernioApiException('invalid_input');
        }

        // Direct uploads expire after seven days; scheduling is bounded to the next day.
        $data = $this->request('POST', '/media/upload-direct', ['contentType' => 'image/jpeg'], image: $image, filename: $filename);
        if ((array_key_exists('contentType', $data) && ! in_array($data['contentType'], ['image/jpeg', 'image/jpg'], true))
            || (array_key_exists('size', $data) && (! is_int($data['size']) || $data['size'] < 0 || $data['size'] > self::MAX_IMAGE_BYTES))) {
            throw new ZernioApiException('malformed_response');
        }

        return $this->mediaUrl($data['url'] ?? null);
    }

    /** @return Post */
    public function scheduleInstagramImage(string $profileId, string $accountId, string $content, string $mediaUrl, string $scheduledFor, string $idempotencyKey): array
    {
        $this->checkInstagramConfiguration();
        $this->inputId($profileId);
        $this->inputId($accountId);
        if (! mb_check_encoding($content, 'UTF-8') || mb_strlen($content, 'UTF-8') > 2200
            || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $content)
            || ! preg_match('/\A'.self::UUID_PATTERN.'\z/i', $idempotencyKey)
            || ! preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/', $scheduledFor)) {
            throw new ZernioApiException('invalid_input');
        }
        try {
            $this->mediaUrl($mediaUrl);
            $this->date($scheduledFor);
            $scheduled = new DateTimeImmutable($scheduledFor);
        } catch (ZernioApiException) {
            throw new ZernioApiException('invalid_input');
        }
        $secondsUntil = (float) $scheduled->format('U.u') - (float) now()->format('U.u');
        if ($secondsUntil < 60 || $secondsUntil > 86400) {
            throw new ZernioApiException('invalid_input');
        }
        $data = $this->request('POST', '/posts', [
            'content' => $content, 'mediaItems' => [['type' => 'image', 'url' => $mediaUrl]],
            'platforms' => [['platform' => 'instagram', 'accountId' => $accountId]],
            'scheduledFor' => $scheduledFor, 'timezone' => 'UTC', 'isDraft' => false, 'publishNow' => false,
        ], $idempotencyKey, [200, 201]);
        $recoveryPostId = $this->recoveryId($data);
        try {
            $post = $this->normalizePost($data['post'] ?? null, includeMedia: true);
            if ($post['profile_id'] === null) {
                throw new ZernioApiException('unverified_profile');
            }
            if ($post['profile_id'] !== $profileId) {
                throw new ZernioApiException('scope_mismatch');
            }
            if ($post['content'] !== $content || $post['status'] !== 'scheduled' || count($post['platforms']) !== 1
                || $post['platforms'][0]['account_id'] !== $accountId || $post['platforms'][0]['platform'] !== 'instagram'
                || $post['platforms'][0]['status'] !== 'pending'
                || count($post['media_items']) !== 1 || $post['media_items'][0]['type'] !== 'image'
                || ! self::isSameInstagramMediaUrl($mediaUrl, $post['media_items'][0]['url'])
                || $post['scheduled_for'] === null || new DateTimeImmutable($post['scheduled_for']) != $scheduled) {
                throw new ZernioApiException('publish_incomplete');
            }
        } catch (ZernioApiException $exception) {
            throw new ZernioApiException($exception->reason, true, $recoveryPostId);
        }

        return $post;
    }

    /**
     * Compares already-validated URLs without fetching or rewriting them.
     * The narrow CDN promotion was observed in an approved live test with matching
     * SHA-256 byte hashes; it is not a documented provider API guarantee.
     */
    public static function isSameInstagramMediaUrl(string $uploaded, string $observed): bool
    {
        if ($uploaded === $observed) {
            return true;
        }

        return preg_match('~\Ahttps://media\.zernio\.com/temp/([A-Za-z0-9_-]{1,251}\.jpg)\z~', $uploaded, $matches) === 1
            && $observed === 'https://media.zernio.com/media/'.$matches[1];
    }

    private function checkInstagramConfiguration(): void
    {
        $this->checkConfiguration(true);
        if (config('agent-api.zernio.instagram_scheduling_enabled') !== true) {
            throw new ZernioApiException('not_configured');
        }
    }

    private function mediaUrl(mixed $value): string
    {
        if (! is_string($value) || strlen($value) > 4096 || ! filter_var($value, FILTER_VALIDATE_URL)
            || preg_match('/[\x00-\x20\x7f\\\\]/', $value)) {
            throw new ZernioApiException('malformed_response');
        }
        $parts = parse_url($value);
        $host = strtolower(rtrim($parts['host'] ?? '', '.'));
        $ip = trim($host, '[]');
        if (($parts['scheme'] ?? null) !== 'https' || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
            || (isset($parts['port']) && $parts['port'] !== 443)
            || preg_match('/(?:\A|\.)(?:localhost|test|local)\z/', $host)
            || (filter_var($ip, FILTER_VALIDATE_IP) !== false
                ? filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE | FILTER_FLAG_GLOBAL_RANGE) === false
                : ! preg_match('/\A(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}\z/', $host))) {
            throw new ZernioApiException('malformed_response');
        }

        return $value;
    }

    /** @param array<string, mixed> $parameters
     * @param  list<int>  $allowedStatuses
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, array $parameters = [], ?string $idempotencyKey = null, array $allowedStatuses = [200], ?UploadedFile $image = null, ?string $filename = null): array
    {
        $publishing = $method === 'POST';
        $key = $this->checkConfiguration($publishing);
        $started = hrtime(true);
        $guard = static function (float $bytes) use ($started, $publishing): void {
            if ($bytes > self::MAX_BYTES) {
                throw new ZernioApiException('response_too_large', $publishing);
            }
            if ((hrtime(true) - $started) / 1e9 >= 15) {
                throw new ZernioApiException('transport_error', $publishing);
            }
        };
        $file = null;
        try {
            $request = Http::withToken($key)->acceptJson()->withHeaders(['Accept-Encoding' => 'identity'])
                ->connectTimeout(5)->timeout(15)->withOptions([
                    'allow_redirects' => false, 'stream' => false, 'decode_content' => false,
                    'http_errors' => false,
                    'on_headers' => static function (ResponseInterface $response) use ($guard): void {
                        $length = $response->getHeaderLine('Content-Length');
                        $guard(is_numeric($length) ? (float) $length : 0);
                    },
                    'progress' => static function (int|float $total, int|float $downloaded, int|float $uploadTotal, int|float $uploaded) use ($guard): void {
                        $guard(max($total, $downloaded));
                    },
                ]);
            if ($image !== null) {
                $file = @fopen($image->getPathname(), 'rb');
                if ($file === false) {
                    throw new ZernioApiException('invalid_input');
                }
                $request->attach('file', $file, $filename, ['Content-Type' => 'image/jpeg']);
            }
            $response = $request->send($method, self::BASE_URL.$path, [
                $image !== null ? 'multipart' : ($publishing ? 'json' : 'query') => $parameters,
                'headers' => $idempotencyKey === null ? [] : ['Idempotency-Key' => $idempotencyKey],
            ]);
            $guard(0);
            $body = $response->toPsrResponse()->getBody();
            $raw = '';
            while (! $body->eof()) {
                $raw .= $body->read(min(8192, self::MAX_BYTES + 1 - strlen($raw)));
                $guard(strlen($raw));
            }
        } catch (Throwable $exception) {
            throw new ZernioApiException($exception instanceof ZernioApiException ? $exception->reason : 'transport_error', $publishing);
        } finally {
            if (is_resource($file)) {
                fclose($file);
            }
        }
        $status = $response->status();
        if ($status >= 400 && $status < 500 && ! in_array($status, $allowedStatuses, true)) {
            $reason = $method === 'GET' && $path === '/analytics' ? match ($status) {
                402 => 'analytics_access_required',
                404 => 'not_found',
                default => 'http_error',
            } : 'http_error';
            if ($method === 'GET' && $status === 404 && ($path === '/profiles' || str_starts_with($path, '/profiles/'))) {
                $reason = 'not_found';
            }
            $ambiguous = $publishing && in_array($status, [408, 409], true);
            $recoveryPostId = null;
            if ($ambiguous) {
                try {
                    $errorData = json_decode($raw, false, 64, JSON_THROW_ON_ERROR);
                    $recoveryPostId = $errorData instanceof stdClass ? $this->recoveryId((array) $errorData) : null;
                } catch (JsonException) {
                    // Error bodies are optional and never exposed to callers.
                }
            }
            throw new ZernioApiException($reason, $ambiguous, $recoveryPostId);
        }
        try {
            $data = json_decode($raw, false, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new ZernioApiException('malformed_response', $publishing);
        }
        if (! $data instanceof stdClass) {
            throw new ZernioApiException('malformed_response', $publishing);
        }
        $data = (array) $data;
        if (! in_array($status, $allowedStatuses, true)) {
            throw new ZernioApiException($status === 207 ? 'publish_incomplete' : 'http_error', $publishing, $publishing ? $this->recoveryId($data) : null);
        }

        $data['_upstream_status'] = $status;

        return $data;
    }

    private function checkConfiguration(bool $publishing): string
    {
        $key = config('agent-api.zernio.api_key');
        if (config('agent-api.zernio.enabled') !== true || ($publishing && config('agent-api.zernio.publishing_enabled') !== true)
            || ! is_string($key) || ! preg_match('/\A[\x21-\x7e]+\z/', $key)) {
            throw new ZernioApiException('not_configured');
        }

        return $key;
    }

    private function inputId(string $id): void
    {
        if (! preg_match('/\A[a-f0-9]{24}\z/', $id)) {
            throw new ZernioApiException('invalid_input');
        }
    }

    private function inputPagination(int $page, int $limit): void
    {
        if ($page < 1 || $page > 1000 || $limit < 1 || $limit > 100) {
            throw new ZernioApiException('invalid_input');
        }
    }

    private function responseId(mixed $value): string
    {
        $value = $value instanceof stdClass ? ($value->_id ?? null) : $value;
        if (! is_string($value) || ! preg_match('/\A[a-f0-9]{24}\z/', $value)) {
            throw new ZernioApiException('malformed_response');
        }

        return $value;
    }

    /** @return list<mixed> */
    private function list(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw new ZernioApiException('malformed_response');
        }

        return $value;
    }

    /** @return Pagination */
    private function pagination(mixed $value, int $page, int $limit): array
    {
        if (! $value instanceof stdClass) {
            throw new ZernioApiException('malformed_response');
        }
        $value = (array) $value;
        $result = [];
        foreach (['page', 'limit', 'total', 'pages'] as $field) {
            if (! is_int($value[$field] ?? null) || $value[$field] < 0) {
                throw new ZernioApiException('malformed_response');
            }
            $result[$field] = $value[$field];
        }
        if ($result['page'] !== $page || $result['limit'] !== $limit) {
            throw new ZernioApiException('malformed_response');
        }

        return $result;
    }

    private function text(mixed $value, int $max = 300000): ?string
    {
        if ($value !== null && (! is_string($value) || strlen($value) > $max || ! mb_check_encoding($value, 'UTF-8') || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $value))) {
            throw new ZernioApiException('malformed_response');
        }

        return $value;
    }

    private function platform(mixed $value): string
    {
        if (! is_string($value) || ! in_array($value, ['tiktok', 'instagram', 'facebook', 'youtube', 'linkedin', 'twitter', 'threads', 'pinterest', 'reddit', 'bluesky', 'googlebusiness', 'telegram', 'snapchat', 'discord', 'slack', 'whatsapp', 'shopify', 'wordpress', 'wix', 'linkedinads', 'metaads', 'pinterestads', 'tiktokads', 'xads', 'googleads', 'openaiads', 'redditads', 'sms', 'phone', 'rcs', 'whopads', 'hubspot', 'webflow'], true)) {
            throw new ZernioApiException('malformed_response');
        }

        return $value;
    }

    private function date(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (! is_string($value) || ! preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})\z/', $value)) {
            throw new ZernioApiException('malformed_response');
        }
        try {
            new DateTimeImmutable($value);
            $errors = DateTimeImmutable::getLastErrors();
            if ($errors !== false && ($errors['warning_count'] || $errors['error_count'])) {
                throw new ZernioApiException('malformed_response');
            }
        } catch (Throwable) {
            throw new ZernioApiException('malformed_response');
        }

        return $value;
    }

    private function analyticsMetrics(mixed $value): ?stdClass
    {
        if ($value === null) {
            return null;
        }
        if (! $value instanceof stdClass) {
            throw new ZernioApiException('malformed_response');
        }
        $integers = ['impressions', 'reach', 'likes', 'comments', 'shares', 'saves', 'clicks', 'views',
            'igReelsAvgWatchTime', 'igReelsVideoViewTotalTime', 'profileViews', 'websiteClicks', 'replays', 'reposts',
            'follows', 'videoDurationSeconds'];
        $numbers = ['engagementRate', 'reelsSkipRate', 'completionRate'];
        $maps = ['impressionSources', 'audienceTypes', 'audienceCountries', 'retentionCurve'];
        $metrics = [];
        foreach ((array) $value as $field => $metric) {
            if (in_array($field, $integers, true)) {
                if ($metric !== null || ! in_array($field, ['follows', 'videoDurationSeconds'], true)) {
                    if (! is_int($metric) || $metric < 0) {
                        throw new ZernioApiException('malformed_response');
                    }
                }
            } elseif (in_array($field, $numbers, true)) {
                if ((! is_int($metric) && ! is_float($metric)) || ! is_finite((float) $metric) || $metric < 0) {
                    throw new ZernioApiException('malformed_response');
                }
            } elseif (in_array($field, $maps, true)) {
                if (! $metric instanceof stdClass || count((array) $metric) > 100) {
                    throw new ZernioApiException('malformed_response');
                }
                $entries = [];
                foreach ((array) $metric as $key => $number) {
                    $key = (string) $key;
                    if (strlen($key) > 128 || ! mb_check_encoding($key, 'UTF-8') || preg_match('/\p{Cc}/u', $key)
                        || (! is_int($number) && ! is_float($number))
                        || ! is_finite((float) $number) || $number < 0) {
                        throw new ZernioApiException('malformed_response');
                    }
                    $entries[$key] = $number;
                }
                $metric = (object) $entries;
            } elseif ($field === 'lastUpdated') {
                if ($metric === null) {
                    throw new ZernioApiException('malformed_response');
                }
                $metric = $this->date($metric);
            } else {
                continue;
            }
            $metrics[$field] = $metric;
        }

        return (object) $metrics;
    }

    /** @return Post */
    private function normalizePost(mixed $post, ?string $profileId = null, bool $includeMedia = false): array
    {
        if (! $post instanceof stdClass || ! is_string($post->_id ?? null) || ! in_array($post->status ?? null, ['draft', 'scheduled', 'publishing', 'published', 'partial', 'failed', 'cancelled'], true)) {
            throw new ZernioApiException('malformed_response');
        }
        $post = (array) $post;
        $observedProfileId = array_key_exists('profileId', $post) ? $this->responseId($post['profileId']) : null;
        if ($profileId !== null && $observedProfileId !== null && $observedProfileId !== $profileId) {
            throw new ZernioApiException('scope_mismatch');
        }
        $hasRootProfile = $observedProfileId !== null;
        $allTargetsHaveProfile = true;
        $targets = [];
        foreach ($this->list($post['platforms'] ?? null) as $target) {
            if (! $target instanceof stdClass || ! in_array($target->status ?? null, ['pending', 'processing', 'uploading', 'published', 'failed', 'cancelled'], true)) {
                throw new ZernioApiException('malformed_response');
            }
            $target = (array) $target;
            $account = $target['accountId'] ?? null;
            if ($account instanceof stdClass && property_exists($account, 'profileId')) {
                $targetProfileId = $this->responseId($account->profileId);
                if (($profileId !== null && $targetProfileId !== $profileId)
                    || ($observedProfileId !== null && $targetProfileId !== $observedProfileId)) {
                    throw new ZernioApiException('scope_mismatch');
                }
                $observedProfileId = $targetProfileId;
            } else {
                $allTargetsHaveProfile = false;
            }
            $url = $this->text($target['platformPostUrl'] ?? null, 4096);
            if ($url === '') {
                $url = null;
            }
            if ($url !== null && (! filter_var($url, FILTER_VALIDATE_URL) || ! in_array(parse_url($url, PHP_URL_SCHEME), ['https', 'http'], true) || parse_url($url, PHP_URL_USER) !== null || parse_url($url, PHP_URL_PASS) !== null)) {
                throw new ZernioApiException('malformed_response');
            }
            $targets[] = ['account_id' => $this->responseId($account), 'platform' => $this->platform($target['platform'] ?? null), 'status' => $target['status'], 'url' => $url];
        }

        $result = [
            'id' => $this->responseId($post['_id'] ?? null),
            'profile_id' => $profileId ?? ($hasRootProfile || ($targets !== [] && $allTargetsHaveProfile) ? $observedProfileId : null),
            'content' => $this->text($post['content'] ?? null), 'status' => $post['status'],
            'scheduled_for' => $this->date($post['scheduledFor'] ?? null),
            'created_at' => $this->date($post['createdAt'] ?? null), 'updated_at' => $this->date($post['updatedAt'] ?? null),
            'platforms' => $targets,
        ];
        if ($includeMedia) {
            $mediaItems = [];
            foreach ($this->list(array_key_exists('mediaItems', $post) ? $post['mediaItems'] : []) as $item) {
                if (! $item instanceof stdClass || ! in_array($item->type ?? null, ['image', 'video', 'gif', 'document'], true)) {
                    throw new ZernioApiException('malformed_response');
                }
                $mediaItems[] = ['type' => $item->type, 'url' => $this->mediaUrl($item->url ?? null)];
            }
            $timezone = $this->text($post['timezone'] ?? null, 128);
            if ($timezone !== null && ($timezone === '' || preg_match('/\p{Cc}/u', $timezone))) {
                throw new ZernioApiException('malformed_response');
            }
            $result['media_items'] = $mediaItems;
            $result['timezone'] = $timezone;
        }

        return $result;
    }

    /** @param array<string, mixed> $data */
    private function recoveryId(array $data): ?string
    {
        $post = $data['post'] ?? null;
        foreach ([$post instanceof stdClass ? ($post->_id ?? null) : null, $data['postId'] ?? null] as $id) {
            if (is_string($id) && preg_match('/\A[a-f0-9]{24}\z/', $id)) {
                return $id;
            }
        }

        return null;
    }
}
