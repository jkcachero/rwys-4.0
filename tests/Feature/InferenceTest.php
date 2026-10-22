<?php

namespace Tests\Feature;

use App\Inference\InferenceService;
use App\Models\ProviderConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class InferenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_prompt_requests_are_rate_limited_without_dispatching_unconfigured_models(): void
    {
        Http::preventStrayRequests();
        Http::fake();
        $this->actingAs(User::factory()->create());

        for ($attempt = 0; $attempt < 20; $attempt++) {
            $this->postJson('/dashboard/prompt', ['provider' => 'openai', 'prompt' => 'Hello'])->assertStatus(503);
        }

        $this->postJson('/dashboard/prompt', ['provider' => 'openai', 'prompt' => 'Hello'])->assertStatus(429);
        Http::assertNothingSent();
    }

    #[TestWith(['openai', 'openai', 'https://api.openai.com/v1/responses'])]
    #[TestWith(['claude', 'claude', 'https://api.anthropic.com/v1/messages'])]
    #[TestWith(['claude', 'anthropic', 'https://api.anthropic.com/v1/messages'])]
    public function test_personal_key_and_saved_model_reach_the_real_gateway(string $storedProvider, string $provider, string $url): void
    {
        Http::preventStrayRequests();
        config(['workspace.models.openai' => ['saved-model' => ['displayName' => 'Test model']]]);
        Http::fake([
            'https://api.openai.com/v1/models' => Http::response(['data' => [['id' => 'saved-model']]]),
            'https://api.anthropic.com/v1/models*' => Http::response(['data' => [['id' => 'saved-model', 'display_name' => 'Test model', 'lifecycle' => 'active']], 'has_more' => false]),
            $url => Http::response($storedProvider === 'openai' ? [
                'id' => 'resp_fictional', 'status' => 'completed', 'model' => 'saved-model',
                'output' => [['type' => 'message', 'status' => 'completed', 'content' => [['type' => 'output_text', 'text' => 'A genuine gateway answer.']]]],
            ] : [
                'id' => 'msg_fictional', 'type' => 'message', 'model' => 'saved-model',
                'content' => [['type' => 'text', 'text' => 'A genuine gateway answer.']], 'stop_reason' => 'end_turn',
            ])]);
        config(['ai.providers.openai.key' => 'fictional-global-key', 'ai.providers.anthropic.key' => 'fictional-global-key']);

        foreach (['fictional-first-owner-key', 'fictional-second-owner-key'] as $key) {
            $user = User::factory()->create();
            $connection = $user->aiConnections()->create([
                'provider' => $storedProvider, 'label' => 'Personal', 'auth_mode' => 'api_key',
                'credentials' => ['api_key' => $key], 'model' => 'saved-model', 'status' => 'configured',
            ]);
            $this->actingAs($user)->postJson('/dashboard/prompt', [
                'provider' => $provider, 'prompt' => 'Hello', 'connectionId' => $connection->id,
            ])->assertOk()->assertJsonPath('text', 'A genuine gateway answer.')->assertJsonPath('model', 'saved-model');
            Http::assertSent(fn (Request $request): bool => $request->url() === $url
                && $request['model'] === 'saved-model'
                && ($storedProvider === 'openai' ? $request['max_output_tokens'] === 512 : $request['max_tokens'] === 512)
                && ($storedProvider === 'openai' ? $request->hasHeader('Authorization', 'Bearer '.$key) : $request->hasHeader('x-api-key', $key)));
            $this->assertSame('active', $connection->fresh()->status);
            $this->assertNotNull($connection->fresh()->last_validated_at);
        }
        Http::assertSentCount(4);
        $this->assertSame('fictional-global-key', config('ai.providers.openai.key'));
    }

    public function test_invalid_connections_are_rejected_before_http(): void
    {
        Http::preventStrayRequests();
        Http::fake();
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $connection = $owner->aiConnections()->create([
            'provider' => 'openai', 'label' => 'Personal', 'auth_mode' => 'api_key',
            'credentials' => ['api_key' => 'fictional-owner-key'], 'status' => 'configured',
        ]);
        $payload = ['provider' => 'openai', 'prompt' => 'Hello', 'connectionId' => $connection->id];
        $this->actingAs($other)->postJson('/dashboard/prompt', $payload)->assertNotFound();
        $this->actingAs($owner)->postJson('/dashboard/prompt', [...$payload, 'connectionId' => 99999])->assertNotFound();
        $this->postJson('/dashboard/prompt', [...$payload, 'provider' => 'claude'])->assertUnprocessable();
        $connection->update(['status' => 'revoked']);
        $this->postJson('/dashboard/prompt', $payload)->assertUnprocessable();
        Http::assertNothingSent();
    }

    public function test_no_id_never_falls_back_to_any_api_key(): void
    {
        Http::preventStrayRequests();
        Http::fake();
        config(['ai.providers.openai.key' => 'fictional-global-key', 'ai.providers.anthropic.key' => 'fictional-global-key']);
        $user = User::factory()->create();
        foreach (['openai', 'claude', 'anthropic'] as $provider) {
            $this->actingAs($user)->postJson('/dashboard/prompt', ['provider' => $provider, 'prompt' => 'Hello'])->assertStatus(503);
        }
        $this->assertSame(['unavailable', 'unavailable'], array_column(app(InferenceService::class)->status($user), 'status'));
        Http::assertNothingSent();
    }

    #[TestWith([401, 'failed'])]
    #[TestWith([200, 'incomplete'])]
    public function test_failed_api_inference_does_not_validate_or_expose_secrets(int $status, string $completion): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://api.openai.com/v1/models' => Http::response(['data' => [['id' => 'gpt-4o-mini']]]),
            'https://api.openai.com/v1/responses' => Http::response([
                'status' => $completion, 'error' => ['message' => 'fictional-owner-key'],
            ], $status)]);
        $user = User::factory()->create();
        $connection = $user->aiConnections()->create([
            'provider' => 'openai', 'label' => 'Personal', 'auth_mode' => 'api_key',
            'credentials' => ['api_key' => 'fictional-owner-key'], 'status' => 'configured',
        ]);
        $response = $this->actingAs($user)->post('/dashboard/prompt', [
            'provider' => 'openai', 'prompt' => 'Hello', 'connectionId' => $connection->id,
        ], ['Accept' => 'text/html'])->assertStatus(503);
        $this->assertStringNotContainsString('fictional-owner-key', $response->getContent());
        $this->assertNull($connection->fresh()->last_validated_at);
        $this->assertSame('configured', $connection->fresh()->status);
        Http::assertSentCount(2);
    }

    #[TestWith(['incomplete', 'output_text', 'Partial answer'])]
    #[TestWith(['completed', 'refusal', ''])]
    public function test_partial_or_refused_api_responses_are_not_validated(string $status, string $type, string $text): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://api.openai.com/v1/models' => Http::response(['data' => [['id' => 'gpt-4o-mini']]]),
            'https://api.openai.com/v1/responses' => Http::response([
                'status' => $status, 'incomplete_details' => ['reason' => 'max_output_tokens'],
                'output' => [['type' => 'message', 'status' => $status, 'content' => [['type' => $type, 'text' => $text]]]],
            ])]);
        $user = User::factory()->create();
        $connection = $user->aiConnections()->create([
            'provider' => 'openai', 'label' => 'Personal', 'auth_mode' => 'api_key',
            'credentials' => ['api_key' => 'fictional-owner-key'], 'status' => 'configured',
        ]);
        $this->actingAs($user)->postJson('/dashboard/prompt', [
            'provider' => 'openai', 'prompt' => 'Hello', 'connectionId' => $connection->id,
        ])->assertStatus(503);
        $this->assertNull($connection->fresh()->last_validated_at);
        Http::assertSentCount(2);
    }

    public function test_status_distinguishes_configured_tested_expired_and_refreshable_without_network(): void
    {
        Http::preventStrayRequests();
        Http::fake();
        $this->freezeTime();
        $user = User::factory()->create();
        $other = User::factory()->create();
        $connection = $user->aiConnections()->create([
            'provider' => 'claude', 'label' => 'Personal', 'auth_mode' => 'api_key',
            'credentials' => ['api_key' => 'fictional-owner-key'], 'status' => 'configured',
        ]);
        $service = app(InferenceService::class);
        $this->assertSame('configured', $service->status($user)[1]['status']);
        $this->assertSame('unavailable', $service->status($other)[1]['status']);
        $connection->update(['status' => 'active', 'last_validated_at' => now()]);
        $this->assertSame('available', $service->status($user)[1]['status']);
        $user->aiConnections()->create([
            'provider' => 'openai', 'label' => 'Tested API only', 'auth_mode' => 'api_key',
            'credentials' => ['api_key' => 'fictional-openai-api-key'], 'status' => 'active', 'last_validated_at' => now(),
        ]);
        $this->assertSame('available', $service->status($user)[0]['status']);
        $this->assertFalse($service->status($user)[0]['connected']);
        $this->assertNull($service->status($user)[0]['expiresAt']);
        $this->assertFalse($service->status($user)[1]['connected']);
        $this->assertNull($service->status($user)[1]['expiresAt']);
        $oauth = $user->providerConnections()->create([
            'provider' => 'openai', 'access_token' => 'fictional-oauth-token',
            'access_token_expires_at' => now()->subMinute(), 'scopes' => config('workspace.oauth.scopes'),
        ]);
        $this->assertFalse($service->status($user)[0]['connected']);
        $this->assertSame($oauth->access_token_expires_at->toIso8601String(), $service->status($user)[0]['expiresAt']);
        $oauth->update(['refresh_token' => 'fictional-refresh-token', 'client_id' => 'fictional-client']);
        $this->assertSame('available', $service->status($user)[0]['status']);
        $this->assertTrue($service->status($user)[0]['connected']);
        $this->assertSame('unavailable', $service->status($other)[0]['status']);
        $this->assertFalse($service->status($other)[0]['connected']);
        $this->assertNull($service->status($other)[0]['expiresAt']);
        $oauth->update(['refresh_token' => null, 'access_token_expires_at' => now()->addHour()]);
        $this->assertTrue($service->status($user)[0]['connected']);
        $this->assertSame($oauth->access_token_expires_at->toIso8601String(), $service->status($user)[0]['expiresAt']);
        $oauth->update(['scopes' => ['chatgpt.tokens.use.direct']]);
        $this->assertFalse($service->status($user)[0]['connected']);
        $oauth->update(['scopes' => []]);
        $this->assertFalse($service->status($user)[0]['connected']);
        Http::assertNothingSent();
    }

    #[TestWith([null, 'account-first', true])]
    #[TestWith(['account-selected', 'account-selected', false])]
    public function test_oauth_inference_uses_the_account_catalog_and_public_responses_contract(?string $selectedModel, string $expectedModel, bool $terminalOutput): void
    {
        Http::preventStrayRequests();
        $this->freezeTime();
        config(['ai.providers.openai.key' => 'fictional-global-key']);
        $user = User::factory()->create();
        $connection = $this->oauthConnection($user);
        $completed = ['type' => 'response.completed', 'response' => ['status' => 'completed']];
        if ($terminalOutput) {
            $completed['response']['output'] = [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => 'Account answer.']]]];
        }
        $stream = ": heartbeat\r\nevent: response.output_text.delta\r\ndata: {\"type\":\"response.output_text.delta\",\r\ndata: \"delta\":\"Account \"}\r\n\r\n"
            ."data: {\"type\":\"response.output_text.delta\",\"delta\":\"answer.\"}\r\n\r\n"
            .'data: '.json_encode($completed)."\r\n\r\ndata: [DONE]\r\n\r\n";
        Http::fake([
            'https://api.openai.com/v1/models' => Http::response(['models' => [
                ['slug' => 'hidden-model', 'display_name' => 'Hidden', 'visibility' => 'hidden'],
                ['slug' => 'account-first', 'display_name' => 'First account model', 'visibility' => 'list'],
                ['slug' => 'account-selected', 'display_name' => 'Selected account model', 'visibility' => 'list'],
            ]]),
            'https://api.openai.com/v1/responses' => Http::response($stream, 200, ['Content-Type' => 'text/event-stream']),
        ]);

        $this->actingAs($user)->postJson('/dashboard/prompt', [
            'provider' => 'openai', 'prompt' => 'Hello account', 'model' => $selectedModel,
        ])->assertOk()->assertJsonPath('model', $expectedModel)->assertJsonPath('text', 'Account answer.');

        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && $request->url() === 'https://api.openai.com/v1/models'
            && $request->hasHeader('Authorization', 'Bearer fictional-oauth-token'));
        Http::assertSent(function (Request $request) use ($expectedModel): bool {
            if ($request->method() !== 'POST') {
                return false;
            }
            $this->assertSame('https://api.openai.com/v1/responses', $request->url());
            $this->assertTrue($request->hasHeader('Authorization', 'Bearer fictional-oauth-token'));
            $this->assertSubscriptionFields($request->data());
            $this->assertSame([
                'model' => $expectedModel,
                'input' => [['role' => 'user', 'content' => [['type' => 'input_text', 'text' => 'Hello account']]]],
                'store' => false,
                'stream' => true,
            ], $request->data());

            return true;
        });
        Http::assertSentCount(2);
        $this->assertSame('fictional-oauth-token', $connection->fresh()->access_token);
        $this->assertSame(0, $user->aiConnections()->count());
    }

    #[TestWith(['response.refusal.delta'])]
    #[TestWith(['response.refusal.done'])]
    #[TestWith(['response.failed'])]
    #[TestWith(['response.incomplete'])]
    #[TestWith(['error'])]
    #[TestWith(['missing-completion'])]
    #[TestWith(['terminal-refusal'])]
    #[TestWith(['terminal-incomplete'])]
    #[TestWith(['invalid-json'])]
    public function test_oauth_rejects_unsuccessful_sse_without_secret_leaks_or_api_fallback(string $failure): void
    {
        Http::preventStrayRequests();
        $this->freezeTime();
        config(['ai.providers.openai.key' => 'fictional-global-key']);
        $user = User::factory()->create();
        $this->oauthConnection($user);
        $api = $user->aiConnections()->create([
            'provider' => 'openai', 'label' => 'Unused API', 'auth_mode' => 'api_key',
            'credentials' => ['api_key' => 'fictional-personal-api-key'], 'status' => 'configured',
        ]);
        $terminal = match ($failure) {
            'missing-completion' => "data: [DONE]\n\n",
            'invalid-json' => "data: {invalid-json}\n\n",
            'terminal-refusal' => 'data: '.json_encode(['type' => 'response.completed', 'response' => [
                'status' => 'completed', 'output' => [['content' => [['type' => 'refusal', 'refusal' => 'fictional-oauth-token']]]],
            ]])."\n\n",
            'terminal-incomplete' => "data: {\"type\":\"response.completed\",\"response\":{\"status\":\"incomplete\"}}\n\n",
            default => 'data: '.json_encode(['type' => $failure, 'message' => 'fictional-oauth-token'])."\n\n",
        };
        Http::fake([
            'https://api.openai.com/v1/models' => Http::response(['models' => [
                ['slug' => 'account-model', 'display_name' => 'Account model', 'visibility' => 'list'],
            ]]),
            'https://api.openai.com/v1/responses' => Http::response(
                "data: {\"type\":\"response.output_text.delta\",\"delta\":\"Partial text\"}\n\n".$terminal,
                200, ['Content-Type' => 'text/event-stream'],
            ),
        ]);
        $response = $this->actingAs($user)->postJson('/dashboard/prompt', [
            'provider' => 'openai', 'prompt' => 'Hello account',
        ])->assertStatus(503)->assertExactJson(['message' => 'The selected provider is unavailable.']);
        $this->assertStringNotContainsString('fictional-oauth-token', $response->getContent());
        $this->assertNull($api->fresh()->last_validated_at);
        $this->assertSame('configured', $api->fresh()->status);
        Http::assertSentCount(2);
        Http::assertNotSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer fictional-global-key')
            || $request->hasHeader('Authorization', 'Bearer fictional-personal-api-key'));
    }

    #[TestWith(['hidden-model'])]
    #[TestWith(['guessed-model'])]
    public function test_oauth_rejects_models_outside_the_eligible_account_catalog(string $model): void
    {
        Http::preventStrayRequests();
        $user = User::factory()->create();
        $this->oauthConnection($user);
        Http::fake(['https://api.openai.com/v1/models' => Http::response(['models' => [
            ['slug' => 'hidden-model', 'display_name' => 'Hidden', 'visibility' => 'hidden'],
            ['slug' => 'account-model', 'display_name' => 'Account model', 'visibility' => 'list'],
        ]])]);
        $this->actingAs($user)->postJson('/dashboard/prompt', [
            'provider' => 'openai', 'prompt' => 'Hello account', 'model' => $model,
        ])->assertUnprocessable()->assertJsonValidationErrors(['model']);
        Http::assertSentCount(1);
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST');
    }

    private function oauthConnection(User $user): ProviderConnection
    {
        return $user->providerConnections()->create([
            'provider' => 'openai', 'client_id' => 'oaiapp_fictional', 'subject' => 'fictional-subject',
            'access_token' => 'fictional-oauth-token', 'refresh_token' => 'fictional-refresh-token',
            'access_token_expires_at' => now()->addHour(), 'scopes' => config('workspace.oauth.scopes'),
        ]);
    }

    public function test_invalid_optional_prompt_fields_are_rejected_without_http(): void
    {
        Http::preventStrayRequests();
        Http::fake();
        $user = User::factory()->create();
        $this->actingAs($user)->postJson('/dashboard/prompt', [
            'provider' => 'openai', 'prompt' => 'Hello', 'connectionId' => 'not-an-id', 'model' => str_repeat('x', 101),
        ])->assertUnprocessable()->assertJsonValidationErrors(['connectionId', 'model']);
        Http::assertNothingSent();
    }

    #[TestWith(['openai'])]
    #[TestWith(['claude'])]
    #[TestWith(['oauth'])]
    public function test_selected_model_effort_and_trusted_history_reach_each_transport(string $transport): void
    {
        Http::preventStrayRequests();
        $user = User::factory()->create();
        $model = match ($transport) {
            'claude' => 'claude-effort',
            'oauth' => 'custom-account-reasoner',
            default => 'gpt-6.1-sol',
        };
        $connectionId = null;
        if ($transport === 'oauth') {
            $this->oauthConnection($user);
        } else {
            $connectionId = $user->aiConnections()->create([
                'provider' => $transport, 'label' => 'Personal', 'auth_mode' => 'api_key',
                'credentials' => ['api_key' => 'fictional-api-key'], 'status' => 'configured',
            ])->id;
        }
        $catalog = Http::sequence();
        foreach (['low', 'ultra'] as $effort) {
            $catalog->push(match ($transport) {
                'claude' => ['data' => [[
                    'id' => $model, 'display_name' => 'Effort', 'lifecycle' => 'active',
                    'capabilities' => ['effort' => ['supported' => true, $effort => ['supported' => true]]],
                ]], 'has_more' => false],
                'oauth' => ['models' => [[
                    'slug' => $model, 'display_name' => 'Reasoner', 'visibility' => 'list',
                    'supported_reasoning_levels' => [['effort' => $effort, 'description' => 'Advertised effort']],
                ]]],
                default => ['data' => [['id' => $model, 'supported_reasoning_levels' => [['effort' => $effort, 'description' => 'Advertised effort']]]]],
            });
        }
        Http::fake([
            ($transport === 'claude' ? 'https://api.anthropic.com/v1/models*' : 'https://api.openai.com/v1/models') => $catalog,
            'https://api.openai.com/v1/responses' => $transport === 'oauth'
                ? Http::response("data: {\"type\":\"response.completed\",\"response\":{\"status\":\"completed\",\"output\":[{\"content\":[{\"type\":\"output_text\",\"text\":\"Answer\"}]}]}}\n\n", 200, ['Content-Type' => 'text/event-stream'])
                : Http::response(['id' => 'resp_test', 'status' => 'completed', 'output' => [[
                    'type' => 'message', 'status' => 'completed', 'content' => [['type' => 'output_text', 'text' => 'Answer']],
                ]]]),
            'https://api.anthropic.com/v1/messages' => Http::response([
                'type' => 'message', 'stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => 'Answer']],
            ]),
        ]);
        $provider = $transport === 'oauth' ? 'openai' : $transport;
        $service = app(InferenceService::class);
        $this->assertSame(['low'], $service->models($user, $provider, $connectionId)[0]['thinkingLevels']);
        $result = $service->prompt($user, $provider,
            'Current question', $connectionId, $model, 'ultra', [
                ['role' => 'user', 'content' => 'Previous question'],
                ['role' => 'assistant', 'content' => 'Previous answer'],
            ]);
        $this->assertSame($model, $result->model);
        $this->assertSame('Answer', $result->text);
        Http::assertSent(function (Request $request) use ($transport, $model): bool {
            if ($request->method() !== 'POST') {
                return false;
            }
            $body = $request->data();
            $messages = $body[$transport === 'claude' ? 'messages' : 'input'];
            $messages = array_values(array_filter($messages, fn (array $message): bool => in_array($message['role'] ?? null, ['user', 'assistant'], true)));
            $this->assertSame(['user', 'assistant', 'user'], array_column($messages, 'role'));
            $this->assertSame(['Previous question', 'Previous answer', 'Current question'], array_map(
                fn (array $message): string => is_string($message['content']) ? $message['content'] : $message['content'][0]['text'], $messages,
            ));
            $this->assertSame($model, $body['model']);
            $this->assertSame('ultra', $transport === 'claude' ? $body['output_config']['effort'] : $body['reasoning']['effort']);
            if ($transport !== 'oauth') {
                $this->assertSame(4096, $body[$transport === 'claude' ? 'max_tokens' : 'max_output_tokens']);
            }
            $this->assertArrayNotHasKey('thinking', $body);
            $this->assertArrayNotHasKey('previous_response_id', $body);
            if ($transport === 'oauth') {
                $this->assertSubscriptionFields($body);
                $this->assertSame(['model', 'input', 'store', 'stream', 'reasoning'], array_keys($body));
                $this->assertSame(['input_text', 'output_text', 'input_text'], array_map(fn (array $message): string => $message['content'][0]['type'], $messages));
                $this->assertFalse($body['store']);
                $this->assertTrue($body['stream']);
            }

            return true;
        });
        Http::assertSentCount(3);
    }

    #[TestWith(['openai'])]
    #[TestWith(['claude'])]
    #[TestWith(['oauth'])]
    public function test_model_removed_after_picker_load_is_rejected_on_prompt_refresh(string $transport): void
    {
        Http::preventStrayRequests();
        $model = $transport === 'claude' ? 'claude-model' : 'gpt-4o-mini';
        $url = $transport === 'claude' ? 'https://api.anthropic.com/v1/models*' : 'https://api.openai.com/v1/models';
        $available = match ($transport) {
            'claude' => ['data' => [['id' => $model, 'display_name' => 'Model', 'lifecycle' => 'active']], 'has_more' => false],
            'oauth' => ['models' => [['slug' => $model, 'display_name' => 'Model', 'visibility' => 'list']]],
            default => ['data' => [['id' => $model]]],
        };
        $removed = match ($transport) {
            'claude' => ['data' => [['id' => $model, 'display_name' => 'Model', 'lifecycle' => 'retired']], 'has_more' => false],
            'oauth' => ['models' => []],
            default => ['data' => [['id' => $model, 'shutdown_date' => '2099-01-01']]],
        };
        Http::fake([$url => Http::sequence()->push($available)->push($removed)]);
        $user = User::factory()->create();
        if ($transport === 'oauth') {
            $this->oauthConnection($user);
            $connectionId = null;
        } else {
            $connectionId = $user->aiConnections()->create([
                'provider' => $transport, 'label' => 'Personal', 'auth_mode' => 'api_key', 'model' => $model,
                'credentials' => ['api_key' => 'fictional-api-key'], 'status' => 'configured',
            ])->id;
        }
        $provider = $transport === 'oauth' ? 'openai' : $transport;
        $service = app(InferenceService::class);
        $this->assertCount(1, $service->models($user, $provider, $connectionId));
        try {
            $service->prompt($user, $provider, 'Hello', $connectionId, $transport === 'oauth' ? $model : null);
            $this->fail('Removed saved model was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('model', $exception->errors());
        }
        Http::assertSentCount(2);
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST');
    }

    #[TestWith(['gpt-4o-mini', 'high'])]
    #[TestWith(['gpt-6.1-sol', 'none'])]
    #[TestWith(['gpt-6.1-sol', 'minimal'])]
    #[TestWith(['gpt-6.1-sol', 'high'])]
    #[TestWith(['claude-effort', 'max', 'claude'])]
    #[TestWith(['gpt-6.1-sol', 'minimal', 'oauth'])]
    public function test_unsupported_thinking_level_never_dispatches_inference(string $model, string $level, string $transport = 'openai'): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://api.openai.com/v1/models' => Http::response($transport === 'oauth' ? ['models' => [
                ['slug' => $model, 'display_name' => 'Sol', 'visibility' => 'list'],
            ]] : ['data' => [['id' => $model]]]),
            'https://api.anthropic.com/v1/models*' => Http::response(['data' => [[
                'id' => $model, 'display_name' => 'Effort', 'lifecycle' => 'active',
                'capabilities' => ['effort' => ['supported' => true, 'low' => ['supported' => true]]],
            ]], 'has_more' => false]),
        ]);
        $user = User::factory()->create();
        if ($transport === 'oauth') {
            $this->oauthConnection($user);
            $connectionId = null;
        } else {
            $connectionId = $user->aiConnections()->create([
                'provider' => $transport, 'label' => 'Personal', 'auth_mode' => 'api_key',
                'credentials' => ['api_key' => 'fictional-api-key'], 'status' => 'configured',
            ])->id;
        }
        try {
            app(InferenceService::class)->prompt($user, $transport === 'oauth' ? 'openai' : $transport, 'Hello', $connectionId, $model, $level);
            $this->fail('Unsupported effort was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('thinkingLevel', $exception->errors());
        }
        Http::assertSentCount(1);
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST');
    }

    #[TestWith([2, 24000])]
    #[TestWith([20, 12])]
    public function test_trusted_history_keeps_only_recent_bounded_text_and_never_forwards_options(int $messages, int $characters): void
    {
        Http::preventStrayRequests();
        config(['workspace.inference.history_max_messages' => $messages, 'workspace.inference.history_max_characters' => $characters]);
        $user = User::factory()->create();
        $this->oauthConnection($user);
        Http::fake([
            'https://api.openai.com/v1/models' => Http::response(['models' => [
                ['slug' => 'account-model', 'display_name' => 'Account model', 'visibility' => 'list'],
            ]]),
            'https://api.openai.com/v1/responses' => Http::response("data: {\"type\":\"response.completed\",\"response\":{\"status\":\"completed\",\"output\":[{\"content\":[{\"type\":\"output_text\",\"text\":\"Answer\"}]}]}}\n\n"),
        ]);
        app(InferenceService::class)->prompt($user, 'openai', 'Current', null, null, null, [
            ['role' => 'user', 'content' => str_repeat('old', 100)],
            ['role' => 'assistant', 'content' => str_repeat('old', 100)],
            ['role' => 'user', 'content' => 'Recent', 'providerOptions' => ['store' => true]],
            ['role' => 'assistant', 'content' => 'Answer', 'reasoning' => 'must-not-forward'],
        ]);
        Http::assertSent(function (Request $request): bool {
            if ($request->method() !== 'POST') {
                return false;
            }
            $this->assertSame([
                ['role' => 'user', 'content' => [['type' => 'input_text', 'text' => 'Recent']]],
                ['role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => 'Answer']]],
                ['role' => 'user', 'content' => [['type' => 'input_text', 'text' => 'Current']]],
            ], $request['input']);
            $this->assertArrayNotHasKey('reasoning', $request->data());
            $this->assertSubscriptionFields($request->data());

            return true;
        });
        Http::assertSentCount(2);
    }

    /** @param array<string, mixed> $body */
    private function assertSubscriptionFields(array $body): void
    {
        $this->assertIsArray($body['input']);
        $this->assertFalse($body['store']);
        $this->assertTrue($body['stream']);
        foreach ([
            'background', 'conversation', 'max_output_tokens', 'max_tool_calls', 'metadata', 'moderation',
            'multi_agent', 'prompt', 'prompt_cache_retention', 'safety_identifier', 'temperature',
            'top_logprobs', 'top_p', 'truncation', 'user', 'previous_response_id',
        ] as $field) {
            $this->assertArrayNotHasKey($field, $body);
        }
    }

    #[TestWith(['openai'])]
    #[TestWith(['claude'])]
    #[TestWith(['oauth'])]
    public function test_effort_removed_after_picker_load_is_rejected_by_fresh_catalog(string $transport): void
    {
        Http::preventStrayRequests();
        $user = User::factory()->create();
        $connectionId = null;
        if ($transport === 'oauth') {
            $this->oauthConnection($user);
        } else {
            $connectionId = $user->aiConnections()->create([
                'provider' => $transport, 'label' => 'Personal', 'auth_mode' => 'api_key',
                'credentials' => ['api_key' => 'fictional-api-key'], 'status' => 'configured',
            ])->id;
        }
        $model = $transport === 'claude' ? 'claude-custom' : 'gpt-6.1-sol';
        $sequence = Http::sequence();
        foreach (['ultra', 'low'] as $effort) {
            $sequence->push(match ($transport) {
                'claude' => ['data' => [[
                    'id' => $model, 'display_name' => 'Model', 'capabilities' => ['effort' => ['supported' => true, $effort => ['supported' => true]]],
                ]], 'has_more' => false],
                'oauth' => ['models' => [[
                    'slug' => $model, 'display_name' => 'Model', 'visibility' => 'list',
                    'supported_reasoning_levels' => [['effort' => $effort, 'description' => 'Advertised effort']],
                ]]],
                default => ['data' => [['id' => $model, 'supported_reasoning_levels' => [['effort' => $effort, 'description' => 'Advertised effort']]]]],
            });
        }
        Http::fake([($transport === 'claude' ? 'https://api.anthropic.com/v1/models*' : 'https://api.openai.com/v1/models') => $sequence]);
        $provider = $transport === 'oauth' ? 'openai' : $transport;
        $service = app(InferenceService::class);
        $this->assertSame(['ultra'], $service->models($user, $provider, $connectionId)[0]['thinkingLevels']);
        try {
            $service->prompt($user, $provider, 'Hello', $connectionId, $model, 'ultra');
            $this->fail('Removed effort was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('thinkingLevel', $exception->errors());
        }
        Http::assertSentCount(2);
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST');
    }
}
