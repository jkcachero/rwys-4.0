<?php

namespace Tests\Feature;

use App\Inference\InferenceService;
use App\Models\AIConnection;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use Tests\TestCase;

class ModelCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_openai_catalog_intersects_exact_policy_and_excludes_shutdown_and_media_models(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://api.openai.com/v1/models' => Http::response(['data' => [
            ['id' => 'gpt-4o-mini', 'supported_reasoning_levels' => [
                ['effort' => 'ultra', 'description' => 'Advertised custom effort'],
                ['effort' => 'low', 'description' => 'Lower effort'],
                ['effort' => 'ultra', 'description' => 'Duplicate'],
            ]], ['id' => 'gpt-4.1-mini', 'supported_reasoning_levels' => []],
            ['id' => 'gpt-6.1-sol', 'default_reasoning_level' => 'high',
                'supported_reasoning_efforts' => [['effort' => 'high', 'description' => 'RPC-only field must not be inferred']]],
            ['id' => 'gpt-4.1', 'shutdown_date' => '2099-01-01'],
            ['id' => 'gpt-4o-mini-audio-preview'], ['id' => 'text-embedding-3-small'], ['id' => 'gpt-6.1-sol-guess'],
        ]])]);
        $user = User::factory()->create();
        $connection = $this->connection($user);
        $models = app(InferenceService::class)->models($user, 'openai', $connection->id);
        $this->assertSame(['gpt-4o-mini', 'gpt-4.1-mini', 'gpt-6.1-sol'], array_column($models, 'slug'));
        $this->assertSame(['ultra', 'low'], $models[0]['thinkingLevels']);
        $this->assertSame([], $models[1]['thinkingLevels']);
        $this->assertSame([], $models[2]['thinkingLevels']);
        $this->assertSame(['slug', 'displayName', 'thinkingLevels'], array_keys($models[0]));
        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && $request->hasHeader('Authorization', 'Bearer fictional-api-key'));
        Http::assertSentCount(1);
    }

    public function test_claude_catalog_pages_and_uses_explicit_lifecycle_and_effort_capabilities(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://api.anthropic.com/v1/models*' => Http::sequence()
            ->push(['data' => [
                ['id' => 'claude-active', 'display_name' => 'Active', 'lifecycle' => 'active', 'capabilities' => ['effort' => [
                    'supported' => true, 'ultra' => ['supported' => true], 'low' => ['supported' => true],
                    'high' => ['supported' => true], 'max' => ['supported' => false],
                    'missing-flag' => [], 'scalar' => true,
                ]]],
                ['id' => 'claude-retired', 'display_name' => 'Retired', 'lifecycle' => 'retired'],
                ['id' => 'claude-deprecated', 'display_name' => 'Deprecated', 'lifecycle' => 'deprecated'],
                ['id' => 'claude-unknown', 'display_name' => 'Unknown lifecycle', 'lifecycle' => null],
            ], 'has_more' => true, 'last_id' => 'page-one'])
            ->push(['data' => [['id' => 'claude-legacy', 'display_name' => 'Legacy']], 'has_more' => false])]);
        $user = User::factory()->create();
        $connection = $this->connection($user, 'claude');
        $models = app(InferenceService::class)->models($user, 'anthropic', $connection->id);
        $this->assertSame([
            ['slug' => 'claude-active', 'displayName' => 'Active', 'thinkingLevels' => ['ultra', 'low', 'high']],
            ['slug' => 'claude-legacy', 'displayName' => 'Legacy', 'thinkingLevels' => []],
        ], $models);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && $request->hasHeader('x-api-key', 'fictional-api-key')
            && $request->hasHeader('anthropic-version', '2023-06-01')
            && $request['limit'] === 100 && ($request->data()['after_id'] ?? null) === 'page-one');
        Http::assertSentCount(2);
    }

    public function test_catalog_cache_expires_and_is_isolated_by_owner_key_and_policy(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://api.openai.com/v1/models' => Http::response(['data' => [['id' => 'gpt-4o-mini']]])]);
        $this->freezeTime();
        $user = User::factory()->create();
        $other = User::factory()->create();
        $connection = $this->connection($user);
        $otherConnection = $this->connection($other);
        $service = app(InferenceService::class);
        $service->models($user, 'openai', $connection->id);
        $service->models($user, 'openai', $connection->id);
        Http::assertSentCount(1);
        $service->models($other, 'openai', $otherConnection->id);
        Http::assertSentCount(2);
        $connection->update(['credentials' => ['api_key' => 'fictional-rotated-key']]);
        $service->models($user, 'openai', $connection->id);
        Http::assertSentCount(3);
        config(['workspace.models.openai.gpt-4o-mini' => ['displayName' => 'Revised policy']]);
        $this->assertSame('Revised policy', $service->models($user, 'openai', $connection->id)[0]['displayName']);
        Http::assertSentCount(4);
        $this->travel(61)->seconds();
        $service->models($user, 'openai', $connection->id);
        Http::assertSentCount(5);
        $service->models($user, 'openai', $connection->id, true);
        Http::assertSentCount(6);
        $keys = implode('|', array_keys(Cache::getStore()->all()));
        $this->assertStringNotContainsString('fictional-api-key', $keys);
        $this->assertStringNotContainsString('fictional-rotated-key', $keys);
    }

    public function test_foreign_and_revoked_catalog_connections_never_send_http(): void
    {
        Http::preventStrayRequests();
        Http::fake();
        $user = User::factory()->create();
        $other = User::factory()->create();
        $connection = $this->connection($user);
        $service = app(InferenceService::class);
        try {
            $service->models($other, 'openai', $connection->id);
            $this->fail('Foreign connection was accepted.');
        } catch (ModelNotFoundException) {
            Http::assertNothingSent();
        }
        $connection->update(['status' => 'revoked']);
        try {
            $service->models($user, 'openai', $connection->id);
            $this->fail('Revoked connection was accepted.');
        } catch (ValidationException) {
            Http::assertNothingSent();
        }
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function test_catalog_failure_never_uses_cached_or_empty_fallback(bool $malformed): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://api.openai.com/v1/models' => Http::sequence()
            ->push(['data' => [['id' => 'gpt-4o-mini']]])
            ->push($malformed ? ['data' => [['unexpected' => 'shape']]] : ['error' => 'fictional-api-key'], $malformed ? 200 : 503)]);
        $user = User::factory()->create();
        $connection = $this->connection($user);
        $service = app(InferenceService::class);
        $this->assertCount(1, $service->models($user, 'openai', $connection->id));
        $failure = null;
        try {
            $service->models($user, 'openai', $connection->id, true);
        } catch (RuntimeException $exception) {
            $failure = $exception;
        }
        $this->assertInstanceOf(RuntimeException::class, $failure);
        $this->assertStringNotContainsString('fictional-api-key', $failure->getMessage());
        Http::assertSentCount(2);
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST');
    }

    #[TestWith([['one', 'two', 'three'], 3])]
    #[TestWith([['one', 'one'], 2])]
    #[TestWith([['one', 'two', 'one'], 3])]
    public function test_incomplete_or_repeated_claude_pagination_fails_closed_before_inference(array $cursors, int $pageCount): void
    {
        Http::preventStrayRequests();
        $data = [['id' => 'claude-model', 'display_name' => 'Model', 'lifecycle' => 'active']];
        $sequence = Http::sequence()->push(['data' => $data, 'has_more' => false]);
        foreach ($cursors as $cursor) {
            $sequence->push(['data' => $data, 'has_more' => true, 'last_id' => $cursor]);
        }
        $sequence->push(['data' => $data, 'has_more' => false]);
        Http::fake([
            'https://api.anthropic.com/v1/models*' => $sequence,
            'https://api.anthropic.com/v1/messages' => Http::response([
                'type' => 'message', 'stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => 'Must not be requested']],
            ]),
        ]);
        $user = User::factory()->create();
        $connection = $this->connection($user, 'claude');
        $service = app(InferenceService::class);
        $this->assertCount(1, $service->models($user, 'claude', $connection->id));
        $failure = null;
        try {
            $service->prompt($user, 'claude', 'Hello', $connection->id, 'claude-model');
        } catch (RuntimeException $exception) {
            $failure = $exception;
        }
        $this->assertInstanceOf(RuntimeException::class, $failure);
        $this->assertSame('The personal provider model catalog could not be loaded.', $failure->getMessage());
        $this->assertNull($connection->fresh()->last_validated_at);
        Http::assertSentCount(1 + $pageCount);
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST');
        $this->assertSame([], array_values(array_filter(array_keys(Cache::getStore()->all()), fn (string $key): bool => str_starts_with($key, 'ai-models:'))));
    }

    public function test_claude_catalog_completing_on_page_three_is_accepted(): void
    {
        Http::preventStrayRequests();
        $data = [['id' => 'claude-model', 'display_name' => 'Model', 'lifecycle' => 'active']];
        Http::fake(['https://api.anthropic.com/v1/models*' => Http::sequence()
            ->push(['data' => $data, 'has_more' => true, 'last_id' => 'one'])
            ->push(['data' => $data, 'has_more' => true, 'last_id' => 'two'])
            ->push(['data' => $data, 'has_more' => false])]);
        $user = User::factory()->create();
        $connection = $this->connection($user, 'claude');
        $this->assertSame([
            ['slug' => 'claude-model', 'displayName' => 'Model', 'thinkingLevels' => []],
        ], app(InferenceService::class)->models($user, 'claude', $connection->id));
        Http::assertSentCount(3);
    }

    public function test_oauth_catalog_preserves_advertised_efforts_without_a_model_mapping_and_refreshes_after_sixty_seconds(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://api.openai.com/v1/models' => Http::response(['models' => [
            ['slug' => 'account-reasoner-custom', 'display_name' => 'Custom account reasoner', 'visibility' => 'list',
                'supported_reasoning_levels' => [
                    ['effort' => 'balanced_2', 'description' => 'Custom balance'],
                    ['effort' => 'ultra', 'description' => 'Custom effort'],
                    ['effort' => 'balanced_2', 'description' => 'Duplicate'],
                ]],
            ['slug' => 'gpt-6.1-sol', 'display_name' => 'Sol without metadata', 'visibility' => 'list'],
        ]])]);
        $this->freezeTime();
        $user = User::factory()->create();
        $user->providerConnections()->create([
            'provider' => 'openai', 'client_id' => 'oaiapp_fictional', 'subject' => 'owner',
            'access_token' => 'fictional-oauth-token', 'access_token_expires_at' => now()->addHour(),
            'scopes' => config('workspace.oauth.scopes'),
        ]);
        $service = app(InferenceService::class);
        $models = $service->models($user, 'openai');
        $this->assertSame('account-reasoner-custom', $models[0]['slug']);
        $this->assertSame(['balanced_2', 'ultra'], $models[0]['thinkingLevels']);
        $this->assertSame([], $models[1]['thinkingLevels']);
        $service->models($user, 'openai');
        Http::assertSentCount(1);
        $this->travel(61)->seconds();
        $service->models($user, 'openai');
        Http::assertSentCount(2);
        $service->models($user, 'openai', null, true);
        Http::assertSentCount(3);
    }

    #[TestWith(['claude'])]
    #[TestWith(['oauth'])]
    public function test_malformed_provider_catalog_is_not_treated_as_an_empty_catalog(string $transport): void
    {
        Http::preventStrayRequests();
        $user = User::factory()->create();
        if ($transport === 'oauth') {
            $user->providerConnections()->create([
                'provider' => 'openai', 'client_id' => 'oaiapp_fictional', 'subject' => 'owner',
                'access_token' => 'fictional-oauth-token', 'access_token_expires_at' => now()->addHour(),
                'scopes' => config('workspace.oauth.scopes'),
            ]);
            $connectionId = null;
            $url = 'https://api.openai.com/v1/models';
            $body = ['models' => [['slug' => 'missing-display-name', 'visibility' => 'list']]];
        } else {
            $connectionId = $this->connection($user, 'claude')->id;
            $url = 'https://api.anthropic.com/v1/models*';
            $body = ['data' => [['id' => 'missing-display-name']], 'has_more' => false];
        }
        Http::fake([$url => Http::response($body)]);
        $failure = null;
        try {
            app(InferenceService::class)->models($user, $transport === 'oauth' ? 'openai' : 'claude', $connectionId);
        } catch (RuntimeException $exception) {
            $failure = $exception;
        }
        $this->assertInstanceOf(RuntimeException::class, $failure);
        Http::assertSentCount(1);
    }

    private function connection(User $user, string $provider = 'openai'): AIConnection
    {
        return $user->aiConnections()->create([
            'provider' => $provider, 'label' => 'Personal', 'auth_mode' => 'api_key',
            'credentials' => ['api_key' => 'fictional-api-key'], 'status' => 'configured',
        ]);
    }

    #[TestWith(['api', null])]
    #[TestWith(['api', 'ultra'])]
    #[TestWith(['api', ['effort' => 'ultra']])]
    #[TestWith(['api', ['ultra']])]
    #[TestWith(['api', [[]]])]
    #[TestWith(['api', [['effort' => '']]])]
    #[TestWith(['api', [['effort' => 123]]])]
    #[TestWith(['api', [['effort' => 'Uppercase']]])]
    #[TestWith(['api', [['effort' => 'bad value']]])]
    #[TestWith(['api', [['effort' => 'more-than-twenty-characters']]])]
    #[TestWith(['api', [['effort' => "ultra\n"]]])]
    #[TestWith(['oauth', [['effort' => 'bad value']]])]
    public function test_explicit_malformed_reasoning_metadata_fails_safely(string $transport, mixed $metadata): void
    {
        Http::preventStrayRequests();
        $user = User::factory()->create();
        if ($transport === 'oauth') {
            $user->providerConnections()->create([
                'provider' => 'openai', 'client_id' => 'oaiapp_fictional', 'subject' => 'owner',
                'access_token' => 'fictional-oauth-token', 'access_token_expires_at' => now()->addHour(),
                'scopes' => config('workspace.oauth.scopes'),
            ]);
            $connectionId = null;
            $body = ['models' => [[
                'slug' => 'account-model', 'display_name' => 'Model', 'visibility' => 'list', 'supported_reasoning_levels' => $metadata,
            ]]];
        } else {
            $connectionId = $this->connection($user)->id;
            $body = ['data' => [['id' => 'gpt-6.1-sol', 'supported_reasoning_levels' => $metadata]]];
        }
        Http::fake(['https://api.openai.com/v1/models' => Http::response($body)]);
        $failure = null;
        try {
            app(InferenceService::class)->models($user, 'openai', $connectionId);
        } catch (RuntimeException $exception) {
            $failure = $exception;
        }
        $this->assertInstanceOf(RuntimeException::class, $failure);
        $this->assertStringNotContainsString('fictional', $failure->getMessage());
        Http::assertSentCount(1);
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST');
    }

    #[TestWith([false])]
    #[TestWith([null])]
    public function test_claude_does_not_expose_levels_without_explicit_effort_support(?bool $supported): void
    {
        Http::preventStrayRequests();
        $effort = ['ultra' => ['supported' => true]];
        if ($supported !== null) {
            $effort['supported'] = $supported;
        }
        Http::fake(['https://api.anthropic.com/v1/models*' => Http::response(['data' => [[
            'id' => 'claude-model', 'display_name' => 'Model', 'lifecycle' => 'active', 'capabilities' => ['effort' => $effort],
        ]], 'has_more' => false])]);
        $user = User::factory()->create();
        $connection = $this->connection($user, 'claude');
        $this->assertSame([], app(InferenceService::class)->models($user, 'claude', $connection->id)[0]['thinkingLevels']);
        Http::assertSentCount(1);
    }

    #[TestWith(['api'])]
    #[TestWith(['oauth'])]
    public function test_old_catalog_cache_records_are_not_reused_by_the_new_normalizer(string $transport): void
    {
        Http::preventStrayRequests();
        $user = User::factory()->create();
        $connectionId = null;
        if ($transport === 'oauth') {
            $connection = $user->providerConnections()->create([
                'provider' => 'openai', 'client_id' => 'oaiapp_fictional', 'subject' => 'owner',
                'access_token' => 'fictional-oauth-token', 'access_token_expires_at' => now()->addHour(),
                'scopes' => config('workspace.oauth.scopes'),
            ]);
            $oldKey = 'openai-oauth:models:'.$user->id.':'.$connection->id.':'.hash('sha256', json_encode([
                $connection->client_id, $connection->subject, $connection->access_token, config('workspace.models.openai'),
            ], JSON_THROW_ON_ERROR));
            $body = ['models' => [['slug' => 'gpt-6.1-sol', 'display_name' => 'Fresh catalog', 'visibility' => 'list']]];
        } else {
            $connection = $this->connection($user);
            $connectionId = $connection->id;
            $oldKey = 'ai-models:'.$user->id.':'.$connectionId.':'.hash('sha256', json_encode([
                'openai', 'fictional-api-key', config('workspace.models.openai'),
            ], JSON_THROW_ON_ERROR));
            $body = ['data' => [['id' => 'gpt-6.1-sol']]];
        }
        Cache::put($oldKey, [['slug' => 'stale-model', 'displayName' => 'Stale two-field record']], 60);
        Http::fake(['https://api.openai.com/v1/models' => Http::response($body)]);
        $this->assertSame([
            ['slug' => 'gpt-6.1-sol', 'displayName' => $transport === 'oauth' ? 'Fresh catalog' : 'GPT-6.1 Sol', 'thinkingLevels' => []],
        ], app(InferenceService::class)->models($user, 'openai', $connectionId));
        Http::assertSentCount(1);
    }

    public function test_invalid_advertised_claude_effort_name_fails_safely(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://api.anthropic.com/v1/models*' => Http::response(['data' => [[
            'id' => 'claude-model', 'display_name' => 'Model',
            'capabilities' => ['effort' => ['supported' => true, 'invalid effort' => ['supported' => true]]],
        ]], 'has_more' => false])]);
        $user = User::factory()->create();
        $connection = $this->connection($user, 'claude');
        $failure = null;
        try {
            app(InferenceService::class)->models($user, 'claude', $connection->id);
        } catch (RuntimeException $exception) {
            $failure = $exception;
        }
        $this->assertInstanceOf(RuntimeException::class, $failure);
        $this->assertSame('The personal provider model catalog could not be loaded.', $failure->getMessage());
        Http::assertSentCount(1);
    }
}
