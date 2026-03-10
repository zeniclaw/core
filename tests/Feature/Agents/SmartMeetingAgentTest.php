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

    public function test_version_is_1_8_0(): void
    {
        $this->assertEquals('1.8.0', $this->agent->version());
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

    // ── New features v1.3.0 ───────────────────────────────────────────────

    public function test_add_note_without_active_meeting_returns_error(): void
    {
        $context = $this->makeContext('reunion note decision importante');

        $agent = $this->getMockBuilder(SmartMeetingAgent::class)
            ->onlyMethods(['sendText'])
            ->getMock();
        $agent->expects($this->once())->method('sendText');

        $result = $agent->handle($context);
        $this->assertEquals('reply', $result->action);
        $this->assertEquals('no_active_meeting', $result->metadata['action']);
    }

    public function test_add_note_missing_text_returns_usage(): void
    {
        $user = User::factory()->create();
        $agentModel = Agent::factory()->create(['user_id' => $user->id]);

        $meeting = MeetingSession::create([
            'user_phone' => $this->testPhone,
            'agent_id' => $agentModel->id,
            'group_name' => 'Note Test',
            'status' => 'active',
            'started_at' => now()->subMinutes(5),
            'messages_captured' => [],
        ]);
        $meeting->activate();

        $context = $this->makeContext('reunion note');

        $agent = $this->getMockBuilder(SmartMeetingAgent::class)
            ->onlyMethods(['sendText'])
            ->getMock();
        $agent->expects($this->once())->method('sendText');

        $result = $agent->handle($context);
        $this->assertEquals('reply', $result->action);
        $this->assertEquals('note_missing_text', $result->metadata['action']);
    }

    public function test_add_note_stores_note_in_meeting(): void
    {
        $user = User::factory()->create();
        $agentModel = Agent::factory()->create(['user_id' => $user->id]);

        $meeting = MeetingSession::create([
            'user_phone' => $this->testPhone,
            'agent_id' => $agentModel->id,
            'group_name' => 'Sprint Planning',
            'status' => 'active',
            'started_at' => now()->subMinutes(10),
            'messages_captured' => [],
        ]);
        $meeting->activate();

        $context = $this->makeContext('reunion note decision: on livre vendredi');

        $agent = $this->getMockBuilder(SmartMeetingAgent::class)
            ->onlyMethods(['sendText'])
            ->getMock();
        $agent->expects($this->once())->method('sendText');

        $result = $agent->handle($context);
        $this->assertEquals('reply', $result->action);
        $this->assertEquals('note_added', $result->metadata['action']);
        $this->assertEquals($meeting->id, $result->metadata['meeting_id']);
        $this->assertStringContainsString('decision: on livre vendredi', $result->metadata['note']);

        $meeting->refresh();
        $messages = $meeting->messages_captured;
        $this->assertCount(1, $messages);
        $this->assertEquals('note', $messages[0]['type']);
        $this->assertStringContainsString('decision: on livre vendredi', $messages[0]['content']);
    }

    public function test_status_shows_note_count_when_notes_exist(): void
    {
        $user = User::factory()->create();
        $agentModel = Agent::factory()->create(['user_id' => $user->id]);

        $meeting = MeetingSession::create([
            'user_phone' => $this->testPhone,
            'agent_id' => $agentModel->id,
            'group_name' => 'Mixed Meeting',
            'status' => 'active',
            'started_at' => now()->subMinutes(20),
            'messages_captured' => [
                ['sender' => 'Alice', 'content' => 'Hello', 'timestamp' => now()->toISOString(), 'type' => 'message'],
                ['sender' => 'Alice', 'content' => 'Note importante', 'timestamp' => now()->toISOString(), 'type' => 'note'],
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
        $this->assertEquals(1, $result->metadata['messages_count']); // only regular messages
        $this->assertStringContainsString('Notes ajoutees', $result->reply);
        $this->assertStringContainsString('Note importante', $result->reply);
    }

    public function test_recap_without_active_meeting_returns_error(): void
    {
        $context = $this->makeContext('reunion recap');

        $agent = $this->getMockBuilder(SmartMeetingAgent::class)
            ->onlyMethods(['sendText'])
            ->getMock();
        $agent->expects($this->once())->method('sendText');

        $result = $agent->handle($context);
        $this->assertEquals('reply', $result->action);
        $this->assertEquals('no_active_meeting', $result->metadata['action']);
    }

    public function test_recap_with_no_messages_returns_empty(): void
    {
        $user = User::factory()->create();
        $agentModel = Agent::factory()->create(['user_id' => $user->id]);

        $meeting = MeetingSession::create([
            'user_phone' => $this->testPhone,
            'agent_id' => $agentModel->id,
            'group_name' => 'Empty Recap',
            'status' => 'active',
            'started_at' => now()->subMinutes(5),
            'messages_captured' => [],
        ]);
        $meeting->activate();

        $context = $this->makeContext('reunion recap');

        $agent = $this->getMockBuilder(SmartMeetingAgent::class)
            ->onlyMethods(['sendText'])
            ->getMock();
        $agent->expects($this->once())->method('sendText');

        $result = $agent->handle($context);
        $this->assertEquals('reply', $result->action);
        $this->assertEquals('recap_empty', $result->metadata['action']);
    }

    public function test_stats_shows_this_week_and_avg_messages(): void
    {
        $user = User::factory()->create();
        $agentModel = Agent::factory()->create(['user_id' => $user->id]);

        MeetingSession::create([
            'user_phone' => $this->testPhone,
            'agent_id' => $agentModel->id,
            'group_name' => 'Weekly Sync',
            'status' => 'completed',
            'started_at' => now()->subHours(2),
            'ended_at' => now()->subHour(),
            'messages_captured' => [
                ['sender' => 'Alice', 'content' => 'Hello', 'timestamp' => now()->toISOString(), 'type' => 'message'],
                ['sender' => 'Bob', 'content' => 'Hi', 'timestamp' => now()->toISOString(), 'type' => 'message'],
                ['sender' => 'Alice', 'content' => 'Note', 'timestamp' => now()->toISOString(), 'type' => 'note'],
            ],
        ]);

        $context = $this->makeContext('reunion stats');

        $agent = $this->getMockBuilder(SmartMeetingAgent::class)
            ->onlyMethods(['sendText'])
            ->getMock();
        $agent->expects($this->once())->method('sendText');

        $result = $agent->handle($context);
        $this->assertEquals('reply', $result->action);
        $this->assertEquals('meeting_stats', $result->metadata['action']);
        $this->assertEquals(2, $result->metadata['total_messages']); // notes excluded
        $this->assertStringContainsString('Cette semaine', $result->reply);
        $this->assertStringContainsString('Moyenne messages', $result->reply);
    }

    public function test_help_message_includes_new_commands(): void
    {
        $context = $this->makeContext('aide reunion');

        $agent = $this->getMockBuilder(SmartMeetingAgent::class)
            ->onlyMethods(['sendText'])
            ->getMock();
        $agent->expects($this->once())->method('sendText');

        $result = $agent->handle($context);
        $this->assertEquals('reply', $result->action);
        $this->assertStringContainsString('reunion note', $result->reply);
        $this->assertStringContainsString('reunion recap', $result->reply);
    }

    // ── New features v1.4.0 ───────────────────────────────────────────────

    public function test_help_includes_agenda_and_export_commands(): void
    {
        $context = $this->makeContext('aide reunion');

        $agent = $this->getMockBuilder(SmartMeetingAgent::class)
            ->onlyMethods(['sendText'])
            ->getMock();
        $agent->expects($this->once())->method('sendText');

        $result = $agent->handle($context);
        $this->assertEquals('reply', $result->action);
        $this->assertStringContainsString('reunion agenda', $result->reply);
        $this->assertStringContainsString('reunion export', $result->reply);
    }

    public function test_agenda_without_active_meeting_returns_error(): void
    {
        $context = $this->makeContext('reunion agenda livrer le module paiement');

        $agent = $this->getMockBuilder(SmartMeetingAgent::class)
            ->onlyMethods(['sendText'])
            ->getMock();
        $agent->expects($this->once())->method('sendText');

        $result = $agent->handle($context);
        $this->assertEquals('reply', $result->action);
        $this->assertEquals('no_active_meeting', $result->metadata['action']);
    }

    public function test_agenda_set_during_active_meeting(): void
    {
        $user = User::factory()->create();
        $agentModel = Agent::factory()->create(['user_id' => $user->id]);

        $meeting = MeetingSession::create([
            'user_phone' => $this->testPhone,
            'agent_id' => $agentModel->id,
            'group_name' => 'Sprint Planning',
            'status' => 'active',
            'started_at' => now()->subMinutes(5),
            'messages_captured' => [],
        ]);
        $meeting->activate();

        $context = $this->makeContext('reunion agenda livrer le module paiement avant vendredi');

        $agent = $this->getMockBuilder(SmartMeetingAgent::class)
            ->onlyMethods(['sendText'])
            ->getMock();
        $agent->expects($this->once())->method('sendText');

        $result = $agent->handle($context);
        $this->assertEquals('reply', $result->action);
        $this->assertEquals('agenda_set', $result->metadata['action']);
        $this->assertEquals($meeting->id, $result->metadata['meeting_id']);
        $this->assertStringContainsString('livrer le module paiement avant vendredi', $result->metadata['agenda']);

        // Verify stored in DB
        $meeting->refresh();
        $messages = $meeting->messages_captured;
        $agendaEntries = array_filter($messages, fn($m) => ($m['type'] ?? '') === 'agenda');
        $this->assertCount(1, $agendaEntries);
        $agendaEntry = array_values($agendaEntries)[0];
        $this->assertStringContainsString('livrer le module paiement avant vendredi', $agendaEntry['content']);
    }

    public function test_agenda_replaces_previous_agenda(): void
    {
        $user = User::factory()->create();
        $agentModel = Agent::factory()->create(['user_id' => $user->id]);

        $meeting = MeetingSession::create([
            'user_phone' => $this->testPhone,
            'agent_id' => $agentModel->id,
            'group_name' => 'Sprint',
            'status' => 'active',
            'started_at' => now()->subMinutes(5),
            'messages_captured' => [
                ['sender' => 'Test User', 'content' => 'ancien agenda', 'timestamp' => now()->toISOString(), 'type' => 'agenda'],
            ],
        ]);
        $meeting->activate();

        $context = $this->makeContext('reunion agenda nouvel objectif');

        $agent = $this->getMockBuilder(SmartMeetingAgent::class)
            ->onlyMethods(['sendText'])
            ->getMock();
        $agent->expects($this->once())->method('sendText');

        $result = $agent->handle($context);
        $this->assertEquals('agenda_set', $result->metadata['action']);

        $meeting->refresh();
        $messages = $meeting->messages_captured;
        $agendaEntries = array_values(array_filter($messages, fn($m) => ($m['type'] ?? '') === 'agenda'));
        $this->assertCount(1, $agendaEntries);
        $this->assertStringContainsString('nouvel objectif', $agendaEntries[0]['content']);
    }

    public function test_agenda_show_when_no_agenda_defined(): void
    {
        $user = User::factory()->create();
        $agentModel = Agent::factory()->create(['user_id' => $user->id]);

        $meeting = MeetingSession::create([
            'user_phone' => $this->testPhone,
            'agent_id' => $agentModel->id,
            'group_name' => 'Standup',
            'status' => 'active',
            'started_at' => now()->subMinutes(5),
            'messages_captured' => [],
        ]);
        $meeting->activate();

        $context = $this->makeContext('reunion agenda');

        $agent = $this->getMockBuilder(SmartMeetingAgent::class)
            ->onlyMethods(['sendText'])
            ->getMock();
        $agent->expects($this->once())->method('sendText');

        $result = $agent->handle($context);
        $this->assertEquals('reply', $result->action);
        $this->assertEquals('agenda_shown', $result->metadata['action']);
        $this->assertStringContainsString('Aucun agenda', $result->reply);
    }

    public function test_agenda_show_when_agenda_is_set(): void
    {
        $user = User::factory()->create();
        $agentModel = Agent::factory()->create(['user_id' => $user->id]);

        $meeting = MeetingSession::create([
            'user_phone' => $this->testPhone,
            'agent_id' => $agentModel->id,
            'group_name' => 'Standup',
            'status' => 'active',
            'started_at' => now()->subMinutes(5),
            'messages_captured' => [
                ['sender' => 'Test User', 'content' => 'objectif: valider la roadmap Q2', 'timestamp' => now()->toISOString(), 'type' => 'agenda'],
            ],
        ]);
        $meeting->activate();

        $context = $this->makeContext('reunion agenda');

        $agent = $this->getMockBuilder(SmartMeetingAgent::class)
            ->onlyMethods(['sendText'])
            ->getMock();
        $agent->expects($this->once())->method('sendText');

        $result = $agent->handle($context);
        $this->assertEquals('reply', $result->action);
        $this->assertEquals('agenda_shown', $result->metadata['action']);
        $this->assertStringContainsString('objectif: valider la roadmap Q2', $result->reply);
    }

    public function test_status_shows_agenda_when_set(): void
    {
        $user = User::factory()->create();
        $agentModel = Agent::factory()->create(['user_id' => $user->id]);

        $meeting = MeetingSession::create([
            'user_phone' => $this->testPhone,
            'agent_id' => $agentModel->id,
            'group_name' => 'Weekly',
            'status' => 'active',
            'started_at' => now()->subMinutes(10),
            'messages_captured' => [
                ['sender' => 'Test User', 'content' => 'revue des KPIs', 'timestamp' => now()->toISOString(), 'type' => 'agenda'],
                ['sender' => 'Alice', 'content' => 'Bonjour', 'timestamp' => now()->toISOString(), 'type' => 'message'],
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
        $this->assertStringContainsString('revue des KPIs', $result->reply);
        $this->assertStringContainsString('Agenda:', $result->reply);
        // Regular messages count should exclude agenda entries
        $this->assertEquals(1, $result->metadata['messages_count']);
    }

    public function test_start_meeting_with_inline_agenda(): void
    {
        $context = $this->makeContext('reunion start Sprint Q2 | objectif: planifier les livraisons');

        $agent = $this->getMockBuilder(SmartMeetingAgent::class)
            ->onlyMethods(['sendText'])
            ->getMock();
        $agent->expects($this->once())->method('sendText');

        $result = $agent->handle($context);
        $this->assertEquals('reply', $result->action);
        $this->assertEquals('meeting_started', $result->metadata['action']);

        $meeting = MeetingSession::find($result->metadata['meeting_id']);
        $this->assertEquals('Sprint Q2', $meeting->group_name);

        $agendaEntries = array_filter($meeting->messages_captured ?? [], fn($m) => ($m['type'] ?? '') === 'agenda');
        $this->assertCount(1, $agendaEntries);
        $agendaEntry = array_values($agendaEntries)[0];
        $this->assertStringContainsString('planifier les livraisons', $agendaEntry['content']);
    }

    public function test_export_meeting_not_found(): void
    {
        $context = $this->makeContext('reunion export introuvable');

        $agent = $this->getMockBuilder(SmartMeetingAgent::class)
            ->onlyMethods(['sendText'])
            ->getMock();
        $agent->expects($this->once())->method('sendText');

        $result = $agent->handle($context);
        $this->assertEquals('reply', $result->action);
        $this->assertEquals('meeting_not_found', $result->metadata['action']);
    }

    public function test_export_meeting_without_summary(): void
    {
        $user = User::factory()->create();
        $agentModel = Agent::factory()->create(['user_id' => $user->id]);

        MeetingSession::create([
            'user_phone' => $this->testPhone,
            'agent_id' => $agentModel->id,
            'group_name' => 'Meeting sans synthese',
            'status' => 'completed',
            'started_at' => now()->subHour(),
            'ended_at' => now(),
            'messages_captured' => [],
            'summary' => null,
        ]);

        $context = $this->makeContext('reunion export');

        $agent = $this->getMockBuilder(SmartMeetingAgent::class)
            ->onlyMethods(['sendText'])
            ->getMock();
        $agent->expects($this->once())->method('sendText');

        $result = $agent->handle($context);
        $this->assertEquals('reply', $result->action);
        $this->assertEquals('no_summary_to_export', $result->metadata['action']);
    }

    public function test_export_meeting_with_summary(): void
    {
        $user = User::factory()->create();
        $agentModel = Agent::factory()->create(['user_id' => $user->id]);

        MeetingSession::create([
            'user_phone' => $this->testPhone,
            'agent_id' => $agentModel->id,
            'group_name' => 'Sprint Review',
            'status' => 'completed',
            'started_at' => now()->subHours(2),
            'ended_at' => now()->subHour(),
            'messages_captured' => [
                ['sender' => 'Alice', 'content' => 'On livre vendredi', 'timestamp' => now()->toISOString(), 'type' => 'message'],
            ],
            'summary' => json_encode([
                'participants' => ['Alice', 'Bob'],
                'decisions' => ['Livraison vendredi'],
                'action_items' => [['task' => 'Preparer la demo', 'assignee' => 'Alice', 'deadline' => 'jeudi']],
                'risks' => [],
                'next_steps' => ['Demo vendredi 14h'],
                'summary' => 'Sprint review concluant. Livraison validee.',
            ]),
        ]);

        $context = $this->makeContext('reunion export Sprint Review');

        $agent = $this->getMockBuilder(SmartMeetingAgent::class)
            ->onlyMethods(['sendText'])
            ->getMock();
        $agent->expects($this->once())->method('sendText');

        $result = $agent->handle($context);
        $this->assertEquals('reply', $result->action);
        $this->assertEquals('meeting_exported', $result->metadata['action']);

        // Plain text format — no WhatsApp markdown
        $this->assertStringContainsString('COMPTE RENDU DE REUNION', $result->reply);
        $this->assertStringContainsString('Sprint Review', $result->reply);
        $this->assertStringContainsString('PARTICIPANTS', $result->reply);
        $this->assertStringContainsString('Alice', $result->reply);
        $this->assertStringContainsString('DECISIONS', $result->reply);
        $this->assertStringContainsString('Livraison vendredi', $result->reply);
        $this->assertStringContainsString('ACTIONS A FAIRE', $result->reply);
        $this->assertStringContainsString('Preparer la demo', $result->reply);
        $this->assertStringContainsString('(-> Alice)', $result->reply);
        $this->assertStringContainsString('[jeudi]', $result->reply);
        $this->assertStringContainsString('PROCHAINES ETAPES', $result->reply);
        $this->assertStringContainsString('ZeniClaw Smart Meeting', $result->reply);
        // Ensure no WhatsApp bold markers
        $this->assertStringNotContainsString('*Participants', $result->reply);
    }

    public function test_stats_shows_most_active_day(): void
    {
        $user = User::factory()->create();
        $agentModel = Agent::factory()->create(['user_id' => $user->id]);

        MeetingSession::create([
            'user_phone' => $this->testPhone,
            'agent_id' => $agentModel->id,
            'group_name' => 'Lundi meeting',
            'status' => 'completed',
            'started_at' => now()->startOfWeek()->addHours(9),
            'ended_at' => now()->startOfWeek()->addHours(10),
            'messages_captured' => [
                ['sender' => 'Alice', 'content' => 'Hello', 'timestamp' => now()->toISOString(), 'type' => 'message'],
            ],
        ]);

        $context = $this->makeContext('reunion stats');

        $agent = $this->getMockBuilder(SmartMeetingAgent::class)
            ->onlyMethods(['sendText'])
            ->getMock();
        $agent->expects($this->once())->method('sendText');

        $result = $agent->handle($context);
        $this->assertEquals('reply', $result->action);
        $this->assertEquals('meeting_stats', $result->metadata['action']);
        $this->assertStringContainsString('Jour le plus frequent', $result->reply);
    }

    public function test_search_finds_by_messages_content(): void
    {
        $user = User::factory()->create();
        $agentModel = Agent::factory()->create(['user_id' => $user->id]);

        MeetingSession::create([
            'user_phone' => $this->testPhone,
            'agent_id' => $agentModel->id,
            'group_name' => 'Reunion Technique',
            'status' => 'completed',
            'started_at' => now()->subHour(),
            'ended_at' => now(),
            'messages_captured' => [
                ['sender' => 'Alice', 'content' => 'On doit migrer vers kubernetes', 'timestamp' => now()->toISOString(), 'type' => 'message'],
            ],
            'summary' => null,
        ]);

        $context = $this->makeContext('reunion search kubernetes');

        $agent = $this->getMockBuilder(SmartMeetingAgent::class)
            ->onlyMethods(['sendText'])
            ->getMock();
        $agent->expects($this->once())->method('sendText');

        $result = $agent->handle($context);
        $this->assertEquals('reply', $result->action);
        $this->assertEquals('search_results', $result->metadata['action']);
        $this->assertEquals(1, $result->metadata['count']);
        $this->assertStringContainsString('Reunion Technique', $result->reply);
    }

    // ── New features v1.5.0 ───────────────────────────────────────────────

    public function test_help_includes_participants_and_quality_commands(): void
    {
        $context = $this->makeContext('aide reunion');

        $agent = $this->getMockBuilder(SmartMeetingAgent::class)
            ->onlyMethods(['sendText'])
            ->getMock();
        $agent->expects($this->once())->method('sendText');

        $result = $agent->handle($context);
        $this->assertEquals('reply', $result->action);
        $this->assertStringContainsString('reunion participants', $result->reply);
        $this->assertStringContainsString('reunion quality', $result->reply);
    }

    public function test_participants_declared_during_active_meeting(): void
    {
        $user = User::factory()->create();
        $agentModel = Agent::factory()->create(['user_id' => $user->id]);

        $meeting = MeetingSession::create([
            'user_phone' => $this->testPhone,
            'agent_id' => $agentModel->id,
            'group_name' => 'Sprint Planning',
            'status' => 'active',
            'started_at' => now()->subMinutes(5),
            'messages_captured' => [],
        ]);
        $meeting->activate();

        $context = $this->makeContext('reunion participants Alice, Bob, Charlie');

        $agent = $this->getMockBuilder(SmartMeetingAgent::class)
            ->onlyMethods(['sendText'])
            ->getMock();
        $agent->expects($this->once())->method('sendText');

        $result = $agent->handle($context);
        $this->assertEquals('reply', $result->action);
        $this->assertEquals('participants_set', $result->metadata['action']);
        $this->assertEquals($meeting->id, $result->metadata['meeting_id']);
        $this->assertContains('Alice', $result->metadata['participants']);
        $this->assertContains('Bob', $result->metadata['participants']);
        $this->assertContains('Charlie', $result->metadata['participants']);

        // Verify stored in DB
        $meeting->refresh();
        $pEntry = array_values(array_filter($meeting->messages_captured, fn($m) => ($m['type'] ?? '') === 'participants'));
        $this->assertCount(1, $pEntry);
        $this->assertContains('Alice', $pEntry[0]['participants']);
    }

    public function test_participants_without_active_meeting_returns_error(): void
    {
        $context = $this->makeContext('reunion participants Alice, Bob');

        $agent = $this->getMockBuilder(SmartMeetingAgent::class)
            ->onlyMethods(['sendText'])
            ->getMock();
        $agent->expects($this->once())->method('sendText');

        $result = $agent->handle($context);
        $this->assertEquals('reply', $result->action);
        $this->assertEquals('no_active_meeting', $result->metadata['action']);
    }

    public function test_participants_show_merges_declared_and_senders(): void
    {
        $user = User::factory()->create();
        $agentModel = Agent::factory()->create(['user_id' => $user->id]);

        $meeting = MeetingSession::create([
            'user_phone' => $this->testPhone,
            'agent_id' => $agentModel->id,
            'group_name' => 'Weekly',
            'status' => 'active',
            'started_at' => now()->subMinutes(10),
            'messages_captured' => [
                ['sender' => 'Alice', 'content' => 'Hello', 'timestamp' => now()->toISOString(), 'type' => 'message'],
                [
                    'sender' => 'Test User',
                    'content' => 'Bob, Charlie',
                    'participants' => ['Bob', 'Charlie'],
                    'timestamp' => now()->toISOString(),
                    'type' => 'participants',
                ],
            ],
        ]);
        $meeting->activate();

        $context = $this->makeContext('reunion participants');

        $agent = $this->getMockBuilder(SmartMeetingAgent::class)
            ->onlyMethods(['sendText'])
            ->getMock();
        $agent->expects($this->once())->method('sendText');

        $result = $agent->handle($context);
        $this->assertEquals('reply', $result->action);
        $this->assertEquals('participants_shown', $result->metadata['action']);
        // Reply should mention all 3 participants (Alice from messages, Bob+Charlie from declared)
        $this->assertStringContainsString('Alice', $result->reply);
        $this->assertStringContainsString('Bob', $result->reply);
        $this->assertStringContainsString('Charlie', $result->reply);
    }

    public function test_quality_rating_stores_on_last_completed_meeting(): void
    {
        $user = User::factory()->create();
        $agentModel = Agent::factory()->create(['user_id' => $user->id]);

        $meeting = MeetingSession::create([
            'user_phone' => $this->testPhone,
            'agent_id' => $agentModel->id,
            'group_name' => 'Sprint Retro',
            'status' => 'completed',
            'started_at' => now()->subHour(),
            'ended_at' => now(),
            'messages_captured' => [],
            'summary' => json_encode(['decisions' => [], 'action_items' => [], 'risks' => [], 'next_steps' => [], 'participants' => [], 'summary' => 'Test']),
        ]);

        $context = $this->makeContext('reunion quality 4 tres productive');

        $agent = $this->getMockBuilder(SmartMeetingAgent::class)
            ->onlyMethods(['sendText'])
            ->getMock();
        $agent->expects($this->once())->method('sendText');

        $result = $agent->handle($context);
        $this->assertEquals('reply', $result->action);
        $this->assertEquals('meeting_rated', $result->metadata['action']);
        $this->assertEquals($meeting->id, $result->metadata['meeting_id']);
        $this->assertEquals(4, $result->metadata['rating']);
        $this->assertStringContainsString('Sprint Retro', $result->reply);
        $this->assertStringContainsString('4/5', $result->reply);
        $this->assertStringContainsString('tres productive', $result->reply);

        // Verify stored in DB
        $meeting->refresh();
        $summary = json_decode($meeting->summary, true);
        $this->assertEquals(4, $summary['quality_rating']);
        $this->assertEquals('tres productive', $summary['quality_comment']);
    }

    public function test_quality_rating_without_completed_meeting_returns_error(): void
    {
        $context = $this->makeContext('reunion quality 5');

        $agent = $this->getMockBuilder(SmartMeetingAgent::class)
            ->onlyMethods(['sendText'])
            ->getMock();
        $agent->expects($this->once())->method('sendText');

        $result = $agent->handle($context);
        $this->assertEquals('reply', $result->action);
        $this->assertEquals('no_meeting_to_rate', $result->metadata['action']);
    }

    public function test_quality_rating_without_score_returns_usage(): void
    {
        $context = $this->makeContext('reunion quality');

        $agent = $this->getMockBuilder(SmartMeetingAgent::class)
            ->onlyMethods(['sendText'])
            ->getMock();
        $agent->expects($this->once())->method('sendText');

        $result = $agent->handle($context);
        $this->assertEquals('reply', $result->action);
        $this->assertEquals('quality_missing_rating', $result->metadata['action']);
    }

    public function test_stats_shows_avg_quality_when_ratings_exist(): void
    {
        $user = User::factory()->create();
        $agentModel = Agent::factory()->create(['user_id' => $user->id]);

        MeetingSession::create([
            'user_phone' => $this->testPhone,
            'agent_id' => $agentModel->id,
            'group_name' => 'Sprint 1',
            'status' => 'completed',
            'started_at' => now()->subHours(3),
            'ended_at' => now()->subHours(2),
            'messages_captured' => [],
            'summary' => json_encode(['decisions' => [], 'action_items' => [], 'risks' => [], 'next_steps' => [], 'participants' => [], 'summary' => 'ok', 'quality_rating' => 4]),
        ]);

        MeetingSession::create([
            'user_phone' => $this->testPhone,
            'agent_id' => $agentModel->id,
            'group_name' => 'Sprint 2',
            'status' => 'completed',
            'started_at' => now()->subHours(2),
            'ended_at' => now()->subHour(),
            'messages_captured' => [],
            'summary' => json_encode(['decisions' => [], 'action_items' => [], 'risks' => [], 'next_steps' => [], 'participants' => [], 'summary' => 'ok', 'quality_rating' => 2]),
        ]);

        $context = $this->makeContext('reunion stats');

        $agent = $this->getMockBuilder(SmartMeetingAgent::class)
            ->onlyMethods(['sendText'])
            ->getMock();
        $agent->expects($this->once())->method('sendText');

        $result = $agent->handle($context);
        $this->assertEquals('reply', $result->action);
        $this->assertEquals('meeting_stats', $result->metadata['action']);
        $this->assertEquals(3.0, $result->metadata['avg_quality']); // (4+2)/2
        $this->assertStringContainsString('Qualite moyenne', $result->reply);
    }

    public function test_list_meetings_shows_quality_stars(): void
    {
        $user = User::factory()->create();
        $agentModel = Agent::factory()->create(['user_id' => $user->id]);

        MeetingSession::create([
            'user_phone' => $this->testPhone,
            'agent_id' => $agentModel->id,
            'group_name' => 'Rated Meeting',
            'status' => 'completed',
            'started_at' => now()->subHour(),
            'ended_at' => now(),
            'messages_captured' => [],
            'summary' => json_encode(['decisions' => [], 'action_items' => [], 'risks' => [], 'next_steps' => [], 'participants' => [], 'summary' => 'ok', 'quality_rating' => 5]),
        ]);

        $context = $this->makeContext('reunion list');

        $agent = $this->getMockBuilder(SmartMeetingAgent::class)
            ->onlyMethods(['sendText'])
            ->getMock();
        $agent->expects($this->once())->method('sendText');

        $result = $agent->handle($context);
        $this->assertEquals('reply', $result->action);
        $this->assertStringContainsString('⭐', $result->reply);
    }

    // ── New features v1.6.0 ───────────────────────────────────────────────

    public function test_rename_meeting_without_active_returns_error(): void
    {
        $context = $this->makeContext('reunion rename Nouveau Nom');

        $agent = $this->getMockBuilder(SmartMeetingAgent::class)
            ->onlyMethods(['sendText'])
            ->getMock();
        $agent->expects($this->once())->method('sendText');

        $result = $agent->handle($context);
        $this->assertEquals('reply', $result->action);
        $this->assertEquals('no_active_meeting', $result->metadata['action']);
    }

    public function test_rename_meeting_missing_name_returns_usage(): void
    {
        $user = User::factory()->create();
        $agentModel = Agent::factory()->create(['user_id' => $user->id]);

        $meeting = MeetingSession::create([
            'user_phone' => $this->testPhone,
            'agent_id' => $agentModel->id,
            'group_name' => 'Old Name',
            'status' => 'active',
            'started_at' => now()->subMinutes(5),
            'messages_captured' => [],
        ]);
        $meeting->activate();

        $context = $this->makeContext('reunion rename');

        $agent = $this->getMockBuilder(SmartMeetingAgent::class)
            ->onlyMethods(['sendText'])
            ->getMock();
        $agent->expects($this->once())->method('sendText');

        $result = $agent->handle($context);
        $this->assertEquals('reply', $result->action);
        $this->assertEquals('rename_missing_name', $result->metadata['action']);
    }

    public function test_rename_meeting_renames_active_meeting(): void
    {
        $user = User::factory()->create();
        $agentModel = Agent::factory()->create(['user_id' => $user->id]);

        $meeting = MeetingSession::create([
            'user_phone' => $this->testPhone,
            'agent_id' => $agentModel->id,
            'group_name' => 'Standup',
            'status' => 'active',
            'started_at' => now()->subMinutes(10),
            'messages_captured' => [],
        ]);
        $meeting->activate();

        $context = $this->makeContext('reunion rename Sprint Review Q2');

        $agent = $this->getMockBuilder(SmartMeetingAgent::class)
            ->onlyMethods(['sendText'])
            ->getMock();
        $agent->expects($this->once())->method('sendText');

        $result = $agent->handle($context);
        $this->assertEquals('reply', $result->action);
        $this->assertEquals('meeting_renamed', $result->metadata['action']);
        $this->assertEquals('Standup', $result->metadata['old_name']);
        $this->assertEquals('Sprint Review Q2', $result->metadata['new_name']);

        $this->assertDatabaseHas('meeting_sessions', [
            'id' => $meeting->id,
            'group_name' => 'Sprint Review Q2',
        ]);
        $this->assertStringContainsString('Standup', $result->reply);
        $this->assertStringContainsString('Sprint Review Q2', $result->reply);
    }

    public function test_pending_actions_when_no_meetings(): void
    {
        $context = $this->makeContext('reunion actions');

        $agent = $this->getMockBuilder(SmartMeetingAgent::class)
            ->onlyMethods(['sendText'])
            ->getMock();
        $agent->expects($this->once())->method('sendText');

        $result = $agent->handle($context);
        $this->assertEquals('reply', $result->action);
        $this->assertEquals('no_actions_found', $result->metadata['action']);
    }

    public function test_pending_actions_shows_action_items_from_recent_meetings(): void
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
            'messages_captured' => [],
            'summary' => json_encode([
                'action_items' => [
                    ['task' => 'Preparer la demo', 'assignee' => 'Alice', 'deadline' => 'jeudi'],
                    ['task' => 'Ecrire les tests', 'assignee' => 'Bob', 'deadline' => null],
                ],
                'decisions' => [], 'risks' => [], 'next_steps' => [], 'participants' => [], 'summary' => 'ok',
            ]),
        ]);

        $context = $this->makeContext('reunion actions');

        $agent = $this->getMockBuilder(SmartMeetingAgent::class)
            ->onlyMethods(['sendText'])
            ->getMock();
        $agent->expects($this->once())->method('sendText');

        $result = $agent->handle($context);
        $this->assertEquals('reply', $result->action);
        $this->assertEquals('pending_actions', $result->metadata['action']);
        $this->assertEquals(2, $result->metadata['total_actions']);
        $this->assertStringContainsString('Preparer la demo', $result->reply);
        $this->assertStringContainsString('Alice', $result->reply);
        $this->assertStringContainsString('Ecrire les tests', $result->reply);
        $this->assertStringContainsString('Sprint Planning', $result->reply);
    }

    public function test_stats_shows_total_decisions(): void
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
            'messages_captured' => [],
            'summary' => json_encode([
                'decisions' => ['Livraison vendredi', 'Budget valide'],
                'action_items' => [], 'risks' => [], 'next_steps' => [], 'participants' => [], 'summary' => 'ok',
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
        $this->assertEquals(2, $result->metadata['total_decisions']);
        $this->assertStringContainsString('Decisions prises', $result->reply);
    }

    public function test_quality_rating_targets_meeting_by_name(): void
    {
        $user = User::factory()->create();
        $agentModel = Agent::factory()->create(['user_id' => $user->id]);

        $older = MeetingSession::create([
            'user_phone' => $this->testPhone,
            'agent_id' => $agentModel->id,
            'group_name' => 'Standup',
            'status' => 'completed',
            'started_at' => now()->subHours(3),
            'ended_at' => now()->subHours(2),
            'messages_captured' => [],
            'summary' => json_encode(['decisions' => [], 'action_items' => [], 'risks' => [], 'next_steps' => [], 'participants' => [], 'summary' => 'ok']),
        ]);

        $newer = MeetingSession::create([
            'user_phone' => $this->testPhone,
            'agent_id' => $agentModel->id,
            'group_name' => 'Sprint Review',
            'status' => 'completed',
            'started_at' => now()->subHours(2),
            'ended_at' => now()->subHour(),
            'messages_captured' => [],
            'summary' => json_encode(['decisions' => [], 'action_items' => [], 'risks' => [], 'next_steps' => [], 'participants' => [], 'summary' => 'ok']),
        ]);

        // Rate the older Standup specifically, not the latest (Sprint Review)
        $context = $this->makeContext('reunion quality 3 Standup');

        $agent = $this->getMockBuilder(SmartMeetingAgent::class)
            ->onlyMethods(['sendText'])
            ->getMock();
        $agent->expects($this->once())->method('sendText');

        $result = $agent->handle($context);
        $this->assertEquals('reply', $result->action);
        $this->assertEquals('meeting_rated', $result->metadata['action']);
        $this->assertEquals($older->id, $result->metadata['meeting_id']);
        $this->assertEquals(3, $result->metadata['rating']);
    }

    public function test_summary_shows_quality_rating_when_present(): void
    {
        $user = User::factory()->create();
        $agentModel = Agent::factory()->create(['user_id' => $user->id]);

        MeetingSession::create([
            'user_phone' => $this->testPhone,
            'agent_id' => $agentModel->id,
            'group_name' => 'Retrospective',
            'status' => 'completed',
            'started_at' => now()->subHours(2),
            'ended_at' => now()->subHour(),
            'messages_captured' => [],
            'summary' => json_encode([
                'decisions' => [], 'action_items' => [], 'risks' => [], 'next_steps' => [],
                'participants' => [], 'summary' => 'Bonne retro',
                'quality_rating' => 5, 'quality_comment' => 'excellente session',
            ]),
        ]);

        $context = $this->makeContext('synthese reunion Retrospective');

        $agent = $this->getMockBuilder(SmartMeetingAgent::class)
            ->onlyMethods(['sendText'])
            ->getMock();
        $agent->expects($this->once())->method('sendText');

        $result = $agent->handle($context);
        $this->assertEquals('reply', $result->action);
        $this->assertStringContainsString('Qualite', $result->reply);
        $this->assertStringContainsString('5/5', $result->reply);
        $this->assertStringContainsString('excellente session', $result->reply);
    }

    public function test_list_meetings_filter_by_week(): void
    {
        $user = User::factory()->create();
        $agentModel = Agent::factory()->create(['user_id' => $user->id]);

        // Meeting from last week
        MeetingSession::create([
            'user_phone' => $this->testPhone,
            'agent_id' => $agentModel->id,
            'group_name' => 'Old Meeting',
            'status' => 'completed',
            'started_at' => now()->subWeeks(2),
            'ended_at' => now()->subWeeks(2)->addHour(),
            'messages_captured' => [],
        ]);

        // Meeting from this week
        MeetingSession::create([
            'user_phone' => $this->testPhone,
            'agent_id' => $agentModel->id,
            'group_name' => 'This Week Meeting',
            'status' => 'completed',
            'started_at' => now()->startOfWeek(\Carbon\Carbon::MONDAY)->addHours(9),
            'ended_at' => now()->startOfWeek(\Carbon\Carbon::MONDAY)->addHours(10),
            'messages_captured' => [],
        ]);

        $context = $this->makeContext('reunion list semaine');

        $agent = $this->getMockBuilder(SmartMeetingAgent::class)
            ->onlyMethods(['sendText'])
            ->getMock();
        $agent->expects($this->once())->method('sendText');

        $result = $agent->handle($context);
        $this->assertEquals('reply', $result->action);
        $this->assertEquals('meeting_list', $result->metadata['action']);
        $this->assertEquals(1, $result->metadata['count']);
        $this->assertStringContainsString('This Week Meeting', $result->reply);
        $this->assertStringNotContainsString('Old Meeting', $result->reply);
        $this->assertStringContainsString('cette semaine', $result->reply);
    }

    public function test_help_includes_rename_and_actions_commands(): void
    {
        $context = $this->makeContext('aide reunion');

        $agent = $this->getMockBuilder(SmartMeetingAgent::class)
            ->onlyMethods(['sendText'])
            ->getMock();
        $agent->expects($this->once())->method('sendText');

        $result = $agent->handle($context);
        $this->assertEquals('reply', $result->action);
        $this->assertStringContainsString('reunion rename', $result->reply);
        $this->assertStringContainsString('reunion actions', $result->reply);
    }

    // ── New features v1.7.0 ───────────────────────────────────────────────

    public function test_help_includes_decision_and_compare_commands(): void
    {
        $context = $this->makeContext('aide reunion');

        $agent = $this->getMockBuilder(SmartMeetingAgent::class)
            ->onlyMethods(['sendText'])
            ->getMock();
        $agent->expects($this->once())->method('sendText');

        $result = $agent->handle($context);
        $this->assertEquals('reply', $result->action);
        $this->assertStringContainsString('reunion decision', $result->reply);
        $this->assertStringContainsString('reunion compare', $result->reply);
    }

    public function test_add_decision_without_active_meeting_returns_error(): void
    {
        $context = $this->makeContext('reunion decision on migre vers PostgreSQL');

        $agent = $this->getMockBuilder(SmartMeetingAgent::class)
            ->onlyMethods(['sendText'])
            ->getMock();
        $agent->expects($this->once())->method('sendText');

        $result = $agent->handle($context);
        $this->assertEquals('reply', $result->action);
        $this->assertEquals('no_active_meeting', $result->metadata['action']);
    }

    public function test_add_decision_missing_text_returns_usage(): void
    {
        $user = User::factory()->create();
        $agentModel = Agent::factory()->create(['user_id' => $user->id]);

        $meeting = MeetingSession::create([
            'user_phone' => $this->testPhone,
            'agent_id' => $agentModel->id,
            'group_name' => 'Sprint',
            'status' => 'active',
            'started_at' => now()->subMinutes(5),
            'messages_captured' => [],
        ]);
        $meeting->activate();

        $context = $this->makeContext('reunion decision');

        $agent = $this->getMockBuilder(SmartMeetingAgent::class)
            ->onlyMethods(['sendText'])
            ->getMock();
        $agent->expects($this->once())->method('sendText');

        $result = $agent->handle($context);
        $this->assertEquals('reply', $result->action);
        $this->assertEquals('decision_missing_text', $result->metadata['action']);
    }

    public function test_add_decision_stores_decision_in_meeting(): void
    {
        $user = User::factory()->create();
        $agentModel = Agent::factory()->create(['user_id' => $user->id]);

        $meeting = MeetingSession::create([
            'user_phone' => $this->testPhone,
            'agent_id' => $agentModel->id,
            'group_name' => 'Sprint Planning',
            'status' => 'active',
            'started_at' => now()->subMinutes(10),
            'messages_captured' => [],
        ]);
        $meeting->activate();

        $context = $this->makeContext('reunion decision on migre vers PostgreSQL en Q3');

        $agent = $this->getMockBuilder(SmartMeetingAgent::class)
            ->onlyMethods(['sendText'])
            ->getMock();
        $agent->expects($this->once())->method('sendText');

        $result = $agent->handle($context);
        $this->assertEquals('reply', $result->action);
        $this->assertEquals('decision_added', $result->metadata['action']);
        $this->assertEquals($meeting->id, $result->metadata['meeting_id']);
        $this->assertEquals(1, $result->metadata['decision_count']);
        $this->assertStringContainsString('on migre vers PostgreSQL en Q3', $result->metadata['decision']);

        $meeting->refresh();
        $messages = $meeting->messages_captured;
        $this->assertCount(1, $messages);
        $this->assertEquals('decision', $messages[0]['type']);
        $this->assertStringContainsString('PostgreSQL', $messages[0]['content']);
    }

    public function test_add_decision_increments_count(): void
    {
        $user = User::factory()->create();
        $agentModel = Agent::factory()->create(['user_id' => $user->id]);

        $meeting = MeetingSession::create([
            'user_phone' => $this->testPhone,
            'agent_id' => $agentModel->id,
            'group_name' => 'Arch Review',
            'status' => 'active',
            'started_at' => now()->subMinutes(10),
            'messages_captured' => [
                ['sender' => 'Alice', 'content' => 'premiere decision', 'timestamp' => now()->toISOString(), 'type' => 'decision'],
            ],
        ]);
        $meeting->activate();

        $context = $this->makeContext('reunion decision deuxieme decision importante');

        $agent = $this->getMockBuilder(SmartMeetingAgent::class)
            ->onlyMethods(['sendText'])
            ->getMock();
        $agent->expects($this->once())->method('sendText');

        $result = $agent->handle($context);
        $this->assertEquals('decision_added', $result->metadata['action']);
        $this->assertEquals(2, $result->metadata['decision_count']);
    }

    public function test_status_shows_decision_count_when_decisions_exist(): void
    {
        $user = User::factory()->create();
        $agentModel = Agent::factory()->create(['user_id' => $user->id]);

        $meeting = MeetingSession::create([
            'user_phone' => $this->testPhone,
            'agent_id' => $agentModel->id,
            'group_name' => 'Planning',
            'status' => 'active',
            'started_at' => now()->subMinutes(20),
            'messages_captured' => [
                ['sender' => 'Alice', 'content' => 'Hello', 'timestamp' => now()->toISOString(), 'type' => 'message'],
                ['sender' => 'Alice', 'content' => 'on deploie en prod vendredi', 'timestamp' => now()->toISOString(), 'type' => 'decision'],
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
        $this->assertEquals(1, $result->metadata['messages_count']); // decisions excluded from regular count
        $this->assertStringContainsString('Decisions notees', $result->reply);
    }

    public function test_compare_without_enough_meetings(): void
    {
        $user = User::factory()->create();
        $agentModel = Agent::factory()->create(['user_id' => $user->id]);

        MeetingSession::create([
            'user_phone' => $this->testPhone,
            'agent_id' => $agentModel->id,
            'group_name' => 'Solo Meeting',
            'status' => 'completed',
            'started_at' => now()->subHour(),
            'ended_at' => now(),
            'messages_captured' => [],
        ]);

        $context = $this->makeContext('reunion compare');

        $agent = $this->getMockBuilder(SmartMeetingAgent::class)
            ->onlyMethods(['sendText'])
            ->getMock();
        $agent->expects($this->once())->method('sendText');

        $result = $agent->handle($context);
        $this->assertEquals('reply', $result->action);
        $this->assertEquals('not_enough_meetings', $result->metadata['action']);
    }

    public function test_compare_shows_multiple_meetings(): void
    {
        $user = User::factory()->create();
        $agentModel = Agent::factory()->create(['user_id' => $user->id]);

        MeetingSession::create([
            'user_phone' => $this->testPhone,
            'agent_id' => $agentModel->id,
            'group_name' => 'Sprint 1',
            'status' => 'completed',
            'started_at' => now()->subHours(5),
            'ended_at' => now()->subHours(4),
            'messages_captured' => [
                ['sender' => 'Alice', 'content' => 'msg1', 'timestamp' => now()->toISOString(), 'type' => 'message'],
            ],
            'summary' => json_encode([
                'decisions' => ['D1', 'D2'],
                'action_items' => [['task' => 'A1', 'assignee' => null, 'deadline' => null]],
                'risks' => [], 'next_steps' => [], 'participants' => ['Alice'], 'summary' => 'ok',
            ]),
        ]);

        MeetingSession::create([
            'user_phone' => $this->testPhone,
            'agent_id' => $agentModel->id,
            'group_name' => 'Sprint 2',
            'status' => 'completed',
            'started_at' => now()->subHours(3),
            'ended_at' => now()->subHours(2),
            'messages_captured' => [
                ['sender' => 'Bob', 'content' => 'msg2', 'timestamp' => now()->toISOString(), 'type' => 'message'],
                ['sender' => 'Bob', 'content' => 'msg3', 'timestamp' => now()->toISOString(), 'type' => 'message'],
            ],
            'summary' => json_encode([
                'decisions' => ['D3'],
                'action_items' => [['task' => 'A2', 'assignee' => 'Bob', 'deadline' => 'vendredi']],
                'risks' => ['R1'], 'next_steps' => [], 'participants' => ['Bob'], 'summary' => 'ok',
            ]),
        ]);

        $context = $this->makeContext('reunion compare');

        $agent = $this->getMockBuilder(SmartMeetingAgent::class)
            ->onlyMethods(['sendText'])
            ->getMock();
        $agent->expects($this->once())->method('sendText');

        $result = $agent->handle($context);
        $this->assertEquals('reply', $result->action);
        $this->assertEquals('meeting_compare', $result->metadata['action']);
        $this->assertEquals(2, $result->metadata['count']);
        $this->assertStringContainsString('Sprint 1', $result->reply);
        $this->assertStringContainsString('Sprint 2', $result->reply);
        $this->assertStringContainsString('Decisions: 2', $result->reply);
        $this->assertStringContainsString('Actions: 1', $result->reply);
    }

    public function test_compare_with_no_meetings_shows_error(): void
    {
        $context = $this->makeContext('reunion compare');

        $agent = $this->getMockBuilder(SmartMeetingAgent::class)
            ->onlyMethods(['sendText'])
            ->getMock();
        $agent->expects($this->once())->method('sendText');

        $result = $agent->handle($context);
        $this->assertEquals('reply', $result->action);
        $this->assertEquals('not_enough_meetings', $result->metadata['action']);
    }

    // ── New features v1.8.0 ───────────────────────────────────────────────

    public function test_help_includes_action_and_template_commands(): void
    {
        $context = $this->makeContext('aide reunion');

        $agent = $this->getMockBuilder(SmartMeetingAgent::class)
            ->onlyMethods(['sendText'])
            ->getMock();
        $agent->expects($this->once())->method('sendText');

        $result = $agent->handle($context);
        $this->assertEquals('reply', $result->action);
        $this->assertStringContainsString('reunion action', $result->reply);
        $this->assertStringContainsString('reunion template', $result->reply);
    }

    public function test_add_action_item_without_active_meeting_returns_error(): void
    {
        $context = $this->makeContext('reunion action preparer les slides');

        $agent = $this->getMockBuilder(SmartMeetingAgent::class)
            ->onlyMethods(['sendText'])
            ->getMock();
        $agent->expects($this->once())->method('sendText');

        $result = $agent->handle($context);
        $this->assertEquals('reply', $result->action);
        $this->assertEquals('no_active_meeting', $result->metadata['action']);
    }

    public function test_add_action_item_missing_text_returns_usage(): void
    {
        $user = User::factory()->create();
        $agentModel = Agent::factory()->create(['user_id' => $user->id]);

        $meeting = MeetingSession::create([
            'user_phone' => $this->testPhone,
            'agent_id' => $agentModel->id,
            'group_name' => 'Planning',
            'status' => 'active',
            'started_at' => now()->subMinutes(5),
            'messages_captured' => [],
        ]);
        $meeting->activate();

        $context = $this->makeContext('reunion action');

        $agent = $this->getMockBuilder(SmartMeetingAgent::class)
            ->onlyMethods(['sendText'])
            ->getMock();
        $agent->expects($this->once())->method('sendText');

        $result = $agent->handle($context);
        $this->assertEquals('reply', $result->action);
        $this->assertEquals('action_missing_text', $result->metadata['action']);
    }

    public function test_add_action_item_stores_task_in_meeting(): void
    {
        $user = User::factory()->create();
        $agentModel = Agent::factory()->create(['user_id' => $user->id]);

        $meeting = MeetingSession::create([
            'user_phone' => $this->testPhone,
            'agent_id' => $agentModel->id,
            'group_name' => 'Sprint Planning',
            'status' => 'active',
            'started_at' => now()->subMinutes(10),
            'messages_captured' => [],
        ]);
        $meeting->activate();

        $context = $this->makeContext('reunion action revoir le budget');

        $agent = $this->getMockBuilder(SmartMeetingAgent::class)
            ->onlyMethods(['sendText'])
            ->getMock();
        $agent->expects($this->once())->method('sendText');

        $result = $agent->handle($context);
        $this->assertEquals('reply', $result->action);
        $this->assertEquals('action_item_added', $result->metadata['action']);
        $this->assertEquals($meeting->id, $result->metadata['meeting_id']);
        $this->assertEquals(1, $result->metadata['action_count']);
        $this->assertEquals('revoir le budget', $result->metadata['task']);
        $this->assertNull($result->metadata['assignee']);

        $meeting->refresh();
        $messages = $meeting->messages_captured;
        $this->assertCount(1, $messages);
        $this->assertEquals('action_item', $messages[0]['type']);
        $this->assertEquals('revoir le budget', $messages[0]['content']);
    }

    public function test_add_action_item_with_assignee_extracts_assignee(): void
    {
        $user = User::factory()->create();
        $agentModel = Agent::factory()->create(['user_id' => $user->id]);

        $meeting = MeetingSession::create([
            'user_phone' => $this->testPhone,
            'agent_id' => $agentModel->id,
            'group_name' => 'Sprint',
            'status' => 'active',
            'started_at' => now()->subMinutes(5),
            'messages_captured' => [],
        ]);
        $meeting->activate();

        $context = $this->makeContext('reunion action preparer les slides -> Alice');

        $agent = $this->getMockBuilder(SmartMeetingAgent::class)
            ->onlyMethods(['sendText'])
            ->getMock();
        $agent->expects($this->once())->method('sendText');

        $result = $agent->handle($context);
        $this->assertEquals('reply', $result->action);
        $this->assertEquals('action_item_added', $result->metadata['action']);
        $this->assertEquals('preparer les slides', $result->metadata['task']);
        $this->assertEquals('Alice', $result->metadata['assignee']);

        $meeting->refresh();
        $messages = $meeting->messages_captured;
        $this->assertCount(1, $messages);
        $this->assertEquals('action_item', $messages[0]['type']);
        $this->assertEquals('Alice', $messages[0]['assignee']);
        $this->assertStringContainsString('Alice', $result->reply);
    }

    public function test_add_action_item_increments_count(): void
    {
        $user = User::factory()->create();
        $agentModel = Agent::factory()->create(['user_id' => $user->id]);

        $meeting = MeetingSession::create([
            'user_phone' => $this->testPhone,
            'agent_id' => $agentModel->id,
            'group_name' => 'Review',
            'status' => 'active',
            'started_at' => now()->subMinutes(10),
            'messages_captured' => [
                ['sender' => 'Alice', 'content' => 'premiere action', 'timestamp' => now()->toISOString(), 'type' => 'action_item'],
            ],
        ]);
        $meeting->activate();

        $context = $this->makeContext('reunion action deuxieme action -> Bob');

        $agent = $this->getMockBuilder(SmartMeetingAgent::class)
            ->onlyMethods(['sendText'])
            ->getMock();
        $agent->expects($this->once())->method('sendText');

        $result = $agent->handle($context);
        $this->assertEquals('action_item_added', $result->metadata['action']);
        $this->assertEquals(2, $result->metadata['action_count']);
    }

    public function test_status_shows_action_items_count_when_present(): void
    {
        $user = User::factory()->create();
        $agentModel = Agent::factory()->create(['user_id' => $user->id]);

        $meeting = MeetingSession::create([
            'user_phone' => $this->testPhone,
            'agent_id' => $agentModel->id,
            'group_name' => 'Sprint',
            'status' => 'active',
            'started_at' => now()->subMinutes(15),
            'messages_captured' => [
                ['sender' => 'Alice', 'content' => 'Hello', 'timestamp' => now()->toISOString(), 'type' => 'message'],
                ['sender' => 'Alice', 'content' => 'preparer la demo', 'timestamp' => now()->toISOString(), 'type' => 'action_item', 'assignee' => 'Bob'],
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
        $this->assertStringContainsString('Actions capturees', $result->reply);
        $this->assertEquals(1, $result->metadata['messages_count']); // only regular messages
    }

    public function test_template_list_shown_when_no_type_given(): void
    {
        $context = $this->makeContext('reunion template');

        $agent = $this->getMockBuilder(SmartMeetingAgent::class)
            ->onlyMethods(['sendText'])
            ->getMock();
        $agent->expects($this->once())->method('sendText');

        $result = $agent->handle($context);
        $this->assertEquals('reply', $result->action);
        $this->assertEquals('template_list_shown', $result->metadata['action']);
        $this->assertStringContainsString('standup', $result->reply);
        $this->assertStringContainsString('retro', $result->reply);
        $this->assertStringContainsString('planning', $result->reply);
    }

    public function test_template_standup_starts_meeting_with_agenda(): void
    {
        $context = $this->makeContext('reunion template standup');

        $agent = $this->getMockBuilder(SmartMeetingAgent::class)
            ->onlyMethods(['sendText'])
            ->getMock();
        $agent->expects($this->once())->method('sendText');

        $result = $agent->handle($context);
        $this->assertEquals('reply', $result->action);
        $this->assertEquals('meeting_started_from_template', $result->metadata['action']);
        $this->assertEquals('standup', $result->metadata['template']);

        $meeting = MeetingSession::find($result->metadata['meeting_id']);
        $this->assertEquals('Standup', $meeting->group_name);

        $agendaEntries = array_filter($meeting->messages_captured ?? [], fn($m) => ($m['type'] ?? '') === 'agenda');
        $this->assertCount(1, $agendaEntries);
        $agendaEntry = array_values($agendaEntries)[0];
        $this->assertStringContainsString('Blockers', $agendaEntry['content']);
    }

    public function test_template_retro_starts_meeting_with_retro_agenda(): void
    {
        $context = $this->makeContext('reunion template retro');

        $agent = $this->getMockBuilder(SmartMeetingAgent::class)
            ->onlyMethods(['sendText'])
            ->getMock();
        $agent->expects($this->once())->method('sendText');

        $result = $agent->handle($context);
        $this->assertEquals('reply', $result->action);
        $this->assertEquals('meeting_started_from_template', $result->metadata['action']);
        $this->assertEquals('retro', $result->metadata['template']);

        $meeting = MeetingSession::find($result->metadata['meeting_id']);
        $this->assertEquals('Retrospective', $meeting->group_name);
        $this->assertStringContainsString('Retrospective', $result->reply);
    }

    public function test_template_when_meeting_already_active_returns_error(): void
    {
        $user = User::factory()->create();
        $agentModel = Agent::factory()->create(['user_id' => $user->id]);

        $meeting = MeetingSession::create([
            'user_phone' => $this->testPhone,
            'agent_id' => $agentModel->id,
            'group_name' => 'Existing',
            'status' => 'active',
            'started_at' => now()->subMinutes(10),
            'messages_captured' => [],
        ]);
        $meeting->activate();

        $context = $this->makeContext('reunion template standup');

        $agent = $this->getMockBuilder(SmartMeetingAgent::class)
            ->onlyMethods(['sendText'])
            ->getMock();
        $agent->expects($this->once())->method('sendText');

        $result = $agent->handle($context);
        $this->assertEquals('reply', $result->action);
        $this->assertEquals('meeting_already_active', $result->metadata['action']);
    }

    public function test_template_unknown_type_shows_list(): void
    {
        $context = $this->makeContext('reunion template unknown_type');

        $agent = $this->getMockBuilder(SmartMeetingAgent::class)
            ->onlyMethods(['sendText'])
            ->getMock();
        $agent->expects($this->once())->method('sendText');

        $result = $agent->handle($context);
        $this->assertEquals('reply', $result->action);
        $this->assertEquals('template_list_shown', $result->metadata['action']);
    }
}
