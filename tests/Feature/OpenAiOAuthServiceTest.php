<?php

namespace Tests\Feature;

use App\Models\ProviderConnection;
use App\Models\User;
use App\Providers\OpenAiOAuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class OpenAiOAuthServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['workspace.oauth.ext_agent_host_id' => null]);
    }

    public function test_registration_uses_issued_client_pkce_resource_and_hides_encrypted_credentials(): void
    {
        $user = User::factory()->create();
        $service = app(OpenAiOAuthService::class);
        $attempt = $service->authorization($user);
        parse_str(parse_url($attempt['url'], PHP_URL_QUERY), $query);
        $this->assertMatchesRegularExpression('/^urn:uuid:[a-f0-9-]{36}$/', $query['ext_agent_host_id']);
        $second = $service->authorization($user);
        parse_str(parse_url($second['url'], PHP_URL_QUERY), $secondQuery);
        $this->assertSame($query['ext_agent_host_id'], $secondQuery['ext_agent_host_id']);
        $this->fakeToken($query['nonce']);
        $connection = $service->complete('code', $attempt['state'], 'oaiapp_test');
        $this->assertSame('oaiapp_test', $connection->client_id);
        $this->assertSame('subject', $connection->subject);
        $this->assertSame('access', $connection->access_token);
        $this->assertStringNotContainsString('access', $connection->getRawOriginal('access_token'));
        $this->assertArrayNotHasKey('access_token', $connection->toArray());
        $this->assertArrayNotHasKey('refresh_token', $connection->toArray());
        Http::assertSent(fn (Request $request) => $request->url() === config('workspace.oauth.token_endpoint')
            && $request->method() === 'POST' && $request->hasHeader('Content-Type', 'application/x-www-form-urlencoded')
            && $request['client_id'] === 'oaiapp_test' && $request['resource'] === 'https://api.openai.com/v1'
            && $request['code'] === 'code' && $request['redirect_uri'] === $query['redirect_uri']
            && rtrim(strtr(base64_encode(hash('sha256', $request['code_verifier'], true)), '+/', '-_'), '=') === $query['code_challenge']);
        $this->assertSame('connected', $service->attempt($user, $attempt['state'])['status']);
        $this->expectException(RuntimeException::class);
        $service->complete('code', $attempt['state'], 'oaiapp_test');
    }

    public function test_successful_public_loopback_callback_connects_the_attempt_owner_without_a_cookie(): void
    {
        config(['workspace.oauth.redirect_uri' => 'http://127.0.0.1:1455/auth/callback']);
        $user = User::factory()->create();
        $attempt = app(OpenAiOAuthService::class)->authorization($user);
        parse_str(parse_url($attempt['url'], PHP_URL_QUERY), $query);
        $this->fakeToken($query['nonce']);
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1', 'SERVER_PORT' => 1455])
            ->get('http://127.0.0.1:1455/auth/callback?'.http_build_query(['state' => $attempt['state'], 'code' => 'private-code', 'client_id' => 'oaiapp_test']))
            ->assertOk()->assertDontSee('private-code')->assertDontSee('refresh');
        $this->assertSame($user->id, ProviderConnection::sole()->user_id);
        $this->assertSame('connected', app(OpenAiOAuthService::class)->attempt($user, $attempt['state'])['status']);
    }

    public function test_exchange_uses_the_resource_and_redirect_saved_at_start(): void
    {
        $service = app(OpenAiOAuthService::class);
        $attempt = $service->authorization(User::factory()->create());
        parse_str(parse_url($attempt['url'], PHP_URL_QUERY), $query);
        $this->fakeToken($query['nonce']);
        config(['workspace.oauth.resource' => 'https://changed.example', 'workspace.oauth.redirect_uri' => 'http://127.0.0.1:9999/auth/callback']);
        $service->complete('code', $attempt['state'], 'oaiapp_test');
        Http::assertSent(fn (Request $request) => $request->url() === config('workspace.oauth.token_endpoint')
            && $request['resource'] === $query['resource'] && $request['redirect_uri'] === $query['redirect_uri']);
    }

    public function test_missing_issued_id_and_expired_attempt_never_exchange_a_code(): void
    {
        $service = app(OpenAiOAuthService::class);
        $user = User::factory()->create();
        $attempt = $service->authorization($user);
        try {
            $service->complete('code', $attempt['state']);
            $this->fail('Missing issued ID accepted.');
        } catch (RuntimeException) {
            $this->assertSame('error', $service->attempt($user, $attempt['state'])['status']);
        }
        $attempt = $service->authorization($user);
        $this->travel(601)->seconds();
        try {
            $service->complete('code', $attempt['state'], 'oaiapp_test');
            $this->fail('Expired state accepted.');
        } catch (RuntimeException) {
            Http::assertNothingSent();
        }
    }

    public function test_an_owner_who_lost_email_verification_cannot_complete_a_pending_authorization(): void
    {
        $service = app(OpenAiOAuthService::class);
        $user = User::factory()->create();
        $attempt = $service->authorization($user);
        parse_str(parse_url($attempt['url'], PHP_URL_QUERY), $query);
        $this->fakeToken($query['nonce']);
        $user->forceFill(['email_verified_at' => null])->save();

        try {
            $service->complete('fictional-code', $attempt['state'], 'oaiapp_test');
            $this->fail('An unverified owner completed authorization.');
        } catch (RuntimeException) {
            Http::assertNothingSent();
            $this->assertDatabaseCount('provider_connections', 0);
        }
    }

    #[DataProvider('rejectedTokens')]
    public function test_invalid_identity_or_grants_never_replace_a_connection(array $claims, ?string $scope): void
    {
        $user = User::factory()->create();
        $service = app(OpenAiOAuthService::class);
        $attempt = $service->authorization($user);
        parse_str(parse_url($attempt['url'], PHP_URL_QUERY), $query);
        $this->fakeToken($query['nonce'], $claims, $scope);
        try {
            $service->complete('code', $attempt['state'], 'oaiapp_test');
            $this->fail('Invalid OAuth response accepted.');
        } catch (RuntimeException) {
            $this->assertDatabaseCount('provider_connections', 0);
            $this->assertSame('error', $service->attempt($user, $attempt['state'])['status']);
        }
    }

    public static function rejectedTokens(): array
    {
        return [
            'nonce' => [['nonce' => 'wrong'], null],
            'audience' => [['aud' => 'wrong'], null],
            'issuer' => [['iss' => 'https://wrong.example'], null],
            'expired' => [['exp' => 1], null],
            'missing subject' => [['sub' => ''], null],
            'denied scope' => [[], 'openid profile email offline_access resource.invoke'],
            'missing resource scope' => [[], 'openid profile email offline_access chatgpt.tokens.use.direct'],
        ];
    }

    public function test_returning_client_and_identity_mismatch_are_rejected(): void
    {
        $user = User::factory()->create();
        $connection = $this->connection($user);
        $service = app(OpenAiOAuthService::class);
        $attempt = $service->authorization($user);
        parse_str(parse_url($attempt['url'], PHP_URL_QUERY), $query);
        $this->assertSame('oaiapp_test', $query['client_id']);
        $this->assertArrayNotHasKey('agent_name_hint', $query);
        try {
            $service->complete('code', $attempt['state'], 'oaiapp_other');
            $this->fail('Wrong client accepted.');
        } catch (RuntimeException) {
            Http::assertNothingSent();
        }
        $attempt = $service->authorization($user);
        parse_str(parse_url($attempt['url'], PHP_URL_QUERY), $query);
        $this->fakeToken($query['nonce'], ['sub' => 'other-account']);
        try {
            $service->complete('code', $attempt['state']);
            $this->fail('Account switch accepted.');
        } catch (RuntimeException) {
            $this->assertSame('old-access', $connection->fresh()->access_token);
        }
    }

    public function test_returning_sign_in_accepts_the_saved_client_when_callback_omits_it(): void
    {
        $user = User::factory()->create();
        $original = $this->connection($user);
        $service = app(OpenAiOAuthService::class);
        $attempt = $service->authorization($user);
        parse_str(parse_url($attempt['url'], PHP_URL_QUERY), $query);
        $this->fakeToken($query['nonce']);
        $connection = $service->complete('code', $attempt['state']);
        $this->assertSame($original->id, $connection->id);
        $this->assertSame('access', $connection->access_token);
        $this->assertSame('oaiapp_test', $connection->client_id);
        $this->assertDatabaseCount('provider_connections', 1);
    }

    public function test_invalid_rsa_signature_is_rejected(): void
    {
        $user = User::factory()->create();
        $service = app(OpenAiOAuthService::class);
        $attempt = $service->authorization($user);
        parse_str(parse_url($attempt['url'], PHP_URL_QUERY), $query);
        $this->fakeToken($query['nonce'], corruptSignature: true);
        try {
            $service->complete('code', $attempt['state'], 'oaiapp_test');
            $this->fail('Invalid signature accepted.');
        } catch (RuntimeException) {
            $this->assertDatabaseCount('provider_connections', 0);
            $this->assertSame('error', $service->attempt($user, $attempt['state'])['status']);
        }
    }

    public function test_denial_claims_state_once_without_exchange_and_is_owner_bound(): void
    {
        $service = app(OpenAiOAuthService::class);
        $user = User::factory()->create();
        $attempt = $service->authorization($user);
        $this->assertNull($service->attempt(User::factory()->create(), $attempt['state']));
        $service->deny($attempt['state'], 'access_denied');
        $this->assertSame('error', $service->attempt($user, $attempt['state'])['status']);
        Http::assertNothingSent();
        $this->expectException(RuntimeException::class);
        $service->complete('code', $attempt['state'], 'oaiapp_test');
    }

    public function test_refresh_rotates_atomically_includes_resource_and_uses_latest_database_token(): void
    {
        $connection = $this->connection(User::factory()->create());
        $stale = $connection->fresh();
        Http::fake([config('workspace.oauth.token_endpoint') => Http::response(['access_token' => 'new-access', 'refresh_token' => 'new-refresh', 'expires_in' => 3600])]);
        $service = app(OpenAiOAuthService::class);
        $this->assertSame('new-access', $service->accessToken($connection));
        $this->assertSame('new-access', $service->accessToken($stale));
        $this->assertSame('new-refresh', $connection->fresh()->refresh_token);
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => $request->data() === ['grant_type' => 'refresh_token', 'client_id' => 'oaiapp_test', 'refresh_token' => 'old-refresh', 'resource' => 'https://api.openai.com/v1']);
    }

    public function test_refresh_cannot_silently_drop_required_grants(): void
    {
        $connection = $this->connection(User::factory()->create());
        Http::fake([config('workspace.oauth.token_endpoint') => Http::response(['access_token' => 'new-access', 'refresh_token' => 'new-refresh', 'expires_in' => 3600, 'scope' => 'openid'])]);
        try {
            app(OpenAiOAuthService::class)->accessToken($connection);
            $this->fail('Reduced grant accepted.');
        } catch (RuntimeException) {
            $this->assertSame('old-access', $connection->fresh()->access_token);
        }
    }

    public function test_token_failure_is_not_retried_and_does_not_expose_response_body(): void
    {
        $connection = $this->connection(User::factory()->create());
        Http::fake([config('workspace.oauth.token_endpoint') => Http::response(['secret' => 'never-expose'], 500)]);
        try {
            app(OpenAiOAuthService::class)->accessToken($connection);
            $this->fail('Failed refresh accepted.');
        } catch (RuntimeException $exception) {
            $this->assertStringNotContainsString('never-expose', $exception->getMessage());
            Http::assertSentCount(1);
            $this->assertSame('old-refresh', $connection->fresh()->refresh_token);
        }
    }

    public function test_disconnect_revokes_remotely_then_deletes_only_owner_connection(): void
    {
        $user = User::factory()->create();
        $this->connection($user);
        $other = $this->connection(User::factory()->create());
        Http::fake([
            'https://auth.openai.com/.well-known/openid-configuration' => Http::response(['revocation_endpoint' => 'https://auth.openai.com/oauth/revoke']),
            'https://auth.openai.com/oauth/revoke' => Http::response('', 200),
        ]);
        app(OpenAiOAuthService::class)->disconnect($user);
        $this->assertDatabaseCount('provider_connections', 1);
        $this->assertNotNull($other->fresh());
        Http::assertSent(fn (Request $request) => $request->url() === 'https://auth.openai.com/oauth/revoke' && $request->method() === 'POST'
            && $request->data() === ['token' => 'old-refresh', 'token_type_hint' => 'refresh_token', 'client_id' => 'oaiapp_test']);
    }

    public function test_unconfirmed_revocation_preserves_credentials_for_retry(): void
    {
        $user = User::factory()->create();
        $connection = $this->connection($user);
        Http::fake([
            'https://auth.openai.com/.well-known/openid-configuration' => Http::response(['revocation_endpoint' => 'https://auth.openai.com/oauth/revoke']),
            'https://auth.openai.com/oauth/revoke' => Http::response('', 503),
        ]);
        try {
            app(OpenAiOAuthService::class)->disconnect($user);
            $this->fail('Unconfirmed revoke accepted.');
        } catch (RuntimeException) {
            $this->assertSame('old-refresh', $connection->fresh()->refresh_token);
        }
    }

    public function test_subscription_models_are_filtered_and_cached_per_connection(): void
    {
        $connection = $this->connection(User::factory()->create());
        $connection->update(['access_token_expires_at' => now()->addHour()]);
        Http::fake(['https://api.openai.com/v1/models' => Http::response(['models' => [
            ['slug' => 'one', 'display_name' => 'One', 'visibility' => 'list',
                'supported_reasoning_levels' => [['effort' => 'ultra', 'description' => 'Custom effort']]],
            ['slug' => 'hidden', 'display_name' => 'Hidden', 'visibility' => 'hidden'],
        ]])]);
        $service = app(OpenAiOAuthService::class);
        $this->assertSame([['slug' => 'one', 'displayName' => 'One', 'thinkingLevels' => ['ultra']]], $service->models($connection));
        $service->models($connection);
        Http::assertSentCount(1);
        $other = $this->connection(User::factory()->create());
        $other->update(['access_token_expires_at' => now()->addHour()]);
        $service->models($other);
        Http::assertSentCount(2);
    }

    private function connection(User $user): ProviderConnection
    {
        return $user->providerConnections()->create(['provider' => 'openai', 'client_id' => 'oaiapp_test', 'subject' => 'subject', 'access_token' => 'old-access', 'refresh_token' => 'old-refresh', 'scopes' => config('workspace.oauth.scopes'), 'access_token_expires_at' => now()->subMinute()]);
    }

    private function fakeToken(string $nonce, array $overrides = [], ?string $scope = null, bool $corruptSignature = false): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $details = openssl_pkey_get_details($key);
        $encode = fn (string $value): string => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
        $header = $encode(json_encode(['alg' => 'RS256', 'kid' => 'test-key']));
        $claims = $encode(json_encode(array_replace(['iss' => 'https://auth.openai.com', 'aud' => 'oaiapp_test', 'sub' => 'subject', 'nonce' => $nonce, 'exp' => time() + 3600], $overrides)));
        openssl_sign($header.'.'.$claims, $signature, $key, OPENSSL_ALGO_SHA256);
        if ($corruptSignature) {
            $signature[0] = chr(ord($signature[0]) ^ 1);
        }
        Http::fake([
            config('workspace.oauth.token_endpoint') => Http::response(['access_token' => 'access', 'refresh_token' => 'refresh', 'id_token' => $header.'.'.$claims.'.'.$encode($signature), 'expires_in' => 3600, 'scope' => $scope ?? 'openid profile email offline_access resource.invoke chatgpt.tokens.use.direct']),
            config('workspace.oauth.jwks_uri') => Http::response(['keys' => [['kid' => 'test-key', 'kty' => 'RSA', 'n' => $encode($details['rsa']['n']), 'e' => $encode($details['rsa']['e'])]]]),
        ]);
    }
}
