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
        $response = $this->get('/dashboard');

        $response->assertRedirect('/login');
    }

    public function test_verified_users_receive_a_read_only_workspace(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
        ]);

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('dashboard')
                ->where('workspace.status', 'ready')
                ->where('workspace.conversation.status', 'empty')
                ->where('workspace.provider.status', 'deferred')
                ->where('workspace.capabilities.2.status', 'disabled')
                ->missing('workspace.user.email'));
    }
}
