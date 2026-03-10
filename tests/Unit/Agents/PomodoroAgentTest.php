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

    public function test_agent_version_is_1_7_0(): void
    {
        $this->assertEquals('1.7.0', (new PomodoroAgent())->version());
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

    public function test_keywords_include_break(): void
    {
        $this->assertContains('break', (new PomodoroAgent())->keywords());
    }

    public function test_keywords_include_records(): void
    {
        $this->assertContains('records', (new PomodoroAgent())->keywords());
    }

    public function test_keywords_include_extend(): void
    {
        $this->assertContains('extend', (new PomodoroAgent())->keywords());
    }

    public function test_keywords_include_prolonger(): void
    {
        $this->assertContains('prolonger', (new PomodoroAgent())->keywords());
    }

    public function test_keywords_include_suggest(): void
    {
        $this->assertContains('suggest', (new PomodoroAgent())->keywords());
    }

    public function test_keywords_include_compare(): void
    {
        $this->assertContains('compare', (new PomodoroAgent())->keywords());
    }

    public function test_keywords_include_weekly(): void
    {
        $this->assertContains('weekly', (new PomodoroAgent())->keywords());
    }

    public function test_keywords_include_objectif_semaine(): void
    {
        $this->assertContains('objectif semaine', (new PomodoroAgent())->keywords());
    }

    public function test_keywords_include_tip(): void
    {
        $this->assertContains('tip', (new PomodoroAgent())->keywords());
    }

    public function test_keywords_include_astuce(): void
    {
        $this->assertContains('astuce', (new PomodoroAgent())->keywords());
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

    public function test_start_clears_active_break(): void
    {
        $agent   = new PomodoroAgent();
        $context = $this->makeContext('start 25');

        $breakKey = "pomodoro:break:{$this->testPhone}:{$context->agent->id}";
        Cache::put($breakKey, ['started_at' => now()->toDateTimeString(), 'duration' => 5], 300);

        $this->callHandleStart($agent, $context, ['action' => 'start', 'duration' => 25]);

        $this->assertNull(Cache::get($breakKey));
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

    public function test_end_suggests_long_break_after_4_sessions(): void
    {
        $agent   = new PomodoroAgent();
        $context = $this->makeContext('end 4');

        // Create 3 already completed sessions today
        for ($i = 0; $i < 3; $i++) {
            $this->createCompletedSession($context, 25, 4);
        }

        // The 4th session
        $this->createActiveSession($context, 25);

        $result = $this->callHandleEnd($agent, $context, ['action' => 'end', 'rating' => 4]);

        $this->assertStringContainsString('break 15', $result->reply);
    }

    public function test_end_suggests_short_break_for_non_4th_session(): void
    {
        $agent   = new PomodoroAgent();
        $context = $this->makeContext('end 4');

        $this->createActiveSession($context, 25);

        $result = $this->callHandleEnd($agent, $context, ['action' => 'end', 'rating' => 4]);

        $this->assertStringContainsString('break 5', $result->reply);
    }

    // ── Break ──────────────────────────────────────────────────────────────

    public function test_break_starts_short_break_by_default(): void
    {
        $agent   = new PomodoroAgent();
        $context = $this->makeContext('break');

        $result = $this->callHandleBreak($agent, $context, ['action' => 'break']);

        $this->assertEquals('pomodoro_break_start', $result->metadata['action']);
        $this->assertEquals(5, $result->metadata['duration']);
        $this->assertStringContainsString('5min', $result->reply);
    }

    public function test_break_starts_short_break_with_duration_5(): void
    {
        $agent   = new PomodoroAgent();
        $context = $this->makeContext('break 5');

        $result = $this->callHandleBreak($agent, $context, ['action' => 'break', 'duration' => 5]);

        $this->assertEquals(5, $result->metadata['duration']);
        $this->assertStringContainsString('Pause courte', $result->reply);
    }

    public function test_break_starts_long_break_with_duration_15(): void
    {
        $agent   = new PomodoroAgent();
        $context = $this->makeContext('break 15');

        $result = $this->callHandleBreak($agent, $context, ['action' => 'break', 'duration' => 15]);

        $this->assertEquals(15, $result->metadata['duration']);
        $this->assertStringContainsString('Longue pause', $result->reply);
    }

    public function test_break_stores_break_in_cache(): void
    {
        $agent   = new PomodoroAgent();
        $context = $this->makeContext('break 5');

        $this->callHandleBreak($agent, $context, ['action' => 'break', 'duration' => 5]);

        $cached = Cache::get("pomodoro:break:{$this->testPhone}:{$context->agent->id}");
        $this->assertNotNull($cached);
        $this->assertEquals(5, $cached['duration']);
    }

    public function test_break_any_duration_under_10_becomes_5(): void
    {
        $agent   = new PomodoroAgent();
        $context = $this->makeContext('break 7');

        $result = $this->callHandleBreak($agent, $context, ['action' => 'break', 'duration' => 7]);

        $this->assertEquals(5, $result->metadata['duration']);
    }

    public function test_break_any_duration_10_or_above_becomes_15(): void
    {
        $agent   = new PomodoroAgent();
        $context = $this->makeContext('break 10');

        $result = $this->callHandleBreak($agent, $context, ['action' => 'break', 'duration' => 10]);

        $this->assertEquals(15, $result->metadata['duration']);
    }

    // ── Extend ─────────────────────────────────────────────────────────────

    public function test_extend_returns_no_session_message_when_no_active_session(): void
    {
        $agent   = new PomodoroAgent();
        $context = $this->makeContext('extend 10');

        $result = $this->callHandleExtend($agent, $context, ['action' => 'extend']);

        $this->assertEquals('pomodoro_extend_no_session', $result->metadata['action']);
        $this->assertStringContainsString('Aucune session', $result->reply);
    }

    public function test_extend_defaults_to_10_minutes_when_no_value(): void
    {
        $agent   = new PomodoroAgent();
        $context = $this->makeContext('extend');

        $this->createActiveSession($context, 25);

        $result = $this->callHandleExtend($agent, $context, ['action' => 'extend']);

        $this->assertEquals('pomodoro_extend', $result->metadata['action']);
        $this->assertEquals(10, $result->metadata['added']);
        $this->assertStringContainsString('10min', $result->reply);
    }

    public function test_extend_with_specific_value_increases_duration(): void
    {
        $agent   = new PomodoroAgent();
        $context = $this->makeContext('extend 15');

        $session = $this->createActiveSession($context, 25);

        $result = $this->callHandleExtend($agent, $context, ['action' => 'extend', 'value' => 15]);

        $this->assertEquals(15, $result->metadata['added']);
        $this->assertStringContainsString('15min', $result->reply);

        $session->refresh();
        $this->assertEquals(40, $session->duration); // 25 + 15
    }

    public function test_extend_clamps_value_to_60_minutes_max(): void
    {
        $agent   = new PomodoroAgent();
        $context = $this->makeContext('extend 999');

        $session = $this->createActiveSession($context, 25);

        $result = $this->callHandleExtend($agent, $context, ['action' => 'extend', 'value' => 999]);

        $this->assertEquals(60, $result->metadata['added']);

        $session->refresh();
        $this->assertEquals(85, $session->duration); // 25 + 60
    }

    public function test_extend_clamps_value_to_1_minute_min(): void
    {
        $agent   = new PomodoroAgent();
        $context = $this->makeContext('extend 0');

        $session = $this->createActiveSession($context, 25);

        $result = $this->callHandleExtend($agent, $context, ['action' => 'extend', 'value' => 0]);

        $this->assertEquals(1, $result->metadata['added']);

        $session->refresh();
        $this->assertEquals(26, $session->duration); // 25 + 1
    }

    public function test_extend_updates_session_duration_in_database(): void
    {
        $agent   = new PomodoroAgent();
        $context = $this->makeContext('extend 10');

        $this->createActiveSession($context, 25);

        $this->callHandleExtend($agent, $context, ['action' => 'extend', 'value' => 10]);

        $this->assertDatabaseHas('pomodoro_sessions', [
            'user_phone' => $this->testPhone,
            'duration'   => 35,
        ]);
    }

    public function test_extend_shows_new_end_time_in_reply(): void
    {
        $agent   = new PomodoroAgent();
        $context = $this->makeContext('extend 10');

        $this->createActiveSession($context, 25);

        $result = $this->callHandleExtend($agent, $context, ['action' => 'extend', 'value' => 10]);

        $this->assertStringContainsString('35min', $result->reply);
        $this->assertStringContainsString('Fin prévue', $result->reply);
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

    public function test_stats_suggests_best_and_report_commands(): void
    {
        $agent   = new PomodoroAgent();
        $context = $this->makeContext('stats');

        $this->createCompletedSession($context, 25, 3);

        $result = $this->callHandleStats($agent, $context);

        $this->assertStringContainsString('best', $result->reply);
        $this->assertStringContainsString('report', $result->reply);
    }

    public function test_stats_shows_best_day_record(): void
    {
        $agent   = new PomodoroAgent();
        $context = $this->makeContext('stats');

        $this->createCompletedSession($context, 25, 4);
        $this->createCompletedSession($context, 25, 3);

        $result = $this->callHandleStats($agent, $context);

        $this->assertStringContainsString('record', $result->reply);
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

    public function test_status_shows_expired_message_when_session_time_exceeded(): void
    {
        $agent   = new PomodoroAgent();
        $context = $this->makeContext('status');

        // Create a session that started 30 minutes ago with a 25min duration → expired
        $session = PomodoroSession::create([
            'agent_id'   => $context->agent->id,
            'user_phone' => $this->testPhone,
            'duration'   => 25,
            'started_at' => now()->subMinutes(30),
        ]);

        Cache::put("pomodoro:active:{$this->testPhone}:{$context->agent->id}", $session->id, 3600);

        $result = $this->callHandleStatus($agent, $context);

        $this->assertEquals('pomodoro_status_expired', $result->metadata['action']);
        $this->assertStringContainsString('terminee', $result->reply);
        $this->assertStringContainsString('end', $result->reply);
    }

    public function test_status_shows_break_status_when_break_active(): void
    {
        $agent   = new PomodoroAgent();
        $context = $this->makeContext('status');

        $breakKey = "pomodoro:break:{$this->testPhone}:{$context->agent->id}";
        Cache::put($breakKey, [
            'started_at' => now()->toDateTimeString(),
            'duration'   => 5,
        ], 600);

        $result = $this->callHandleStatus($agent, $context);

        $this->assertEquals('pomodoro_break_status', $result->metadata['action']);
        $this->assertStringContainsString('Pause en cours', $result->reply);
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

    public function test_report_shows_avg_focus_in_summary(): void
    {
        $agent   = new PomodoroAgent();
        $context = $this->makeContext('rapport');

        $this->createCompletedSession($context, 25, 4);
        $this->createCompletedSession($context, 25, 5);

        $result = $this->callHandleReport($agent, $context);

        $this->assertStringContainsString('focus moy', $result->reply);
    }

    // ── Best ───────────────────────────────────────────────────────────────

    public function test_best_shows_empty_message_when_no_sessions(): void
    {
        $agent   = new PomodoroAgent();
        $context = $this->makeContext('best');

        $result = $this->callHandleBest($agent, $context);

        $this->assertEquals('pomodoro_best_empty', $result->metadata['action']);
        $this->assertStringContainsString('Pas encore', $result->reply);
    }

    public function test_best_shows_best_day(): void
    {
        $agent   = new PomodoroAgent();
        $context = $this->makeContext('best');

        $this->createCompletedSession($context, 25, 4);
        $this->createCompletedSession($context, 25, 5);

        $result = $this->callHandleBest($agent, $context);

        $this->assertEquals('pomodoro_best', $result->metadata['action']);
        $this->assertStringContainsString('Meilleure journee', $result->reply);
    }

    public function test_best_shows_best_focus_session(): void
    {
        $agent   = new PomodoroAgent();
        $context = $this->makeContext('best');

        $this->createCompletedSession($context, 25, 5);

        $result = $this->callHandleBest($agent, $context);

        $this->assertStringContainsString('Meilleure session', $result->reply);
        $this->assertStringContainsString('5/5', $result->reply);
    }

    public function test_best_shows_total_cumulated(): void
    {
        $agent   = new PomodoroAgent();
        $context = $this->makeContext('best');

        $this->createCompletedSession($context, 25, 4);

        $result = $this->callHandleBest($agent, $context);

        $this->assertStringContainsString('Total cumule', $result->reply);
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
        $this->assertStringContainsString('break', $result->reply);
        $this->assertStringContainsString('best', $result->reply);
        $this->assertStringContainsString('today', $result->reply);
        $this->assertStringContainsString('label', $result->reply);
        $this->assertStringContainsString('suggest', $result->reply);
        $this->assertStringContainsString('compare', $result->reply);
    }

    // ── Today ──────────────────────────────────────────────────────────────

    public function test_today_shows_empty_when_no_sessions(): void
    {
        $agent   = new PomodoroAgent();
        $context = $this->makeContext('today');

        $result = $this->callHandleToday($agent, $context);

        $this->assertEquals('pomodoro_today_empty', $result->metadata['action']);
        $this->assertStringContainsString("aujourd'hui", strtolower($result->reply));
    }

    public function test_today_shows_completed_sessions_count(): void
    {
        $agent   = new PomodoroAgent();
        $context = $this->makeContext('today');

        $this->createCompletedSession($context, 25, 4);
        $this->createCompletedSession($context, 30, 5);

        $result = $this->callHandleToday($agent, $context);

        $this->assertEquals('pomodoro_today', $result->metadata['action']);
        $this->assertStringContainsString('2', $result->reply);
        $this->assertStringContainsString('Bilan du jour', $result->reply);
    }

    public function test_today_shows_total_focus_time(): void
    {
        $agent   = new PomodoroAgent();
        $context = $this->makeContext('today');

        $this->createCompletedSession($context, 25, 4);
        $this->createCompletedSession($context, 25, 3);

        $result = $this->callHandleToday($agent, $context);

        $this->assertStringContainsString('50min', $result->reply);
    }

    public function test_today_shows_goal_progress_when_goal_set(): void
    {
        $agent   = new PomodoroAgent();
        $context = $this->makeContext('today');

        Cache::put("pomodoro:goal:{$this->testPhone}:{$context->agent->id}", 4, now()->addDay());
        $this->createCompletedSession($context, 25, 4);

        $result = $this->callHandleToday($agent, $context);

        $this->assertStringContainsString('Objectif', $result->reply);
        $this->assertStringContainsString('1/4', $result->reply);
    }

    public function test_today_shows_last_sessions_list(): void
    {
        $agent   = new PomodoroAgent();
        $context = $this->makeContext('today');

        $this->createCompletedSession($context, 25, 4);

        $result = $this->callHandleToday($agent, $context);

        $this->assertStringContainsString('Dernieres sessions', $result->reply);
        $this->assertStringContainsString('OK', $result->reply);
    }

    public function test_today_shows_abandoned_sessions(): void
    {
        $agent   = new PomodoroAgent();
        $context = $this->makeContext('today');

        $this->createCompletedSession($context, 25, 4);
        // Create an abandoned session
        PomodoroSession::create([
            'agent_id'     => $context->agent->id,
            'user_phone'   => $this->testPhone,
            'duration'     => 25,
            'started_at'   => now()->subMinutes(10),
            'ended_at'     => now(),
            'is_completed' => false,
        ]);

        $result = $this->callHandleToday($agent, $context);

        $this->assertStringContainsString('abandonnee', $result->reply);
    }

    // ── Label ──────────────────────────────────────────────────────────────

    public function test_label_set_stores_in_cache(): void
    {
        $agent   = new PomodoroAgent();
        $context = $this->makeContext('label coding');

        $result = $this->callHandleLabel($agent, $context, ['action' => 'label', 'value' => 'coding']);

        $this->assertEquals('pomodoro_label_set', $result->metadata['action']);
        $this->assertEquals('coding', $result->metadata['label']);

        $cached = Cache::get("pomodoro:label:{$this->testPhone}:{$context->agent->id}");
        $this->assertEquals('coding', $cached);
    }

    public function test_label_set_shows_confirmation(): void
    {
        $agent   = new PomodoroAgent();
        $context = $this->makeContext('label lecture');

        $result = $this->callHandleLabel($agent, $context, ['action' => 'label', 'value' => 'lecture']);

        $this->assertStringContainsString('lecture', $result->reply);
        $this->assertStringContainsString('Label', $result->reply);
    }

    public function test_label_view_without_active_label_shows_hint(): void
    {
        $agent   = new PomodoroAgent();
        $context = $this->makeContext('label');

        $result = $this->callHandleLabel($agent, $context, ['action' => 'label']);

        $this->assertEquals('pomodoro_label_view', $result->metadata['action']);
        $this->assertStringContainsString('Aucun label', $result->reply);
    }

    public function test_label_view_with_active_label_removes_it(): void
    {
        $agent   = new PomodoroAgent();
        $context = $this->makeContext('label');

        Cache::put("pomodoro:label:{$this->testPhone}:{$context->agent->id}", 'coding', now()->addHours(4));

        $result = $this->callHandleLabel($agent, $context, ['action' => 'label']);

        $this->assertEquals('pomodoro_label_view', $result->metadata['action']);
        $this->assertStringContainsString('coding', $result->reply);
        $this->assertStringContainsString('supprime', $result->reply);
        $this->assertNull(Cache::get("pomodoro:label:{$this->testPhone}:{$context->agent->id}"));
    }

    public function test_label_appears_in_status(): void
    {
        $agent   = new PomodoroAgent();
        $context = $this->makeContext('status');

        Cache::put("pomodoro:label:{$this->testPhone}:{$context->agent->id}", 'deep work', now()->addHours(4));
        $this->createActiveSession($context, 25);

        $result = $this->callHandleStatus($agent, $context);

        $this->assertStringContainsString('deep work', $result->reply);
    }

    public function test_label_appears_in_end_reply_and_is_cleared(): void
    {
        $agent   = new PomodoroAgent();
        $context = $this->makeContext('end 4');

        Cache::put("pomodoro:label:{$this->testPhone}:{$context->agent->id}", 'projet X', now()->addHours(4));
        $this->createActiveSession($context, 25);

        $result = $this->callHandleEnd($agent, $context, ['action' => 'end', 'rating' => 4]);

        $this->assertStringContainsString('projet X', $result->reply);
        $this->assertNull(Cache::get("pomodoro:label:{$this->testPhone}:{$context->agent->id}"));
    }

    public function test_label_truncated_to_50_characters(): void
    {
        $agent   = new PomodoroAgent();
        $context = $this->makeContext('label ' . str_repeat('a', 100));

        $this->callHandleLabel($agent, $context, ['action' => 'label', 'value' => str_repeat('a', 100)]);

        $cached = Cache::get("pomodoro:label:{$this->testPhone}:{$context->agent->id}");
        $this->assertEquals(50, mb_strlen($cached));
    }

    // ── Stop improvements ──────────────────────────────────────────────────

    public function test_stop_shows_close_encouragement_at_80_percent_or_more(): void
    {
        $agent   = new PomodoroAgent();
        $context = $this->makeContext('stop');

        // Session started 25min ago with 25min duration → 100% → >=80%
        $session = PomodoroSession::create([
            'agent_id'   => $context->agent->id,
            'user_phone' => $this->testPhone,
            'duration'   => 25,
            'started_at' => now()->subMinutes(25),
        ]);
        Cache::put("pomodoro:active:{$this->testPhone}:{$context->agent->id}", $session->id, 3600);

        $result = $this->callHandleStop($agent, $context);

        $this->assertStringContainsString('proche', $result->reply);
    }

    public function test_stop_shows_halfway_encouragement_at_50_to_79_percent(): void
    {
        $agent   = new PomodoroAgent();
        $context = $this->makeContext('stop');

        // Session started 15min ago with 25min duration → 60%
        $session = PomodoroSession::create([
            'agent_id'   => $context->agent->id,
            'user_phone' => $this->testPhone,
            'duration'   => 25,
            'started_at' => now()->subMinutes(15),
        ]);
        Cache::put("pomodoro:active:{$this->testPhone}:{$context->agent->id}", $session->id, 3600);

        $result = $this->callHandleStop($agent, $context);

        $this->assertStringContainsString('moitie', $result->reply);
    }

    // ── Suggest ────────────────────────────────────────────────────────────

    public function test_suggest_returns_default_25_when_no_history(): void
    {
        $agent   = new PomodoroAgent();
        $context = $this->makeContext('suggest');

        $result = $this->callHandleSuggest($agent, $context);

        $this->assertEquals('pomodoro_suggest', $result->metadata['action']);
        $this->assertEquals(25, $result->metadata['suggested_duration']);
        $this->assertStringContainsString('25', $result->reply);
    }

    public function test_suggest_returns_duration_with_history(): void
    {
        $agent   = new PomodoroAgent();
        $context = $this->makeContext('suggest');

        $this->createCompletedSession($context, 25, 4);
        $this->createCompletedSession($context, 30, 5);

        $result = $this->callHandleSuggest($agent, $context);

        $this->assertEquals('pomodoro_suggest', $result->metadata['action']);
        $this->assertIsInt($result->metadata['suggested_duration']);
        $this->assertGreaterThanOrEqual(15, $result->metadata['suggested_duration']);
        $this->assertLessThanOrEqual(60, $result->metadata['suggested_duration']);
    }

    public function test_suggest_increases_duration_for_high_focus(): void
    {
        $agent   = new PomodoroAgent();
        $context = $this->makeContext('suggest');

        // 10 sessions with rating 5 → avg focus >= 4.5 → duration should increase
        for ($i = 0; $i < 10; $i++) {
            $this->createCompletedSession($context, 25, 5);
        }

        $result = $this->callHandleSuggest($agent, $context);

        // With high focus the suggested duration should be > base
        $this->assertGreaterThan(25, $result->metadata['suggested_duration']);
    }

    public function test_suggest_decreases_duration_for_low_focus(): void
    {
        $agent   = new PomodoroAgent();
        $context = $this->makeContext('suggest');

        // 10 sessions with rating 1 → avg focus < 2.5 → duration should decrease
        for ($i = 0; $i < 10; $i++) {
            $this->createCompletedSession($context, 30, 1);
        }

        $result = $this->callHandleSuggest($agent, $context);

        $this->assertLessThanOrEqual(25, $result->metadata['suggested_duration']);
    }

    public function test_suggest_reply_contains_start_command(): void
    {
        $agent   = new PomodoroAgent();
        $context = $this->makeContext('suggest');

        $result = $this->callHandleSuggest($agent, $context);

        $this->assertStringContainsString('start', $result->reply);
    }

    // ── Compare ────────────────────────────────────────────────────────────

    public function test_compare_shows_empty_message_when_no_data(): void
    {
        $agent   = new PomodoroAgent();
        $context = $this->makeContext('compare');

        $result = $this->callHandleCompare($agent, $context);

        $this->assertEquals('pomodoro_compare_empty', $result->metadata['action']);
        $this->assertStringContainsString('assez', $result->reply);
    }

    public function test_compare_shows_current_week_sessions(): void
    {
        $agent   = new PomodoroAgent();
        $context = $this->makeContext('compare');

        $this->createCompletedSession($context, 25, 4);
        $this->createCompletedSession($context, 30, 5);

        $result = $this->callHandleCompare($agent, $context);

        $this->assertEquals('pomodoro_compare', $result->metadata['action']);
        $this->assertStringContainsString('2', $result->reply);
        $this->assertStringContainsString('sem.', $result->reply);
    }

    public function test_compare_shows_positive_delta_when_improving(): void
    {
        $agent   = new PomodoroAgent();
        $context = $this->makeContext('compare');

        // 1 session last week (Wednesday of last week = safely mid-week)
        $lastWeekDay = now()->startOfWeek(\Carbon\Carbon::MONDAY)->subDays(4)->setTime(12, 0, 0);
        PomodoroSession::create([
            'agent_id'      => $context->agent->id,
            'user_phone'    => $this->testPhone,
            'duration'      => 25,
            'started_at'    => $lastWeekDay,
            'ended_at'      => $lastWeekDay->copy()->addMinutes(25),
            'is_completed'  => true,
            'focus_quality' => 3,
        ]);

        // 3 sessions this week
        for ($i = 0; $i < 3; $i++) {
            $this->createCompletedSession($context, 25, 4);
        }

        $result = $this->callHandleCompare($agent, $context);

        $this->assertStringContainsString('+2', $result->reply);
        $this->assertStringContainsString('progression', strtolower($result->reply));
    }

    public function test_compare_shows_negative_delta_when_declining(): void
    {
        $agent   = new PomodoroAgent();
        $context = $this->makeContext('compare');

        // 3 sessions last week (different days mid-last-week)
        $lastWeekBase = now()->startOfWeek(\Carbon\Carbon::MONDAY)->subDays(5)->setTime(12, 0, 0);
        for ($i = 0; $i < 3; $i++) {
            PomodoroSession::create([
                'agent_id'      => $context->agent->id,
                'user_phone'    => $this->testPhone,
                'duration'      => 25,
                'started_at'    => $lastWeekBase->copy()->addDays($i),
                'ended_at'      => $lastWeekBase->copy()->addDays($i)->addMinutes(25),
                'is_completed'  => true,
                'focus_quality' => 4,
            ]);
        }

        // 1 session this week
        $this->createCompletedSession($context, 25, 3);

        $result = $this->callHandleCompare($agent, $context);

        $this->assertStringContainsString('-2', $result->reply);
        $this->assertStringContainsString('baisse', strtolower($result->reply));
    }

    public function test_compare_shows_stable_when_equal(): void
    {
        $agent   = new PomodoroAgent();
        $context = $this->makeContext('compare');

        // 2 sessions last week
        $lastWeekBase = now()->startOfWeek(\Carbon\Carbon::MONDAY)->subDays(5)->setTime(12, 0, 0);
        for ($i = 0; $i < 2; $i++) {
            PomodoroSession::create([
                'agent_id'      => $context->agent->id,
                'user_phone'    => $this->testPhone,
                'duration'      => 25,
                'started_at'    => $lastWeekBase->copy()->addDays($i),
                'ended_at'      => $lastWeekBase->copy()->addDays($i)->addMinutes(25),
                'is_completed'  => true,
                'focus_quality' => 4,
            ]);
        }

        // 2 sessions this week
        for ($i = 0; $i < 2; $i++) {
            $this->createCompletedSession($context, 25, 4);
        }

        $result = $this->callHandleCompare($agent, $context);

        $this->assertStringContainsString('Stable', $result->reply);
    }

    // ── Break duplicate detection ──────────────────────────────────────────

    public function test_break_returns_already_active_when_break_in_progress(): void
    {
        $agent   = new PomodoroAgent();
        $context = $this->makeContext('break 5');

        $breakKey = "pomodoro:break:{$this->testPhone}:{$context->agent->id}";
        Cache::put($breakKey, [
            'started_at' => now()->toDateTimeString(),
            'duration'   => 5,
        ], 600);

        $result = $this->callHandleBreak($agent, $context, ['action' => 'break', 'duration' => 5]);

        $this->assertEquals('pomodoro_break_already_active', $result->metadata['action']);
        $this->assertStringContainsString('deja en cours', $result->reply);
    }

    public function test_break_replaces_expired_break(): void
    {
        $agent   = new PomodoroAgent();
        $context = $this->makeContext('break 5');

        $breakKey = "pomodoro:break:{$this->testPhone}:{$context->agent->id}";
        // Break started 10 minutes ago with duration 5 → already expired
        Cache::put($breakKey, [
            'started_at' => now()->subMinutes(10)->toDateTimeString(),
            'duration'   => 5,
        ], 600);

        $result = $this->callHandleBreak($agent, $context, ['action' => 'break', 'duration' => 5]);

        // Should start a new break
        $this->assertEquals('pomodoro_break_start', $result->metadata['action']);
    }

    // ── Weekly goal ────────────────────────────────────────────────────────

    public function test_weekly_goal_set_stores_in_cache(): void
    {
        $agent   = new PomodoroAgent();
        $context = $this->makeContext('objectif semaine 20');

        $result = $this->callHandleWeekly($agent, $context, ['action' => 'weekly', 'value' => 20]);

        $this->assertEquals('pomodoro_weekly_goal_set', $result->metadata['action']);
        $this->assertEquals(20, $result->metadata['goal']);

        $cached = Cache::get("pomodoro:weekly_goal:{$this->testPhone}:{$context->agent->id}");
        $this->assertEquals(20, $cached);
    }

    public function test_weekly_goal_set_clamps_to_max_100(): void
    {
        $agent   = new PomodoroAgent();
        $context = $this->makeContext('objectif semaine 999');

        $this->callHandleWeekly($agent, $context, ['action' => 'weekly', 'value' => 999]);

        $cached = Cache::get("pomodoro:weekly_goal:{$this->testPhone}:{$context->agent->id}");
        $this->assertEquals(100, $cached);
    }

    public function test_weekly_goal_set_clamps_to_min_1(): void
    {
        $agent   = new PomodoroAgent();
        $context = $this->makeContext('objectif semaine 0');

        $this->callHandleWeekly($agent, $context, ['action' => 'weekly', 'value' => 0]);

        $cached = Cache::get("pomodoro:weekly_goal:{$this->testPhone}:{$context->agent->id}");
        $this->assertEquals(1, $cached);
    }

    public function test_weekly_goal_view_shows_no_goal_when_not_set(): void
    {
        $agent   = new PomodoroAgent();
        $context = $this->makeContext('weekly');

        $result = $this->callHandleWeekly($agent, $context, ['action' => 'weekly']);

        $this->assertEquals('pomodoro_weekly_goal_view', $result->metadata['action']);
        $this->assertStringContainsString("pas d'objectif", $result->reply);
    }

    public function test_weekly_goal_view_shows_progress_when_set(): void
    {
        $agent   = new PomodoroAgent();
        $context = $this->makeContext('weekly');

        Cache::put("pomodoro:weekly_goal:{$this->testPhone}:{$context->agent->id}", 10, now()->addWeek());
        $this->createCompletedSession($context, 25, 4);
        $this->createCompletedSession($context, 25, 5);

        $result = $this->callHandleWeekly($agent, $context, ['action' => 'weekly']);

        $this->assertStringContainsString('10 sessions', $result->reply);
        $this->assertStringContainsString('2/10', $result->reply);
    }

    public function test_weekly_goal_view_shows_atteint_when_reached(): void
    {
        $agent   = new PomodoroAgent();
        $context = $this->makeContext('weekly');

        Cache::put("pomodoro:weekly_goal:{$this->testPhone}:{$context->agent->id}", 2, now()->addWeek());
        $this->createCompletedSession($context, 25, 4);
        $this->createCompletedSession($context, 25, 5);

        $result = $this->callHandleWeekly($agent, $context, ['action' => 'weekly']);

        $this->assertStringContainsString('ATTEINT', $result->reply);
    }

    public function test_stats_shows_weekly_goal_when_set(): void
    {
        $agent   = new PomodoroAgent();
        $context = $this->makeContext('stats');

        Cache::put("pomodoro:weekly_goal:{$this->testPhone}:{$context->agent->id}", 10, now()->addWeek());
        $this->createCompletedSession($context, 25, 4);

        $result = $this->callHandleStats($agent, $context);

        $this->assertStringContainsString('objectif semaine: 10', $result->reply);
    }

    public function test_end_shows_weekly_goal_progress_when_set(): void
    {
        $agent   = new PomodoroAgent();
        $context = $this->makeContext('end 4');

        Cache::put("pomodoro:weekly_goal:{$this->testPhone}:{$context->agent->id}", 5, now()->addWeek());
        $this->createActiveSession($context, 25);

        $result = $this->callHandleEnd($agent, $context, ['action' => 'end', 'rating' => 4]);

        $this->assertStringContainsString('Semaine', $result->reply);
    }

    // ── Tip ────────────────────────────────────────────────────────────────

    public function test_tip_returns_a_tip(): void
    {
        $agent   = new PomodoroAgent();
        $context = $this->makeContext('tip');

        $result = $this->callHandleTip($agent, $context);

        $this->assertEquals('pomodoro_tip', $result->metadata['action']);
        $this->assertStringContainsString('Astuce', $result->reply);
        $this->assertStringContainsString('start', $result->reply);
    }

    public function test_tip_reply_contains_quoted_tip_text(): void
    {
        $agent   = new PomodoroAgent();
        $context = $this->makeContext('tip');

        $result = $this->callHandleTip($agent, $context);

        $this->assertMatchesRegularExpression('/"[^"]+"/u', $result->reply);
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

    private function callHandleBreak(PomodoroAgent $agent, AgentContext $context, array $parsed): \App\Services\Agents\AgentResult
    {
        $method = new \ReflectionMethod($agent, 'handleBreak');
        $method->setAccessible(true);
        return $method->invoke($agent, $context, $parsed);
    }

    private function callHandleExtend(PomodoroAgent $agent, AgentContext $context, array $parsed): \App\Services\Agents\AgentResult
    {
        $method = new \ReflectionMethod($agent, 'handleExtend');
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

    private function callHandleBest(PomodoroAgent $agent, AgentContext $context): \App\Services\Agents\AgentResult
    {
        $method = new \ReflectionMethod($agent, 'handleBest');
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

    private function callHandleToday(PomodoroAgent $agent, AgentContext $context): \App\Services\Agents\AgentResult
    {
        $method = new \ReflectionMethod($agent, 'handleToday');
        $method->setAccessible(true);
        return $method->invoke($agent, $context);
    }

    private function callHandleLabel(PomodoroAgent $agent, AgentContext $context, array $parsed): \App\Services\Agents\AgentResult
    {
        $method = new \ReflectionMethod($agent, 'handleLabel');
        $method->setAccessible(true);
        return $method->invoke($agent, $context, $parsed);
    }

    private function callHandleSuggest(PomodoroAgent $agent, AgentContext $context): \App\Services\Agents\AgentResult
    {
        $method = new \ReflectionMethod($agent, 'handleSuggest');
        $method->setAccessible(true);
        return $method->invoke($agent, $context);
    }

    private function callHandleCompare(PomodoroAgent $agent, AgentContext $context): \App\Services\Agents\AgentResult
    {
        $method = new \ReflectionMethod($agent, 'handleCompare');
        $method->setAccessible(true);
        return $method->invoke($agent, $context);
    }

    private function callHandleWeekly(PomodoroAgent $agent, AgentContext $context, array $parsed): \App\Services\Agents\AgentResult
    {
        $method = new \ReflectionMethod($agent, 'handleWeekly');
        $method->setAccessible(true);
        return $method->invoke($agent, $context, $parsed);
    }

    private function callHandleTip(PomodoroAgent $agent, AgentContext $context): \App\Services\Agents\AgentResult
    {
        $method = new \ReflectionMethod($agent, 'handleTip');
        $method->setAccessible(true);
        return $method->invoke($agent, $context);
    }
}
