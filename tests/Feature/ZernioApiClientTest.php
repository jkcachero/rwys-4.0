<?php

namespace Tests\Feature;

use App\Zernio\Exceptions\ZernioApiException;
use App\Zernio\Services\ZernioApiClient;
use GuzzleHttp\Psr7\Response;
use Illuminate\Config\Repository;
use Illuminate\Foundation\Application;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use stdClass;
use Tests\TestCase;

class ZernioApiClientTest extends TestCase
{
    private const PROFILE = 'aaaaaaaaaaaaaaaaaaaaaaaa';

    private const ACCOUNT = 'bbbbbbbbbbbbbbbbbbbbbbbb';

    private const POST = 'cccccccccccccccccccccccc';

    private const OPERATION = '12345678-1234-4123-8123-123456789abc';

    private const MEDIA = 'https://cdn.example.com/image.jpg';

    public function createApplication(): Application
    {
        $this->traitsUsedByTest = class_uses_recursive(static::class);
        $app = new Application(dirname(__DIR__, 2));
        $app->instance('config', new Repository);
        Facade::setFacadeApplication($app);

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Http::fake([]);
        config(['agent-api.zernio' => ['enabled' => true, 'publishing_enabled' => true, 'instagram_scheduling_enabled' => true, 'api_key' => 'test-key']]);
    }

    public function test_instagram_upload_is_authenticated_multipart_with_a_controlled_filename(): void
    {
        $image = $this->jpeg();
        Http::fake(function (Request $request, array $options) use ($image) {
            $this->assertSame('POST', $request->method());
            $this->assertSame('https://zernio.com/api/v1/media/upload-direct', $request->url());
            $this->assertTrue($request->hasHeader('Authorization', 'Bearer test-key'));
            $this->assertTrue($request->isMultipart());
            $this->assertArrayNotHasKey('json', $options);
            $this->assertSame(5, $options['connect_timeout']);
            $this->assertSame(15, $options['timeout']);
            $this->assertFalse($options['allow_redirects']);
            $this->assertStringContainsString(self::OPERATION.'.jpg', $request->body());
            $this->assertStringContainsString('image/jpeg', $request->body());
            $this->assertStringContainsString(file_get_contents($image->getPathname()), $request->body());

            return Http::response(['url' => self::MEDIA, 'filename' => 'provider-generated.jpg', 'contentType' => 'image/jpeg', 'size' => 0]);
        });
        $this->assertSame(self::MEDIA, (new ZernioApiClient)->uploadInstagramImage($image, self::OPERATION.'.jpg'));
        Http::assertSentCount(1);
    }

    public function test_upload_optional_metadata_is_not_required_or_echoed(): void
    {
        Http::fake(['zernio.com/api/v1/media/upload-direct' => Http::response(['url' => self::MEDIA])]);
        $this->assertSame(self::MEDIA, (new ZernioApiClient)->uploadInstagramImage($this->jpeg(), self::OPERATION.'.jpg'));
        Http::assertSentCount(1);
    }

    #[DataProvider('unsafeMediaUrls')]
    public function test_upload_rejects_unsafe_urls_without_fetching_them(mixed $url): void
    {
        Http::fake(['zernio.com/api/v1/media/upload-direct' => Http::response(['url' => $url])]);
        try {
            (new ZernioApiClient)->uploadInstagramImage($this->jpeg(), self::OPERATION.'.jpg');
            $this->fail('Unsafe upload URL must throw.');
        } catch (ZernioApiException $exception) {
            $this->assertSame('malformed_response', $exception->reason);
            $this->assertNull($exception->getPrevious());
        }
        Http::assertSentCount(1);
    }

    public static function unsafeMediaUrls(): array
    {
        return array_map(static fn (mixed $url): array => [$url], [null, [], '', 'http://cdn.example.com/a.jpg',
            'https://user:PRIVATE@cdn.example.com/a.jpg', 'https://cdn.example.com/a.jpg#fragment',
            'https://cdn.example.com:444/a.jpg', 'https://localhost/a.jpg', 'https://sub.localhost/a.jpg',
            'https://evil.test/a.jpg', 'https://evil.local/a.jpg', 'https://127.0.0.1/a.jpg',
            'https://10.1.2.3/a.jpg', 'https://192.0.2.1/a.jpg', 'https://[::1]/a.jpg',
            'https://[fc00::1]/a.jpg', 'https://2130706433/a.jpg', 'https://cdn.example.com/'.str_repeat('a', 4096)]);
    }

    #[DataProvider('invalidUploadMetadata')]
    public function test_upload_optional_metadata_is_validated(string $field, mixed $value): void
    {
        Http::fake(['zernio.com/api/v1/media/upload-direct' => Http::response(['url' => self::MEDIA, $field => $value])]);
        $this->expectException(ZernioApiException::class);
        try {
            (new ZernioApiClient)->uploadInstagramImage($this->jpeg(), self::OPERATION.'.jpg');
        } finally {
            Http::assertSentCount(1);
        }
    }

    public static function invalidUploadMetadata(): array
    {
        return [['contentType', null], ['contentType', 'image/png'], ['contentType', []], ['size', null],
            ['size', -1], ['size', 2097153], ['size', '1'], ['size', 1.5]];
    }

    #[DataProvider('invalidImages')]
    public function test_invalid_images_and_filenames_have_no_network_io(string $variant): void
    {
        $image = $this->jpeg();
        $filename = self::OPERATION.'.jpg';
        if ($variant === 'svg') {
            $image = UploadedFile::fake()->createWithContent('image.jpg', '<svg xmlns="http://www.w3.org/2000/svg"/>');
        } elseif ($variant === 'png') {
            $image = UploadedFile::fake()->createWithContent('image.jpg', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII='));
        } elseif ($variant === 'huge') {
            $image = UploadedFile::fake()->createWithContent('image.jpg', file_get_contents($image->getPathname()).str_repeat('x', 2097153));
        } elseif (in_array($variant, ['zero', 'wide', 'tall', 'dimension'], true)) {
            $bytes = file_get_contents($image->getPathname());
            $offset = strpos($bytes, "\xff\xc0");
            $this->assertNotFalse($offset);
            [$width, $height] = match ($variant) {
                'zero' => [0, 1], 'wide' => [192, 100], 'tall' => [56, 100], 'dimension' => [4097, 4097],
            };
            $image = UploadedFile::fake()->createWithContent('image.jpg', substr_replace($bytes, pack('nn', $height, $width), $offset + 5, 4));
        } else {
            $filename = $variant === 'path' ? '../'.self::OPERATION.'.jpg' : 'uncontrolled.jpg';
        }
        try {
            (new ZernioApiClient)->uploadInstagramImage($image, $filename);
            $this->fail('Invalid upload must throw.');
        } catch (ZernioApiException $exception) {
            $this->assertSame('invalid_input', $exception->reason);
            $this->assertFalse($exception->ambiguous);
        }
        Http::assertNothingSent();
    }

    public static function invalidImages(): array
    {
        return array_map(static fn (string $variant): array => [$variant], ['svg', 'png', 'huge', 'zero', 'wide', 'tall', 'dimension', 'path', 'filename']);
    }

    public function test_instagram_all_three_flags_must_be_explicitly_true_before_either_write(): void
    {
        foreach (['enabled', 'publishing_enabled', 'instagram_scheduling_enabled'] as $flag) {
            foreach ([false, null, 'true', 1] as $value) {
                config(['agent-api.zernio.'.$flag => $value]);
                foreach (['upload', 'schedule'] as $method) {
                    try {
                        $client = new ZernioApiClient;
                        $method === 'upload' ? $client->uploadInstagramImage($this->jpeg(), self::OPERATION.'.jpg')
                            : $client->scheduleInstagramImage(self::PROFILE, self::ACCOUNT, 'Hello', self::MEDIA, now()->addMinutes(5)->utc()->format('Y-m-d\TH:i:s\Z'), self::OPERATION);
                        $this->fail('Disabled capability must throw.');
                    } catch (ZernioApiException $exception) {
                        $this->assertSame('not_configured', $exception->reason);
                        $this->assertFalse($exception->ambiguous);
                    }
                }
            }
            config(['agent-api.zernio.'.$flag => true]);
        }
        Http::assertNothingSent();
    }

    #[DataProvider('scheduleSuccesses')]
    public function test_instagram_schedule_uses_one_exact_post_and_verifies_the_result(int $status): void
    {
        $this->freezeTime();
        $scheduled = now()->addMinutes(5)->utc()->format('Y-m-d\TH:i:s\Z');
        $post = $this->scheduledBody($scheduled);
        $post['scheduledFor'] = (new \DateTimeImmutable($scheduled))->format('Y-m-d\TH:i:sP');
        Http::fake(['zernio.com/api/v1/posts' => Http::response(['post' => $post], $status)]);
        $result = (new ZernioApiClient)->scheduleInstagramImage(self::PROFILE, self::ACCOUNT, 'Hello', self::MEDIA, $scheduled, self::OPERATION);
        $this->assertSame(['id' => self::POST, 'profile_id' => self::PROFILE, 'content' => 'Hello', 'status' => 'scheduled',
            'scheduled_for' => $post['scheduledFor'], 'created_at' => '2026-10-22T10:00:00Z', 'updated_at' => null,
            'platforms' => [['account_id' => self::ACCOUNT, 'platform' => 'instagram', 'status' => 'pending', 'url' => null]],
            'media_items' => [['type' => 'image', 'url' => self::MEDIA]], 'timezone' => 'UTC'], $result);
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->hasHeader('Authorization', 'Bearer test-key') && $request->hasHeader('Idempotency-Key', self::OPERATION)
            && $request->data() === ['content' => 'Hello', 'mediaItems' => [['type' => 'image', 'url' => self::MEDIA]],
                'platforms' => [['platform' => 'instagram', 'accountId' => self::ACCOUNT]],
                'scheduledFor' => $scheduled, 'timezone' => 'UTC', 'isDraft' => false, 'publishNow' => false]);
    }

    public static function scheduleSuccesses(): array
    {
        return [[200], [201]];
    }

    #[DataProvider('instagramMediaUrlPairs')]
    public function test_instagram_media_url_equivalence_is_exact_or_a_narrow_cdn_promotion(string $uploaded, string $observed, bool $matches): void
    {
        $this->assertSame($matches, ZernioApiClient::isSameInstagramMediaUrl($uploaded, $observed));
        Http::assertNothingSent();
    }

