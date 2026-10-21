<?php

namespace Tests\Feature;

use App\Models\User;
use App\Workspace\Contracts\WorkspaceAnalyst;
use App\Workspace\Services\LaravelAiWorkspaceAnalyst;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WorkspaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_cannot_access_the_workspace(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_guests_cannot_access_chat(): void
    {
        $this->get('/chat')->assertRedirect('/login');
    }

    public function test_verified_users_can_open_chat_without_a_provider_request(): void
    {
        Http::preventStrayRequests();
        Http::fake();
        $this->actingAs(User::factory()->create())->get('/chat')->assertOk()
            ->assertInertia(fn ($page) => $page->component('chat')
                ->where('workspace.connections', [])
                ->where('workspace.provider.status', 'unavailable'));
        Http::assertNothingSent();
    }

    public function test_verified_users_receive_a_functional_workspace(): void
    {
        Http::preventStrayRequests();
        Http::fake();
        $user = User::factory()->create(['email_verified_at' => now()]);

        $this->actingAs($user)->get('/dashboard')->assertOk()->assertInertia(fn ($page) => $page
            ->component('dashboard')
            ->where('workspace.status', 'unavailable')
            ->where('workspace.provider.status', 'unavailable')
            ->where('workspace.capabilities.0.status', 'unavailable')
            ->where('workspace.capabilities.2.status', 'unavailable')
            ->where('workspace.connections', [])
            ->missing('workspace.user.email'));
        Http::assertNothingSent();
    }

    public function test_verified_users_can_submit_a_bounded_read_request(): void
    {
        config(['services.workspace.ai_driver' => 'fake']);
        $user = User::factory()->create(['email_verified_at' => now()]);

        $this->actingAs($user)->postJson('/workspace/read', [
            'provider' => 'adkit', 'scopeId' => 'default', 'question' => 'Which accounts are connected?',
        ])->assertOk()
            ->assertJsonPath('message.sources.0.label', 'Demo campaign account')
            ->assertJsonPath('message.content', 'Accounts available: 1. Request analyzed: Which accounts are connected?');
    }

    public function test_workspace_uses_the_configured_ai_analyst_boundary(): void
    {
        config(['services.workspace.ai_driver' => 'fake']);

        $user = User::factory()->create(['email_verified_at' => now()]);

        $this->actingAs($user)->postJson('/workspace/read', [
            'provider' => 'zernio', 'scopeId' => 'default', 'question' => 'Summarize connected accounts.',
        ])->assertOk()->assertJsonPath(
            'message.content',
            'Accounts available: 1. Request analyzed: Summarize connected accounts.',
        );
    }

    public function test_laravel_ai_can_be_selected_without_calling_the_provider_during_boot(): void
    {
        config(['services.workspace.ai_driver' => 'laravel-ai']);

        $this->assertInstanceOf(LaravelAiWorkspaceAnalyst::class, app(WorkspaceAnalyst::class));
    }

    public function test_read_requests_reject_unapproved_providers(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);

        $this->actingAs($user)->postJson('/workspace/read', [
            'provider' => 'unknown', 'scopeId' => 'default', 'question' => 'Run it',
        ])->assertUnprocessable();
    }

    public function test_live_reads_are_unavailable_and_prompt_validation_is_json(): void
    {
        config(['services.workspace.ai_driver' => 'laravel-ai']);
        $user = User::factory()->create();
        $this->actingAs($user)->postJson('/workspace/read', [
            'provider' => 'adkit', 'question' => 'List accounts', 'scopeId' => 'guessed-owner',
        ])->assertStatus(503);
        $this->post('/dashboard/prompt', ['provider' => 'invalid'], ['Accept' => 'text/html'])
            ->assertUnprocessable()->assertJsonValidationErrors(['provider', 'prompt']);
    }
}
