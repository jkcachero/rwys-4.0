<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkspaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_cannot_access_the_workspace(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_verified_users_receive_a_functional_workspace(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);

        $this->actingAs($user)->get('/dashboard')->assertOk()->assertInertia(fn ($page) => $page
            ->component('dashboard')
            ->where('workspace.status', 'ready')
            ->where('workspace.provider.status', 'ready')
            ->where('workspace.capabilities.0.status', 'ready')
            ->where('workspace.capabilities.2.status', 'approval_required')
            ->missing('workspace.user.email'));
    }

    public function test_verified_users_can_submit_a_bounded_read_request(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);

        $this->actingAs($user)->postJson('/workspace/read', [
            'provider' => 'adkit', 'scopeId' => 'default', 'question' => 'Which accounts are connected?',
        ])->assertOk()->assertJsonPath('message.sources.0.label', 'Demo campaign account');
    }

    public function test_read_requests_reject_unapproved_providers(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);

        $this->actingAs($user)->postJson('/workspace/read', [
            'provider' => 'unknown', 'scopeId' => 'default', 'question' => 'Run it',
        ])->assertUnprocessable();
    }
}