    public static function instagramMediaUrlPairs(): array
    {
        $uploaded = 'https://media.zernio.com/temp/fixture-image_123.jpg';
        $observed = 'https://media.zernio.com/media/fixture-image_123.jpg';

        return [
            'exact other public host' => [self::MEDIA, self::MEDIA, true],
            'exact signed URL' => [self::MEDIA.'?signature=fixture', self::MEDIA.'?signature=fixture', true],
            'exact temp URL' => [$uploaded, $uploaded, true],
            'promoted' => [$uploaded, $observed, true],
            'altered filename' => [$uploaded, str_replace('123', '124', $observed), false],
            'changed case' => [$uploaded, str_replace('fixture', 'Fixture', $observed), false],
            'evil hostname' => [$uploaded, str_replace('media.zernio.com', 'media.zernio.com.evil.example.com', $observed), false],
            'source cross host' => [str_replace('media.zernio.com', 'cdn.example.com', $uploaded), $observed, false],
            'destination cross host' => [$uploaded, str_replace('media.zernio.com', 'cdn.example.com', $observed), false],
            'temp prefix' => [str_replace('/temp/', '/temp-/', $uploaded), $observed, false],
            'wrong destination directory' => [$uploaded, str_replace('/media/', '/other/', $observed), false],
            'source traversal' => [str_replace('/temp/', '/temp/../temp/', $uploaded), $observed, false],
            'destination traversal' => [$uploaded, str_replace('/media/', '/media/../media/', $observed), false],
            'source nested path' => [str_replace('/temp/', '/temp/nested/', $uploaded), $observed, false],
            'source encoding' => [str_replace('fixture', '%66ixture', $uploaded), $observed, false],
            'destination encoding' => [$uploaded, str_replace('fixture', '%66ixture', $observed), false],
            'source query' => [$uploaded.'?signature=fixture', $observed, false],
            'destination query' => [$uploaded, $observed.'?signature=fixture', false],
            'empty source query' => [$uploaded.'?', $observed, false],
            'empty destination query' => [$uploaded, $observed.'?', false],
            'source port' => [str_replace('.com/', '.com:443/', $uploaded), $observed, false],
            'destination port' => [$uploaded, str_replace('.com/', '.com:443/', $observed), false],
            'source userinfo' => [str_replace('https://', 'https://user@', $uploaded), $observed, false],
            'destination userinfo' => [$uploaded, str_replace('https://', 'https://user@', $observed), false],
            'source fragment' => [$uploaded.'#fixture', $observed, false],
            'destination fragment' => [$uploaded, $observed.'#fixture', false],
            'source scheme' => [str_replace('https:', 'http:', $uploaded), $observed, false],
            'destination scheme' => [$uploaded, str_replace('https:', 'http:', $observed), false],
            'reverse promotion' => [$observed, $uploaded, false],
            'wrong extension' => [str_replace('.jpg', '.png', $uploaded), str_replace('.jpg', '.png', $observed), false],
        ];
    }

