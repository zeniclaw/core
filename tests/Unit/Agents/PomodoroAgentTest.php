<?php

namespace Tests\Unit\Agents;

use App\Models\Agent;
use App\Models\AgentSession;
use App\Models\PomodoroSession;
use App\Models\User;
use App\Services\AgentContext;
use App\Services\Agents\PomodoroAgent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PomodoroAgentTest extends TestCase
{
    use RefreshDatabase;

    private string $testPhone = '33612345678@s.whatsapp.net';

    protected function setUp(): void
    {
        parent::setUp();
        // Mock WAHA endpoint to avoid real HTTP calls in tests
        Http::fake(['*' => Http::response(['success' => true], 200)]);
    }

    // ── Basics ────────────────────────────────────────────────────────────────

    public function test_agent_name_is_pomodoro(): void
    {
        $this->assertEquals('pomodoro', (new PomodoroAgent())->name());
    }

    public function test_agent_version_is_1_2_0(): void
    {
        $this->assertEquals('1.2.0', (new PomodoroAgent())->version());
    }

    public function test_agent_has_description(): void
    {
        $this->assertNotEmpty((new PomodoroAgent())->description());
    }

    public function test_keywords_include_pomodoro(): void
    {
        $this->assertContains('pomodoro', (new PomodoroAgent())->keywords());
    }

    public function test_keywords_include_focus(): void
    {
        $this->assertContains('focus', (new PomodoroAgent())->keywords());
    }

    public function test_keywords_include_rapport_pomodoro(): void
    {
        $this->assertContains('rapport pomodoro', (new PomodoroAgent())->keywords());
    }

    public function test_keywords_include_reset_objectif(): void
    {
        $this->assertContains('reset objectif', (new PomodoroAgent())->keywords());
    }

    public function test_can_handle_returns_true_when_routed_to_pomodoro(): void
    {
        $agent   = new PomodoroAgent();
        $context = $this->makeContext('start 25', routedAgent: 'pomodoro');
        $this->assertTrue($agent->canHandle($context));
    }

    public function test_can_handle_returns_false_when_not_routed_to_pomodoro(): void
    {
        $agent   = new PomodoroAgent();
        $context = $this->makeContext('start 25', routedAgent: 'finance');
        $this->assertFalse($agent->canHandle($context));
    }

    // ── Start ──────────────────────────────────────────────────────────────

    public function test_start_creates_pomodoro_session(): void
    {
        $agent   = new PomodoroAgent();
        $context = $this->makeContext('start 25');

        $result = $this->callHandleStart($agent, $context, ['action' => 'start', 'duration' => 25]);

        $this->assertEquals('reply', $result->action);
        $this->assertStringContainsString('25min', $result->reply);

        $this->assertDatabaseHas('pomodoro_sessions', [
            'user_phone' => $this->testPhone,
            'agent_id'   => $context->agent->id,
            'duration'   => 25,
        ]);
    }

    public function test_start_clamps_duration_min_to_1(): void
    {
        $agent   = new PomodoroAgent();
        $context = $this->makeContext('start 0');

        $result = $this->callHandleStart($agent, $context, ['action' => 'start', 'duration' => 0]);

        $this->assertDatabaseHas('pomodoro_sessions', [
            'user_phone' => $this->testPhone,
            'duration'   => 1,
        ]);
    }

    public function test_start_clamps_duration_max_to_120(): void
    {
        $agent   = new PomodoroAgent();
        $context = $this->makeContext('start 999');

        $result = $this->callHandleStart($agent, $context, ['action' => 'start', 'duration' => 999]);

        $this->assertDatabaseHas('pomodoro_sessions', [
            'user_phone' => $this->testPhone,
            'duration'   => 120,
        ]);
    }

    public function test_start_with_no_duration_defaults_to_25(): void
    {
        $agent   = new PomodoroAgent();
        $context = $this->makeContext('pomodoro');

        $this->callHandleStart($agent, $context, ['action' => 'start']);

        $this->assertDatabaseHas('pomodoro_sessions', [
            'user_phone' => $this->testPhone,
            'duration'   => 25,
        ]);
    }

    public function test_start_shows_warning_when_replacing_active_session(): void
    {
        $agent   = new PomodoroAgent();
        $context = $this->makeContext('start 30');

        // First session
        $this->callHandleStart($agent, $context, ['action' => 'start', 'duration' => 25]);

        // Second session — should warn
        $result = $this->callHandleStart($agent, $context, ['action' => 'start', 'duration' => 30]);

        $this->assertStringContainsString('abandonnee', $result->reply);
    }

    public function test_start_shows_daily_goal_progress_when_goal_set(): void
    {
        $agent   = new PomodoroAgent();
        $context = $this->makeContext('start 25');

        Cache::put("pomodoro:goal:{$this->testPhone}:{$context->agent->id}", 4, now()->addDay());

        $result = $this->callHandleStart($agent, $context, ['action' => 'start', 'duration' => 25]);

        $this->assertStringContainsString('Objectif', $result->reply);
    }

    // ── Pause ──────────────────────────────────────────────────────────────

    public function test_pause_returns_no_session_message_when_no_active_session(): void
    {
        $agent   = new PomodoroAgent();
        $context = $this->makeContext('pause');

        $result = $this->callHandlePause($agent, $context);

        $this->assertEquals('pomodoro_pause_no_session', $result->metadata['action']);
        $this->assertStringContainsString('Aucune session', $result->reply);
    }

    public function test_pause_pauses_active_session(): void
    {
        $agent   = new PomodoroAgent();
        $context = $this->makeContext('pause');

        $this->createActiveSession($context, 25);

        $result = $this->callHandlePause($agent, $context);

        $this->assertEquals('reply', $result->action);
        $this->assertStringContainsString('pause', $result->reply);

        $this->assertDatabaseHas('pomodoro_sessions', [
            'user_phone' => $this->testPhone,
            'agent_id'   => $context->agent->id,
            'is_completed' => false,
        ]);
    }

    public function test_pause_resumes_paused_session(): void
    {
        $agent   = new PomodoroAgent();
        $context = $this->makeContext('reprends');

        $session = $this->createActiveSession($context, 25);
        $session->update(['paused_at' => now()]);

        // Toggle off pause
        $result = $this->callHandlePause($agent, $context);

        $this->assertStringContainsString('reprise', $result->reply);

        $session->refresh();
        $this->assertNull($session->paused_at);
    }

    // ── Stop ───────────────────────────────────────────────────────────────

    public function test_stop_returns_no_session_message_when_no_active_session(): void
    {
        $agent   = new PomodoroAgent();
        $context = $this->makeContext('stop');

        $result = $this->callHandleStop($agent, $context);

        $this->assertEquals('pomodoro_stop_no_session', $result->metadata['action']);
        $this->assertStringContainsString('Aucune session', $result->reply);
    }

    public function test_stop_abandons_active_session(): void
    {
        $agent   = new PomodoroAgent();
        $context = $this->makeContext('stop');

        $session = $this->createActiveSession($context, 25);

        $result = $this->callHandleStop($agent, $context);

        $this->assertEquals('pomodoro_stop', $result->metadata['action']);
        $this->assertStringContainsString('abandonnee', $result->reply);

        $session->refresh();
        $this->assertNotNull($session->ended_at);
        $this->assertFalse($session->is_completed);
    }

    public function test_stop_shows_percentage_complete(): void
    {
        $agent   = new PomodoroAgent();
        $context = $this->makeContext('stop');

        $this->createActiveSession($context, 25);

        $result = $this->callHandleStop($agent, $context);

        $this->assertStringContainsString('%', $result->reply);
    }

    // ── End ────────────────────────────────────────────────────────────────

    public function test_end_returns_no_session_message_when_no_active_session(): void
    {
        $agent   = new PomodoroAgent();
        $context = $this->makeContext('end 4');

        $result = $this->callHandleEnd($agent, $context, ['action' => 'end', 'rating' => 4]);

        $this->assertEquals('pomodoro_end_no_session', $result->metadata['action']);
    }

    public function test_end_completes_session_with_rating(): void
    {
        $agent   = new PomodoroAgent();
        $context = $this->makeContext('end 4');

        $this->createActiveSession($context, 25);

        $result = $this->callHandleEnd($agent, $context, ['action' => 'end', 'rating' => 4]);

        $this->assertEquals('pomodoro_end', $result->metadata['action']);
        $this->assertStringContainsString('termine', $result->reply);

        $this->assertDatabaseHas('pomodoro_sessions', [
            'user_phone'    => $this->testPhone,
            'is_completed'  => true,
            'focus_quality' => 4,
        ]);
    }

    public function test_end_completes_session_without_rating(): void
    {
        $agent   = new PomodoroAgent();
        $context = $this->makeContext('end');

        $this->createActiveSession($context, 25);

        $result = $this->callHandleEnd($agent, $context, ['action' => 'end']);

        $this->assertEquals('pomodoro_end', $result->metadata['action']);
        $this->assertStringContainsString('non note', $result->reply);
    }

    public function test_end_clamps_rating_between_1_and_5(): void
    {
        $agent   = new PomodoroAgent();
        $context = $this->makeContext('end 10');

        $this->createActiveSession($context, 25);

        $this->callHandleEnd($agent, $context, ['action' => 'end', 'rating' => 10]);

        $this->assertDatabaseHas('pomodoro_sessions', [
            'user_phone'    => $this->testPhone,
            'focus_quality' => 5,
        ]);
    }

    public function test_end_shows_motivational_message_for_high_rating(): void
    {
        $agent   = new PomodoroAgent();
        $context = $this->makeContext('end 5');

        $this->createActiveSession($context, 25);

        $result = $this->callHandleEnd($agent, $context, ['action' => 'end', 'rating' => 5]);

        $this->assertStringContainsString('flow', $result->reply);
    }

    public function test_end_shows_motivational_message_for_low_rating(): void
    {
        $agent   = new PomodoroAgent();
        $context = $this->makeContext('end 1');

        $this->createActiveSession($context, 25);

        $result = $this->callHandleEnd($agent, $context, ['action' => 'end', 'rating' => 1]);

        $this->assertStringContainsString('difficiles', $result->reply);
    }

    public function test_end_shows_goal_progress_when_goal_set(): void
    {
        $agent   = new PomodoroAgent();
        $context = $this->makeContext('end 4');

        Cache::put("pomodoro:goal:{$this->testPhone}:{$context->agent->id}", 4, now()->addDay());
        $this->createActiveSession($context, 25);

        $result = $this->callHandleEnd($agent, $context, ['action' => 'end', 'rating' => 4]);

        $this->assertStringContainsString('Objectif', $result->reply);
    }

    // ── Stats ──────────────────────────────────────────────────────────────

    public function test_stats_shows_empty_message_when_no_sessions(): void
    {
        $agent   = new PomodoroAgent();
        $context = $this->makeContext('stats');

        $result = $this->callHandleStats($agent, $context);

        $this->assertStringContainsString('pas encore', $result->reply);
    }

    public function test_stats_shows_statistics_with_sessions(): void
    {
        $agent   = new PomodoroAgent();
        $context = $this->makeContext('stats');

        $this->createCompletedSession($context, 25, 4);
        $this->createCompletedSession($context, 30, 5);

        $result = $this->callHandleStats($agent, $context);

        $this->assertStringContainsString('Total', $result->reply);
        $this->assertStringContainsString('2 sessions', $result->reply);
    }

    public function test_stats_shows_daily_goal_when_set(): void
    {
        $agent   = new PomodoroAgent();
        $context = $this->makeContext('stats');

        Cache::put("pomodoro:goal:{$this->testPhone}:{$context->agent->id}", 4, now()->addDay());
        $this->createCompletedSession($context, 25, 3);

        $result = $this->callHandleStats($agent, $context);

        $this->assertStringContainsString('objectif: 4', $result->reply);
    }

    public function test_stats_suggests_report_command(): void
    {
        $agent   = new PomodoroAgent();
        $context = $this->makeContext('stats');

        $this->createCompletedSession($context, 25, 3);

        $result = $this->callHandleStats($agent, $context);

        $this->assertStringContainsString('report', $result->reply);
    }

    // ── Status ─────────────────────────────────────────────────────────────

    public function test_status_shows_no_session_message_when_inactive(): void
    {
        $agent   = new PomodoroAgent();
        $context = $this->makeContext('status');

        $result = $this->callHandleStatus($agent, $context);

        $this->assertEquals('pomodoro_status_none', $result->metadata['action']);
        $this->assertStringContainsString('Aucune session', $result->reply);
    }

    public function test_status_shows_progress_bar_for_active_session(): void
    {
        $agent   = new PomodoroAgent();
        $context = $this->makeContext('status');

        $this->createActiveSession($context, 25);

        $result = $this->callHandleStatus($agent, $context);

        $this->assertStringContainsString('[', $result->reply);
        $this->assertStringContainsString(']', $result->reply);
        $this->assertStringContainsString('%', $result->reply);
    }

    public function test_status_shows_paused_state(): void
    {
        $agent   = new PomodoroAgent();
        $context = $this->makeContext('status');

        $session = $this->createActiveSession($context, 25);
        $session->update(['paused_at' => now()]);

        $result = $this->callHandleStatus($agent, $context);

        $this->assertStringContainsString('EN PAUSE', $result->reply);
    }

    // ── History ────────────────────────────────────────────────────────────

    public function test_history_shows_empty_message_when_no_sessions(): void
    {
        $agent   = new PomodoroAgent();
        $context = $this->makeContext('history');

        $result = $this->callHandleHistory($agent, $context);

        $this->assertEquals('pomodoro_history_empty', $result->metadata['action']);
        $this->assertStringContainsString('Aucune session', $result->reply);
    }

    public function test_history_shows_last_7_sessions(): void
    {
        $agent   = new PomodoroAgent();
        $context = $this->makeContext('history');

        for ($i = 0; $i < 10; $i++) {
            $this->createCompletedSession($context, 25, 4);
        }

        $result = $this->callHandleHistory($agent, $context);

        $this->assertStringContainsString('7 dernieres', $result->reply);
        // Count lines for sessions (should be at most 7)
        $lines = explode("\n", trim($result->reply));
        $this->assertLessThanOrEqual(8, count($lines)); // header + 7 sessions
    }

    public function test_history_shows_ok_icon_for_completed_sessions(): void
    {
        $agent   = new PomodoroAgent();
        $context = $this->makeContext('history');

        $this->createCompletedSession($context, 25, 4);

        $result = $this->callHandleHistory($agent, $context);

        $this->assertStringContainsString('OK', $result->reply);
    }

    public function test_history_shows_x_icon_for_abandoned_sessions(): void
    {
        $agent   = new PomodoroAgent();
        $context = $this->makeContext('history');

        PomodoroSession::create([
            'agent_id'     => $context->agent->id,
            'user_phone'   => $this->testPhone,
            'duration'     => 25,
            'started_at'   => now()->subMinutes(10),
            'ended_at'     => now(),
            'is_completed' => false,
        ]);

        $result = $this->callHandleHistory($agent, $context);

        $this->assertStringContainsString('X', $result->reply);
    }

    // ── Goal ───────────────────────────────────────────────────────────────

    public function test_goal_set_stores_goal_in_cache(): void
    {
        $agent   = new PomodoroAgent();
        $context = $this->makeContext('objectif 4');

        $result = $this->callHandleGoal($agent, $context, ['action' => 'goal', 'value' => 4]);

        $this->assertEquals('pomodoro_goal_set', $result->metadata['action']);
        $this->assertStringContainsString('4 sessions', $result->reply);

        $cached = Cache::get("pomodoro:goal:{$this->testPhone}:{$context->agent->id}");
        $this->assertEquals(4, $cached);
    }

    public function test_goal_set_clamps_to_max_20(): void
    {
        $agent   = new PomodoroAgent();
        $context = $this->makeContext('objectif 100');

        $this->callHandleGoal($agent, $context, ['action' => 'goal', 'value' => 100]);

        $cached = Cache::get("pomodoro:goal:{$this->testPhone}:{$context->agent->id}");
        $this->assertEquals(20, $cached);
    }

    public function test_goal_view_shows_no_goal_message_when_not_set(): void
    {
        $agent   = new PomodoroAgent();
        $context = $this->makeContext('goal');

        $result = $this->callHandleGoal($agent, $context, ['action' => 'goal']);

        $this->assertEquals('pomodoro_goal_view', $result->metadata['action']);
        $this->assertStringContainsString("pas d'objectif", $result->reply);
    }

    public function test_goal_view_shows_current_goal_and_progress(): void
    {
        $agent   = new PomodoroAgent();
        $context = $this->makeContext('goal');

        Cache::put("pomodoro:goal:{$this->testPhone}:{$context->agent->id}", 4, now()->addDay());
        $this->createCompletedSession($context, 25, 3);

        $result = $this->callHandleGoal($agent, $context, ['action' => 'goal']);

        $this->assertStringContainsString('4 sessions', $result->reply);
        $this->assertStringContainsString('1/4', $result->reply);
    }

    public function test_goal_view_shows_atteint_when_goal_reached(): void
    {
        $agent   = new PomodoroAgent();
        $context = $this->makeContext('goal');

        Cache::put("pomodoro:goal:{$this->testPhone}:{$context->agent->id}", 2, now()->addDay());
        $this->createCompletedSession($context, 25, 4);
        $this->createCompletedSession($context, 25, 5);

        $result = $this->callHandleGoal($agent, $context, ['action' => 'goal']);

        $this->assertStringContainsString('ATTEINT', $result->reply);
    }

    // ── Reset ──────────────────────────────────────────────────────────────

    public function test_reset_removes_existing_goal(): void
    {
        $agent   = new PomodoroAgent();
        $context = $this->makeContext('reset objectif');

        Cache::put("pomodoro:goal:{$this->testPhone}:{$context->agent->id}", 4, now()->addDay());

        $result = $this->callHandleReset($agent, $context);

        $this->assertEquals('pomodoro_goal_reset', $result->metadata['action']);
        $this->assertStringContainsString('supprime', $result->reply);
        $this->assertNull(Cache::get("pomodoro:goal:{$this->testPhone}:{$context->agent->id}"));
    }

    public function test_reset_shows_message_when_no_goal_was_set(): void
    {
        $agent   = new PomodoroAgent();
        $context = $this->makeContext('reset objectif');

        $result = $this->callHandleReset($agent, $context);

        $this->assertEquals('pomodoro_goal_reset', $result->metadata['action']);
        $this->assertStringContainsString("pas d'objectif", $result->reply);
    }

    // ── Report ─────────────────────────────────────────────────────────────

    public function test_report_shows_empty_message_when_no_sessions_this_week(): void
    {
        $agent   = new PomodoroAgent();
        $context = $this->makeContext('rapport semaine');

        $result = $this->callHandleReport($agent, $context);

        $this->assertEquals('pomodoro_report_empty', $result->metadata['action']);
        $this->assertStringContainsString('Aucune session', $result->reply);
    }

    public function test_report_shows_weekly_breakdown(): void
    {
        $agent   = new PomodoroAgent();
        $context = $this->makeContext('rapport semaine');

        $this->createCompletedSession($context, 25, 4);
        $this->createCompletedSession($context, 30, 5);

        $result = $this->callHandleReport($agent, $context);

        $this->assertEquals('pomodoro_report', $result->metadata['action']);
        $this->assertStringContainsString('Rapport semaine', $result->reply);
        $this->assertStringContainsString('Total', $result->reply);
        $this->assertStringContainsString('2 sessions', $result->reply);
    }

    public function test_report_shows_total_time_in_minutes_when_under_1_hour(): void
    {
        $agent   = new PomodoroAgent();
        $context = $this->makeContext('rapport');

        $this->createCompletedSession($context, 25, 4);

        $result = $this->callHandleReport($agent, $context);

        $this->assertStringContainsString('25min', $result->reply);
    }

    public function test_report_ignores_sessions_from_previous_weeks(): void
    {
        $agent   = new PomodoroAgent();
        $context = $this->makeContext('rapport');

        // Session from 2 weeks ago — should NOT appear in report
        PomodoroSession::create([
            'agent_id'     => $context->agent->id,
            'user_phone'   => $this->testPhone,
            'duration'     => 25,
            'started_at'   => now()->subWeeks(2),
            'ended_at'     => now()->subWeeks(2)->addMinutes(25),
            'is_completed' => true,
            'focus_quality' => 4,
        ]);

        $result = $this->callHandleReport($agent, $context);

        $this->assertEquals('pomodoro_report_empty', $result->metadata['action']);
    }

    // ── Help ───────────────────────────────────────────────────────────────

    public function test_help_shows_all_commands(): void
    {
        $agent   = new PomodoroAgent();
        $context = $this->makeContext('help');

        $result = $this->callHandleHelp($agent, $context);

        $this->assertEquals('pomodoro_help', $result->metadata['action']);
        $this->assertStringContainsString('start', $result->reply);
        $this->assertStringContainsString('pause', $result->reply);
        $this->assertStringContainsString('stop', $result->reply);
        $this->assertStringContainsString('end', $result->reply);
        $this->assertStringContainsString('stats', $result->reply);
        $this->assertStringContainsString('history', $result->reply);
        $this->assertStringContainsString('report', $result->reply);
        $this->assertStringContainsString('goal', $result->reply);
        $this->assertStringContainsString('reset', $result->reply);
    }

    // ── Progress bar ───────────────────────────────────────────────────────

    public function test_build_progress_bar_is_empty_at_start(): void
    {
        $agent = new PomodoroAgent();
        $bar = $this->callBuildProgressBar($agent, 0, 25);
        $this->assertEquals('[----------]', $bar);
    }

    public function test_build_progress_bar_is_full_when_complete(): void
    {
        $agent = new PomodoroAgent();
        $bar = $this->callBuildProgressBar($agent, 25, 25);
        $this->assertEquals('[##########]', $bar);
    }

    public function test_build_progress_bar_is_half_at_50_percent(): void
    {
        $agent = new PomodoroAgent();
        $bar = $this->callBuildProgressBar($agent, 12, 25);
        // 12/25 = 48% ≈ 5 filled
        $this->assertStringContainsString('#', $bar);
        $this->assertStringContainsString('-', $bar);
    }

    public function test_build_progress_bar_handles_zero_total(): void
    {
        $agent = new PomodoroAgent();
        $bar = $this->callBuildProgressBar($agent, 0, 0);
        $this->assertEquals('[----------]', $bar);
    }

    // ── Motivational messages ──────────────────────────────────────────────

    public function test_motivational_message_for_rating_5(): void
    {
        $agent = new PomodoroAgent();
        $msg = $this->callGetMotivationalMessage($agent, 5);
        $this->assertStringContainsString('flow', $msg);
    }

    public function test_motivational_message_for_rating_4(): void
    {
        $agent = new PomodoroAgent();
        $msg = $this->callGetMotivationalMessage($agent, 4);
        $this->assertStringContainsString('Tres bonne', $msg);
    }

    public function test_motivational_message_for_rating_3(): void
    {
        $agent = new PomodoroAgent();
        $msg = $this->callGetMotivationalMessage($agent, 3);
        $this->assertStringContainsString('correcte', $msg);
    }

    public function test_motivational_message_for_rating_2(): void
    {
        $agent = new PomodoroAgent();
        $msg = $this->callGetMotivationalMessage($agent, 2);
        $this->assertStringContainsString('prochaine', $msg);
    }

    public function test_motivational_message_for_rating_1(): void
    {
        $agent = new PomodoroAgent();
        $msg = $this->callGetMotivationalMessage($agent, 1);
        $this->assertStringContainsString('difficiles', $msg);
    }

    public function test_motivational_message_for_null_rating(): void
    {
        $agent = new PomodoroAgent();
        $msg = $this->callGetMotivationalMessage($agent, null);
        $this->assertStringContainsString('noter', $msg);
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    private function makeContext(string $body, string $routedAgent = 'pomodoro'): AgentContext
    {
        $user    = User::factory()->create();
        $agentDb = Agent::factory()->create(['user_id' => $user->id]);
        $session = AgentSession::create([
            'agent_id'        => $agentDb->id,
            'session_key'     => AgentSession::keyFor($agentDb->id, 'whatsapp', $this->testPhone),
            'channel'         => 'whatsapp',
            'peer_id'         => $this->testPhone,
            'last_message_at' => now(),
        ]);

        return new AgentContext(
            agent:      $agentDb,
            session:    $session,
            from:       $this->testPhone,
            senderName: 'Test User',
            body:       $body,
            hasMedia:   false,
            mediaUrl:   null,
            mimetype:   null,
            media:      null,
            routedAgent: $routedAgent,
        );
    }

    private function createActiveSession(AgentContext $context, int $duration): PomodoroSession
    {
        $session = PomodoroSession::create([
            'agent_id'   => $context->agent->id,
            'user_phone' => $this->testPhone,
            'duration'   => $duration,
            'started_at' => now(),
        ]);

        Cache::put("pomodoro:active:{$this->testPhone}:{$context->agent->id}", $session->id, $duration * 60 + 300);

        return $session;
    }

    private function createCompletedSession(AgentContext $context, int $duration, int $rating): PomodoroSession
    {
        return PomodoroSession::create([
            'agent_id'      => $context->agent->id,
            'user_phone'    => $this->testPhone,
            'duration'      => $duration,
            'started_at'    => now()->subMinutes($duration + 1),
            'ended_at'      => now(),
            'is_completed'  => true,
            'focus_quality' => $rating,
        ]);
    }

    // ── Private method callers via Reflection ──────────────────────────────

    private function callHandleStart(PomodoroAgent $agent, AgentContext $context, array $parsed): \App\Services\Agents\AgentResult
    {
        $method = new \ReflectionMethod($agent, 'handleStart');
        $method->setAccessible(true);
        return $method->invoke($agent, $context, $parsed);
    }

    private function callHandlePause(PomodoroAgent $agent, AgentContext $context): \App\Services\Agents\AgentResult
    {
        $method = new \ReflectionMethod($agent, 'handlePause');
        $method->setAccessible(true);
        return $method->invoke($agent, $context);
    }

    private function callHandleStop(PomodoroAgent $agent, AgentContext $context): \App\Services\Agents\AgentResult
    {
        $method = new \ReflectionMethod($agent, 'handleStop');
        $method->setAccessible(true);
        return $method->invoke($agent, $context);
    }

    private function callHandleEnd(PomodoroAgent $agent, AgentContext $context, array $parsed): \App\Services\Agents\AgentResult
    {
        $method = new \ReflectionMethod($agent, 'handleEnd');
        $method->setAccessible(true);
        return $method->invoke($agent, $context, $parsed);
    }

    private function callHandleStats(PomodoroAgent $agent, AgentContext $context): \App\Services\Agents\AgentResult
    {
        $method = new \ReflectionMethod($agent, 'handleStats');
        $method->setAccessible(true);
        return $method->invoke($agent, $context);
    }

    private function callHandleStatus(PomodoroAgent $agent, AgentContext $context): \App\Services\Agents\AgentResult
    {
        $method = new \ReflectionMethod($agent, 'handleStatus');
        $method->setAccessible(true);
        return $method->invoke($agent, $context);
    }

    private function callHandleHistory(PomodoroAgent $agent, AgentContext $context): \App\Services\Agents\AgentResult
    {
        $method = new \ReflectionMethod($agent, 'handleHistory');
        $method->setAccessible(true);
        return $method->invoke($agent, $context);
    }

    private function callHandleGoal(PomodoroAgent $agent, AgentContext $context, array $parsed): \App\Services\Agents\AgentResult
    {
        $method = new \ReflectionMethod($agent, 'handleGoal');
        $method->setAccessible(true);
        return $method->invoke($agent, $context, $parsed);
    }

    private function callHandleReset(PomodoroAgent $agent, AgentContext $context): \App\Services\Agents\AgentResult
    {
        $method = new \ReflectionMethod($agent, 'handleReset');
        $method->setAccessible(true);
        return $method->invoke($agent, $context);
    }

    private function callHandleReport(PomodoroAgent $agent, AgentContext $context): \App\Services\Agents\AgentResult
    {
        $method = new \ReflectionMethod($agent, 'handleReport');
        $method->setAccessible(true);
        return $method->invoke($agent, $context);
    }

    private function callHandleHelp(PomodoroAgent $agent, AgentContext $context): \App\Services\Agents\AgentResult
    {
        $method = new \ReflectionMethod($agent, 'handleHelp');
        $method->setAccessible(true);
        return $method->invoke($agent, $context);
    }

    private function callBuildProgressBar(PomodoroAgent $agent, int $elapsed, int $total): string
    {
        $method = new \ReflectionMethod($agent, 'buildProgressBar');
        $method->setAccessible(true);
        return $method->invoke($agent, $elapsed, $total);
    }

    private function callGetMotivationalMessage(PomodoroAgent $agent, ?int $rating): string
    {
        $method = new \ReflectionMethod($agent, 'getMotivationalMessage');
        $method->setAccessible(true);
        return $method->invoke($agent, $rating);
    }
}
