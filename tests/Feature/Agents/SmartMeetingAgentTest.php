<?php

namespace Tests\Feature\Agents;

use App\Models\Agent;
use App\Models\AgentSession;
use App\Models\MeetingSession;
use App\Models\User;
use App\Services\AgentContext;
use App\Services\Agents\SmartMeetingAgent;
use App\Services\MeetingAnalyzer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class SmartMeetingAgentTest extends TestCase
{
    use RefreshDatabase;

    private string $testPhone = '33612345678@s.whatsapp.net';
    private SmartMeetingAgent $agent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agent = new SmartMeetingAgent();
    }

    protected function tearDown(): void
    {
        Cache::forget(MeetingSession::getActiveCacheKey($this->testPhone));
        parent::tearDown();
    }

    private function makeContext(string $body): AgentContext
    {
        $user = User::factory()->create();
        $agent = Agent::factory()->create(['user_id' => $user->id]);
        $session = AgentSession::create([
            'agent_id' => $agent->id,
            'session_key' => AgentSession::keyFor($agent->id, 'whatsapp', $this->testPhone),
            'channel' => 'whatsapp',
            'peer_id' => $this->testPhone,
            'last_message_at' => now(),
        ]);

        return new AgentContext(
            agent: $agent,
            session: $session,
            from: $this->testPhone,
            senderName: 'Test User',
            body: $body,
            hasMedia: false,
            mediaUrl: null,
            mimetype: null,
            media: null,
            routedAgent: 'smart_meeting',
            routedModel: 'claude-haiku-4-5-20251001',
        );
    }

    // ── Agent basics ──────────────────────────────────────────────────────

    public function test_agent_name_returns_smart_meeting(): void
    {
        $this->assertEquals('smart_meeting', $this->agent->name());
    }

    public function test_agent_can_handle_when_routed(): void
    {
        $context = $this->makeContext('reunion start standup');
        $this->assertTrue($this->agent->canHandle($context));
    }

    // ── MeetingSession model ──────────────────────────────────────────────

    public function test_meeting_session_can_be_created(): void
    {
        $user = User::factory()->create();
        $agent = Agent::factory()->create(['user_id' => $user->id]);

        $meeting = MeetingSession::create([
            'user_phone' => $this->testPhone,
            'agent_id' => $agent->id,
            'group_name' => 'Test Meeting',
            'status' => 'active',
            'started_at' => now(),
            'messages_captured' => [],
        ]);

        $this->assertDatabaseHas('meeting_sessions', [
            'user_phone' => $this->testPhone,
            'group_name' => 'Test Meeting',
            'status' => 'active',
        ]);
    }

    public function test_meeting_session_can_add_messages(): void
    {
        $user = User::factory()->create();
        $agent = Agent::factory()->create(['user_id' => $user->id]);

        $meeting = MeetingSession::create([
            'user_phone' => $this->testPhone,
            'agent_id' => $agent->id,
            'group_name' => 'Sprint Review',
            'status' => 'active',
            'started_at' => now(),
            'messages_captured' => [],
        ]);

        $meeting->addMessage('Alice', 'On doit livrer le module paiement vendredi');
        $meeting->addMessage('Bob', 'Je prends la partie front');

        $meeting->refresh();
        $messages = $meeting->messages_captured;

        $this->assertCount(2, $messages);
        $this->assertEquals('Alice', $messages[0]['sender']);
        $this->assertEquals('On doit livrer le module paiement vendredi', $messages[0]['content']);
        $this->assertEquals('Bob', $messages[1]['sender']);
    }

    public function test_meeting_session_cache_activation(): void
    {
        $user = User::factory()->create();
        $agent = Agent::factory()->create(['user_id' => $user->id]);

        $meeting = MeetingSession::create([
            'user_phone' => $this->testPhone,
            'agent_id' => $agent->id,
            'group_name' => 'Standup',
            'status' => 'active',
            'started_at' => now(),
            'messages_captured' => [],
        ]);

        // Before activation
        $this->assertNull(MeetingSession::getActive($this->testPhone));

        // Activate
        $meeting->activate();
        $active = MeetingSession::getActive($this->testPhone);
        $this->assertNotNull($active);
        $this->assertEquals($meeting->id, $active->id);

        // Deactivate
        $meeting->deactivate();
        $this->assertNull(MeetingSession::getActive($this->testPhone));
    }

    // ── MeetingAnalyzer ───────────────────────────────────────────────────

    public function test_meeting_analyzer_handles_empty_messages(): void
    {
        $analyzer = new MeetingAnalyzer();
        $result = $analyzer->analyze([], 'Empty Meeting');

        $this->assertArrayHasKey('decisions', $result);
        $this->assertArrayHasKey('action_items', $result);
        $this->assertArrayHasKey('risks', $result);
        $this->assertArrayHasKey('next_steps', $result);
        $this->assertArrayHasKey('summary', $result);
        $this->assertEmpty($result['decisions']);
        $this->assertEmpty($result['action_items']);
    }

    // ── Meeting flow ──────────────────────────────────────────────────────

    public function test_help_message_when_no_command_matches(): void
    {
        $context = $this->makeContext('aide reunion');

        // Mock sendText to avoid HTTP calls
        $agent = $this->getMockBuilder(SmartMeetingAgent::class)
            ->onlyMethods(['sendText'])
            ->getMock();
        $agent->expects($this->once())->method('sendText');

        $result = $agent->handle($context);
        $this->assertEquals('reply', $result->action);
        $this->assertStringContainsString('reunion start', $result->reply);
    }

    public function test_cannot_start_meeting_when_one_already_active(): void
    {
        $user = User::factory()->create();
        $agentModel = Agent::factory()->create(['user_id' => $user->id]);

        // Create an active meeting
        $meeting = MeetingSession::create([
            'user_phone' => $this->testPhone,
            'agent_id' => $agentModel->id,
            'group_name' => 'Existing Meeting',
            'status' => 'active',
            'started_at' => now(),
            'messages_captured' => [],
        ]);
        $meeting->activate();

        $context = $this->makeContext('reunion start nouveau');

        $agent = $this->getMockBuilder(SmartMeetingAgent::class)
            ->onlyMethods(['sendText'])
            ->getMock();
        $agent->expects($this->once())->method('sendText');

        $result = $agent->handle($context);
        $this->assertEquals('reply', $result->action);
        $this->assertStringContainsString('deja en cours', $result->reply);
    }

    public function test_end_meeting_without_active_returns_error(): void
    {
        $context = $this->makeContext('reunion end');

        $agent = $this->getMockBuilder(SmartMeetingAgent::class)
            ->onlyMethods(['sendText'])
            ->getMock();
        $agent->expects($this->once())->method('sendText');

        $result = $agent->handle($context);
        $this->assertEquals('reply', $result->action);
        $this->assertStringContainsString('Aucune reunion en cours', $result->reply);
    }

    // ── New features v1.1.0 ───────────────────────────────────────────────

    public function test_status_when_no_active_meeting(): void
    {
        $context = $this->makeContext('reunion status');

        $agent = $this->getMockBuilder(SmartMeetingAgent::class)
            ->onlyMethods(['sendText'])
            ->getMock();
        $agent->expects($this->once())->method('sendText');

        $result = $agent->handle($context);
        $this->assertEquals('reply', $result->action);
        $this->assertStringContainsString('Aucune reunion en cours', $result->reply);
        $this->assertEquals('no_active_meeting', $result->metadata['action']);
    }

    public function test_status_shows_active_meeting_info(): void
    {
        $user = User::factory()->create();
        $agentModel = Agent::factory()->create(['user_id' => $user->id]);

        $meeting = MeetingSession::create([
            'user_phone' => $this->testPhone,
            'agent_id' => $agentModel->id,
            'group_name' => 'Sprint Planning',
            'status' => 'active',
            'started_at' => now()->subMinutes(15),
            'messages_captured' => [
                ['sender' => 'Alice', 'content' => 'Hello', 'timestamp' => now()->toISOString()],
                ['sender' => 'Bob', 'content' => 'Hi', 'timestamp' => now()->toISOString()],
            ],
        ]);
        $meeting->activate();

        $context = $this->makeContext('reunion status');

        $agent = $this->getMockBuilder(SmartMeetingAgent::class)
            ->onlyMethods(['sendText'])
            ->getMock();
        $agent->expects($this->once())->method('sendText');

        $result = $agent->handle($context);
        $this->assertEquals('reply', $result->action);
        $this->assertStringContainsString('Sprint Planning', $result->reply);
        $this->assertEquals('meeting_status', $result->metadata['action']);
        $this->assertEquals(2, $result->metadata['messages_count']);
        $this->assertContains('Alice', $result->metadata['participants']);
        $this->assertContains('Bob', $result->metadata['participants']);
    }

    public function test_list_meetings_when_none_exist(): void
    {
        $context = $this->makeContext('reunion list');

        $agent = $this->getMockBuilder(SmartMeetingAgent::class)
            ->onlyMethods(['sendText'])
            ->getMock();
        $agent->expects($this->once())->method('sendText');

        $result = $agent->handle($context);
        $this->assertEquals('reply', $result->action);
        $this->assertEquals('no_meetings_found', $result->metadata['action']);
    }

    public function test_list_meetings_shows_completed_meetings(): void
    {
        $user = User::factory()->create();
        $agentModel = Agent::factory()->create(['user_id' => $user->id]);

        MeetingSession::create([
            'user_phone' => $this->testPhone,
            'agent_id' => $agentModel->id,
            'group_name' => 'Weekly Sync',
            'status' => 'completed',
            'started_at' => now()->subHour(),
            'ended_at' => now(),
            'messages_captured' => [['sender' => 'A', 'content' => 'x', 'timestamp' => now()->toISOString()]],
        ]);

        $context = $this->makeContext('reunion list');

        $agent = $this->getMockBuilder(SmartMeetingAgent::class)
            ->onlyMethods(['sendText'])
            ->getMock();
        $agent->expects($this->once())->method('sendText');

        $result = $agent->handle($context);
        $this->assertEquals('reply', $result->action);
        $this->assertEquals('meeting_list', $result->metadata['action']);
        $this->assertEquals(1, $result->metadata['count']);
        $this->assertStringContainsString('Weekly Sync', $result->reply);
    }

    public function test_start_meeting_preserves_group_name_case(): void
    {
        $context = $this->makeContext('reunion start Sprint Review Q1');

        $agent = $this->getMockBuilder(SmartMeetingAgent::class)
            ->onlyMethods(['sendText'])
            ->getMock();
        $agent->expects($this->once())->method('sendText');

        $result = $agent->handle($context);
        $this->assertEquals('reply', $result->action);
        $this->assertEquals('meeting_started', $result->metadata['action']);

        $meeting = MeetingSession::find($result->metadata['meeting_id']);
        $this->assertEquals('Sprint Review Q1', $meeting->group_name);
    }

    public function test_message_capture_shows_cap_warning_at_limit(): void
    {
        $user = User::factory()->create();
        $agentModel = Agent::factory()->create(['user_id' => $user->id]);

        // Create 500 messages to hit the cap
        $messages = [];
        for ($i = 0; $i < 500; $i++) {
            $messages[] = ['sender' => 'User', 'content' => "msg {$i}", 'timestamp' => now()->toISOString()];
        }

        $meeting = MeetingSession::create([
            'user_phone' => $this->testPhone,
            'agent_id' => $agentModel->id,
            'group_name' => 'Big Meeting',
            'status' => 'active',
            'started_at' => now(),
            'messages_captured' => $messages,
        ]);
        $meeting->activate();

        $context = $this->makeContext('Un autre message');

        $agent = $this->getMockBuilder(SmartMeetingAgent::class)
            ->onlyMethods(['sendText'])
            ->getMock();
        $agent->expects($this->once())->method('sendText');

        $result = $agent->handle($context);
        $this->assertEquals('silent', $result->action);
        $this->assertEquals('message_cap_reached', $result->metadata['action']);
    }

    public function test_meeting_session_scopes(): void
    {
        $user = User::factory()->create();
        $agent = Agent::factory()->create(['user_id' => $user->id]);

        MeetingSession::create([
            'user_phone' => $this->testPhone,
            'agent_id' => $agent->id,
            'group_name' => 'Completed Meeting',
            'status' => 'completed',
            'started_at' => now()->subHour(),
            'ended_at' => now(),
            'messages_captured' => [['sender' => 'A', 'content' => 'test', 'timestamp' => now()->toISOString()]],
        ]);

        MeetingSession::create([
            'user_phone' => 'other@s.whatsapp.net',
            'agent_id' => $agent->id,
            'group_name' => 'Other Meeting',
            'status' => 'completed',
            'started_at' => now(),
            'ended_at' => now(),
            'messages_captured' => [],
        ]);

        $userMeetings = MeetingSession::forUser($this->testPhone)->completed()->get();
        $this->assertCount(1, $userMeetings);
        $this->assertEquals('Completed Meeting', $userMeetings->first()->group_name);
    }

    // ── New features v1.2.0 ───────────────────────────────────────────────

    public function test_version_is_1_2_0(): void
    {
        $this->assertEquals('1.2.0', $this->agent->version());
    }

    public function test_cancel_meeting_without_active_returns_error(): void
    {
        $context = $this->makeContext('reunion cancel');

        $agent = $this->getMockBuilder(SmartMeetingAgent::class)
            ->onlyMethods(['sendText'])
            ->getMock();
        $agent->expects($this->once())->method('sendText');

        $result = $agent->handle($context);
        $this->assertEquals('reply', $result->action);
        $this->assertEquals('no_active_meeting', $result->metadata['action']);
        $this->assertStringContainsString('annuler', $result->reply);
    }

    public function test_cancel_meeting_marks_as_cancelled(): void
    {
        $user = User::factory()->create();
        $agentModel = Agent::factory()->create(['user_id' => $user->id]);

        $meeting = MeetingSession::create([
            'user_phone' => $this->testPhone,
            'agent_id' => $agentModel->id,
            'group_name' => 'Meeting a Annuler',
            'status' => 'active',
            'started_at' => now()->subMinutes(5),
            'messages_captured' => [
                ['sender' => 'Alice', 'content' => 'test', 'timestamp' => now()->toISOString()],
            ],
        ]);
        $meeting->activate();

        $context = $this->makeContext('reunion cancel');

        $agent = $this->getMockBuilder(SmartMeetingAgent::class)
            ->onlyMethods(['sendText'])
            ->getMock();
        $agent->expects($this->once())->method('sendText');

        $result = $agent->handle($context);
        $this->assertEquals('reply', $result->action);
        $this->assertEquals('meeting_cancelled', $result->metadata['action']);
        $this->assertStringContainsString('Meeting a Annuler', $result->reply);

        $this->assertDatabaseHas('meeting_sessions', [
            'id' => $meeting->id,
            'status' => 'cancelled',
        ]);
        $this->assertNull(MeetingSession::getActive($this->testPhone));
    }

    public function test_stats_when_no_meetings(): void
    {
        $context = $this->makeContext('reunion stats');

        $agent = $this->getMockBuilder(SmartMeetingAgent::class)
            ->onlyMethods(['sendText'])
            ->getMock();
        $agent->expects($this->once())->method('sendText');

        $result = $agent->handle($context);
        $this->assertEquals('reply', $result->action);
        $this->assertEquals('stats_no_data', $result->metadata['action']);
    }

    public function test_stats_shows_meeting_statistics(): void
    {
        $user = User::factory()->create();
        $agentModel = Agent::factory()->create(['user_id' => $user->id]);

        MeetingSession::create([
            'user_phone' => $this->testPhone,
            'agent_id' => $agentModel->id,
            'group_name' => 'Sprint Planning',
            'status' => 'completed',
            'started_at' => now()->subHours(2),
            'ended_at' => now()->subHour(),
            'messages_captured' => [
                ['sender' => 'Alice', 'content' => 'Hello', 'timestamp' => now()->toISOString()],
                ['sender' => 'Bob', 'content' => 'Hi', 'timestamp' => now()->toISOString()],
            ],
            'summary' => json_encode([
                'action_items' => [['task' => 'Do something', 'assignee' => 'Alice', 'deadline' => null]],
                'decisions' => [], 'risks' => [], 'next_steps' => [], 'participants' => ['Alice', 'Bob'], 'summary' => 'Test',
            ]),
        ]);

        $context = $this->makeContext('reunion stats');

        $agent = $this->getMockBuilder(SmartMeetingAgent::class)
            ->onlyMethods(['sendText'])
            ->getMock();
        $agent->expects($this->once())->method('sendText');

        $result = $agent->handle($context);
        $this->assertEquals('reply', $result->action);
        $this->assertEquals('meeting_stats', $result->metadata['action']);
        $this->assertEquals(1, $result->metadata['total_meetings']);
        $this->assertEquals(2, $result->metadata['total_messages']);
        $this->assertEquals(1, $result->metadata['total_action_items']);
        $this->assertStringContainsString('Sprint Planning', $result->reply);
    }

    public function test_search_meetings_missing_term(): void
    {
        $context = $this->makeContext('reunion search');

        $agent = $this->getMockBuilder(SmartMeetingAgent::class)
            ->onlyMethods(['sendText'])
            ->getMock();
        $agent->expects($this->once())->method('sendText');

        $result = $agent->handle($context);
        $this->assertEquals('reply', $result->action);
        $this->assertEquals('search_missing_term', $result->metadata['action']);
    }

    public function test_search_meetings_no_results(): void
    {
        $context = $this->makeContext('reunion search introuvable');

        $agent = $this->getMockBuilder(SmartMeetingAgent::class)
            ->onlyMethods(['sendText'])
            ->getMock();
        $agent->expects($this->once())->method('sendText');

        $result = $agent->handle($context);
        $this->assertEquals('reply', $result->action);
        $this->assertEquals('search_no_results', $result->metadata['action']);
        $this->assertEquals('introuvable', $result->metadata['term']);
    }

    public function test_search_meetings_finds_by_name(): void
    {
        $user = User::factory()->create();
        $agentModel = Agent::factory()->create(['user_id' => $user->id]);

        MeetingSession::create([
            'user_phone' => $this->testPhone,
            'agent_id' => $agentModel->id,
            'group_name' => 'Sprint Review Q1',
            'status' => 'completed',
            'started_at' => now()->subHour(),
            'ended_at' => now(),
            'messages_captured' => [],
        ]);

        $context = $this->makeContext('reunion search sprint');

        $agent = $this->getMockBuilder(SmartMeetingAgent::class)
            ->onlyMethods(['sendText'])
            ->getMock();
        $agent->expects($this->once())->method('sendText');

        $result = $agent->handle($context);
        $this->assertEquals('reply', $result->action);
        $this->assertEquals('search_results', $result->metadata['action']);
        $this->assertEquals(1, $result->metadata['count']);
        $this->assertStringContainsString('Sprint Review Q1', $result->reply);
    }

    public function test_list_meetings_with_custom_limit(): void
    {
        $user = User::factory()->create();
        $agentModel = Agent::factory()->create(['user_id' => $user->id]);

        for ($i = 1; $i <= 8; $i++) {
            MeetingSession::create([
                'user_phone' => $this->testPhone,
                'agent_id' => $agentModel->id,
                'group_name' => "Meeting {$i}",
                'status' => 'completed',
                'started_at' => now()->subHours($i + 1),
                'ended_at' => now()->subHours($i),
                'messages_captured' => [],
            ]);
        }

        $context = $this->makeContext('reunion list 3');

        $agent = $this->getMockBuilder(SmartMeetingAgent::class)
            ->onlyMethods(['sendText'])
            ->getMock();
        $agent->expects($this->once())->method('sendText');

        $result = $agent->handle($context);
        $this->assertEquals('reply', $result->action);
        $this->assertEquals('meeting_list', $result->metadata['action']);
        $this->assertEquals(3, $result->metadata['count']);
        // Should mention total count
        $this->assertStringContainsString('8 reunions', $result->reply);
    }

    public function test_status_shows_last_messages(): void
    {
        $user = User::factory()->create();
        $agentModel = Agent::factory()->create(['user_id' => $user->id]);

        $meeting = MeetingSession::create([
            'user_phone' => $this->testPhone,
            'agent_id' => $agentModel->id,
            'group_name' => 'Demo',
            'status' => 'active',
            'started_at' => now()->subMinutes(10),
            'messages_captured' => [
                ['sender' => 'Alice', 'content' => 'Premier message', 'timestamp' => now()->toISOString()],
                ['sender' => 'Bob', 'content' => 'Deuxieme message', 'timestamp' => now()->toISOString()],
                ['sender' => 'Alice', 'content' => 'Troisieme message', 'timestamp' => now()->toISOString()],
            ],
        ]);
        $meeting->activate();

        $context = $this->makeContext('reunion status');

        $agent = $this->getMockBuilder(SmartMeetingAgent::class)
            ->onlyMethods(['sendText'])
            ->getMock();
        $agent->expects($this->once())->method('sendText');

        $result = $agent->handle($context);
        $this->assertEquals('reply', $result->action);
        $this->assertStringContainsString('Derniers messages', $result->reply);
        $this->assertStringContainsString('Troisieme message', $result->reply);
    }

    public function test_meeting_session_cancelled_scope(): void
    {
        $user = User::factory()->create();
        $agent = Agent::factory()->create(['user_id' => $user->id]);

        MeetingSession::create([
            'user_phone' => $this->testPhone,
            'agent_id' => $agent->id,
            'group_name' => 'Cancelled Meeting',
            'status' => 'cancelled',
            'started_at' => now()->subHour(),
            'ended_at' => now(),
            'messages_captured' => [],
        ]);

        $cancelled = MeetingSession::forUser($this->testPhone)->cancelled()->get();
        $this->assertCount(1, $cancelled);
        $this->assertEquals('Cancelled Meeting', $cancelled->first()->group_name);
    }

    public function test_format_duration_handles_days(): void
    {
        // Use a completed meeting with > 24h duration
        $user = User::factory()->create();
        $agentModel = Agent::factory()->create(['user_id' => $user->id]);

        MeetingSession::create([
            'user_phone' => $this->testPhone,
            'agent_id' => $agentModel->id,
            'group_name' => 'Long Offsite',
            'status' => 'completed',
            'started_at' => now()->subDays(2),
            'ended_at' => now(),
            'messages_captured' => [],
        ]);

        $context = $this->makeContext('reunion list');

        $agent = $this->getMockBuilder(SmartMeetingAgent::class)
            ->onlyMethods(['sendText'])
            ->getMock();
        $agent->expects($this->once())->method('sendText');

        $result = $agent->handle($context);
        $this->assertStringContainsString('j', $result->reply); // days shown as "Xj"
    }
}
