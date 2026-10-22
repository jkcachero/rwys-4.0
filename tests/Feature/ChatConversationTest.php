<?php

namespace Tests\Feature;

use App\Inference\InferenceResult;
use App\Inference\InferenceService;
use App\Models\ChatConversation;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class ChatConversationTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_chat_is_owned_empty_and_encrypted_without_inference(): void
    {
        Http::preventStrayRequests();
        $user = User::factory()->create();
        $this->mock(InferenceService::class, function ($mock): void {
            $mock->shouldNotReceive('prompt');
            $mock->shouldReceive('status')->andReturn([]);
        });

        $id = $this->actingAs($user)->postJson('/chat/conversations', [
            'title' => 'Private title', 'user_id' => User::factory()->create()->id,
        ])->assertCreated()->assertJsonPath('conversation.title', 'Private title')->json('conversation.id');

        $conversation = $user->chatConversations()->findOrFail($id);
        $this->assertNotSame('Private title', $conversation->getRawOriginal('title'));
        $this->getJson('/chat/conversations/'.$id)->assertOk()->assertJsonPath('messages', [])->assertJsonPath('hasMore', false);
        $this->get('/chat')->assertOk()->assertInertia(fn ($page) => $page
            ->where('workspace.conversation.id', null)->where('workspace.conversation.status', 'empty'));
        Http::assertNothingSent();
    }

    public function test_prompt_persists_pair_and_reopens_without_model_calls(): void
    {
        $user = User::factory()->create();
        $conversation = ChatConversation::factory()->for($user)->create();
        $requestId = (string) Str::uuid();
        $this->mock(InferenceService::class, function ($mock) use ($user, $conversation): void {
            $mock->shouldReceive('prompt')->once()->withArgs(function (...$args) use ($user, $conversation): bool {
                $this->assertSame(0, DB::transactionLevel() - 1);
                $this->assertSame('pending', $conversation->messages()->sole()->status);

                return $args[0]->is($user) && array_slice($args, 1) === ['openai', 'Private question', null, null, 'low', []];
            })->andReturn(new InferenceResult('openai', 'actual-model', 'Private answer', 'correlation'));
            $mock->shouldReceive('status')->andReturn([]);
        });

        $payload = ['provider' => 'openai', 'prompt' => 'Private question', 'thinkingLevel' => 'low', 'conversationId' => $conversation->id, 'requestId' => $requestId];
        $this->actingAs($user)->postJson('/dashboard/prompt', $payload)->assertOk()
            ->assertJsonPath('text', 'Private answer')->assertJsonPath('conversationId', $conversation->id);
        $this->postJson('/dashboard/prompt', $payload)->assertOk()->assertJsonPath('text', 'Private answer');
        $this->getJson('/chat/conversations/'.$conversation->id)->assertOk()
            ->assertJsonPath('messages.0.requestId', $requestId)
            ->assertJsonPath('messages.1.requestId', $requestId);

        $this->assertSame(['completed', 'completed'], $conversation->messages()->orderBy('id')->pluck('status')->all());
        $this->assertStringNotContainsString('Private', $conversation->messages()->first()->getRawOriginal('content'));
        $this->get('/chat?conversation='.$conversation->id)->assertOk()->assertInertia(fn ($page) => $page
            ->where('workspace.conversation.id', $conversation->id)
            ->where('workspace.conversation.status', 'active')
            ->where('workspace.conversation.model', 'actual-model')
            ->where('workspace.conversation.messages.0.content', 'Private question')
            ->where('workspace.conversation.messages.1.content', 'Private answer'));
    }

    public function test_older_completed_request_requires_history_reload_without_inference(): void
    {
        Http::preventStrayRequests();
        $conversation = ChatConversation::factory()->create([
            'provider' => 'openai', 'auth_mode' => 'oauth', 'model' => 'latest-model',
        ]);
        $requestId = (string) Str::uuid();
        foreach ([$requestId, (string) Str::uuid()] as $id) {
            foreach (['user', 'assistant'] as $role) {
                $conversation->messages()->create([
                    'role' => $role, 'status' => 'completed', 'content' => $role,
                    'request_id' => $id,
                ]);
            }
        }
        $this->mock(InferenceService::class)->shouldNotReceive('prompt');

        $this->actingAs($conversation->user)->postJson('/dashboard/prompt', [
            'provider' => 'openai', 'prompt' => 'user', 'conversationId' => $conversation->id,
            'requestId' => $requestId,
        ])->assertUnprocessable()->assertJsonValidationErrors('requestId')
            ->assertJsonPath('errors.requestId.0', 'Already completed; reload history');

        $this->assertSame(4, $conversation->messages()->count());
        Http::assertNothingSent();
    }

    #[TestWith(['provider', 'claude'])]
    #[TestWith(['model', 'different-model'])]
    #[TestWith(['thinkingLevel', 'high'])]
    #[TestWith(['prompt', 'different question'])]
    #[TestWith(['connectionId', 'owned'])]
    #[TestWith(['connectionId', 'owned-claude'])]
    public function test_completed_request_rejects_changed_payload_without_inference(string $field, string $value): void
    {
        Http::preventStrayRequests();
        $conversation = ChatConversation::factory()->create([
            'provider' => 'openai', 'auth_mode' => 'oauth', 'model' => 'actual-model', 'thinking_level' => 'low',
        ]);
        $requestId = (string) Str::uuid();
        foreach (['user', 'assistant'] as $role) {
            $conversation->messages()->create([
                'role' => $role, 'status' => 'completed', 'content' => $role,
                'request_id' => $requestId,
            ]);
        }
        $payload = [
            'provider' => 'openai', 'prompt' => 'user', 'model' => 'actual-model', 'thinkingLevel' => 'low',
            'conversationId' => $conversation->id, 'requestId' => $requestId,
        ];
        if ($field === 'connectionId') {
            $value = (string) $conversation->user->aiConnections()->create([
                'provider' => $value === 'owned-claude' ? 'claude' : 'openai', 'label' => 'Other owned connection', 'auth_mode' => 'api_key',
                'credentials' => ['api_key' => 'fictional-key'], 'status' => 'configured',
            ])->id;
        }
        $payload[$field] = $value;
        $this->mock(InferenceService::class)->shouldNotReceive('prompt');

        $this->actingAs($conversation->user)->postJson('/dashboard/prompt', $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('requestId')
            ->assertJsonPath('errors.requestId.0', 'Already completed; reload history');

        $this->assertSame(2, $conversation->messages()->count());
        Http::assertNothingSent();
    }

    public function test_latest_owned_api_exchange_replays_with_normalized_provider_and_no_new_inference(): void
    {
        Http::preventStrayRequests();
        $user = User::factory()->create();
        $connection = $user->aiConnections()->create([
            'provider' => 'claude', 'label' => 'Owned', 'auth_mode' => 'api_key',
            'credentials' => ['api_key' => 'fictional-key'], 'status' => 'configured',
        ]);
        $conversation = ChatConversation::factory()->for($user)->create([
            'provider' => 'claude', 'auth_mode' => 'api_key', 'connection_id' => $connection->id,
            'model' => 'actual-model', 'thinking_level' => 'low',
        ]);
        $requestId = (string) Str::uuid();
        foreach (['user', 'assistant'] as $role) {
            $conversation->messages()->create([
                'role' => $role, 'status' => 'completed', 'content' => $role, 'request_id' => $requestId,
            ]);
        }
        $conversation->messages()->create([
            'role' => 'user', 'status' => 'failed', 'content' => 'Later failed attempt', 'request_id' => (string) Str::uuid(),
        ]);
        $this->mock(InferenceService::class)->shouldNotReceive('prompt');

        $this->actingAs($user)->postJson('/dashboard/prompt', [
            'provider' => 'anthropic', 'prompt' => 'user', 'connectionId' => $connection->id,
            'model' => 'actual-model', 'thinkingLevel' => 'low',
            'conversationId' => $conversation->id, 'requestId' => $requestId,
        ])->assertOk()->assertJsonPath('text', 'assistant')->assertJsonPath('provider', 'claude')
            ->assertJsonPath('model', 'actual-model')->assertJsonPath('correlationId', $requestId);

        $this->assertSame(3, $conversation->messages()->count());
        Http::assertNothingSent();
    }

    #[TestWith(['user'])]
    #[TestWith(['admin'])]
    public function test_other_owners_receive_404_even_as_admin(string $role): void
    {
        $conversation = ChatConversation::factory()->create();
        $this->mock(InferenceService::class)->shouldNotReceive('prompt');
        $this->actingAs(User::factory()->create(['role' => $role]));

        $this->getJson('/chat/conversations/'.$conversation->id)->assertNotFound();
        $this->deleteJson('/chat/conversations/'.$conversation->id)->assertNotFound();
        $this->get('/chat?conversation='.$conversation->id)->assertNotFound();
        $this->postJson('/dashboard/prompt', ['provider' => 'openai', 'prompt' => 'hello', 'conversationId' => $conversation->id])->assertNotFound();
        $this->getJson('/chat/conversations')->assertOk()->assertJsonPath('data', []);
        $this->assertModelExists($conversation);
    }

    public function test_failed_attempt_keeps_question_and_cannot_blindly_retry_request_id(): void
    {
        $conversation = ChatConversation::factory()->create();
        $this->mock(InferenceService::class)->shouldReceive('prompt')->once()->andThrow(new \RuntimeException('SECRET provider body'));
        $payload = ['provider' => 'openai', 'prompt' => 'Keep this question', 'conversationId' => $conversation->id, 'requestId' => (string) Str::uuid()];

        $this->actingAs($conversation->user)->postJson('/dashboard/prompt', $payload)->assertStatus(503)
            ->assertDontSee('SECRET');
        $this->postJson('/dashboard/prompt', $payload)->assertStatus(503);

        $message = $conversation->messages()->sole();
        $this->assertSame('failed', $message->status);
        $this->assertSame('Keep this question', $message->content);
    }

    public function test_only_bounded_completed_owned_pairs_are_forwarded_as_history(): void
    {
        $conversation = ChatConversation::factory()->create();
        $other = ChatConversation::factory()->for($conversation->user)->create();
        $other->messages()->create(['role' => 'user', 'status' => 'completed', 'content' => 'OTHER SESSION']);
        for ($i = 0; $i < 14; $i++) {
            foreach (['user', 'assistant'] as $role) {
                $conversation->messages()->create(['role' => $role, 'status' => 'completed', 'content' => str_repeat($role === 'user' ? 'Q' : 'A', 1000)]);
            }
        }
        $conversation->messages()->create(['role' => 'user', 'status' => 'failed', 'content' => 'FAILED']);
        $conversation->messages()->create(['role' => 'assistant', 'status' => 'pending', 'content' => 'PENDING']);
        $this->mock(InferenceService::class)->shouldReceive('prompt')->once()->withArgs(function (...$args): bool {
            $history = $args[6];
            $this->assertCount(16, $history);
            $this->assertSame(16000, array_sum(array_map(fn ($item) => mb_strlen($item['content']), $history)));
            $this->assertSame(array_merge(...array_fill(0, 8, ['user', 'assistant'])), array_column($history, 'role'));
            $this->assertStringNotContainsString('FORGED', json_encode($history));

            return true;
        })->andReturn(new InferenceResult('openai', 'model', 'answer', 'correlation'));

        $this->actingAs($conversation->user)->postJson('/dashboard/prompt', [
            'provider' => 'openai', 'prompt' => 'CURRENT', 'conversationId' => $conversation->id,
            'history' => [['role' => 'system', 'content' => 'FORGED']], 'role' => 'assistant',
        ])->assertOk();
    }

    public function test_conversation_list_and_older_messages_are_paginated_and_delete_cascades(): void
    {
        $this->freezeTime();
        $user = User::factory()->create();
        ChatConversation::factory()->count(21)->for($user)->create();
        $conversation = $user->chatConversations()->orderByDesc('id')->first();
        $ids = [];
        for ($i = 0; $i < 53; $i++) {
            $ids[] = $conversation->messages()->create(['role' => 'user', 'status' => 'failed', 'content' => 'Question '.$i])->id;
        }

        $this->actingAs($user)->getJson('/chat/conversations')->assertOk()->assertJsonCount(20, 'data')
            ->assertJsonPath('current_page', 1)->assertJsonPath('last_page', 2)->assertJsonPath('data.0.id', $conversation->id);
        $this->getJson('/chat/conversations?page=2')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/chat/conversations/'.$conversation->id)->assertOk()->assertJsonCount(50, 'messages')
            ->assertJsonPath('messages.0.id', $ids[3])->assertJsonPath('messages.49.id', $ids[52])
            ->assertJsonPath('hasMore', true)->assertJsonPath('nextBeforeId', $ids[3]);
        $this->getJson('/chat/conversations/'.$conversation->id.'?beforeId='.$ids[3])->assertOk()
            ->assertJsonCount(3, 'messages')->assertJsonPath('hasMore', false)->assertJsonPath('messages.0.id', $ids[0]);
        $this->deleteJson('/chat/conversations/'.$conversation->id)->assertOk()->assertExactJson(['status' => 'deleted']);

        $this->assertModelMissing($conversation);
        $this->assertDatabaseCount('chat_messages', 0);
    }

    public function test_pending_claim_and_held_lock_prevent_another_inference_call(): void
    {
        $conversation = ChatConversation::factory()->create();
        $requestId = (string) Str::uuid();
        $conversation->messages()->create(['role' => 'user', 'status' => 'pending', 'content' => 'hello', 'request_id' => $requestId]);
        $this->mock(InferenceService::class)->shouldNotReceive('prompt');
        $this->actingAs($conversation->user);
        $payload = ['provider' => 'openai', 'prompt' => 'hello', 'conversationId' => $conversation->id, 'requestId' => $requestId];

        $this->postJson('/dashboard/prompt', $payload)->assertStatus(503);
        $lock = Cache::lock('chat-conversation:'.$conversation->id, 180);
        $lock->get();
        try {
            $this->postJson('/dashboard/prompt', [...$payload, 'requestId' => (string) Str::uuid()])->assertStatus(503);
        } finally {
            $lock->release();
        }
        $this->assertSame(1, $conversation->messages()->count());
    }

    public function test_guest_and_unverified_users_cannot_access_conversation_endpoints(): void
    {
        $id = (string) Str::uuid();
        foreach ([['GET', '/chat/conversations'], ['POST', '/chat/conversations'], ['GET', '/chat/conversations/'.$id], ['DELETE', '/chat/conversations/'.$id], ['GET', '/chat/models?provider=openai']] as [$method, $uri]) {
            $this->json($method, $uri)->assertUnauthorized();
        }
        $this->actingAs(User::factory()->unverified()->create());
        $this->getJson('/chat/conversations')->assertForbidden();
        $this->postJson('/chat/conversations')->assertForbidden();
        $this->deleteJson('/chat/conversations/'.$id)->assertForbidden();
    }

    public function test_invalid_optional_fields_and_foreign_connection_are_rejected_before_inference(): void
    {
        $owner = User::factory()->create();
        $connection = $owner->aiConnections()->create(['provider' => 'openai', 'label' => 'Private', 'auth_mode' => 'api_key', 'credentials' => ['api_key' => 'SECRET'], 'status' => 'active']);
        $this->mock(InferenceService::class)->shouldNotReceive('prompt');
        $this->actingAs(User::factory()->create());

        $this->postJson('/dashboard/prompt', ['provider' => 'openai', 'prompt' => 'hello', 'connectionId' => $connection->id])->assertNotFound();
        $this->postJson('/dashboard/prompt', ['provider' => 'openai', 'prompt' => 'hello', 'conversationId' => 'invalid', 'requestId' => 'invalid', 'thinkingLevel' => str_repeat('x', 21)])
            ->assertUnprocessable()->assertJsonValidationErrors(['conversationId', 'requestId', 'thinkingLevel']);
        $this->postJson('/chat/conversations', ['title' => str_repeat('x', 101)])->assertUnprocessable()->assertJsonValidationErrors('title');
        $this->assertDatabaseCount('chat_messages', 0);
    }

    public function test_legacy_prompt_without_conversation_creates_one(): void
    {
        $user = User::factory()->create();
        $this->mock(InferenceService::class)->shouldReceive('prompt')->once()->andReturn(new InferenceResult('openai', 'actual', 'answer', 'correlation'));

        $response = $this->actingAs($user)->postJson('/dashboard/prompt', ['provider' => 'openai', 'prompt' => 'hello'])->assertOk();

        $this->assertSame(2, $user->chatConversations()->findOrFail($response->json('conversationId'))->messages()->count());
    }

    public function test_delete_requires_csrf_and_matching_token_allows_it(): void
    {
        $conversation = ChatConversation::factory()->create();
        $this->app->instance(PreventRequestForgery::class, new class($this->app, $this->app['encrypter']) extends PreventRequestForgery
        {
            protected function runningUnitTests(): bool
            {
                return false;
            }
        });
        $this->actingAs($conversation->user);

        $this->deleteJson('/chat/conversations/'.$conversation->id)->assertStatus(419);
        $this->assertModelExists($conversation);
        $this->withSession(['_token' => 'test-csrf-token'])->deleteJson('/chat/conversations/'.$conversation->id, [], ['X-CSRF-TOKEN' => 'test-csrf-token'])
            ->assertOk()->assertExactJson(['status' => 'deleted']);
        $this->assertModelMissing($conversation);
    }

    public function test_history_keeps_only_the_latest_twenty_messages(): void
    {
        $conversation = ChatConversation::factory()->create();
        for ($i = 0; $i < 12; $i++) {
            foreach (['user', 'assistant'] as $role) {
                $conversation->messages()->create(['role' => $role, 'status' => 'completed', 'content' => $role.$i]);
            }
        }
        $this->mock(InferenceService::class)->shouldReceive('prompt')->once()->withArgs(function (...$args): bool {
            $this->assertCount(20, $args[6]);
            $this->assertSame(['role' => 'user', 'content' => 'user2'], $args[6][0]);
            $this->assertSame(['role' => 'assistant', 'content' => 'assistant11'], $args[6][19]);

            return true;
        })->andReturn(new InferenceResult('openai', 'actual', 'answer', 'correlation'));

        $this->actingAs($conversation->user)->postJson('/dashboard/prompt', [
            'provider' => 'openai', 'prompt' => 'current', 'conversationId' => $conversation->id,
        ])->assertOk();
    }

    public function test_new_request_does_not_replay_or_forward_an_old_pending_attempt(): void
    {
        $conversation = ChatConversation::factory()->create();
        $conversation->messages()->create([
            'role' => 'user', 'status' => 'pending', 'content' => 'Old interrupted attempt', 'request_id' => (string) Str::uuid(),
        ]);
        $this->mock(InferenceService::class)->shouldReceive('prompt')->once()
            ->withArgs(fn (...$args): bool => $args[2] === 'New question' && $args[6] === [])
            ->andReturn(new InferenceResult('openai', 'actual', 'answer', 'correlation'));

        $this->actingAs($conversation->user)->postJson('/dashboard/prompt', [
            'provider' => 'openai', 'prompt' => 'New question', 'conversationId' => $conversation->id, 'requestId' => (string) Str::uuid(),
        ])->assertOk();

        $this->assertSame(['pending', 'completed', 'completed'], $conversation->messages()->orderBy('id')->pluck('status')->all());
    }

    public function test_success_stores_only_selected_owned_connection_metadata_and_nulls_deleted_connection(): void
    {
        $user = User::factory()->create();
        $connection = $user->aiConnections()->create([
            'provider' => 'claude', 'label' => 'Private', 'auth_mode' => 'api_key',
            'credentials' => ['api_key' => 'fictional-secret-key'], 'status' => 'configured',
        ]);
        $this->mock(InferenceService::class)->shouldReceive('prompt')->once()
            ->withArgs(fn (...$args): bool => $args[3] === $connection->id && $args[4] === 'selected' && $args[5] === null)
            ->andReturn(new InferenceResult('claude', 'actual', 'answer', 'correlation'));

        $id = $this->actingAs($user)->postJson('/dashboard/prompt', [
            'provider' => 'anthropic', 'prompt' => 'question', 'connectionId' => $connection->id, 'model' => 'selected',
        ])->assertOk()->json('conversationId');

        $this->getJson('/chat/conversations/'.$id)->assertOk()->assertJsonPath('conversation.authMode', 'api_key')
            ->assertJsonPath('conversation.connectionId', $connection->id)->assertJsonPath('conversation.model', 'actual')
            ->assertJsonPath('conversation.thinkingLevel', null)->assertDontSee('fictional-secret-key');
        $connection->delete();
        $this->getJson('/chat/conversations/'.$id)->assertOk()->assertJsonPath('conversation.connectionId', null);
        $user->delete();
        $this->assertDatabaseCount('chat_conversations', 0);
        $this->assertDatabaseCount('chat_messages', 0);
    }

    public function test_model_validation_errors_keep_422_and_preserve_failed_question(): void
    {
        $conversation = ChatConversation::factory()->create();
        $this->mock(InferenceService::class)->shouldReceive('prompt')->once()
            ->andThrow(ValidationException::withMessages(['model' => 'Select an available model.']));

        $this->actingAs($conversation->user)->postJson('/dashboard/prompt', [
            'provider' => 'openai', 'prompt' => 'question', 'model' => 'invalid', 'conversationId' => $conversation->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('model');

        $this->assertSame('failed', $conversation->messages()->sole()->status);
    }

    public function test_model_picker_forwards_validated_selection_and_has_separate_rate_limit(): void
    {
        $user = User::factory()->create();
        $models = [['slug' => 'model', 'displayName' => 'Model', 'thinkingLevels' => ['low']]];
        $this->mock(InferenceService::class)->shouldReceive('models')->times(30)
            ->withArgs(fn ($owner, $provider, $connectionId, $refresh): bool => $owner->is($user) && $provider === 'openai' && $connectionId === 17 && $refresh)
            ->andReturn($models);
        $this->actingAs($user);

        for ($i = 0; $i < 30; $i++) {
            $this->getJson('/chat/models?provider=openai&connectionId=17&refresh=1')->assertOk()->assertExactJson(['models' => $models, 'cacheSeconds' => 60]);
        }
        $this->getJson('/chat/models?provider=openai')->assertStatus(429);
        $this->postJson('/chat/conversations')->assertCreated();
    }
}
