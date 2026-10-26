<?php

namespace Tests\Feature;

use App\AdKit\AdKitHttpClient;
use App\Exceptions\AdKit\AdKitRequestException;
use App\Models\ProviderConnection;
use App\Models\ProviderProfile;
use GuzzleHttp\Psr7\Stream;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Request as IncomingRequest;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class AdKitHttpClientTest extends TestCase
{
    private const string BASE_URL = 'https://app.adkit.so/api/v1';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.adkit' => [
            'enabled' => true,
            'base_url' => self::BASE_URL,
            'timeout' => 15,
            'connect_timeout' => 5,
            'max_response_bytes' => 1048576,
        ]]);
        Http::preventStrayRequests();
    }

    public function test_returns_validated_connected_accounts_with_project_header(): void
    {
        $accounts = [['id' => 'act_123', 'platform' => 'meta', 'name' => 'Synthetic account', 'currency' => 'USD', 'status' => 'active']];
        Http::fake([self::BASE_URL.'/manage/meta/accounts' => Http::response(['accounts' => $accounts])]);

        $result = (new AdKitHttpClient)->connectedAccounts($this->connection());

        self::assertSame($accounts, $result);
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && $request->url() === self::BASE_URL.'/manage/meta/accounts'
            && $request->hasHeader('Authorization', 'Bearer synthetic-token')
            && $request->hasHeader('x-adkit-project-id', 'project-123')
            && $request->hasHeader('Accept', 'application/json')
            && $request->data() === []);
    }

    public function test_unwraps_executed_meta_response_and_sends_only_read_fields(): void
    {
        Http::fake([self::BASE_URL.'/manage/platform-api-requests' => function (Request $request, array $options) {
            self::assertTrue($options['verify']);
            self::assertFalse($options['allow_redirects']);
            self::assertTrue($options['stream']);
            self::assertSame(15, $options['timeout']);
            self::assertSame(15, $options['read_timeout']);
            self::assertSame(5, $options['connect_timeout']);

            return Http::response(['platform' => 'meta', 'status' => 'executed', 'response' => ['data' => []], 'results' => []]);
        }]);

        $result = (new AdKitHttpClient)->read($this->profile(), '/act_123/campaigns', ['fields' => 'id,name'], 'Read campaigns');

        self::assertSame(['data' => []], $result);
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === self::BASE_URL.'/manage/platform-api-requests'
            && $request->hasHeader('Authorization', 'Bearer synthetic-token')
            && $request->hasHeader('x-adkit-project-id', 'project-123')
            && $request->hasHeader('Content-Type', 'application/json')
            && $request->data() === [
                'platform' => 'meta',
                'accountId' => 'act_123',
                'endpoint' => '/act_123/campaigns',
                'method' => 'GET',
                'description' => 'Read campaigns',
                'payload' => ['fields' => 'id,name'],
            ]);
    }

    public function test_forward_preserves_original_body_and_full_executed_envelope(): void
    {
        $body = ['platform' => 'meta', 'endpoint' => 'act_123/insights', 'method' => 'GET',
            'description' => 'Read stats', 'payload' => ['fields' => 'account_id,spend', 'date_preset' => 'maximum']];
        $envelope = ['platform' => 'meta', 'status' => 'executed', 'response' => ['data' => [['account_id' => '123', 'spend' => '180.28']]], 'results' => []];
        Http::fake([self::BASE_URL.'/manage/platform-api-requests' => Http::response($envelope)]);

        self::assertSame($envelope, (new AdKitHttpClient)->forward($this->profile(), $body));

        Http::assertSent(fn (Request $request): bool => $request->data() === [...$body, 'accountId' => 'act_123']
            && $request->hasHeader('Authorization', 'Bearer synthetic-token')
            && $request->hasHeader('x-adkit-project-id', 'project-123'));
        Http::assertSentCount(1);
    }

    #[DataProvider('invalidConfiguration')]
    public function test_rejects_disabled_or_invalid_configuration_without_http(string $key, mixed $value, string $code): void
    {
        config(['services.adkit.'.$key => $value]);
        Http::fake([self::BASE_URL.'/manage/meta/accounts' => Http::response(['accounts' => []])]);

        $this->assertFailure(fn () => (new AdKitHttpClient)->connectedAccounts($this->connection()), $code, 503);

        Http::assertNothingSent();
    }

    public static function invalidConfiguration(): array
    {
        return [
            'disabled' => ['enabled', false, 'adkit_disabled'],
            'missing enabled' => ['enabled', null, 'adkit_disabled'],
            'missing base' => ['base_url', null, 'adkit_configuration_error'],
            'insecure' => ['base_url', 'http://app.adkit.so/api/v1', 'adkit_configuration_error'],
            'other host' => ['base_url', 'https://evil.test/api/v1', 'adkit_configuration_error'],
            'userinfo' => ['base_url', 'https://secret@app.adkit.so/api/v1', 'adkit_configuration_error'],
            'query' => ['base_url', self::BASE_URL.'?token=secret', 'adkit_configuration_error'],
            'zero timeout' => ['timeout', 0, 'adkit_configuration_error'],
            'missing connect timeout' => ['connect_timeout', null, 'adkit_configuration_error'],
            'invalid size' => ['max_response_bytes', -1, 'adkit_configuration_error'],
        ];
    }

    #[DataProvider('invalidConnections')]
    public function test_rejects_invalid_credentials_without_http(array $attributes): void
    {
        $this->freezeTime();
        $connection = $this->connection();
        $connection->fill($attributes);
        Http::fake([self::BASE_URL.'/manage/meta/accounts' => Http::response(['accounts' => []])]);

        $this->assertFailure(fn () => (new AdKitHttpClient)->connectedAccounts($connection), 'adkit_connection_invalid', 503);

        Http::assertNothingSent();
    }

    public static function invalidConnections(): array
    {
        return [
            'wrong provider' => [['provider' => 'zernio']],
            'missing project' => [['client_id' => null]],
            'blank project' => [['client_id' => ' ']],
            'missing token' => [['access_token' => null]],
            'blank token' => [['access_token' => ' ']],
            'expired' => [['access_token_expires_at' => '2000-01-01T00:00:00Z']],
        ];
    }

    #[DataProvider('invalidMappings')]
    public function test_rejects_invalid_profile_mapping_without_http(string $case): void
    {
        $profile = $this->profile();
        match ($case) {
            'provider' => $profile->provider = 'zernio',
            'project' => $profile->provider_id = 'another-project',
            'connection provider' => $profile->providerConnection->provider = 'zernio',
            'missing connection' => $profile->setRelation('providerConnection', null),
            'bare subject' => $profile->providerConnection->subject = '123',
            'invalid subject' => $profile->providerConnection->subject = 'act_123/ads',
            'missing subject' => $profile->providerConnection->subject = null,
        };
        Http::fake([self::BASE_URL.'/manage/platform-api-requests' => Http::response([])]);

        $this->assertFailure(fn () => (new AdKitHttpClient)->read($profile, '/act_123/campaigns', [], 'Read'), 'adkit_mapping_invalid', 503);

        Http::assertNothingSent();
    }

    public static function invalidMappings(): array
    {
        return array_combine($cases = ['provider', 'project', 'connection provider', 'missing connection', 'bare subject', 'invalid subject', 'missing subject'], array_map(fn (string $case): array => [$case], $cases));
    }

    #[DataProvider('invalidReadEnvelopes')]
    public function test_rejects_unexecuted_or_malformed_read_envelopes(mixed $body): void
    {
        Http::fake([self::BASE_URL.'/manage/platform-api-requests' => Http::response($body)]);

        $this->assertFailure(fn () => (new AdKitHttpClient)->read($this->profile(), '/act_123/campaigns', [], 'Read'), 'adkit_invalid_response', 502);

        Http::assertSentCount(1);
    }

    public static function invalidReadEnvelopes(): array
    {
        return [
            'draft' => [['platform' => 'meta', 'status' => 'draft', 'response' => ['data' => []]]],
            'wrong platform' => [['platform' => 'google', 'status' => 'executed', 'response' => []]],
            'missing response' => [['platform' => 'meta', 'status' => 'executed']],
            'prose response' => [['platform' => 'meta', 'status' => 'executed', 'response' => 'Success']],
            'meta error' => [['platform' => 'meta', 'status' => 'executed', 'response' => ['error' => ['message' => 'secret']]]],
            'outer error' => [['platform' => 'meta', 'status' => 'executed', 'response' => [], 'error' => 'secret']],
            'natural language' => ['Here are your campaigns'],
            'invalid json' => ['{"platform":'],
            'list' => [[]],
        ];
    }

    #[DataProvider('invalidAccountEnvelopes')]
    public function test_rejects_malformed_account_envelopes(array $body): void
    {
        Http::fake([self::BASE_URL.'/manage/meta/accounts' => Http::response($body)]);

        $this->assertFailure(fn () => (new AdKitHttpClient)->connectedAccounts($this->connection()), 'adkit_invalid_response', 502);

        Http::assertSentCount(1);
    }

    public static function invalidAccountEnvelopes(): array
    {
        $account = ['id' => 'act_123', 'platform' => 'meta', 'name' => 'Synthetic', 'currency' => 'USD', 'status' => 'active'];

        return [
            'missing accounts' => [[]],
            'non-list' => [['accounts' => ['account' => $account]]],
            'wrong platform' => [['accounts' => [array_replace($account, ['platform' => 'google'])]]],
            'invalid id' => [['accounts' => [array_replace($account, ['id' => '123'])]]],
            'missing fields' => [['accounts' => [['id' => 'act_123', 'platform' => 'meta']]]],
            'non-account' => [['accounts' => ['secret']]],
            'error' => [['accounts' => [], 'error' => 'secret']],
        ];
    }

    #[DataProvider('upstreamErrors')]
    public function test_sanitizes_upstream_errors_without_retries(int $status, string $code, int $responseStatus): void
    {
        Http::fake([self::BASE_URL.'/manage/meta/accounts' => Http::response(['error' => 'synthetic-token secret'], $status)]);

        $this->assertFailure(fn () => (new AdKitHttpClient)->connectedAccounts($this->connection()), $code, $responseStatus);

        Http::assertSentCount(1);
    }

    public static function upstreamErrors(): array
    {
        return [
            'authentication' => [401, 'adkit_authentication_failed', 502],
            'forbidden' => [403, 'adkit_authentication_failed', 502],
            'rate limit' => [429, 'adkit_rate_limited', 503],
            'server error' => [500, 'adkit_provider_error', 502],
            'redirect' => [302, 'adkit_provider_error', 502],
        ];
    }

    public function test_sanitizes_connection_timeout(): void
    {
        Http::fake([self::BASE_URL.'/manage/meta/accounts' => Http::failedConnection('Timeout synthetic-token secret')]);

        $this->assertFailure(fn () => (new AdKitHttpClient)->connectedAccounts($this->connection()), 'adkit_unreachable', 503);
    }

    #[DataProvider('expiredResponseDeadlines')]
    public function test_total_deadline_covers_dispatch_and_body_and_closes_stream(float $dispatchSeconds, float $readSeconds, int $chunkBytes, int $expectedReads): void
    {
        $clock = (object) ['seconds' => 100.0];
        $client = new class($clock) extends AdKitHttpClient
        {
            public function __construct(private object $clock) {}

            protected function monotonicSeconds(): float
            {
                return $this->clock->seconds;
            }
        };
        $stream = new class(Utils::streamFor('{"accounts":[]}')->detach(), $clock, $readSeconds, $chunkBytes) extends Stream
        {
            public int $reads = 0;

            public bool $closed = false;

            public function __construct($resource, private object $clock, private float $readSeconds, private int $chunkBytes)
            {
                parent::__construct($resource);
            }

            public function read(int $length): string
            {
                $this->reads++;
                $this->clock->seconds += $this->readSeconds;

                return parent::read(min($length, $this->chunkBytes));
            }

            public function close(): void
            {
                $this->closed = true;
                parent::close();
            }
        };
        Http::fake([self::BASE_URL.'/manage/meta/accounts' => function () use ($clock, $dispatchSeconds, $stream) {
            $clock->seconds += $dispatchSeconds;

            return Http::response($stream);
        }]);

        $this->assertFailure(fn () => $client->connectedAccounts($this->connection()), 'adkit_unreachable', 503);

        self::assertSame($expectedReads, $stream->reads);
        self::assertTrue($stream->closed);
        Http::assertSentCount(1);
    }

    public static function expiredResponseDeadlines(): array
    {
        return [
            'dispatch exhausts budget before first read' => [15.0, 0.0, 8192, 0],
            'slow chunks consume shared dispatch and body budget' => [4.0, 4.0, 1, 3],
            'final chunk reaches deadline' => [10.0, 5.0, 8192, 1],
        ];
    }

    public function test_sanitizes_thrown_request_exception(): void
    {
        Http::fake([self::BASE_URL.'/manage/meta/accounts' => fn () => throw Http::failedRequest(['error' => 'synthetic-token secret'], 401)]);

        $this->assertFailure(fn () => (new AdKitHttpClient)->connectedAccounts($this->connection()), 'adkit_authentication_failed', 502);
    }

    public function test_rejects_oversized_response(): void
    {
        config(['services.adkit.max_response_bytes' => 32]);
        Http::fake([self::BASE_URL.'/manage/meta/accounts' => Http::response(str_repeat('x', 33))]);

        $this->assertFailure(fn () => (new AdKitHttpClient)->connectedAccounts($this->connection()), 'adkit_response_too_large', 502);

        Http::assertSentCount(1);
    }

    public function test_accepts_empty_accounts_at_exact_size_limit(): void
    {
        config(['services.adkit.max_response_bytes' => strlen('{"accounts":[]}')]);
        Http::fake([self::BASE_URL.'/manage/meta/accounts' => Http::response('{"accounts":[]}')]);

        self::assertSame([], (new AdKitHttpClient)->connectedAccounts($this->connection()));

        Http::assertSentCount(1);
    }

    #[DataProvider('invalidReadRequests')]
    public function test_rejects_unsafe_read_inputs_without_http(string $endpoint, array $payload, string $description): void
    {
        Http::fake([self::BASE_URL.'/manage/platform-api-requests' => Http::response([])]);

        $this->assertFailure(fn () => (new AdKitHttpClient)->read($this->profile(), $endpoint, $payload, $description), 'adkit_invalid_request', 502);

        Http::assertNothingSent();
    }

    public static function invalidReadRequests(): array
    {
        return [
            'absolute URL' => ['https://evil.test/campaigns', [], 'Read'],
            'protocol relative URL' => ['//evil.test/campaigns', [], 'Read'],
            'traversal' => ['/../campaigns', [], 'Read'],
            'publish payload' => ['/act_123/campaigns', ['publish' => true], 'Read'],
            'empty description' => ['/act_123/campaigns', [], ' '],
        ];
    }

    public function test_rejects_unreadable_encrypted_credentials_without_http(): void
    {
        $connection = $this->connection();
        $connection->setRawAttributes(array_replace($connection->getAttributes(), ['access_token' => 'invalid-ciphertext']));
        Http::fake([self::BASE_URL.'/manage/meta/accounts' => Http::response(['accounts' => []])]);

        $this->assertFailure(fn () => (new AdKitHttpClient)->connectedAccounts($connection), 'adkit_connection_invalid', 503);

        Http::assertNothingSent();
    }

    public function test_unknown_exception_code_renders_static_default_502(): void
    {
        $this->assertFailure(fn () => throw new AdKitRequestException('synthetic-token secret'), 'adkit_provider_error', 502);
    }

    private function connection(): ProviderConnection
    {
        return new ProviderConnection(['provider' => 'adkit', 'client_id' => 'project-123', 'subject' => 'act_123', 'access_token' => 'synthetic-token']);
    }

    private function profile(): ProviderProfile
    {
        return ProviderProfile::factory()->make(['provider' => 'adkit', 'provider_id' => 'project-123'])
            ->setRelation('providerConnection', $this->connection());
    }

    private function assertFailure(callable $action, string $code, int $status): void
    {
        try {
            $action();
            self::fail('Expected a safe AdKit exception.');
        } catch (AdKitRequestException $exception) {
            $response = $exception->render(IncomingRequest::create('/'));
            self::assertSame($status, $response->getStatusCode());
            self::assertSame(['error' => $code, 'message' => $exception->getMessage()], $response->getData(true));
            self::assertStringNotContainsString('synthetic-token', $response->getContent());
            self::assertStringNotContainsString('secret', $response->getContent());
            self::assertNull($exception->getPrevious());
        }
    }
}