    #[DataProvider('instagramMediaUrlPairs')]
    public function test_instagram_creation_media_matching_preserves_observed_metadata_and_never_resends(string $uploaded, string $observed, bool $matches): void
    {
        try {
            $scheduled = now()->addMinutes(5)->utc()->format('Y-m-d\TH:i:s\Z');
            $post = $this->scheduledBody($scheduled);
            $post['mediaItems'] = [['type' => 'image', 'url' => $observed]];
            Http::fake(['zernio.com/api/v1/posts' => Http::response(['post' => $post], 201)]);
            $result = (new ZernioApiClient)->scheduleInstagramImage(self::PROFILE, self::ACCOUNT, 'Hello', $uploaded, $scheduled, self::OPERATION);
            $this->assertTrue($matches);
            $this->assertSame(self::POST, $result['id']);
            $this->assertSame(self::PROFILE, $result['profile_id']);
            $this->assertSame('Hello', $result['content']);
            $this->assertSame($scheduled, $result['scheduled_for']);
            $this->assertSame([['type' => 'image', 'url' => $observed]], $result['media_items']);
        } catch (ZernioApiException $exception) {
            $this->assertFalse($matches);
            // Invalid input URLs must fail even before the single permitted POST.
            if ($exception->reason === 'invalid_input') {
                $this->assertFalse($exception->ambiguous);
                Http::assertNothingSent();

                return;
            }
            $this->assertTrue($exception->ambiguous);
            $this->assertSame(self::POST, $exception->recoveryPostId);
            $this->assertNull($exception->getPrevious());
            $this->assertStringNotContainsString(self::POST, $exception->getMessage());
            $this->assertStringNotContainsString($observed, $exception->getMessage());
        }
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://zernio.com/api/v1/posts'
            && $request['mediaItems'] === [['type' => 'image', 'url' => $uploaded]]);
    }

    #[DataProvider('creationProfileEvidence')]
    public function test_instagram_creation_requires_observed_profile_membership(?string $root, ?string $nested, bool $additionalTarget, ?string $reason): void
    {
        $scheduled = now()->addMinutes(5)->utc()->format('Y-m-d\TH:i:s\Z');
        $post = $this->scheduledBody($scheduled);
        unset($post['profileId']);
        if ($root !== null) {
            $post['profileId'] = $root;
        }
        if ($nested !== null) {
            $post['platforms'][0]['accountId'] = ['_id' => self::ACCOUNT, 'profileId' => ['_id' => $nested]];
        }
        if ($additionalTarget) {
            $post['platforms'][] = ['accountId' => self::ACCOUNT, 'platform' => 'instagram', 'status' => 'pending'];
        }
        Http::fake(['zernio.com/api/v1/posts' => Http::response(['post' => $post], 201)]);
        try {
            $result = (new ZernioApiClient)->scheduleInstagramImage(self::PROFILE, self::ACCOUNT, 'Hello', self::MEDIA, $scheduled, self::OPERATION);
            $this->assertNull($reason, 'Creation without verified membership must not return a pending post.');
            $this->assertSame(self::PROFILE, $result['profile_id']);
            $this->assertSame('pending', $result['platforms'][0]['status']);
        } catch (ZernioApiException $exception) {
            $this->assertSame($reason, $exception->reason);
            $this->assertTrue($exception->ambiguous);
            $this->assertSame(self::POST, $exception->recoveryPostId);
            $this->assertNull($exception->getPrevious());
            $this->assertStringNotContainsString(self::POST, $exception->getMessage());
            $this->assertStringNotContainsString(self::PROFILE, $exception->getMessage());
        }
        Http::assertSentCount(1);
        Http::assertNotSent(fn (Request $request): bool => $request->method() !== 'POST');
    }

    public static function creationProfileEvidence(): array
    {
        return [
            'scalar target without root' => [null, null, false, 'unverified_profile'],
            'matching root' => [self::PROFILE, null, false, null],
            'matching nested' => [null, self::PROFILE, false, null],
            'matching root and nested' => [self::PROFILE, self::PROFILE, false, null],
            'foreign root' => [self::ACCOUNT, null, false, 'scope_mismatch'],
            'foreign nested' => [null, self::ACCOUNT, false, 'scope_mismatch'],
            'contradictory nested' => [self::PROFILE, self::ACCOUNT, false, 'scope_mismatch'],
            'contradictory root' => [self::ACCOUNT, self::PROFILE, false, 'scope_mismatch'],
            'additional target with root' => [self::PROFILE, null, true, 'publish_incomplete'],
            'additional target without membership' => [null, self::PROFILE, true, 'unverified_profile'],
        ];
    }

    #[DataProvider('scheduleFailures')]
    public function test_schedule_incomplete_or_uncertain_results_are_safe_and_never_resent(int $status, string $field, mixed $value, bool $ambiguous = true): void
    {
        $scheduled = now()->addMinutes(5)->utc()->format('Y-m-d\TH:i:s\Z');
        $post = $this->scheduledBody($scheduled);
        if ($field !== '') {
            data_set($post, $field, $value);
        }
        $calls = 0;
        Http::fake(function () use (&$calls, $post, $status, $field) {
            $calls++;

            return $field === 'timeout' ? Http::failedConnection('PRIVATE signed-url test-key') : Http::response(['post' => $post, 'error' => 'PRIVATE test-key'], $status);
        });
        try {
            (new ZernioApiClient)->scheduleInstagramImage(self::PROFILE, self::ACCOUNT, 'Hello', self::MEDIA, $scheduled, self::OPERATION);
            $this->fail('Unconfirmed scheduling must throw.');
        } catch (ZernioApiException $exception) {
            $this->assertSame($ambiguous, $exception->ambiguous);
            $this->assertSame($ambiguous && $field !== 'timeout' && $field !== '_id' ? self::POST : null, $exception->recoveryPostId);
            $this->assertNull($exception->getPrevious());
            $this->assertStringNotContainsString('PRIVATE', $exception->getMessage());
            $this->assertStringNotContainsString('test-key', $exception->getMessage());
        }
        $this->assertSame(1, $calls);
        Http::assertNotSent(fn (Request $request): bool => $request->method() !== 'POST');
    }

    public static function scheduleFailures(): array
    {
        return [[202, '', null], [207, '', null], [409, '', null], [408, '', null], [503, '', null], [302, '', null],
            [401, '', null, false], [429, '', null, false], [201, 'timeout', null], [201, '_id', 'bad'],
            [201, 'status', 'published'], [201, 'content', 'Wrong'], [201, 'profileId', self::ACCOUNT],
            [201, 'platforms', []], [201, 'platforms.0.accountId', self::PROFILE], [201, 'platforms.0.platform', 'facebook'],
            [201, 'platforms.0.status', 'published'], [201, 'mediaItems', []], [201, 'mediaItems', null],
            [201, 'mediaItems.0.url', 'https://cdn.example.com/other.jpg'], [201, 'mediaItems.0.type', 'video'],
            [201, 'scheduledFor', null], [201, 'scheduledFor', '2026-01-01T00:00:00Z'], [201, 'timezone', []]];
    }

    #[DataProvider('invalidSchedules')]
    public function test_schedule_input_validation_has_no_io(string $field, mixed $value): void
    {
        $arguments = ['profileId' => self::PROFILE, 'accountId' => self::ACCOUNT, 'content' => 'Hello',
            'mediaUrl' => self::MEDIA, 'scheduledFor' => now()->addMinutes(5)->utc()->format('Y-m-d\TH:i:s\Z'), 'idempotencyKey' => self::OPERATION];
        $arguments[$field] = $value === 'too-soon' ? now()->addSeconds(59)->utc()->format('Y-m-d\TH:i:s\Z') : $value;
        try {
            (new ZernioApiClient)->scheduleInstagramImage(...$arguments);
            $this->fail('Invalid scheduling input must throw.');
        } catch (ZernioApiException $exception) {
            $this->assertSame('invalid_input', $exception->reason);
            $this->assertFalse($exception->ambiguous);
        }
        Http::assertNothingSent();
    }

    public static function invalidSchedules(): array
    {
        return [['profileId', 'all'], ['accountId', 'bad'], ['content', str_repeat('x', 2201)], ['content', "bad\x00"],
            ['content', "\xff"], ['idempotencyKey', 'not-a-uuid'], ['scheduledFor', 'too-soon'],
            ['scheduledFor', '2026-02-30T00:00:00Z'], ['scheduledFor', '2027-01-01T00:00:00+01:00'],
            ['mediaUrl', 'https://127.0.0.1/image.jpg']];
    }

    public function test_media_read_opt_in_preserves_legacy_fields_and_does_not_change_queries(): void
    {
        $post = $this->scheduledBody('2027-01-01T00:00:00Z');
        $post['mediaItems'][0]['secret'] = 'PRIVATE';
        Http::fake([
            'zernio.com/api/v1/posts?*' => fn () => Http::response(['posts' => [$post], 'pagination' => $this->pagination()]),
            'zernio.com/api/v1/posts/'.self::POST => fn () => Http::response(['post' => $post]),
        ]);
        $client = new ZernioApiClient;
        $legacy = $client->post(self::POST);
        $withMedia = $client->post(self::POST, true);
        $this->assertSame($legacy + ['media_items' => [['type' => 'image', 'url' => self::MEDIA]], 'timezone' => 'UTC'], $withMedia);
        $this->assertSame($client->posts(self::PROFILE, self::ACCOUNT)['posts'][0] + [
            'media_items' => $withMedia['media_items'], 'timezone' => 'UTC',
        ], $client->posts(self::PROFILE, self::ACCOUNT, includeMedia: true)['posts'][0]);
        Http::assertSentCount(4);
        Http::assertNotSent(fn (Request $request): bool => isset($request['includeMedia']));
    }

    #[DataProvider('mediaReadFields')]
    public function test_media_read_validation_only_applies_when_requested(string $field, mixed $value): void
    {
        $post = $this->scheduledBody('2027-01-01T00:00:00Z');
        data_set($post, $field, $value);
        Http::fake(['zernio.com/api/v1/posts/*' => fn () => Http::response(['post' => $post])]);
        $this->assertArrayNotHasKey('media_items', (new ZernioApiClient)->post(self::POST));
        $this->expectException(ZernioApiException::class);
        (new ZernioApiClient)->post(self::POST, true);
    }

    public static function mediaReadFields(): array
    {
        return [['mediaItems', null], ['mediaItems', (object) []], ['mediaItems.0', null], ['mediaItems.0.type', []],
            ['mediaItems.0.url', 'http://evil.test/image.jpg'], ['timezone', []], ['timezone', "bad\n"]];
    }

    public function test_absent_media_and_timezone_remain_empty_and_unknown(): void
    {
        Http::fake(['zernio.com/api/v1/posts/*' => Http::response(['post' => $this->postBody()])]);
        $result = (new ZernioApiClient)->post(self::POST, true);
        $this->assertSame([], $result['media_items']);
        $this->assertNull($result['timezone']);
    }

    #[DataProvider('uploadErrors')]
    public function test_upload_failures_reuse_bounded_transport_and_safe_errors(int $status, string $body, bool $timeout = false): void
    {
        $calls = 0;
        Http::fake(function (Request $request, array $options) use (&$calls, $status, $body, $timeout) {
            $calls++;
            $this->assertSame('https://zernio.com/api/v1/media/upload-direct', $request->url());
            $this->assertFalse($options['allow_redirects']);
            $this->assertFalse($options['decode_content']);
            $this->assertSame(5, $options['connect_timeout']);
            $this->assertSame(15, $options['timeout']);

            return $timeout ? Http::failedConnection('PRIVATE signed-url test-key')
                : Http::response($body, $status, ['Content-Length' => '1', 'Location' => 'https://evil.test/PRIVATE']);
        });
        try {
            (new ZernioApiClient)->uploadInstagramImage($this->jpeg(), self::OPERATION.'.jpg');
            $this->fail('Unconfirmed upload must throw.');
        } catch (ZernioApiException $exception) {
            $this->assertNull($exception->getPrevious());
            $this->assertStringNotContainsString('PRIVATE', $exception->getMessage());
            $this->assertStringNotContainsString('test-key', $exception->getMessage());
            $this->assertNull($exception->recoveryPostId);
        }
        $this->assertSame(1, $calls);
    }

    public static function uploadErrors(): array
    {
        return [[200, '{PRIVATE'], [200, '[]'], [200, str_repeat('x', 1048577)], [302, '{}'], [401, 'PRIVATE'],
            [409, '{}'], [503, '{}'], [201, '{"url":"https://cdn.example.com/image.jpg"}'], [200, '', true]];
    }

    #[DataProvider('jpegHeaderDimensions')]
    public function test_upload_enforces_size_and_pixel_limits_from_headers_without_claiming_bitmap_decode(int $width, int $height, bool $valid): void
    {
        $fixture = $this->jpeg();
        $bytes = file_get_contents($fixture->getPathname());
        $offset = strpos($bytes, "\xff\xc0");
        $bytes = substr_replace($bytes, pack('nn', $height, $width), $offset + 5, 4);
        $image = UploadedFile::fake()->createWithContent('image.jpg', str_pad(substr($bytes, 0, -2), 2097150, "\x00")."\xff\xd9");
        Http::fake(['zernio.com/api/v1/media/upload-direct' => Http::response([
            'url' => self::MEDIA, 'contentType' => 'image/jpg', 'size' => 2097152,
        ])]);
        try {
            $result = (new ZernioApiClient)->uploadInstagramImage($image, self::OPERATION.'.jpg');
            $this->assertTrue($valid);
            $this->assertSame(self::MEDIA, $result);
        } catch (ZernioApiException $exception) {
            $this->assertFalse($valid);
            $this->assertSame('invalid_input', $exception->reason);
        }
        Http::assertSentCount($valid ? 1 : 0);
    }

    public static function jpegHeaderDimensions(): array
    {
        return [[4000, 4000, true], [4096, 3906, true], [4001, 4000, false], [4096, 4096, false]];
    }

    #[DataProvider('truncatedJpegs')]
    public function test_truncated_and_header_only_jpegs_are_rejected_before_upload(string $variant): void
    {
        $fixture = $this->jpeg();
        $bytes = file_get_contents($fixture->getPathname());
        $bytes = match ($variant) {
            'missing-eoi' => substr($bytes, 0, -2),
            'partial-eoi' => substr($bytes, 0, -1),
            'header-only' => substr($bytes, 0, strpos($bytes, "\xff\xda")),
        };
        $image = UploadedFile::fake()->createWithContent('image.jpg', $bytes);
        $this->assertSame('image/jpeg', (new \finfo(FILEINFO_MIME_TYPE))->file($image->getPathname()));
        $this->assertSame([1, 1], array_slice(getimagesize($image->getPathname()), 0, 2));
        try {
            (new ZernioApiClient)->uploadInstagramImage($image, self::OPERATION.'.jpg');
            $this->fail('Recognizably truncated JPEG must not be uploaded.');
        } catch (ZernioApiException $exception) {
            $this->assertSame('invalid_input', $exception->reason);
            $this->assertFalse($exception->ambiguous);
            $this->assertNull($exception->getPrevious());
        }
        Http::assertNothingSent();
    }

    public static function truncatedJpegs(): array
    {
        return [['missing-eoi'], ['partial-eoi'], ['header-only']];
    }

    #[DataProvider('scheduleBounds')]
    public function test_schedule_caption_and_dispatch_time_boundaries(int $seconds, bool $valid): void
    {
        $this->travelTo(now()->startOfSecond());
        $scheduled = now()->addSeconds($seconds)->utc()->format('Y-m-d\TH:i:s\Z');
        $content = str_repeat("\u{1F600}", 2200);
        $post = $this->scheduledBody($scheduled);
        $post['content'] = $content;
        Http::fake(['zernio.com/api/v1/posts' => Http::response(['post' => $post], 201)]);
        try {
            $result = (new ZernioApiClient)->scheduleInstagramImage(self::PROFILE, self::ACCOUNT, $content, self::MEDIA, $scheduled, self::OPERATION);
            $this->assertTrue($valid);
            $this->assertSame($content, $result['content']);
        } catch (ZernioApiException $exception) {
            $this->assertFalse($valid);
            $this->assertSame('invalid_input', $exception->reason);
        }
        Http::assertSentCount($valid ? 1 : 0);
    }

    public static function scheduleBounds(): array
    {
        return [[59, false], [60, true], [86400, true], [86401, false]];
    }

    #[DataProvider('recoveryCandidates')]
    public function test_schedule_ambiguous_statuses_preserve_only_valid_known_recovery_ids(int $status, array $body, ?string $expected): void
    {
        Http::fake(['zernio.com/api/v1/posts' => Http::response($body + ['error' => 'PRIVATE'], $status)]);
        try {
            (new ZernioApiClient)->scheduleInstagramImage(self::PROFILE, self::ACCOUNT, 'Hello', self::MEDIA,
                now()->addMinutes(5)->utc()->format('Y-m-d\TH:i:s\Z'), self::OPERATION);
            $this->fail('Ambiguous create must throw.');
        } catch (ZernioApiException $exception) {
            $this->assertTrue($exception->ambiguous);
            $this->assertSame($expected, $exception->recoveryPostId);
            $this->assertNull($exception->getPrevious());
        }
        Http::assertSentCount(1);
    }

    public static function recoveryCandidates(): array
    {
        $cases = [];
        foreach ([202, 207, 409] as $status) {
            $cases[] = [$status, ['postId' => self::POST], self::POST];
            $cases[] = [$status, ['post' => ['_id' => 'bad'], 'postId' => self::POST], self::POST];
            $cases[] = [$status, ['postId' => 'PRIVATE'], null];
        }

        return $cases;
    }

    private function scheduledBody(string $scheduled): array
    {
        $post = $this->postBody('instagram');
        $post['status'] = 'scheduled';
        $post['platforms'][0]['status'] = 'pending';
        unset($post['platforms'][0]['platformPostUrl']);

        return $post + ['profileId' => self::PROFILE, 'scheduledFor' => $scheduled, 'timezone' => 'UTC', 'mediaItems' => [['type' => 'image', 'url' => self::MEDIA]]];
    }

    private function jpeg(): UploadedFile
    {
        // Complete 1x1 white JPEG encoded by ImageMagick, not a synthesized SOF header.
        return UploadedFile::fake()->createWithContent('untrusted-client-name.jpg', base64_decode('/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAAMCAgICAgMCAgIDAwMDBAYEBAQEBAgGBgUGCQgKCgkICQkKDA8MCgsOCwkJDRENDg8QEBEQCgwSExIQEw8QEBD/wAALCAABAAEBAREA/8QAFAABAAAAAAAAAAAAAAAAAAAACf/EABQQAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQEAAD8AVN//2Q=='));
    }

    public function test_accounts_are_scoped_paginated_and_allowlisted(): void
    {
        Http::fake(['zernio.com/api/v1/accounts*' => Http::response($this->accountsBody())]);
        $result = (new ZernioApiClient)->accounts(self::PROFILE, 1, 25, self::ACCOUNT);
        $this->assertSame(['accounts' => [[
            'id' => self::ACCOUNT, 'profile_id' => self::PROFILE, 'platform' => 'linkedin',
            'username' => 'example', 'display_name' => 'Example', 'is_active' => true,
        ]], 'pagination' => $this->pagination()], $result);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && $request->hasHeader('Authorization', 'Bearer test-key')
            && $request['profileId'] === self::PROFILE && $request['page'] === 1
            && $request['limit'] === 25 && $request['search'] === self::ACCOUNT);
    }

    public function test_operator_discovery_reads_one_bounded_page_across_profiles(): void
    {
        $body = $this->accountsBody();
        $body['accounts'][] = array_replace($body['accounts'][0], [
            '_id' => 'dddddddddddddddddddddddd',
            'profileId' => ['_id' => 'eeeeeeeeeeeeeeeeeeeeeeee', 'name' => 'Other profile'],
            'accessToken' => 'never-expose-provider-credentials',
        ]);
        $body['pagination']['total'] = 2;
        Http::fake(['zernio.com/api/v1/accounts*' => Http::response($body)]);

        $result = (new ZernioApiClient)->discoverAccounts(1, 25, 'example');

        $this->assertCount(2, $result['accounts']);
        $this->assertSame('eeeeeeeeeeeeeeeeeeeeeeee', $result['accounts'][1]['profile_id']);
        $this->assertStringNotContainsString('never-expose-provider-credentials', json_encode($result));
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && $request->data() === ['page' => 1, 'limit' => 25, 'search' => 'example']);
    }

    public function test_operator_discovery_without_search_still_sends_both_pagination_bounds(): void
    {
        Http::fake(['zernio.com/api/v1/accounts*' => Http::response($this->accountsBody())]);

        (new ZernioApiClient)->discoverAccounts();

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request->data() === ['page' => 1, 'limit' => 25]);
    }

    public function test_discovery_rejects_search_exceeding_the_documented_provider_limit(): void
    {
        $this->expectException(ZernioApiException::class);
        try {
            (new ZernioApiClient)->discoverAccounts(search: str_repeat('a', 101));
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_posts_and_single_post_preserve_every_target_without_private_fields(): void
    {
        $post = $this->postBody();
        $post['platforms'][] = ['platform' => 'facebook', 'accountId' => ['_id' => 'dddddddddddddddddddddddd', 'profileId' => ['_id' => self::PROFILE]], 'status' => 'failed', 'errorMessage' => 'PRIVATE'];
        Http::fake([
            'zernio.com/api/v1/posts?*' => Http::response(['posts' => [$post], 'pagination' => $this->pagination()]),
            'zernio.com/api/v1/posts/'.self::POST => Http::response(['post' => $post]),
        ]);
        $client = new ZernioApiClient;
        $list = $client->posts(self::PROFILE, self::ACCOUNT);
        $single = $client->post(self::POST);
        $this->assertSame(self::PROFILE, $list['posts'][0]['profile_id']);
        $this->assertNull($single['profile_id']);
        $this->assertSame(['id', 'profile_id', 'content', 'status', 'scheduled_for', 'created_at', 'updated_at', 'platforms'], array_keys($single));
        $this->assertCount(2, $single['platforms']);
        $this->assertSame(['account_id' => 'dddddddddddddddddddddddd', 'platform' => 'facebook', 'status' => 'failed', 'url' => null], $single['platforms'][1]);
        $this->assertStringNotContainsString('PRIVATE', json_encode($single));
        Http::assertSent(fn (Request $request): bool => ($request['profileId'] ?? null) === self::PROFILE && $request['accountId'] === self::ACCOUNT && $request['page'] === 1 && $request['limit'] === 25);
    }

    #[DataProvider('platforms')]
    public function test_text_publish_uses_the_exact_contract(string $platform): void
    {
        Http::fake([
            'zernio.com/api/v1/posts' => Http::response(['post' => $this->postBody($platform)], 201),
        ]);
        $result = (new ZernioApiClient)->publishText(self::PROFILE, self::ACCOUNT, $platform, 'Hello', 'logical-post');
        $this->assertSame('published', $result['status']);
        $this->assertSame(self::PROFILE, $result['profile_id']);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://zernio.com/api/v1/posts'
            && $request->hasHeader('Idempotency-Key', 'logical-post')
            && $request->data() === ['content' => 'Hello', 'publishNow' => true, 'platforms' => [['platform' => $platform, 'accountId' => self::ACCOUNT]]]);
        Http::assertSentCount(1);
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'GET');
    }

    public static function platforms(): array
    {
        return array_map(static fn (string $platform): array => [$platform], ['linkedin', 'facebook', 'threads', 'bluesky']);
    }

    #[DataProvider('publishFailures')]
    public function test_publish_failures_are_safe_and_never_retried(int $status, string $variant, bool $ambiguous): void
    {
        $post = $this->postBody();
        if ($variant === 'missing-target') {
            $post['platforms'] = [];
        } elseif ($variant === 'pending') {
            $post['platforms'][0]['status'] = 'pending';
        } elseif ($variant === 'wrong-account') {
            $post['platforms'][0]['accountId'] = 'dddddddddddddddddddddddd';
        } elseif ($variant === 'bad-date') {
            $post['createdAt'] = 'tomorrow';
        }
        Http::fake([
            'zernio.com/api/v1/posts' => $variant === 'timeout' ? Http::failedConnection('PRIVATE test-key') : Http::response($variant === 'invalid-json' ? '{PRIVATE' : ['post' => $post, 'error' => 'PRIVATE test-key'], $status),
        ]);
        try {
            (new ZernioApiClient)->publishText(self::PROFILE, self::ACCOUNT, 'linkedin', 'Hello', 'logical-post');
            $this->fail('Publish must throw.');
        } catch (ZernioApiException $exception) {
            $this->assertSame($ambiguous, $exception->ambiguous);
            $this->assertStringNotContainsString('PRIVATE', $exception->getMessage());
            $this->assertStringNotContainsString('test-key', $exception->getMessage());
            $this->assertNull($exception->getPrevious());
            if (in_array($variant, ['missing-target', 'pending', 'wrong-account', 'bad-date', 'partial'], true)) {
                $this->assertSame(self::POST, $exception->recoveryPostId);
            }
        }
        $this->assertLessThanOrEqual(1, Http::recorded()->count());
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'GET');
    }

    public static function publishFailures(): array
    {
        return [[201, 'missing-target', true], [200, 'pending', true], [201, 'wrong-account', true], [201, 'bad-date', true], [207, 'partial', true], [503, 'server', true], [408, 'timeout-status', true], [202, 'accepted', true], [201, 'invalid-json', true], [200, 'timeout', true], [401, 'rejected', false], [429, 'rejected', false], [302, 'redirect', true]];
    }

    #[DataProvider('invalidInputs')]
    public function test_invalid_inputs_do_not_send_requests(string $method, array $arguments): void
    {
        $this->expectException(ZernioApiException::class);
        try {
            (new ZernioApiClient)->{$method}(...$arguments);
        } finally {
            Http::assertNothingSent();
        }
    }

    public static function invalidInputs(): array
    {
        return [
            ['accounts', ['all']], ['accounts', [self::PROFILE, 0]], ['accounts', [self::PROFILE, 1001]],
            ['accounts', [self::PROFILE, 1, 101]], ['accounts', [self::PROFILE, 1, 0]],
            ['post', ['https://evil.test']], ['posts', [self::PROFILE, 'all']],
            ['publishText', [self::PROFILE, self::ACCOUNT, 'instagram', 'Hello', 'key']],
            ['publishText', [self::PROFILE, self::ACCOUNT, 'linkedin', ' ', 'key']],
            ['publishText', [self::PROFILE, self::ACCOUNT, 'linkedin', 'Hello', "key\r\nInjected: value"]],
            ['publishText', [self::PROFILE, self::ACCOUNT, 'linkedin', 'Hello', str_repeat('k', 256)]],
        ];
    }

    public function test_configuration_gates_fail_closed(): void
    {
        foreach (['enabled' => false, 'api_key' => '', 'publishing_enabled' => false] as $key => $value) {
            config(['agent-api.zernio.'.$key => $value]);
            try {
                (new ZernioApiClient)->publishText(self::PROFILE, self::ACCOUNT, 'linkedin', 'Hello', 'key');
                $this->fail('Configuration must block publishing.');
            } catch (ZernioApiException $exception) {
                $this->assertFalse($exception->ambiguous);
            }
            config(['agent-api.zernio.'.$key => $key === 'api_key' ? 'test-key' : true]);
        }
        Http::assertNothingSent();
    }

    public function test_account_scope_fails_closed(): void
    {
        $body = $this->accountsBody();
        $body['accounts'][0]['profileId'] = 'dddddddddddddddddddddddd';
        Http::fake(['zernio.com/api/v1/accounts*' => Http::response($body)]);
        $this->expectException(ZernioApiException::class);
        try {
            (new ZernioApiClient)->accounts(self::PROFILE);
        } finally {
            Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST');
        }
    }

    #[DataProvider('malformedReads')]
    public function test_read_schemas_are_strict(mixed $body): void
    {
        Http::fake(['zernio.com/api/v1/posts/*' => Http::response($body)]);
        $this->expectException(ZernioApiException::class);
        (new ZernioApiClient)->post(self::POST);
    }

    public static function malformedReads(): array
    {
        return [['null'], ['[]'], ['{'], [['post' => null]], [['post' => ['_id' => self::POST, 'status' => 'PRIVATE', 'platforms' => []]]],
            ['{"post":{"_id":"cccccccccccccccccccccccc","status":"draft","platforms":{}}}']];
    }

    public function test_response_body_size_is_bounded_even_with_a_false_length(): void
    {
        Http::fake(['zernio.com/api/v1/posts/*' => Http::response(str_repeat('x', 1048577), 200, ['Content-Length' => '1'])]);
        $this->expectException(ZernioApiException::class);
        (new ZernioApiClient)->post(self::POST);
    }

    public function test_transport_options_and_download_guards(): void
    {
        Http::fake(function (Request $request, array $options) {
            $this->assertSame(5, $options['connect_timeout']);
            $this->assertSame(15, $options['timeout']);
            $this->assertFalse($options['allow_redirects']);
            $this->assertFalse($options['stream']);
            $this->assertFalse($options['decode_content']);
            foreach ([fn () => $options['on_headers'](new Response(200, ['Content-Length' => '1048577'])), fn () => $options['progress'](0, 1048577, 0, 0)] as $guard) {
                try {
                    $guard();
                    $this->fail('Download guard must abort.');
                } catch (ZernioApiException $exception) {
                    $this->assertSame('response_too_large', $exception->reason);
                }
            }

            return Http::response(['post' => $this->postBody()]);
        });
        (new ZernioApiClient)->post(self::POST);
    }

    #[DataProvider('invalidPostFields')]
    public function test_post_fields_are_not_coerced_or_silently_dropped(string $field, mixed $value): void
    {
        $post = $this->postBody();
        data_set($post, $field, $value);
        Http::fake(['zernio.com/api/v1/posts/*' => Http::response(['post' => $post])]);
        $this->expectException(ZernioApiException::class);
        (new ZernioApiClient)->post(self::POST);
    }

    public static function invalidPostFields(): array
    {
        return [['_id', 'bad-id'], ['_id', ['_id' => self::POST]], ['content', ['PRIVATE']], ['status', 'success'], ['createdAt', '2026-02-30T00:00:00Z'],
            ['platforms', ['not-a-list' => []]], ['platforms.0', null], ['platforms.0.platform', 'made-up'],
            ['platforms.0.accountId', null], ['platforms.0.status', null], ['platforms.0.status', 'success'],
            ['platforms.0.platformPostUrl', 'javascript:alert(1)'], ['platforms.0.platformPostUrl', 'https://user:password@example.com'],
            ['platforms.0.platformPostUrl', ['PRIVATE']], ['updatedAt', 123], ['scheduledFor', 'tomorrow']];
    }

    #[DataProvider('invalidAccountFields')]
    public function test_account_envelopes_are_strict(string $field, mixed $value): void
    {
        $body = $this->accountsBody();
        data_set($body, $field, $value);
        Http::fake(['zernio.com/api/v1/accounts*' => Http::response($body)]);
        $this->expectException(ZernioApiException::class);
        (new ZernioApiClient)->accounts(self::PROFILE);
    }

    public static function invalidAccountFields(): array
    {
        return [['accounts', null], ['accounts.0', 'PRIVATE'], ['accounts.0._id', null], ['accounts.0._id', ['_id' => self::ACCOUNT]],
            ['accounts.0.profileId', ['name' => 'PRIVATE']], ['accounts.0.isActive', 'true'],
            ['accounts.0.displayName', ['PRIVATE']], ['accounts.0.platform', 'made-up'],
            ['hasAnalyticsAccess', null], ['pagination', null], ['pagination.total', '1'],
            ['pagination.total', -1], ['pagination.page', 2], ['pagination.limit', 100]];
    }

    #[DataProvider('readFailures')]
    public function test_read_errors_are_safe_unambiguous_and_not_retried(int $status, bool $timeout): void
    {
        Http::fake(['zernio.com/api/v1/posts/*' => $timeout ? Http::failedConnection('PRIVATE test-key') : Http::response('PRIVATE test-key', $status, ['Location' => 'https://evil.test'])]);
        try {
            (new ZernioApiClient)->post(self::POST);
            $this->fail('Read must throw.');
        } catch (ZernioApiException $exception) {
            $this->assertFalse($exception->ambiguous);
            $this->assertNull($exception->recoveryPostId);
            $this->assertNull($exception->getPrevious());
            $this->assertStringNotContainsString('PRIVATE', $exception->getMessage());
            $this->assertStringNotContainsString('test-key', $exception->getMessage());
        }
        $this->assertLessThanOrEqual(1, Http::recorded()->count());
    }

    public static function readFailures(): array
    {
        return [[401, false], [403, false], [404, false], [429, false], [503, false], [302, false], [200, true]];
    }

    public function test_idempotent_replay_requires_explicit_publication_not_a_url(): void
    {
        $post = $this->postBody();
        unset($post['platforms'][0]['platformPostUrl']);
        Http::fake([
            'zernio.com/api/v1/posts' => Http::response(['post' => $post], 200),
        ]);
        $result = (new ZernioApiClient)->publishText(self::PROFILE, self::ACCOUNT, 'linkedin', 'Hello', 'key');
        $this->assertSame('published', $result['platforms'][0]['status']);
        $this->assertNull($result['platforms'][0]['url']);
    }

    public function test_id_only_publish_response_preserves_recovery_without_claiming_success(): void
    {
        Http::fake([
            'zernio.com/api/v1/posts' => Http::response(['post' => ['_id' => self::POST]], 201),
        ]);
        try {
            (new ZernioApiClient)->publishText(self::PROFILE, self::ACCOUNT, 'linkedin', 'Hello', 'key');
            $this->fail('An ID is not publication.');
        } catch (ZernioApiException $exception) {
            $this->assertTrue($exception->ambiguous);
            $this->assertSame(self::POST, $exception->recoveryPostId);
            $this->assertStringNotContainsString(self::POST, $exception->getMessage());
        }
        Http::assertSentCount(1);
    }

    #[DataProvider('textLimits')]
    public function test_maximum_text_is_published_and_read_without_preflight(string $platform, int $limit, string $character): void
    {
        $content = str_repeat($character, $limit);
        $post = $this->postBody($platform);
        $post['content'] = $content;
        Http::fake([
            'zernio.com/api/v1/posts' => Http::response(['post' => $post], 201),
            'zernio.com/api/v1/posts?*' => Http::response(['posts' => [$post], 'pagination' => $this->pagination()]),
            'zernio.com/api/v1/posts/'.self::POST => Http::response(['post' => $post]),
        ]);
        $client = new ZernioApiClient;
        $this->assertSame($content, $client->publishText(self::PROFILE, self::ACCOUNT, $platform, $content, 'key')['content']);
        Http::assertSentCount(1);
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'GET');
        $this->assertSame($content, $client->post(self::POST)['content']);
        $this->assertSame($content, $client->posts(self::PROFILE, self::ACCOUNT)['posts'][0]['content']);
    }

    #[DataProvider('textLimits')]
    public function test_overlong_text_is_rejected_without_io(string $platform, int $limit, string $character): void
    {
        try {
            (new ZernioApiClient)->publishText(self::PROFILE, self::ACCOUNT, $platform, str_repeat($character, $limit + 1), 'key');
            $this->fail('Text above the platform character limit must be rejected.');
        } catch (ZernioApiException $exception) {
            $this->assertSame('invalid_input', $exception->reason);
            $this->assertFalse($exception->ambiguous);
        } finally {
            Http::assertNothingSent();
        }
    }

    public static function textLimits(): array
    {
        $cases = [];
        foreach (['linkedin' => 3000, 'facebook' => 63206, 'threads' => 500, 'bluesky' => 300] as $platform => $limit) {
            foreach (['x', "\u{1F600}"] as $character) {
                $cases[] = [$platform, $limit, $character];
            }
        }

        return $cases;
    }

    #[DataProvider('profileEvidence')]
    public function test_single_post_uses_only_observed_profile_evidence(?string $root, array $nested, ?string $expected): void
    {
        $post = $this->postBody();
        if ($root !== null) {
            $post['profileId'] = $root;
        }
        $post['platforms'] = [];
        foreach ($nested as $profile) {
            $post['platforms'][] = ['accountId' => $profile === null ? self::ACCOUNT : ['_id' => self::ACCOUNT, 'profileId' => ['_id' => $profile]], 'platform' => 'linkedin', 'status' => 'published'];
        }
        Http::fake(['zernio.com/api/v1/posts/*' => Http::response(['post' => $post])]);
        $this->assertSame($expected, (new ZernioApiClient)->post(self::POST)['profile_id']);
        Http::assertSentCount(1);
    }

    public static function profileEvidence(): array
    {
        return [[self::PROFILE, [null], self::PROFILE], [null, [self::PROFILE], self::PROFILE],
            [null, [self::PROFILE, self::PROFILE], self::PROFILE], [self::PROFILE, [self::PROFILE, null], self::PROFILE],
            [null, [null], null], [null, [], null], [null, [self::PROFILE, null], null],
            ['dddddddddddddddddddddddd', [null], 'dddddddddddddddddddddddd']];
    }

    #[DataProvider('invalidProfileAssertions')]
    public function test_all_explicit_profile_assertions_are_validated(string $method, ?string $root, ?string $nested): void
    {
        $post = $this->postBody();
        $rawPost = json_encode($post, JSON_THROW_ON_ERROR);
        if ($root !== null) {
            $rawPost = substr_replace($rawPost, '"profileId":'.$root.',', 1, 0);
        }
        if ($nested !== null) {
            $rawPost = str_replace('"accountId":"'.self::ACCOUNT.'"', '"accountId":{"_id":"'.self::ACCOUNT.'","profileId":'.$nested.'}', $rawPost);
        }
        $raw = $method === 'posts' ? '{"posts":['.$rawPost.'],"pagination":'.json_encode($this->pagination()).'}' : '{"post":'.$rawPost.'}';
        Http::fake(['zernio.com/api/v1/posts*' => Http::response($raw, $method === 'publishText' ? 201 : 200)]);
        try {
            $client = new ZernioApiClient;
            match ($method) {
                'post' => $client->post(self::POST),
                'posts' => $client->posts(self::PROFILE, self::ACCOUNT),
                'publishText' => $client->publishText(self::PROFILE, self::ACCOUNT, 'linkedin', 'Hello', 'key'),
            };
            $this->fail('Invalid or contradictory profile evidence must be rejected.');
        } catch (ZernioApiException $exception) {
            $this->assertSame($method === 'publishText', $exception->ambiguous);
            if ($method === 'publishText') {
                $this->assertSame(self::POST, $exception->recoveryPostId);
            }
        }
        Http::assertSentCount(1);
    }

    public static function invalidProfileAssertions(): array
    {
        $cases = [];
        foreach (['post', 'posts', 'publishText'] as $method) {
            foreach (['null', 'false', '123', '""', '"all"', '[]', '{}', '{"_id":null}'] as $invalid) {
                $cases[] = [$method, $invalid, null];
                $cases[] = [$method, null, $invalid];
            }
            $cases[] = [$method, '"'.self::PROFILE.'"', '"dddddddddddddddddddddddd"'];
            if ($method !== 'post') {
                $cases[] = [$method, '"dddddddddddddddddddddddd"', null];
                $cases[] = [$method, null, '"dddddddddddddddddddddddd"'];
            }
        }

        return $cases;
    }

    public function test_conflicting_nested_profiles_are_rejected_without_root_evidence(): void
    {
        $post = $this->postBody();
        $post['platforms'][0]['accountId'] = ['_id' => self::ACCOUNT, 'profileId' => self::PROFILE];
        $post['platforms'][] = ['accountId' => ['_id' => self::ACCOUNT, 'profileId' => 'dddddddddddddddddddddddd'], 'platform' => 'facebook', 'status' => 'failed'];
        Http::fake(['zernio.com/api/v1/posts/*' => Http::response(['post' => $post])]);
        $this->expectException(ZernioApiException::class);
        (new ZernioApiClient)->post(self::POST);
    }

    public function test_reads_do_not_require_the_publishing_switch_and_accept_maximum_pagination(): void
    {
        config(['agent-api.zernio.publishing_enabled' => false, 'agent-api.zernio.base_url' => 'https://evil.test']);
        Http::fake(['zernio.com/api/v1/accounts*' => Http::response([
            'accounts' => [], 'hasAnalyticsAccess' => false,
            'pagination' => ['page' => 1000, 'limit' => 100, 'total' => 0, 'pages' => 0],
        ])]);
        $result = (new ZernioApiClient)->accounts(self::PROFILE, 1000, 100);
        $this->assertSame([], $result['accounts']);
        Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://zernio.com/api/v1/accounts?') && ! isset($request['search']));
    }

    #[DataProvider('analyticsStates')]
    public function test_analytics_exact_contract_is_read_only(int $httpStatus, string $status, string $syncStatus, ?array $metrics): void
    {
        config(['agent-api.zernio.publishing_enabled' => false, 'agent-api.zernio.base_url' => 'https://evil.test']);
        $body = $this->analyticsBody();
        $body['status'] = $status;
        $body['syncStatus'] = $syncStatus;
        $body['analytics'] = $metrics;
        $body['platformAnalytics'][0]['status'] = $status === 'failed' ? 'failed' : 'published';
        $targetSyncStatus = $syncStatus === 'partial' ? 'synced' : $syncStatus;
        $body['platformAnalytics'][0]['syncStatus'] = $targetSyncStatus;
        $body['platformAnalytics'][0]['analytics'] = $metrics;
        Http::fake(['zernio.com/api/v1/analytics*' => Http::response($body, $httpStatus)]);
        $result = (new ZernioApiClient)->postAnalytics(self::PROFILE, self::ACCOUNT, self::POST);
        $expectedMetrics = $metrics === null ? null : (object) $metrics;
        $this->assertEquals([
            'post_id' => self::POST, 'zernio_post_id' => null, 'status' => $status, 'sync_status' => $syncStatus,
            'analytics' => $expectedMetrics, 'platform_analytics' => [[
                'account_id' => self::ACCOUNT, 'platform' => 'linkedin', 'status' => $body['platformAnalytics'][0]['status'],
                'sync_status' => $targetSyncStatus, 'analytics' => $expectedMetrics, 'error_code' => null, 'is_owner' => null,
            ]], 'upstream_status' => $httpStatus, 'source' => 'zernio', 'profile_id' => self::PROFILE,
        ], $result);
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && $request->url() === 'https://zernio.com/api/v1/analytics?'.http_build_query(['profileId' => self::PROFILE, 'accountId' => self::ACCOUNT, 'postId' => self::POST])
            && $request->hasHeader('Authorization', 'Bearer test-key')
            && $request->data() === ['profileId' => self::PROFILE, 'accountId' => self::ACCOUNT, 'postId' => self::POST]);
        Http::assertNotSent(fn (Request $request): bool => $request->method() !== 'GET');
    }

    public static function analyticsStates(): array
    {
        return [[200, 'published', 'synced', ['likes' => 0]], [202, 'publishing', 'pending', null],
            [424, 'failed', 'unavailable', null], [200, 'partial', 'partial', ['views' => 3]]];
    }

    public function test_analytics_preserve_all_documented_metrics_at_collection_limits(): void
    {
        $body = $this->analyticsBody();
        $metrics = array_fill_keys(['impressions', 'reach', 'likes', 'comments', 'shares', 'saves', 'clicks', 'views',
            'igReelsAvgWatchTime', 'igReelsVideoViewTotalTime', 'profileViews', 'websiteClicks', 'replays', 'reposts',
            'follows', 'videoDurationSeconds'], 0);
        $metrics += ['engagementRate' => 0, 'reelsSkipRate' => 0.0, 'completionRate' => 0.5,
            'impressionSources' => [], 'audienceTypes' => [], 'audienceCountries' => [],
            'retentionCurve' => array_fill(0, 100, 0), 'lastUpdated' => '2026-10-22T10:00:00.123+01:00'];
        $body['analytics'] = $metrics;
        foreach (['impressionSources', 'audienceTypes', 'audienceCountries', 'retentionCurve'] as $field) {
            $body['analytics'][$field] = (object) $metrics[$field];
        }
        $body['platformAnalytics'] = array_fill(0, 100, $body['platformAnalytics'][0]);
        Http::fake(['zernio.com/api/v1/analytics*' => Http::response(json_encode($body, JSON_PRESERVE_ZERO_FRACTION))]);
        $result = (new ZernioApiClient)->postAnalytics(self::PROFILE, self::ACCOUNT, self::POST);
        $this->assertEquals((object) $body['analytics'], $result['analytics']);
        $this->assertCount(100, $result['platform_analytics']);
        Http::assertSentCount(1);
    }

    public function test_absent_analytics_remain_unknown_without_fabricated_metrics(): void
    {
        $body = $this->analyticsBody();
        unset($body['analytics'], $body['platformAnalytics'][0]['analytics'], $body['latePostId']);
        Http::fake(['zernio.com/api/v1/analytics*' => Http::response($body)]);
        $result = (new ZernioApiClient)->postAnalytics(self::PROFILE, self::ACCOUNT, self::POST);
        $this->assertNull($result['analytics']);
        $this->assertNull($result['platform_analytics'][0]['analytics']);
        $this->assertNull($result['zernio_post_id']);
        Http::assertSentCount(1);
    }

    public function test_analytics_preserve_external_identity_and_every_target_but_project_only_safe_fields(): void
    {
        $body = $this->analyticsBody();
        $body['postId'] = 'dddddddddddddddddddddddd';
        $body['latePostId'] = self::POST;
        $body['profileId'] = self::PROFILE;
        $metrics = ['likes' => 0, 'follows' => null, 'videoDurationSeconds' => null, 'engagementRate' => 1.25,
            'reelsSkipRate' => 3, 'completionRate' => 0.5, 'lastUpdated' => '2026-10-22T10:00:00Z',
            'impressionSources' => ['forYou' => 0.5], 'audienceTypes' => ['newViewer' => 1],
            'audienceCountries' => ['US' => 0.5], 'retentionCurve' => ['0' => 1, '4' => 0.5]];
        foreach (['impressionSources', 'audienceTypes', 'audienceCountries', 'retentionCurve'] as $field) {
            $metrics[$field] = (object) $metrics[$field];
        }
        $body['analytics'] = $metrics + ['accessToken' => 'PRIVATE'];
        $body['platformAnalytics'][0]['analytics'] = $body['analytics'];
        $body['platformAnalytics'][0]['isOwner'] = false;
        $body['platformAnalytics'][0]['errorCode'] = 'not_post_owner';
        $body['platformAnalytics'][] = ['accountId' => 'eeeeeeeeeeeeeeeeeeeeeeee', 'platform' => 'facebook',
            'status' => 'failed', 'syncStatus' => 'unavailable', 'errorMessage' => 'PRIVATE'];
        Http::fake(['zernio.com/api/v1/analytics*' => Http::response($body)]);
        $result = (new ZernioApiClient)->postAnalytics(self::PROFILE, self::ACCOUNT, self::POST);
        $this->assertSame($body['postId'], $result['post_id']);
        $this->assertSame(self::POST, $result['zernio_post_id']);
        $this->assertEquals((object) $metrics, $result['analytics']);
        $this->assertEquals($result['analytics'], $result['platform_analytics'][0]['analytics']);
        $this->assertFalse($result['platform_analytics'][0]['is_owner']);
        $this->assertSame('not_post_owner', $result['platform_analytics'][0]['error_code']);
        $this->assertSame(['account_id' => 'eeeeeeeeeeeeeeeeeeeeeeee', 'platform' => 'facebook', 'status' => 'failed',
            'sync_status' => 'unavailable', 'analytics' => null, 'error_code' => null, 'is_owner' => null], $result['platform_analytics'][1]);
        $this->assertStringNotContainsString('PRIVATE', json_encode($result));
        Http::assertSentCount(1);
    }

    #[DataProvider('invalidAnalytics')]
    public function test_analytics_fail_closed_on_invalid_or_unscoped_responses(string $field, mixed $value, string $reason = 'malformed_response'): void
    {
        $body = $this->analyticsBody();
        data_set($body, $field, $value);
        Http::fake(['zernio.com/api/v1/analytics*' => Http::response($body)]);
        try {
            (new ZernioApiClient)->postAnalytics(self::PROFILE, self::ACCOUNT, self::POST);
            $this->fail('Invalid analytics must throw.');
        } catch (ZernioApiException $exception) {
            $this->assertSame($reason, $exception->reason);
            $this->assertFalse($exception->ambiguous);
            $this->assertNull($exception->getPrevious());
            $this->assertStringNotContainsString('PRIVATE', $exception->getMessage());
        }
        Http::assertSentCount(1);
    }

    public static function invalidAnalytics(): array
    {
        return [
            ['postId', 'dddddddddddddddddddddddd', 'scope_mismatch'], ['postId', null], ['latePostId', 'bad'],
            ['profileId', 'dddddddddddddddddddddddd', 'scope_mismatch'], ['profileId', null], ['profileId', ['_id' => self::PROFILE]],
            ['status', 'PRIVATE'], ['syncStatus', 'PRIVATE'], ['platformAnalytics', [], 'scope_mismatch'],
            ['platformAnalytics', array_fill(0, 101, [])], ['platformAnalytics', (object) []], ['platformAnalytics.0', null],
            ['platformAnalytics.0.accountId', null], ['platformAnalytics.0.accountId', ['_id' => self::ACCOUNT]],
            ['platformAnalytics.0.accountId', 'dddddddddddddddddddddddd', 'scope_mismatch'],
            ['platformAnalytics.0.platform', 'PRIVATE'], ['platformAnalytics.0.status', 'pending'],
            ['platformAnalytics.0.syncStatus', 'partial'], ['platformAnalytics.0.isOwner', 1],
            ['platformAnalytics.0.errorCode', 'PRIVATE test-key'], ['analytics', []], ['analytics', 'PRIVATE'],
            ['analytics.likes', null], ['analytics.likes', '0'], ['analytics.likes', -1], ['analytics.likes', 1.5],
            ['analytics.follows', -1], ['analytics.videoDurationSeconds', false], ['analytics.engagementRate', '1'],
            ['analytics.completionRate', -1], ['analytics.reelsSkipRate', null], ['analytics.lastUpdated', '2026-02-30T00:00:00Z'],
            ['analytics.lastUpdated', null], ['analytics.impressionSources', []], ['analytics.audienceTypes', ["bad\nkey" => 1]],
            ['analytics.audienceCountries', ['US' => -1]], ['analytics.retentionCurve', ["bad\x7fkey" => 1]],
            ['analytics.impressionSources', (object) array_fill_keys(range(0, 100), 1)],
            ['platformAnalytics.0.analytics', ['likes' => 'PRIVATE']],
            ['platformAnalytics.1', ['accountId' => 'bad', 'platform' => 'facebook', 'status' => 'published', 'syncStatus' => 'synced']],
            ['analytics.impressionSources', [str_repeat('a', 129) => 1]],
            ['analytics.retentionCurve', ['0' => '1']],
            ['analytics.audienceTypes', ["bad\u{0085}key" => 1]],
        ];
    }

    #[DataProvider('analyticsObjects')]
    public function test_analytics_and_maps_remain_objects_after_json_serialization(stdClass $upstream, stdClass $expected): void
    {
        $body = $this->analyticsBody();
        $body['analytics'] = $upstream;
        $body['platformAnalytics'][0]['analytics'] = $upstream;
        Http::fake(['zernio.com/api/v1/analytics*' => Http::response($body)]);
        $result = (new ZernioApiClient)->postAnalytics(self::PROFILE, self::ACCOUNT, self::POST);
        $encoded = json_encode($result, JSON_THROW_ON_ERROR);
        $decoded = json_decode($encoded, false, 512, JSON_THROW_ON_ERROR);
        foreach ([$result['analytics'], $result['platform_analytics'][0]['analytics'],
            $decoded->analytics, $decoded->platform_analytics[0]->analytics] as $metrics) {
            $this->assertInstanceOf(stdClass::class, $metrics);
            $this->assertEquals($expected, $metrics);
            foreach (['impressionSources', 'audienceTypes', 'audienceCountries', 'retentionCurve'] as $field) {
                if (property_exists($expected, $field)) {
                    $this->assertInstanceOf(stdClass::class, $metrics->{$field});
                    $this->assertSame(array_keys((array) $expected->{$field}), array_keys((array) $metrics->{$field}));
                }
            }
        }
        Http::assertSentCount(1);
    }

    public static function analyticsObjects(): array
    {
        $emptyMaps = (object) array_fill_keys(['impressionSources', 'audienceTypes', 'audienceCountries', 'retentionCurve'], (object) []);
        $contiguous = (object) ['retentionCurve' => (object) ['0' => 1, '1' => 0.75, '2' => 0.5]];
        $sparse = (object) ['retentionCurve' => (object) ['0' => 1, '4' => 0.5]];
        $openKeys = (object) [
            'impressionSources' => (object) ['non-follower' => 0.25, str_repeat('a', 128) => 0.75],
            'audienceTypes' => (object) ["\u{00e9}" => 0.5, 'other' => 0.5],
            'audienceCountries' => (object) ['non-follower' => 0.25, 'other' => 0.75],
            'retentionCurve' => (object) ['1234567890' => 0.25, 'other' => 0.75],
        ];

        return [
            'empty' => [(object) [], (object) []],
            'empty maps' => [$emptyMaps, $emptyMaps],
            'contiguous seconds' => [$contiguous, $contiguous],
            'sparse seconds' => [$sparse, $sparse],
            'unknown fields only' => [(object) ['accessToken' => 'PRIVATE', 'metadata' => (object) ['secret' => 'PRIVATE']], (object) []],
            'open bounded keys' => [$openKeys, $openKeys],
        ];
    }

    #[DataProvider('analyticsErrors')]
    public function test_analytics_errors_are_safe_and_never_retried(int $status, string $body, string $reason): void
    {
        Http::fake(['zernio.com/api/v1/analytics*' => Http::response($body, $status, ['Content-Length' => '1'])]);
        try {
            (new ZernioApiClient)->postAnalytics(self::PROFILE, self::ACCOUNT, self::POST);
            $this->fail('Analytics must throw.');
        } catch (ZernioApiException $exception) {
            $this->assertSame($reason, $exception->reason);
            $this->assertFalse($exception->ambiguous);
            $this->assertNull($exception->recoveryPostId);
            $this->assertNull($exception->getPrevious());
            $this->assertStringNotContainsString('PRIVATE', $exception->getMessage());
            $this->assertStringNotContainsString('test-key', $exception->getMessage());
        }
        Http::assertSentCount(1);
        Http::assertNotSent(fn (Request $request): bool => $request->method() !== 'GET');
    }

    public static function analyticsErrors(): array
    {
        return [[402, 'PRIVATE test-key', 'analytics_access_required'], [404, 'PRIVATE test-key', 'not_found'],
            [403, 'PRIVATE test-key', 'http_error'], [200, '{PRIVATE test-key', 'malformed_response'],
            [200, 'null', 'malformed_response'], [200, '[]', 'malformed_response'],
            [201, '{}', 'http_error'], [302, '{}', 'http_error'], [503, '{}', 'http_error'],
            [200, str_repeat('x', 1048577), 'response_too_large']];
    }

    public function test_analytics_nonfinite_numbers_are_rejected(): void
    {
        $body = $this->analyticsBody();
        $body['analytics'] = ['engagementRate' => 'NONFINITE'];
        Http::fake(['zernio.com/api/v1/analytics*' => Http::response(str_replace('"NONFINITE"', '1e999', json_encode($body)))]);
        $this->expectException(ZernioApiException::class);
        (new ZernioApiClient)->postAnalytics(self::PROFILE, self::ACCOUNT, self::POST);
    }

    public function test_analytics_connection_failure_is_safe_without_retry(): void
    {
        $calls = 0;
        Http::fake(function (Request $request, array $options) use (&$calls) {
            $calls++;
            $this->assertSame('GET', $request->method());
            $this->assertSame(5, $options['connect_timeout']);
            $this->assertSame(15, $options['timeout']);
            $this->assertFalse($options['allow_redirects']);

            return Http::failedConnection('PRIVATE test-key');
        });
        try {
            (new ZernioApiClient)->postAnalytics(self::PROFILE, self::ACCOUNT, self::POST);
            $this->fail('A connection failure must throw.');
        } catch (ZernioApiException $exception) {
            $this->assertSame('transport_error', $exception->reason);
            $this->assertFalse($exception->ambiguous);
            $this->assertNull($exception->getPrevious());
            $this->assertStringNotContainsString('PRIVATE', $exception->getMessage());
        }
        $this->assertSame(1, $calls);
    }

    #[DataProvider('nonAnalyticsStatuses')]
    public function test_analytics_status_policy_does_not_change_other_reads(int $status): void
    {
        Http::fake(['zernio.com/api/v1/posts/*' => Http::response(['post' => $this->postBody()], $status)]);
        try {
            (new ZernioApiClient)->post(self::POST);
            $this->fail('Other reads must retain their status policy.');
        } catch (ZernioApiException $exception) {
            $this->assertSame('http_error', $exception->reason);
        }
        Http::assertSentCount(1);
    }

    public static function nonAnalyticsStatuses(): array
    {
        return [[202], [424], [402], [404]];
    }

    #[DataProvider('invalidAnalyticsInputs')]
    public function test_analytics_validate_all_ids_before_io(string $profile, string $account, string $post): void
    {
        $this->expectException(ZernioApiException::class);
        try {
            (new ZernioApiClient)->postAnalytics($profile, $account, $post);
        } finally {
            Http::assertNothingSent();
        }
    }

    public static function invalidAnalyticsInputs(): array
    {
        return [['all', self::ACCOUNT, self::POST], [self::PROFILE, strtoupper(self::ACCOUNT), self::POST],
            [self::PROFILE, self::ACCOUNT, 'https://evil.test']];
    }

    public function test_profiles_discovery_reads_only_one_page_and_projects_metadata_without_accounts(): void
    {
        config(['agent-api.zernio.publishing_enabled' => false, 'agent-api.zernio.base_url' => 'https://evil.test']);
        Http::fake(['zernio.com/api/v1/profiles*' => Http::response([
            'profiles' => [['_id' => self::PROFILE, 'name' => 'Team', 'accountCount' => 0,
                'userId' => 'PRIVATE', 'description' => 'PRIVATE', 'apiKey' => 'PRIVATE'],
                ['_id' => self::ACCOUNT, 'name' => 'Other', 'accountCount' => 200]],
            'total' => 51, 'skip' => 25, 'limit' => 25, 'secret' => 'PRIVATE',
        ])]);

        $this->assertSame(['profiles' => [
            ['id' => self::PROFILE, 'name' => 'Team', 'account_count' => 0],
            ['id' => self::ACCOUNT, 'name' => 'Other', 'account_count' => 200],
        ], 'pagination' => ['page' => 2, 'limit' => 25, 'total' => 51, 'pages' => 3]], (new ZernioApiClient)->profiles(2));
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && $request->url() === 'https://zernio.com/api/v1/profiles?limit=25&skip=25'
            && $request->hasHeader('Authorization', 'Bearer test-key')
            && $request->data() === ['limit' => 25, 'skip' => 25]);
    }

    #[DataProvider('profilePages')]
    public function test_profiles_send_explicit_bounds_and_optional_exact_name(int $page, int $limit, ?string $name, int $total): void
    {
        $skip = ($page - 1) * $limit;
        Http::fake(['zernio.com/api/v1/profiles*' => Http::response(['profiles' => [], 'total' => $total, 'skip' => $skip, 'limit' => $limit])]);
        $result = (new ZernioApiClient)->profiles($page, $limit, $name);
        $this->assertSame(['profiles' => [], 'pagination' => ['page' => $page, 'limit' => $limit,
            'total' => $total, 'pages' => (int) ceil($total / $limit)]], $result);
        $expected = ['limit' => $limit, 'skip' => $skip];
        if ($name !== null) {
            $expected['name'] = trim($name);
        }
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET' && $request->data() === $expected);
    }

    public static function profilePages(): array
    {
        return [[1, 25, null, 0], [1000, 100, null, 0], [1, 1, ' Team & Co ', 1],
            [1, 25, str_repeat("\u{00e9}", 100), 0]];
    }

    #[DataProvider('profileCounts')]
    public function test_profile_lookup_requires_matching_identity_and_projects_only_safe_fields(array $count, ?int $expected): void
    {
        Http::fake(['zernio.com/api/v1/profiles/*' => Http::response(['profile' => $count + [
            '_id' => self::PROFILE, 'name' => str_repeat("\u{00e9}", 512), 'userId' => 'PRIVATE',
            'description' => 'PRIVATE', 'accessToken' => 'PRIVATE', 'accounts' => ['PRIVATE'],
        ], 'apiKey' => 'PRIVATE'])]);
        $this->assertSame(['id' => self::PROFILE, 'name' => str_repeat("\u{00e9}", 512),
            'account_count' => $expected], (new ZernioApiClient)->profile(self::PROFILE));
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && $request->url() === 'https://zernio.com/api/v1/profiles/'.self::PROFILE && $request->data() === []);
    }

    public static function profileCounts(): array
    {
        return [[[], null], [['accountCount' => null], null], [['accountCount' => 0], 0], [['accountCount' => 10], 10]];
    }

    #[DataProvider('invalidProfileInputs')]
    public function test_profile_inputs_fail_without_io(string $method, array $arguments): void
    {
        try {
            (new ZernioApiClient)->{$method}(...$arguments);
            $this->fail('Invalid profile input must throw.');
        } catch (ZernioApiException $exception) {
            $this->assertSame('invalid_input', $exception->reason);
        }
        Http::assertNothingSent();
    }

    public static function invalidProfileInputs(): array
    {
        $cases = [['profiles', [0]], ['profiles', [1001]], ['profiles', [1, 0]], ['profiles', [1, 101]]];
        foreach (['', '   ', "Team\n", "\tTeam", "bad\x7f", "bad\u{0085}", "\xff", str_repeat('a', 101), str_repeat("\u{00e9}", 101)] as $name) {
            $cases[] = ['profiles', [1, 25, $name]];
        }
        foreach (['', 'all', strtoupper(self::PROFILE), self::PROFILE.'/', 'https://evil.test', str_repeat('a', 23), str_repeat('g', 24)] as $id) {
            $cases[] = ['profile', [$id]];
        }

        return $cases;
    }

    #[DataProvider('invalidProfileResponses')]
    public function test_profile_projection_is_strict_for_list_and_lookup(string $method, mixed $profile): void
    {
        $body = $method === 'profiles' ? ['profiles' => [$profile], 'total' => 1, 'skip' => 0, 'limit' => 25] : ['profile' => $profile];
        Http::fake(['zernio.com/api/v1/profiles*' => Http::response($body)]);
        try {
            $method === 'profiles' ? (new ZernioApiClient)->profiles() : (new ZernioApiClient)->profile(self::PROFILE);
            $this->fail('Invalid profile must throw.');
        } catch (ZernioApiException $exception) {
            $this->assertSame('malformed_response', $exception->reason);
            $this->assertFalse($exception->ambiguous);
        }
        Http::assertSentCount(1);
    }

    public static function invalidProfileResponses(): array
    {
        $valid = ['_id' => self::PROFILE, 'name' => 'Team'];
        $profiles = [null, [], 'PRIVATE', ['_id' => self::PROFILE], ['name' => 'Team']];
        foreach (['_id' => [null, 'bad', strtoupper(self::PROFILE), ['_id' => self::PROFILE]],
            'name' => [null, '', '   ', 123, [], "bad\n", "bad\u{0085}", str_repeat('a', 1025)],
            'accountCount' => [-1, '0', false, 1.5, []]] as $field => $values) {
            foreach ($values as $value) {
                $profiles[] = array_replace($valid, [$field => $value]);
            }
        }
        $cases = [];
        foreach (['profiles', 'profile'] as $method) {
            foreach ($profiles as $profile) {
                $cases[] = [$method, $profile];
            }
        }
        $cases[] = ['profile', ['_id' => self::ACCOUNT, 'name' => 'Foreign']];

        return $cases;
    }

    #[DataProvider('invalidProfilePagination')]
    public function test_profile_pages_require_strict_pagination_echoes(array $body): void
    {
        Http::fake(['zernio.com/api/v1/profiles*' => Http::response($body)]);
        try {
            (new ZernioApiClient)->profiles();
            $this->fail('Invalid pagination must throw.');
        } catch (ZernioApiException $exception) {
            $this->assertSame('malformed_response', $exception->reason);
        }
        Http::assertSentCount(1);
    }

    public static function invalidProfilePagination(): array
    {
        $valid = ['profiles' => [], 'total' => 0, 'skip' => 0, 'limit' => 25];
        $cases = [];
        foreach (['total', 'skip', 'limit'] as $field) {
            $missing = $valid;
            unset($missing[$field]);
            $cases[] = [$missing];
            foreach ([null, -1, '25', 25.5, false] as $value) {
                $cases[] = [array_replace($valid, [$field => $value])];
            }
        }
        foreach (['skip' => 25, 'limit' => 24, 'profiles' => (object) []] as $field => $value) {
            $cases[] = [array_replace($valid, [$field => $value])];
        }
        $cases[] = [array_replace($valid, ['profiles' => array_fill(0, 26, ['_id' => self::PROFILE, 'name' => 'Team'])])];

        return $cases;
    }

    #[DataProvider('profileErrors')]
    public function test_profile_errors_are_safe_bounded_and_never_retried(string $method, int $status, string $body, string $reason): void
    {
        Http::fake(['zernio.com/api/v1/profiles*' => Http::response($body, $status, ['Content-Length' => '1', 'Location' => 'https://evil.test'])]);
        try {
            $method === 'profiles' ? (new ZernioApiClient)->profiles() : (new ZernioApiClient)->profile(self::PROFILE);
            $this->fail('Profile errors must throw.');
        } catch (ZernioApiException $exception) {
            $this->assertSame($reason, $exception->reason);
            $this->assertFalse($exception->ambiguous);
            $this->assertNull($exception->getPrevious());
            $this->assertNull($exception->recoveryPostId);
            $this->assertStringNotContainsString('PRIVATE', $exception->getMessage());
            $this->assertStringNotContainsString('test-key', $exception->getMessage());
        }
        Http::assertSentCount(1);
    }

    public static function profileErrors(): array
    {
        $cases = [];
        foreach (['profiles', 'profile'] as $method) {
            foreach ([[401, 'PRIVATE test-key', 'http_error'], [403, 'PRIVATE test-key', 'http_error'],
                [404, 'PRIVATE test-key', 'not_found'], [402, '{}', 'http_error'], [500, '{}', 'http_error'],
                [302, '{}', 'http_error'], [200, '{PRIVATE test-key', 'malformed_response'],
                [200, 'null', 'malformed_response'], [200, '[]', 'malformed_response'],
                [200, str_repeat('x', 1048577), 'response_too_large']] as $error) {
                $cases[] = [$method, ...$error];
            }
        }

        return $cases;
    }

    #[DataProvider('profileTransports')]
    public function test_profile_transport_reuses_guards_and_never_retries(string $method, bool $timeout): void
    {
        $calls = 0;
        config(['agent-api.zernio.publishing_enabled' => false]);
        Http::fake(function (Request $request, array $options) use ($method, $timeout, &$calls) {
            $calls++;
            $this->assertSame('GET', $request->method());
            $this->assertSame(5, $options['connect_timeout']);
            $this->assertSame(15, $options['timeout']);
            $this->assertFalse($options['allow_redirects']);
            $this->assertFalse($options['stream']);
            $this->assertFalse($options['decode_content']);
            $this->assertTrue($request->hasHeader('Accept-Encoding', 'identity'));
            foreach ([fn () => $options['on_headers'](new Response(200, ['Content-Length' => '1048577'])),
                fn () => $options['progress'](0, 1048577, 0, 0)] as $guard) {
                try {
                    $guard();
                    $this->fail('Profile download guard must abort.');
                } catch (ZernioApiException $exception) {
                    $this->assertSame('response_too_large', $exception->reason);
                }
            }

            return $timeout ? Http::failedConnection('PRIVATE test-key') : Http::response($method === 'profiles'
                ? ['profiles' => [], 'total' => 0, 'skip' => 0, 'limit' => 25]
                : ['profile' => ['_id' => self::PROFILE, 'name' => 'Team']]);
        });
        try {
            $method === 'profiles' ? (new ZernioApiClient)->profiles() : (new ZernioApiClient)->profile(self::PROFILE);
            $this->assertFalse($timeout, 'Connection failure must throw.');
        } catch (ZernioApiException $exception) {
            $this->assertTrue($timeout);
            $this->assertSame('transport_error', $exception->reason);
            $this->assertFalse($exception->ambiguous);
            $this->assertNull($exception->getPrevious());
            $this->assertStringNotContainsString('PRIVATE', $exception->getMessage());
            $this->assertStringNotContainsString('test-key', $exception->getMessage());
        }
        $this->assertSame(1, $calls);
    }

    public static function profileTransports(): array
    {
        return [['profiles', false], ['profiles', true], ['profile', false], ['profile', true]];
    }

    public function test_profiles_configuration_gates_block_all_io(): void
    {
        foreach (['enabled' => false, 'api_key' => ''] as $key => $value) {
            config(['agent-api.zernio.'.$key => $value]);
            foreach (['profiles', 'profile'] as $method) {
                try {
                    $method === 'profiles' ? (new ZernioApiClient)->profiles() : (new ZernioApiClient)->profile(self::PROFILE);
                    $this->fail('Configuration must block profile reads.');
                } catch (ZernioApiException $exception) {
                    $this->assertSame('not_configured', $exception->reason);
                }
            }
            config(['agent-api.zernio.'.$key => $key === 'api_key' ? 'test-key' : true]);
        }
        Http::assertNothingSent();
    }

    private function analyticsBody(): array
    {
        return ['postId' => self::POST, 'latePostId' => null, 'status' => 'published', 'syncStatus' => 'synced',
            'analytics' => ['likes' => 0], 'content' => 'PRIVATE', 'message' => 'PRIVATE', 'isExternal' => true,
            'platformAnalytics' => [['accountId' => self::ACCOUNT, 'platform' => 'linkedin', 'status' => 'published',
                'syncStatus' => 'synced', 'analytics' => ['likes' => 0], 'errorMessage' => 'PRIVATE', 'accessToken' => 'PRIVATE']]];
    }

    private function pagination(): array
    {
        return ['page' => 1, 'limit' => 25, 'total' => 1, 'pages' => 1];
    }

    private function accountsBody(string $platform = 'linkedin'): array
    {
        return ['accounts' => [[
            '_id' => self::ACCOUNT, 'profileId' => ['_id' => self::PROFILE], 'platform' => $platform,
            'username' => 'example', 'displayName' => 'Example', 'isActive' => true, 'accessToken' => 'PRIVATE',
        ]], 'hasAnalyticsAccess' => false, 'pagination' => $this->pagination()];
    }

    private function postBody(string $platform = 'linkedin'): array
    {
        return ['_id' => self::POST, 'content' => 'Hello', 'status' => 'published',
            'createdAt' => '2026-10-22T10:00:00Z', 'metadata' => ['secret' => 'PRIVATE'],
            'platforms' => [['accountId' => self::ACCOUNT, 'platform' => $platform, 'status' => 'published', 'platformPostUrl' => 'https://example.com/post']]];
    }
}
