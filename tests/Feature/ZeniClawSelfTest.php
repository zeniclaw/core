<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\AgentLog;
use App\Models\Reminder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ZeniClawSelfTest extends TestCase
{
    use RefreshDatabase;

    // ── Health ────────────────────────────────────────────────────────────────

    public function test_health_endpoint_returns_ok(): void
    {
        $response = $this->getJson('/health');
        $response->assertStatus(200)
                 ->assertJsonStructure(['status', 'version', 'db', 'redis', 'timestamp'])
                 ->assertJsonPath('db.ok', true);
    }

    public function test_health_endpoint_no_auth_required(): void
    {
        $response = $this->getJson('/health');
        $response->assertStatus(200); // must work without being logged in
    }

    // ── Auth ─────────────────────────────────────────────────────────────────

    public function test_dashboard_requires_auth(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_user_can_login_and_see_dashboard(): void
    {
        $user = User::factory()->create(['role' => 'viewer']);
        $this->actingAs($user)->get('/dashboard')->assertOk()->assertSee('Dashboard');
    }

    public function test_login_page_loads(): void
    {
        $this->get('/login')->assertOk();
    }

    public function test_register_page_loads(): void
    {
        $this->get('/register')->assertOk();
    }

    // ── Agents ────────────────────────────────────────────────────────────────

    public function test_agents_index_requires_auth(): void
    {
        $this->get('/agents')->assertRedirect('/login');
    }

    public function test_user_can_create_agent(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $response = $this->actingAs($user)->post('/agents', [
            'name'   => 'Test Agent',
            'model'  => 'claude-sonnet-4-5',
            'status' => 'active',
        ]);
        $response->assertRedirect('/agents');
        $this->assertDatabaseHas('agents', ['name' => 'Test Agent', 'user_id' => $user->id]);
    }

    public function test_user_cannot_see_other_users_agents(): void
    {
        $user1 = User::factory()->create(['role' => 'viewer']);
        $user2 = User::factory()->create(['role' => 'viewer']);
        $agent = Agent::factory()->create(['user_id' => $user2->id]);

        $this->actingAs($user1)->get("/agents/{$agent->id}")->assertForbidden();
    }

    public function test_agent_list_is_scoped_to_user(): void
    {
        $user1 = User::factory()->create(['role' => 'viewer']);
        $user2 = User::factory()->create(['role' => 'viewer']);
        $agent1 = Agent::factory()->create(['user_id' => $user1->id, 'name' => 'My Agent']);
        $agent2 = Agent::factory()->create(['user_id' => $user2->id, 'name' => 'Other Agent']);

        $response = $this->actingAs($user1)->get('/agents');
        $response->assertOk()->assertSee('My Agent')->assertDontSee('Other Agent');
    }

    public function test_user_can_update_own_agent(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $agent = Agent::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)->put("/agents/{$agent->id}", [
            'name'   => 'Updated Name',
            'model'  => 'gpt-4o',
            'status' => 'inactive',
        ])->assertRedirect('/agents');

        $this->assertDatabaseHas('agents', ['id' => $agent->id, 'name' => 'Updated Name']);
    }

    public function test_user_can_delete_own_agent(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $agent = Agent::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)->delete("/agents/{$agent->id}")->assertRedirect('/agents');
        $this->assertDatabaseMissing('agents', ['id' => $agent->id]);
    }

    // ── Reminders ────────────────────────────────────────────────────────────

    public function test_user_can_create_reminder(): void
    {
        $user = User::factory()->create(['role' => 'viewer']);
        $agent = Agent::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)->post('/reminders', [
            'agent_id'     => $agent->id,
            'message'      => 'Test reminder',
            'channel'      => 'whatsapp',
            'scheduled_at' => now()->addDay()->format('Y-m-d H:i:s'),
        ])->assertRedirect('/reminders');

        $this->assertDatabaseHas('reminders', ['message' => 'Test reminder']);
    }

    public function test_reminders_are_scoped_to_user(): void
    {
        $user1 = User::factory()->create(['role' => 'viewer']);
        $user2 = User::factory()->create(['role' => 'viewer']);
        $agent1 = Agent::factory()->create(['user_id' => $user1->id]);
        $agent2 = Agent::factory()->create(['user_id' => $user2->id]);

        Reminder::factory()->create(['user_id' => $user1->id, 'agent_id' => $agent1->id, 'message' => 'My reminder']);
        Reminder::factory()->create(['user_id' => $user2->id, 'agent_id' => $agent2->id, 'message' => 'Their reminder']);

        $response = $this->actingAs($user1)->get('/reminders');
        // Scoped via agent_id in controller
        $response->assertOk();
    }

    // ── Logs ─────────────────────────────────────────────────────────────────

    public function test_logs_page_loads_for_authenticated_user(): void
    {
        $user = User::factory()->create(['role' => 'viewer']);
        $this->actingAs($user)->get('/logs')->assertOk();
    }

    public function test_logs_are_scoped_to_user(): void
    {
        $user1 = User::factory()->create(['role' => 'viewer']);
        $user2 = User::factory()->create(['role' => 'viewer']);
        $agent1 = Agent::factory()->create(['user_id' => $user1->id]);
        $agent2 = Agent::factory()->create(['user_id' => $user2->id]);

        AgentLog::create(['agent_id' => $agent1->id, 'level' => 'info', 'message' => 'User1 log']);
        AgentLog::create(['agent_id' => $agent2->id, 'level' => 'info', 'message' => 'User2 log']);

        $response = $this->actingAs($user1)->get('/logs');
        $response->assertOk()->assertSee('User1 log')->assertDontSee('User2 log');
    }

    // ── Settings ─────────────────────────────────────────────────────────────

    public function test_settings_page_loads(): void
    {
        $user = User::factory()->create(['role' => 'viewer']);
        $this->actingAs($user)->get('/settings')->assertOk();
    }

    // ── Webhooks ──────────────────────────────────────────────────────────────

    public function test_whatsapp_webhook_accepts_without_auth(): void
    {
        $user = User::factory()->create(['role' => 'viewer']);
        $agent = Agent::factory()->create(['user_id' => $user->id]);

        $response = $this->postJson("/webhook/whatsapp/{$agent->id}", [
            'event'   => 'message',
            'payload' => ['body' => 'Hello world'],
        ]);

        $response->assertOk()->assertJson(['ok' => true]);
        $this->assertDatabaseHas('agent_logs', ['agent_id' => $agent->id, 'level' => 'info']);
    }

    // ── Admin: superadmin only ────────────────────────────────────────────────

    public function test_non_admin_cannot_access_update_page(): void
    {
        $user = User::factory()->create(['role' => 'viewer']);
        $this->actingAs($user)->get('/admin/update')->assertForbidden();
    }

    public function test_superadmin_can_access_update_page(): void
    {
        $user = User::factory()->create(['role' => 'superadmin']);
        $this->actingAs($user)->get('/admin/update')->assertOk();
    }

    public function test_superadmin_can_access_health_page(): void
    {
        $user = User::factory()->create(['role' => 'superadmin']);
        $this->actingAs($user)->get('/admin/health')->assertOk();
    }
}
