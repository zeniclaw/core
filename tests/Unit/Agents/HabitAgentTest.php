<?php

namespace Tests\Unit\Agents;

use App\Models\Agent;
use App\Models\AgentSession;
use App\Models\Habit;
use App\Models\HabitLog;
use App\Models\User;
use App\Services\AgentContext;
use App\Services\Agents\HabitAgent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class HabitAgentTest extends TestCase
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

    public function test_agent_name_is_habit(): void
    {
        $this->assertEquals('habit', (new HabitAgent())->name());
    }

    public function test_agent_version_is_1_10_0(): void
    {
        $this->assertEquals('1.10.0', (new HabitAgent())->version());
    }

    public function test_agent_has_description(): void
    {
        $this->assertNotEmpty((new HabitAgent())->description());
    }

    public function test_keywords_include_habitude(): void
    {
        $this->assertContains('habitude', (new HabitAgent())->keywords());
    }

    public function test_keywords_include_streak(): void
    {
        $this->assertContains('streak', (new HabitAgent())->keywords());
    }

    public function test_keywords_include_classement_streak(): void
    {
        $this->assertContains('classement streak', (new HabitAgent())->keywords());
    }

    public function test_keywords_include_rapport_semaine(): void
    {
        $this->assertContains('rapport semaine', (new HabitAgent())->keywords());
    }

    public function test_can_handle_returns_true_when_routed_to_habit(): void
    {
        $agent   = new HabitAgent();
        $context = $this->makeContext('mes habitudes', routedAgent: 'habit');
        $this->assertTrue($agent->canHandle($context));
    }

    public function test_can_handle_returns_false_when_not_routed_to_habit(): void
    {
        $agent   = new HabitAgent();
        $context = $this->makeContext('mes habitudes', routedAgent: 'finance');
        $this->assertFalse($agent->canHandle($context));
    }

    // ── Add ──────────────────────────────────────────────────────────────────

    public function test_add_creates_habit(): void
    {
        $agent   = new HabitAgent();
        $context = $this->makeContext('ajouter habitude Meditation');

        $result = $this->callHandleAdd($agent, $context, [
            'action'      => 'add',
            'name'        => 'Meditation',
            'frequency'   => 'daily',
            'description' => null,
        ]);

        $this->assertEquals('reply', $result->action);
        $this->assertStringContainsString('Meditation', $result->reply);

        $this->assertDatabaseHas('habits', [
            'user_phone' => $this->testPhone,
            'agent_id'   => $context->agent->id,
            'name'       => 'Meditation',
            'frequency'  => 'daily',
        ]);
    }

    public function test_add_creates_weekly_habit(): void
    {
        $agent   = new HabitAgent();
        $context = $this->makeContext('ajouter habitude Sport hebdo');

        $result = $this->callHandleAdd($agent, $context, [
            'action'      => 'add',
            'name'        => 'Sport',
            'frequency'   => 'weekly',
            'description' => null,
        ]);

        $this->assertDatabaseHas('habits', [
            'user_phone' => $this->testPhone,
            'frequency'  => 'weekly',
        ]);
        $this->assertStringContainsString('hebdomadaire', $result->reply);
    }

    public function test_add_rejects_empty_name(): void
    {
        $agent   = new HabitAgent();
        $context = $this->makeContext('ajouter habitude');

        $result = $this->callHandleAdd($agent, $context, ['action' => 'add', 'name' => '']);

        $this->assertEquals('habit_add_no_name', $result->metadata['action']);
    }

    public function test_add_rejects_name_too_long(): void
    {
        $agent   = new HabitAgent();
        $context = $this->makeContext('ajouter habitude');

        $result = $this->callHandleAdd($agent, $context, [
            'action' => 'add',
            'name'   => str_repeat('a', 51),
        ]);

        $this->assertEquals('habit_add_name_too_long', $result->metadata['action']);
    }

    public function test_add_accepts_name_at_max_length(): void
    {
        $agent   = new HabitAgent();
        $context = $this->makeContext('ajouter habitude');

        $result = $this->callHandleAdd($agent, $context, [
            'action'    => 'add',
            'name'      => str_repeat('a', 50),
            'frequency' => 'daily',
        ]);

        $this->assertDatabaseHas('habits', ['user_phone' => $this->testPhone]);
    }

    public function test_add_rejects_duplicate_name(): void
    {
        $agent   = new HabitAgent();
        $context = $this->makeContext('ajouter habitude Meditation');

        $this->createHabit($context, 'Meditation');

        $result = $this->callHandleAdd($agent, $context, [
            'action'    => 'add',
            'name'      => 'Meditation',
            'frequency' => 'daily',
        ]);

        $this->assertEquals('habit_add_duplicate', $result->metadata['action']);
    }

    public function test_add_rejects_duplicate_name_case_insensitive(): void
    {
        $agent   = new HabitAgent();
        $context = $this->makeContext('ajouter habitude meditation');

        $this->createHabit($context, 'Meditation');

        $result = $this->callHandleAdd($agent, $context, [
            'action'    => 'add',
            'name'      => 'meditation',
            'frequency' => 'daily',
        ]);

        $this->assertEquals('habit_add_duplicate', $result->metadata['action']);
    }

    public function test_add_enforces_max_habits_limit(): void
    {
        $agent   = new HabitAgent();
        $context = $this->makeContext('ajouter habitude Test');

        for ($i = 1; $i <= 20; $i++) {
            $this->createHabit($context, "Habitude {$i}");
        }

        $result = $this->callHandleAdd($agent, $context, [
            'action'    => 'add',
            'name'      => 'Nouvelle',
            'frequency' => 'daily',
        ]);

        $this->assertEquals('habit_add_limit_reached', $result->metadata['action']);
    }

    // ── Log ──────────────────────────────────────────────────────────────────

    public function test_log_checks_habit(): void
    {
        $agent   = new HabitAgent();
        $context = $this->makeContext("j'ai medite");
        $habit   = $this->createHabit($context, 'Meditation');
        $habits  = Habit::where('user_phone', $this->testPhone)->orderBy('name')->get();

        $result = $this->callHandleLog($agent, $context, $habits, ['action' => 'log', 'item' => 1]);

        $this->assertEquals('reply', $result->action);
        $this->assertStringContainsString('Meditation', $result->reply);
        $this->assertStringContainsString('cochee', $result->reply);

        $this->assertDatabaseHas('habit_logs', ['habit_id' => $habit->id]);
    }

    public function test_log_returns_streak(): void
    {
        $agent   = new HabitAgent();
        $context = $this->makeContext("j'ai medite");
        $this->createHabit($context, 'Meditation');
        $habits = Habit::where('user_phone', $this->testPhone)->orderBy('name')->get();

        $result = $this->callHandleLog($agent, $context, $habits, ['action' => 'log', 'item' => 1]);

        $this->assertStringContainsString('Streak', $result->reply);
    }

    public function test_log_prevents_double_log_daily(): void
    {
        $agent   = new HabitAgent();
        $context = $this->makeContext("j'ai medite");
        $habit   = $this->createHabit($context, 'Meditation');

        HabitLog::create([
            'habit_id'       => $habit->id,
            'completed_date' => now()->toDateString(),
            'streak_count'   => 1,
            'best_streak'    => 1,
        ]);

        $habits = Habit::where('user_phone', $this->testPhone)->orderBy('name')->get();
        $result = $this->callHandleLog($agent, $context, $habits, ['action' => 'log', 'item' => 1]);

        $this->assertEquals('habit_already_logged', $result->metadata['action']);
    }

    public function test_log_no_item_returns_error(): void
    {
        $agent   = new HabitAgent();
        $context = $this->makeContext("j'ai fait quelque chose");
        $habits  = collect();

        $result = $this->callHandleLog($agent, $context, $habits, ['action' => 'log', 'item' => null]);

        $this->assertEquals('habit_log_no_item', $result->metadata['action']);
    }

    public function test_log_invalid_item_returns_error(): void
    {
        $agent   = new HabitAgent();
        $context = $this->makeContext("cocher habitude 99");
        $this->createHabit($context, 'Meditation');
        $habits = Habit::where('user_phone', $this->testPhone)->orderBy('name')->get();

        $result = $this->callHandleLog($agent, $context, $habits, ['action' => 'log', 'item' => 99]);

        $this->assertEquals('habit_log_not_found', $result->metadata['action']);
    }

    // ── Unlog ────────────────────────────────────────────────────────────────

    public function test_unlog_removes_todays_log(): void
    {
        $agent   = new HabitAgent();
        $context = $this->makeContext("annuler log meditation");
        $habit   = $this->createHabit($context, 'Meditation');

        HabitLog::create([
            'habit_id'       => $habit->id,
            'completed_date' => now()->toDateString(),
            'streak_count'   => 1,
            'best_streak'    => 1,
        ]);

        $habits = Habit::where('user_phone', $this->testPhone)->orderBy('name')->get();
        $result = $this->callHandleUnlog($agent, $context, $habits, ['action' => 'unlog', 'item' => 1]);

        $this->assertEquals('reply', $result->action);
        $this->assertDatabaseMissing('habit_logs', ['habit_id' => $habit->id]);
    }

    public function test_unlog_returns_error_when_not_logged_today(): void
    {
        $agent   = new HabitAgent();
        $context = $this->makeContext("annuler log meditation");
        $this->createHabit($context, 'Meditation');
        $habits = Habit::where('user_phone', $this->testPhone)->orderBy('name')->get();

        $result = $this->callHandleUnlog($agent, $context, $habits, ['action' => 'unlog', 'item' => 1]);

        $this->assertEquals('habit_unlog_not_logged', $result->metadata['action']);
    }

    // ── List ─────────────────────────────────────────────────────────────────

    public function test_list_shows_empty_message_when_no_habits(): void
    {
        $agent   = new HabitAgent();
        $context = $this->makeContext('mes habitudes');
        $habits  = collect();

        $result = $this->callHandleList($agent, $context, $habits);

        $this->assertEquals('habit_list_empty', $result->metadata['action']);
        $this->assertStringContainsString('aucune habitude', $result->reply);
    }

    public function test_list_shows_habits_with_streaks(): void
    {
        $agent   = new HabitAgent();
        $context = $this->makeContext('mes habitudes');
        $this->createHabit($context, 'Meditation');
        $this->createHabit($context, 'Sport');
        $habits = Habit::where('user_phone', $this->testPhone)->orderBy('name')->get();

        $result = $this->callHandleList($agent, $context, $habits);

        $this->assertStringContainsString('Meditation', $result->reply);
        $this->assertStringContainsString('Sport', $result->reply);
        $this->assertStringContainsString('Streak', $result->reply);
    }

    public function test_list_shows_done_status(): void
    {
        $agent   = new HabitAgent();
        $context = $this->makeContext('mes habitudes');
        $habit   = $this->createHabit($context, 'Meditation');

        HabitLog::create([
            'habit_id'       => $habit->id,
            'completed_date' => now()->toDateString(),
            'streak_count'   => 1,
            'best_streak'    => 1,
        ]);

        $habits = Habit::where('user_phone', $this->testPhone)->orderBy('name')->get();
        $result = $this->callHandleList($agent, $context, $habits);

        $this->assertStringContainsString('[FAIT]', $result->reply);
    }

    // ── Today ────────────────────────────────────────────────────────────────

    public function test_today_shows_pending_and_done_habits(): void
    {
        $agent   = new HabitAgent();
        $context = $this->makeContext("aujourd'hui");
        $habit1  = $this->createHabit($context, 'Lecture');
        $habit2  = $this->createHabit($context, 'Meditation');

        HabitLog::create([
            'habit_id'       => $habit1->id,
            'completed_date' => now()->toDateString(),
            'streak_count'   => 1,
            'best_streak'    => 1,
        ]);

        $habits = Habit::where('user_phone', $this->testPhone)->orderBy('name')->get();
        $result = $this->callHandleToday($agent, $context, $habits);

        $this->assertStringContainsString('Faites', $result->reply);
        $this->assertStringContainsString('A faire', $result->reply);
        $this->assertStringContainsString('Lecture', $result->reply);
        $this->assertStringContainsString('Meditation', $result->reply);
    }

    public function test_today_shows_congratulations_when_all_done(): void
    {
        $agent   = new HabitAgent();
        $context = $this->makeContext("aujourd'hui");
        $habit   = $this->createHabit($context, 'Meditation');

        HabitLog::create([
            'habit_id'       => $habit->id,
            'completed_date' => now()->toDateString(),
            'streak_count'   => 1,
            'best_streak'    => 1,
        ]);

        $habits = Habit::where('user_phone', $this->testPhone)->orderBy('name')->get();
        $result = $this->callHandleToday($agent, $context, $habits);

        $this->assertStringContainsString('Bravo', $result->reply);
    }

    // ── Stats ────────────────────────────────────────────────────────────────

    public function test_stats_shows_empty_message_when_no_habits(): void
    {
        $agent   = new HabitAgent();
        $context = $this->makeContext('stats');
        $habits  = collect();

        $result = $this->callHandleStats($agent, $context, $habits);

        $this->assertEquals('habit_stats_empty', $result->metadata['action']);
    }

    public function test_stats_shows_completion_rate(): void
    {
        $agent   = new HabitAgent();
        $context = $this->makeContext('stats habitudes');
        $habit   = $this->createHabit($context, 'Meditation');
        $habits  = Habit::where('user_phone', $this->testPhone)->orderBy('name')->get();

        $result = $this->callHandleStats($agent, $context, $habits);

        $this->assertStringContainsString('Taux 30j', $result->reply);
        $this->assertStringContainsString('Meditation', $result->reply);
    }

    // ── Delete ───────────────────────────────────────────────────────────────

    public function test_delete_removes_habit(): void
    {
        $agent   = new HabitAgent();
        $context = $this->makeContext('supprimer habitude 1');
        $habit   = $this->createHabit($context, 'Meditation');
        $habits  = Habit::where('user_phone', $this->testPhone)->orderBy('name')->get();

        $result = $this->callHandleDelete($agent, $context, $habits, ['action' => 'delete', 'item' => 1]);

        $this->assertStringContainsString('supprimee', $result->reply);
        $this->assertDatabaseMissing('habits', ['id' => $habit->id, 'deleted_at' => null]);
    }

    public function test_delete_returns_error_for_invalid_item(): void
    {
        $agent   = new HabitAgent();
        $context = $this->makeContext('supprimer habitude 99');
        $this->createHabit($context, 'Meditation');
        $habits = Habit::where('user_phone', $this->testPhone)->orderBy('name')->get();

        $result = $this->callHandleDelete($agent, $context, $habits, ['action' => 'delete', 'item' => 99]);

        $this->assertEquals('habit_delete_not_found', $result->metadata['action']);
    }

    // ── Reset ────────────────────────────────────────────────────────────────

    public function test_reset_clears_logs_and_cache(): void
    {
        $agent   = new HabitAgent();
        $context = $this->makeContext('reset habitude 1');
        $habit   = $this->createHabit($context, 'Meditation');

        HabitLog::create([
            'habit_id'       => $habit->id,
            'completed_date' => now()->toDateString(),
            'streak_count'   => 5,
            'best_streak'    => 5,
        ]);
        Cache::put("habit_streak:{$habit->id}", 5, 3600);

        $habits = Habit::where('user_phone', $this->testPhone)->orderBy('name')->get();
        $result = $this->callHandleReset($agent, $context, $habits, ['action' => 'reset', 'item' => 1]);

        $this->assertStringContainsString('reinitialisee', $result->reply);
        $this->assertDatabaseMissing('habit_logs', ['habit_id' => $habit->id]);
        $this->assertNull(Cache::get("habit_streak:{$habit->id}"));
    }

    // ── Rename ───────────────────────────────────────────────────────────────

    public function test_rename_updates_habit_name(): void
    {
        $agent   = new HabitAgent();
        $context = $this->makeContext('renommer habitude 1 en Course a pied');
        $this->createHabit($context, 'Sport');
        $habits = Habit::where('user_phone', $this->testPhone)->orderBy('name')->get();

        $result = $this->callHandleRename($agent, $context, $habits, [
            'action' => 'rename',
            'item'   => 1,
            'name'   => 'Course a pied',
        ]);

        $this->assertStringContainsString('Course a pied', $result->reply);
        $this->assertDatabaseHas('habits', ['name' => 'Course a pied']);
    }

    public function test_rename_rejects_duplicate_name(): void
    {
        $agent   = new HabitAgent();
        $context = $this->makeContext('renommer habitude 1 en Lecture');
        $this->createHabit($context, 'Sport');
        $this->createHabit($context, 'Lecture');
        $habits = Habit::where('user_phone', $this->testPhone)->orderBy('name')->get();

        $result = $this->callHandleRename($agent, $context, $habits, [
            'action' => 'rename',
            'item'   => 2,
            'name'   => 'Lecture',
        ]);

        $this->assertEquals('habit_rename_duplicate', $result->metadata['action']);
    }

    // ── Change Frequency ─────────────────────────────────────────────────────

    public function test_change_frequency_switches_daily_to_weekly(): void
    {
        $agent   = new HabitAgent();
        $context = $this->makeContext('passer habitude 1 en hebdo');
        $this->createHabit($context, 'Sport', 'daily');
        $habits = Habit::where('user_phone', $this->testPhone)->orderBy('name')->get();

        $result = $this->callHandleChangeFrequency($agent, $context, $habits, [
            'action'    => 'change_frequency',
            'item'      => 1,
            'frequency' => 'weekly',
        ]);

        $this->assertStringContainsString('hebdomadaire', $result->reply);
        $this->assertDatabaseHas('habits', ['name' => 'Sport', 'frequency' => 'weekly']);
    }

    public function test_change_frequency_rejects_same_frequency(): void
    {
        $agent   = new HabitAgent();
        $context = $this->makeContext('passer habitude 1 en daily');
        $this->createHabit($context, 'Sport', 'daily');
        $habits = Habit::where('user_phone', $this->testPhone)->orderBy('name')->get();

        $result = $this->callHandleChangeFrequency($agent, $context, $habits, [
            'action'    => 'change_frequency',
            'item'      => 1,
            'frequency' => 'daily',
        ]);

        $this->assertEquals('habit_change_freq_same', $result->metadata['action']);
    }

    // ── History ──────────────────────────────────────────────────────────────

    public function test_history_shows_7_days_for_one_habit(): void
    {
        $agent   = new HabitAgent();
        $context = $this->makeContext('historique meditation');
        $habit   = $this->createHabit($context, 'Meditation');
        $habits  = Habit::where('user_phone', $this->testPhone)->orderBy('name')->get();

        $result = $this->callHandleHistory($agent, $context, $habits, ['action' => 'history', 'item' => 1]);

        $this->assertStringContainsString('7 derniers jours', $result->reply);
        $this->assertStringContainsString('Meditation', $result->reply);
    }

    public function test_history_shows_all_habits_when_item_null(): void
    {
        $agent = new HabitAgent();
        $context = $this->makeContext('historique toutes habitudes');
        $this->createHabit($context, 'Lecture');
        $this->createHabit($context, 'Meditation');
        $habits = Habit::where('user_phone', $this->testPhone)->orderBy('name')->get();

        $result = $this->callHandleHistory($agent, $context, $habits, ['action' => 'history', 'item' => null]);

        $this->assertStringContainsString('Lecture', $result->reply);
        $this->assertStringContainsString('Meditation', $result->reply);
    }

    // ── Motivate ─────────────────────────────────────────────────────────────

    public function test_motivate_shows_empty_message_when_no_habits(): void
    {
        $agent   = new HabitAgent();
        $context = $this->makeContext('motivation');
        $habits  = collect();

        $result = $this->callHandleMotivate($agent, $context, $habits);

        $this->assertEquals('habit_motivate_empty', $result->metadata['action']);
    }

    public function test_motivate_shows_at_risk_streaks(): void
    {
        $agent   = new HabitAgent();
        $context = $this->makeContext('motivation');
        $habit   = $this->createHabit($context, 'Meditation');

        // Log yesterday to create a streak of 1 (at risk today)
        HabitLog::create([
            'habit_id'       => $habit->id,
            'completed_date' => now()->subDay()->toDateString(),
            'streak_count'   => 1,
            'best_streak'    => 1,
        ]);

        $habits = Habit::where('user_phone', $this->testPhone)->orderBy('name')->get();
        $result = $this->callHandleMotivate($agent, $context, $habits);

        $this->assertStringContainsString('en jeu', $result->reply);
    }

    public function test_motivate_shows_congrats_when_all_done(): void
    {
        $agent   = new HabitAgent();
        $context = $this->makeContext('motivation');
        $habit   = $this->createHabit($context, 'Meditation');

        HabitLog::create([
            'habit_id'       => $habit->id,
            'completed_date' => now()->toDateString(),
            'streak_count'   => 1,
            'best_streak'    => 1,
        ]);

        $habits = Habit::where('user_phone', $this->testPhone)->orderBy('name')->get();
        $result = $this->callHandleMotivate($agent, $context, $habits);

        $this->assertStringContainsString('Bravo', $result->reply);
    }

    // ── Streak Board ─────────────────────────────────────────────────────────

    public function test_streak_board_shows_empty_message_when_no_habits(): void
    {
        $agent   = new HabitAgent();
        $context = $this->makeContext('classement streaks');
        $habits  = collect();

        $result = $this->callHandleStreakBoard($agent, $context, $habits);

        $this->assertEquals('habit_streak_board_empty', $result->metadata['action']);
    }

    public function test_streak_board_ranks_habits_by_streak(): void
    {
        $agent   = new HabitAgent();
        $context = $this->makeContext('classement streaks');
        $habitA  = $this->createHabit($context, 'Lecture');
        $habitB  = $this->createHabit($context, 'Sport');

        // Sport has streak of 3, Lecture has streak of 1
        for ($i = 3; $i >= 1; $i--) {
            HabitLog::create([
                'habit_id'       => $habitB->id,
                'completed_date' => now()->subDays($i - 1)->toDateString(),
                'streak_count'   => 4 - $i,
                'best_streak'    => 3,
            ]);
        }
        HabitLog::create([
            'habit_id'       => $habitA->id,
            'completed_date' => now()->toDateString(),
            'streak_count'   => 1,
            'best_streak'    => 1,
        ]);

        $habits = Habit::where('user_phone', $this->testPhone)->orderBy('name')->get();
        $result = $this->callHandleStreakBoard($agent, $context, $habits);

        $this->assertEquals('habit_streak_board', $result->metadata['action']);
        $this->assertStringContainsString('Classement', $result->reply);
        $this->assertStringContainsString('1er', $result->reply);
        $this->assertStringContainsString('Total streaks', $result->reply);
    }

    public function test_streak_board_shows_all_habits(): void
    {
        $agent   = new HabitAgent();
        $context = $this->makeContext('classement streaks');
        $this->createHabit($context, 'Lecture');
        $this->createHabit($context, 'Meditation');
        $this->createHabit($context, 'Sport');
        $habits = Habit::where('user_phone', $this->testPhone)->orderBy('name')->get();

        $result = $this->callHandleStreakBoard($agent, $context, $habits);

        $this->assertStringContainsString('Lecture', $result->reply);
        $this->assertStringContainsString('Meditation', $result->reply);
        $this->assertStringContainsString('Sport', $result->reply);
    }

    // ── Weekly Report ────────────────────────────────────────────────────────

    public function test_weekly_report_shows_empty_message_when_no_habits(): void
    {
        $agent   = new HabitAgent();
        $context = $this->makeContext('rapport semaine');
        $habits  = collect();

        $result = $this->callHandleWeeklyReport($agent, $context, $habits);

        $this->assertEquals('habit_weekly_report_empty', $result->metadata['action']);
    }

    public function test_weekly_report_shows_completion_for_daily_habit(): void
    {
        $agent   = new HabitAgent();
        $context = $this->makeContext('rapport semaine');
        $habit   = $this->createHabit($context, 'Meditation', 'daily');

        HabitLog::create([
            'habit_id'       => $habit->id,
            'completed_date' => now()->toDateString(),
            'streak_count'   => 1,
            'best_streak'    => 1,
        ]);

        $habits = Habit::where('user_phone', $this->testPhone)->orderBy('name')->get();
        $result = $this->callHandleWeeklyReport($agent, $context, $habits);

        $this->assertEquals('habit_weekly_report', $result->metadata['action']);
        $this->assertStringContainsString('Rapport semaine', $result->reply);
        $this->assertStringContainsString('Meditation', $result->reply);
        $this->assertStringContainsString('Bilan semaine', $result->reply);
    }

    public function test_weekly_report_shows_completion_for_weekly_habit(): void
    {
        $agent   = new HabitAgent();
        $context = $this->makeContext('rapport semaine');
        $habit   = $this->createHabit($context, 'Sport', 'weekly');

        HabitLog::create([
            'habit_id'       => $habit->id,
            'completed_date' => now()->toDateString(),
            'streak_count'   => 1,
            'best_streak'    => 1,
        ]);

        $habits = Habit::where('user_phone', $this->testPhone)->orderBy('name')->get();
        $result = $this->callHandleWeeklyReport($agent, $context, $habits);

        $this->assertStringContainsString('hebdo', $result->reply);
        $this->assertStringContainsString('1/1', $result->reply);
    }

    public function test_weekly_report_shows_perfect_message_when_all_done(): void
    {
        $agent   = new HabitAgent();
        $context = $this->makeContext('rapport semaine');
        $habit   = $this->createHabit($context, 'Meditation', 'weekly');

        HabitLog::create([
            'habit_id'       => $habit->id,
            'completed_date' => now()->toDateString(),
            'streak_count'   => 1,
            'best_streak'    => 1,
        ]);

        $habits = Habit::where('user_phone', $this->testPhone)->orderBy('name')->get();
        $result = $this->callHandleWeeklyReport($agent, $context, $habits);

        $this->assertStringContainsString('parfaite', $result->reply);
    }

    // ── Monthly Report ───────────────────────────────────────────────────────

    public function test_monthly_report_shows_empty_message_when_no_habits(): void
    {
        $agent   = new HabitAgent();
        $context = $this->makeContext('rapport mensuel');
        $habits  = collect();

        $result = $this->callHandleMonthlyReport($agent, $context, $habits);

        $this->assertEquals('habit_monthly_report_empty', $result->metadata['action']);
    }

    public function test_monthly_report_shows_segments_for_daily_habit(): void
    {
        $agent   = new HabitAgent();
        $context = $this->makeContext('rapport mensuel');
        $habit   = $this->createHabit($context, 'Meditation', 'daily');

        HabitLog::create([
            'habit_id'       => $habit->id,
            'completed_date' => now()->toDateString(),
            'streak_count'   => 1,
            'best_streak'    => 1,
        ]);

        $habits = Habit::where('user_phone', $this->testPhone)->orderBy('name')->get();
        $result = $this->callHandleMonthlyReport($agent, $context, $habits);

        $this->assertEquals('habit_monthly_report', $result->metadata['action']);
        $this->assertStringContainsString('Rapport mensuel', $result->reply);
        $this->assertStringContainsString('Meditation', $result->reply);
        $this->assertStringContainsString('S4', $result->reply);
        $this->assertStringContainsString('Total', $result->reply);
    }

    public function test_monthly_report_shows_segments_for_weekly_habit(): void
    {
        $agent   = new HabitAgent();
        $context = $this->makeContext('rapport mensuel');
        $habit   = $this->createHabit($context, 'Sport', 'weekly');

        HabitLog::create([
            'habit_id'       => $habit->id,
            'completed_date' => now()->toDateString(),
            'streak_count'   => 1,
            'best_streak'    => 1,
        ]);

        $habits = Habit::where('user_phone', $this->testPhone)->orderBy('name')->get();
        $result = $this->callHandleMonthlyReport($agent, $context, $habits);

        $this->assertEquals('habit_monthly_report', $result->metadata['action']);
        $this->assertStringContainsString('Sport', $result->reply);
        $this->assertStringContainsString('/4 sem', $result->reply);
    }

    public function test_monthly_report_shows_perfect_message_when_100_percent(): void
    {
        $agent   = new HabitAgent();
        $context = $this->makeContext('rapport mensuel');
        $habit   = $this->createHabit($context, 'Meditation', 'daily');

        // Log all 30 days
        for ($i = 0; $i < 30; $i++) {
            HabitLog::create([
                'habit_id'       => $habit->id,
                'completed_date' => now()->subDays($i)->toDateString(),
                'streak_count'   => $i + 1,
                'best_streak'    => 30,
            ]);
        }

        $habits = Habit::where('user_phone', $this->testPhone)->orderBy('name')->get();
        $result = $this->callHandleMonthlyReport($agent, $context, $habits);

        $this->assertStringContainsString('exceptionnel', $result->reply);
    }

    // ── Best Day ─────────────────────────────────────────────────────────────

    public function test_best_day_shows_error_when_no_daily_habits(): void
    {
        $agent   = new HabitAgent();
        $context = $this->makeContext('meilleur jour');
        $this->createHabit($context, 'Sport', 'weekly');
        $habits = Habit::where('user_phone', $this->testPhone)->orderBy('name')->get();

        $result = $this->callHandleBestDay($agent, $context, $habits);

        $this->assertEquals('habit_best_day_no_daily', $result->metadata['action']);
    }

    public function test_best_day_shows_no_data_when_no_logs(): void
    {
        $agent   = new HabitAgent();
        $context = $this->makeContext('meilleur jour');
        $this->createHabit($context, 'Meditation', 'daily');
        $habits = Habit::where('user_phone', $this->testPhone)->orderBy('name')->get();

        $result = $this->callHandleBestDay($agent, $context, $habits);

        $this->assertEquals('habit_best_day_no_data', $result->metadata['action']);
    }

    public function test_best_day_shows_analysis_with_logs(): void
    {
        $agent   = new HabitAgent();
        $context = $this->makeContext('meilleur jour');
        $habit   = $this->createHabit($context, 'Meditation', 'daily');

        // Log the last 7 days
        for ($i = 0; $i < 7; $i++) {
            HabitLog::create([
                'habit_id'       => $habit->id,
                'completed_date' => now()->subDays($i)->toDateString(),
                'streak_count'   => $i + 1,
                'best_streak'    => 7,
            ]);
        }

        $habits = Habit::where('user_phone', $this->testPhone)->orderBy('name')->get();
        $result = $this->callHandleBestDay($agent, $context, $habits);

        $this->assertEquals('habit_best_day', $result->metadata['action']);
        $this->assertStringContainsString('Meilleur jour', $result->reply);
        $this->assertStringContainsString('Lundi', $result->reply);
        $this->assertArrayHasKey('best_dow', $result->metadata);
    }

    public function test_best_day_returns_day_between_1_and_7(): void
    {
        $agent   = new HabitAgent();
        $context = $this->makeContext('meilleur jour');
        $habit   = $this->createHabit($context, 'Meditation', 'daily');

        for ($i = 0; $i < 14; $i++) {
            HabitLog::create([
                'habit_id'       => $habit->id,
                'completed_date' => now()->subDays($i)->toDateString(),
                'streak_count'   => $i + 1,
                'best_streak'    => 14,
            ]);
        }

        $habits = Habit::where('user_phone', $this->testPhone)->orderBy('name')->get();
        $result = $this->callHandleBestDay($agent, $context, $habits);

        $this->assertGreaterThanOrEqual(1, $result->metadata['best_dow']);
        $this->assertLessThanOrEqual(7, $result->metadata['best_dow']);
    }

    // ── Log Multiple ─────────────────────────────────────────────────────────

    public function test_log_multiple_logs_several_habits(): void
    {
        $agent   = new HabitAgent();
        $context = $this->makeContext("j'ai fait sport et meditation");
        $habit1  = $this->createHabit($context, 'Meditation');
        $habit2  = $this->createHabit($context, 'Sport');
        $habits  = Habit::where('user_phone', $this->testPhone)->orderBy('name')->get();

        $result = $this->callHandleLogMultiple($agent, $context, $habits, [
            'action' => 'log_multiple',
            'items'  => [1, 2],
        ]);

        $this->assertEquals('habit_log_multiple', $result->metadata['action']);
        $this->assertEquals(2, $result->metadata['logged']);
        $this->assertDatabaseHas('habit_logs', ['habit_id' => $habit1->id]);
        $this->assertDatabaseHas('habit_logs', ['habit_id' => $habit2->id]);
    }

    public function test_log_multiple_skips_already_logged(): void
    {
        $agent  = new HabitAgent();
        $context = $this->makeContext("j'ai fait sport et meditation");
        $habit  = $this->createHabit($context, 'Meditation');

        HabitLog::create([
            'habit_id'       => $habit->id,
            'completed_date' => now()->toDateString(),
            'streak_count'   => 1,
            'best_streak'    => 1,
        ]);

        $habits = Habit::where('user_phone', $this->testPhone)->orderBy('name')->get();
        $result = $this->callHandleLogMultiple($agent, $context, $habits, [
            'action' => 'log_multiple',
            'items'  => [1],
        ]);

        $this->assertEquals(0, $result->metadata['logged']);
        $this->assertStringContainsString('Deja cochee', $result->reply);
    }

    public function test_log_multiple_no_items_returns_error(): void
    {
        $agent   = new HabitAgent();
        $context = $this->makeContext("j'ai fait des trucs");
        $habits  = collect();

        $result = $this->callHandleLogMultiple($agent, $context, $habits, [
            'action' => 'log_multiple',
            'items'  => [],
        ]);

        $this->assertEquals('habit_log_multiple_no_items', $result->metadata['action']);
    }

    // ── Pause / Resume ───────────────────────────────────────────────────────

    public function test_pause_pauses_an_active_habit(): void
    {
        $agent   = new HabitAgent();
        $context = $this->makeContext('mettre en pause habitude 1');
        $habit   = $this->createHabit($context, 'Meditation');
        $habits  = Habit::where('user_phone', $this->testPhone)->orderBy('name')->get();

        $result = $this->callHandlePause($agent, $context, $habits, ['action' => 'pause', 'item' => 1]);

        $this->assertEquals('habit_pause', $result->metadata['action']);
        $this->assertStringContainsString('pause', $result->reply);
        $this->assertDatabaseHas('habits', ['id' => $habit->id]);
        $this->assertNotNull(Habit::find($habit->id)->paused_at);
    }

    public function test_pause_returns_error_if_already_paused(): void
    {
        $agent   = new HabitAgent();
        $context = $this->makeContext('mettre en pause habitude 1');
        $habit   = $this->createHabit($context, 'Meditation');
        $habit->update(['paused_at' => now()]);
        $habits  = Habit::where('user_phone', $this->testPhone)->orderBy('name')->get();

        $result = $this->callHandlePause($agent, $context, $habits, ['action' => 'pause', 'item' => 1]);

        $this->assertEquals('habit_pause_already_paused', $result->metadata['action']);
    }

    public function test_resume_reactivates_paused_habit(): void
    {
        $agent   = new HabitAgent();
        $context = $this->makeContext('reprendre habitude 1');
        $habit   = $this->createHabit($context, 'Meditation');
        $habit->update(['paused_at' => now()]);
        $habits  = Habit::where('user_phone', $this->testPhone)->orderBy('name')->get();

        $result = $this->callHandleResume($agent, $context, $habits, ['action' => 'resume', 'item' => 1]);

        $this->assertEquals('habit_resume', $result->metadata['action']);
        $this->assertStringContainsString('reactivee', $result->reply);
        $this->assertNull(Habit::find($habit->id)->paused_at);
    }

    public function test_resume_returns_error_if_not_paused(): void
    {
        $agent   = new HabitAgent();
        $context = $this->makeContext('reprendre habitude 1');
        $this->createHabit($context, 'Meditation');
        $habits  = Habit::where('user_phone', $this->testPhone)->orderBy('name')->get();

        $result = $this->callHandleResume($agent, $context, $habits, ['action' => 'resume', 'item' => 1]);

        $this->assertEquals('habit_resume_not_paused', $result->metadata['action']);
    }

    public function test_log_auto_resumes_paused_habit(): void
    {
        $agent   = new HabitAgent();
        $context = $this->makeContext("j'ai medite");
        $habit   = $this->createHabit($context, 'Meditation');
        $habit->update(['paused_at' => now()]);
        $habits  = Habit::where('user_phone', $this->testPhone)->orderBy('name')->get();

        $result = $this->callHandleLog($agent, $context, $habits, ['action' => 'log', 'item' => 1]);

        $this->assertEquals('reply', $result->action);
        $this->assertStringContainsString('reactivee', $result->reply);
        $this->assertNull(Habit::find($habit->id)->paused_at);
    }

    public function test_today_excludes_paused_habits_from_pending(): void
    {
        $agent   = new HabitAgent();
        $context = $this->makeContext("aujourd'hui");
        $habit   = $this->createHabit($context, 'Meditation');
        $habit->update(['paused_at' => now()]);
        $habits  = Habit::where('user_phone', $this->testPhone)->orderBy('name')->get();

        $result = $this->callHandleToday($agent, $context, $habits);

        $this->assertStringContainsString('[PAUSE]', $result->reply);
        $this->assertStringNotContainsString('A faire', $result->reply);
    }

    public function test_list_shows_pause_status(): void
    {
        $agent   = new HabitAgent();
        $context = $this->makeContext('mes habitudes');
        $habit   = $this->createHabit($context, 'Meditation');
        $habit->update(['paused_at' => now()]);
        $habits  = Habit::where('user_phone', $this->testPhone)->orderBy('name')->get();

        $result = $this->callHandleList($agent, $context, $habits);

        $this->assertStringContainsString('[PAUSE]', $result->reply);
    }

    // ── Set Goal ─────────────────────────────────────────────────────────────

    public function test_set_goal_stores_goal(): void
    {
        $agent   = new HabitAgent();
        $context = $this->makeContext('objectif 3 habitudes par jour');

        $result = $this->callHandleSetGoal($agent, $context, ['action' => 'set_goal', 'count' => 3]);

        $this->assertEquals('habit_set_goal', $result->metadata['action']);
        $this->assertEquals(3, $result->metadata['goal']);
        $this->assertStringContainsString('3', $result->reply);
    }

    public function test_set_goal_zero_removes_goal(): void
    {
        $agent   = new HabitAgent();
        $context = $this->makeContext('supprimer mon objectif');

        $result = $this->callHandleSetGoal($agent, $context, ['action' => 'set_goal', 'count' => 0]);

        $this->assertEquals('habit_goal_removed', $result->metadata['action']);
    }

    public function test_set_goal_invalid_count_returns_error(): void
    {
        $agent   = new HabitAgent();
        $context = $this->makeContext('objectif habitudes');

        $result = $this->callHandleSetGoal($agent, $context, ['action' => 'set_goal', 'count' => -1]);

        $this->assertEquals('habit_set_goal_invalid', $result->metadata['action']);
    }

    public function test_today_shows_goal_progress_when_goal_set(): void
    {
        $agent   = new HabitAgent();
        $context = $this->makeContext("aujourd'hui");
        $habit   = $this->createHabit($context, 'Meditation');

        // Set a goal of 2
        \App\Models\AppSetting::set('habit_goal_' . md5($this->testPhone), '2');

        $habits = \App\Models\Habit::where('user_phone', $this->testPhone)->orderBy('name')->get();
        $result = $this->callHandleToday($agent, $context, $habits);

        $this->assertStringContainsString('Objectif du jour', $result->reply);
    }

    // ── Perfect Streak ───────────────────────────────────────────────────────

    public function test_perfect_streak_returns_no_daily_when_no_daily_habits(): void
    {
        $agent   = new HabitAgent();
        $context = $this->makeContext('jours parfaits');
        $this->createHabit($context, 'Sport', 'weekly');
        $habits = \App\Models\Habit::where('user_phone', $this->testPhone)->orderBy('name')->get();

        $result = $this->callHandlePerfectStreak($agent, $context, $habits);

        $this->assertEquals('habit_perfect_streak_no_daily', $result->metadata['action']);
    }

    public function test_perfect_streak_returns_zero_when_no_logs(): void
    {
        $agent   = new HabitAgent();
        $context = $this->makeContext('jours parfaits');
        $this->createHabit($context, 'Meditation', 'daily');
        $habits = \App\Models\Habit::where('user_phone', $this->testPhone)->orderBy('name')->get();

        $result = $this->callHandlePerfectStreak($agent, $context, $habits);

        $this->assertEquals('habit_perfect_streak', $result->metadata['action']);
        $this->assertEquals(0, $result->metadata['streak']);
    }

    public function test_perfect_streak_counts_consecutive_days(): void
    {
        $agent   = new HabitAgent();
        $context = $this->makeContext('jours parfaits');
        $habit   = $this->createHabit($context, 'Meditation', 'daily');

        // Log 3 consecutive days including today
        for ($i = 0; $i < 3; $i++) {
            HabitLog::create([
                'habit_id'       => $habit->id,
                'completed_date' => now()->subDays($i)->toDateString(),
                'streak_count'   => $i + 1,
                'best_streak'    => 3,
            ]);
        }

        $habits = \App\Models\Habit::where('user_phone', $this->testPhone)->orderBy('name')->get();
        $result = $this->callHandlePerfectStreak($agent, $context, $habits);

        $this->assertEquals('habit_perfect_streak', $result->metadata['action']);
        $this->assertEquals(3, $result->metadata['streak']);
        $this->assertStringContainsString('3', $result->reply);
    }

    public function test_perfect_streak_breaks_when_not_all_habits_done(): void
    {
        $agent  = new HabitAgent();
        $context = $this->makeContext('jours parfaits');
        $habit1  = $this->createHabit($context, 'Meditation', 'daily');
        $habit2  = $this->createHabit($context, 'Sport', 'daily');

        // Only habit1 done today — not a perfect day (habit2 missing)
        HabitLog::create([
            'habit_id'       => $habit1->id,
            'completed_date' => now()->toDateString(),
            'streak_count'   => 1,
            'best_streak'    => 1,
        ]);

        $habits = \App\Models\Habit::where('user_phone', $this->testPhone)->orderBy('name')->get();
        $result = $this->callHandlePerfectStreak($agent, $context, $habits);

        $this->assertEquals(0, $result->metadata['streak']);
        $this->assertStringContainsString('Fais TOUTES', $result->reply);
    }

    // ── Compare Week ─────────────────────────────────────────────────────────

    public function test_compare_week_shows_empty_when_no_habits(): void
    {
        $agent   = new HabitAgent();
        $context = $this->makeContext('comparer semaine');
        $habits  = collect();

        $result = $this->callHandleCompareWeek($agent, $context, $habits);

        $this->assertEquals('habit_compare_week_empty', $result->metadata['action']);
    }

    public function test_compare_week_shows_comparison_for_habits(): void
    {
        $agent   = new HabitAgent();
        $context = $this->makeContext('comparer semaine');
        $habit   = $this->createHabit($context, 'Meditation', 'daily');

        // Log once in previous week
        HabitLog::create([
            'habit_id'       => $habit->id,
            'completed_date' => now()->subWeek()->startOfWeek(\Carbon\Carbon::MONDAY)->toDateString(),
            'streak_count'   => 1,
            'best_streak'    => 1,
        ]);
        // Log once in current week
        HabitLog::create([
            'habit_id'       => $habit->id,
            'completed_date' => now()->toDateString(),
            'streak_count'   => 2,
            'best_streak'    => 2,
        ]);

        $habits = \App\Models\Habit::where('user_phone', $this->testPhone)->orderBy('name')->get();
        $result = $this->callHandleCompareWeek($agent, $context, $habits);

        $this->assertEquals('habit_compare_week', $result->metadata['action']);
        $this->assertArrayHasKey('cur_rate', $result->metadata);
        $this->assertArrayHasKey('prev_rate', $result->metadata);
        $this->assertStringContainsString('Comparaison', $result->reply);
        $this->assertStringContainsString('Meditation', $result->reply);
    }

    public function test_compare_week_shows_global_trend(): void
    {
        $agent   = new HabitAgent();
        $context = $this->makeContext('comparer semaine');
        $habit   = $this->createHabit($context, 'Sport', 'daily');

        $habits = \App\Models\Habit::where('user_phone', $this->testPhone)->orderBy('name')->get();
        $result = $this->callHandleCompareWeek($agent, $context, $habits);

        $this->assertStringContainsString('Global', $result->reply);
        $this->assertIsInt($result->metadata['cur_rate']);
        $this->assertIsInt($result->metadata['prev_rate']);
    }

    // ── Top Habits ───────────────────────────────────────────────────────────

    public function test_top_habits_shows_empty_when_no_habits(): void
    {
        $agent   = new HabitAgent();
        $context = $this->makeContext('top habitudes');
        $habits  = collect();

        $result = $this->callHandleTopHabits($agent, $context, $habits);

        $this->assertEquals('habit_top_habits_empty', $result->metadata['action']);
    }

    public function test_top_habits_shows_podium(): void
    {
        $agent   = new HabitAgent();
        $context = $this->makeContext('top habitudes');
        $habit1  = $this->createHabit($context, 'Meditation', 'daily');
        $habit2  = $this->createHabit($context, 'Sport', 'daily');

        // Meditation: 20/30 days logged
        for ($i = 0; $i < 20; $i++) {
            HabitLog::create([
                'habit_id'       => $habit1->id,
                'completed_date' => now()->subDays($i)->toDateString(),
                'streak_count'   => $i + 1,
                'best_streak'    => 20,
            ]);
        }
        // Sport: 5/30 days logged
        for ($i = 0; $i < 5; $i++) {
            HabitLog::create([
                'habit_id'       => $habit2->id,
                'completed_date' => now()->subDays($i)->toDateString(),
                'streak_count'   => $i + 1,
                'best_streak'    => 5,
            ]);
        }

        $habits = \App\Models\Habit::where('user_phone', $this->testPhone)->orderBy('name')->get();
        $result = $this->callHandleTopHabits($agent, $context, $habits);

        $this->assertEquals('habit_top_habits', $result->metadata['action']);
        $this->assertArrayHasKey('top_rate', $result->metadata);
        $this->assertStringContainsString('Top habitudes', $result->reply);
        $this->assertStringContainsString('1er', $result->reply);
        // Meditation should rank first (higher rate)
        $this->assertStringContainsString('Meditation', $result->reply);
    }

    public function test_top_habits_returns_top_rate(): void
    {
        $agent   = new HabitAgent();
        $context = $this->makeContext('top habitudes');
        $habit   = $this->createHabit($context, 'Lecture', 'daily');

        // 15 logs in last 30 days = 50%
        for ($i = 0; $i < 15; $i++) {
            HabitLog::create([
                'habit_id'       => $habit->id,
                'completed_date' => now()->subDays($i)->toDateString(),
                'streak_count'   => $i + 1,
                'best_streak'    => 15,
            ]);
        }

        $habits = \App\Models\Habit::where('user_phone', $this->testPhone)->orderBy('name')->get();
        $result = $this->callHandleTopHabits($agent, $context, $habits);

        $this->assertEquals(50, $result->metadata['top_rate']);
    }

    public function test_top_habits_shows_others_section_when_more_than_3(): void
    {
        $agent   = new HabitAgent();
        $context = $this->makeContext('top habitudes');
        $this->createHabit($context, 'H1', 'daily');
        $this->createHabit($context, 'H2', 'daily');
        $this->createHabit($context, 'H3', 'daily');
        $this->createHabit($context, 'H4', 'daily');

        $habits = \App\Models\Habit::where('user_phone', $this->testPhone)->orderBy('name')->get();
        $result = $this->callHandleTopHabits($agent, $context, $habits);

        $this->assertStringContainsString('Autres', $result->reply);
    }

    // ── Suggest ──────────────────────────────────────────────────────────────

    public function test_suggest_returns_reply_when_claude_fails(): void
    {
        // With Http::fake returning {success:true}, Claude parsing fails -> fallback error message
        $agent   = new HabitAgent();
        $context = $this->makeContext('suggere-moi des habitudes');
        $habits  = collect();

        $result = $this->callHandleSuggest($agent, $context, $habits);

        $this->assertEquals('reply', $result->action);
        $this->assertNotEmpty($result->reply);
    }

    public function test_suggest_keywords_exist(): void
    {
        $keywords = (new HabitAgent())->keywords();
        $this->assertContains('suggerer habitude', $keywords);
        $this->assertContains('heatmap habitude', $keywords);
    }

    // ── Heatmap ──────────────────────────────────────────────────────────────

    public function test_heatmap_shows_empty_when_no_habits(): void
    {
        $agent   = new HabitAgent();
        $context = $this->makeContext('heatmap');
        $habits  = collect();

        $result = $this->callHandleHeatmap($agent, $context, $habits, ['action' => 'heatmap', 'item' => null]);

        $this->assertEquals('habit_heatmap_empty', $result->metadata['action']);
        $this->assertStringContainsString('aucune habitude', $result->reply);
    }

    public function test_heatmap_shows_28_days_for_one_habit(): void
    {
        $agent   = new HabitAgent();
        $context = $this->makeContext('heatmap meditation');
        $habit   = $this->createHabit($context, 'Meditation', 'daily');

        // Log the last 5 days
        for ($i = 0; $i < 5; $i++) {
            HabitLog::create([
                'habit_id'       => $habit->id,
                'completed_date' => now()->subDays($i)->toDateString(),
                'streak_count'   => $i + 1,
                'best_streak'    => 5,
            ]);
        }

        $habits = Habit::where('user_phone', $this->testPhone)->orderBy('name')->get();
        $result = $this->callHandleHeatmap($agent, $context, $habits, ['action' => 'heatmap', 'item' => 1]);

        $this->assertEquals('habit_heatmap', $result->metadata['action']);
        $this->assertStringContainsString('Heatmap', $result->reply);
        $this->assertStringContainsString('Meditation', $result->reply);
        $this->assertStringContainsString('28j', $result->reply);
        $this->assertStringContainsString('X', $result->reply);
        $this->assertStringContainsString('_', $result->reply);
    }

    public function test_heatmap_shows_all_habits_when_item_null(): void
    {
        $agent = new HabitAgent();
        $context = $this->makeContext('heatmap toutes les habitudes');
        $this->createHabit($context, 'Lecture', 'daily');
        $this->createHabit($context, 'Meditation', 'daily');
        $habits = Habit::where('user_phone', $this->testPhone)->orderBy('name')->get();

        $result = $this->callHandleHeatmap($agent, $context, $habits, ['action' => 'heatmap', 'item' => null]);

        $this->assertEquals('habit_heatmap', $result->metadata['action']);
        $this->assertStringContainsString('Lecture', $result->reply);
        $this->assertStringContainsString('Meditation', $result->reply);
    }

    public function test_heatmap_returns_not_found_for_invalid_item(): void
    {
        $agent   = new HabitAgent();
        $context = $this->makeContext('heatmap habitude 99');
        $this->createHabit($context, 'Meditation', 'daily');
        $habits = Habit::where('user_phone', $this->testPhone)->orderBy('name')->get();

        $result = $this->callHandleHeatmap($agent, $context, $habits, ['action' => 'heatmap', 'item' => 99]);

        $this->assertEquals('habit_heatmap_not_found', $result->metadata['action']);
    }

    public function test_heatmap_limits_to_5_habits(): void
    {
        $agent   = new HabitAgent();
        $context = $this->makeContext('heatmap');

        for ($i = 1; $i <= 6; $i++) {
            $this->createHabit($context, "Habitude {$i}", 'daily');
        }

        $habits = Habit::where('user_phone', $this->testPhone)->orderBy('name')->get();
        $result = $this->callHandleHeatmap($agent, $context, $habits, ['action' => 'heatmap', 'item' => null]);

        $this->assertEquals(5, $result->metadata['count']);
        $this->assertStringContainsString('limite', $result->reply);
    }

    public function test_heatmap_shows_header_with_day_labels(): void
    {
        $agent   = new HabitAgent();
        $context = $this->makeContext('heatmap');
        $this->createHabit($context, 'Meditation', 'daily');
        $habits = Habit::where('user_phone', $this->testPhone)->orderBy('name')->get();

        $result = $this->callHandleHeatmap($agent, $context, $habits, ['action' => 'heatmap', 'item' => 1]);

        $this->assertStringContainsString('L', $result->reply);
        $this->assertStringContainsString('M', $result->reply);
        $this->assertStringContainsString('J', $result->reply);
        $this->assertStringContainsString('V', $result->reply);
        $this->assertStringContainsString('S', $result->reply);
        $this->assertStringContainsString('D', $result->reply);
    }

    // ── Backdate ─────────────────────────────────────────────────────────────

    public function test_backdate_logs_for_yesterday(): void
    {
        $agent   = new HabitAgent();
        $context = $this->makeContext("j'ai fait sport hier");
        $habit   = $this->createHabit($context, 'Sport');
        $habits  = Habit::where('user_phone', $this->testPhone)->orderBy('name')->get();

        $result = $this->callHandleBackdate($agent, $context, $habits, ['action' => 'backdate', 'item' => 1]);

        $this->assertEquals('habit_backdate', $result->metadata['action']);
        $this->assertStringContainsString('Rattrapage', $result->reply);
        $this->assertDatabaseHas('habit_logs', [
            'habit_id'       => $habit->id,
            'completed_date' => now()->subDay()->toDateString(),
        ]);
    }

    public function test_backdate_returns_error_when_already_logged_yesterday(): void
    {
        $agent   = new HabitAgent();
        $context = $this->makeContext("j'ai fait sport hier");
        $habit   = $this->createHabit($context, 'Sport');

        HabitLog::create([
            'habit_id'       => $habit->id,
            'completed_date' => now()->subDay()->toDateString(),
            'streak_count'   => 1,
            'best_streak'    => 1,
        ]);

        $habits = Habit::where('user_phone', $this->testPhone)->orderBy('name')->get();
        $result = $this->callHandleBackdate($agent, $context, $habits, ['action' => 'backdate', 'item' => 1]);

        $this->assertEquals('habit_backdate_already_logged', $result->metadata['action']);
    }

    public function test_backdate_returns_error_when_no_item(): void
    {
        $agent   = new HabitAgent();
        $context = $this->makeContext("j'ai fait hier");
        $habits  = collect();

        $result = $this->callHandleBackdate($agent, $context, $habits, ['action' => 'backdate', 'item' => null]);

        $this->assertEquals('habit_backdate_no_item', $result->metadata['action']);
    }

    public function test_backdate_returns_error_for_invalid_item(): void
    {
        $agent   = new HabitAgent();
        $context = $this->makeContext("j'ai fait sport hier");
        $this->createHabit($context, 'Sport');
        $habits = Habit::where('user_phone', $this->testPhone)->orderBy('name')->get();

        $result = $this->callHandleBackdate($agent, $context, $habits, ['action' => 'backdate', 'item' => 99]);

        $this->assertEquals('habit_backdate_not_found', $result->metadata['action']);
    }

    public function test_backdate_shows_streak_in_reply(): void
    {
        $agent   = new HabitAgent();
        $context = $this->makeContext("j'ai fait sport hier");
        $this->createHabit($context, 'Sport');
        $habits = Habit::where('user_phone', $this->testPhone)->orderBy('name')->get();

        $result = $this->callHandleBackdate($agent, $context, $habits, ['action' => 'backdate', 'item' => 1]);

        $this->assertStringContainsString('Streak', $result->reply);
        $this->assertEquals(1, $result->metadata['streak']);
    }

    // ── Streak Challenge ─────────────────────────────────────────────────────

    public function test_streak_challenge_sets_target(): void
    {
        $agent   = new HabitAgent();
        $context = $this->makeContext('defi 30 jours sur meditation');
        $this->createHabit($context, 'Meditation');
        $habits = Habit::where('user_phone', $this->testPhone)->orderBy('name')->get();

        $result = $this->callHandleStreakChallenge($agent, $context, $habits, [
            'action' => 'streak_challenge',
            'item'   => 1,
            'target' => 30,
        ]);

        $this->assertEquals('habit_challenge_set', $result->metadata['action']);
        $this->assertEquals(30, $result->metadata['target']);
        $this->assertStringContainsString('30', $result->reply);
    }

    public function test_streak_challenge_view_shows_progress(): void
    {
        $agent   = new HabitAgent();
        $context = $this->makeContext('mon defi streak meditation');
        $habit   = $this->createHabit($context, 'Meditation');

        \App\Models\AppSetting::set('habit_challenge_' . $habit->id, '30');

        $habits = Habit::where('user_phone', $this->testPhone)->orderBy('name')->get();
        $result = $this->callHandleStreakChallenge($agent, $context, $habits, [
            'action' => 'streak_challenge',
            'item'   => 1,
            'target' => null,
        ]);

        $this->assertEquals('habit_challenge_view', $result->metadata['action']);
        $this->assertStringContainsString('30', $result->reply);
    }

    public function test_streak_challenge_view_shows_no_challenge_message(): void
    {
        $agent   = new HabitAgent();
        $context = $this->makeContext('mon defi streak meditation');
        $this->createHabit($context, 'Meditation');
        $habits = Habit::where('user_phone', $this->testPhone)->orderBy('name')->get();

        $result = $this->callHandleStreakChallenge($agent, $context, $habits, [
            'action' => 'streak_challenge',
            'item'   => 1,
            'target' => null,
        ]);

        $this->assertEquals('habit_challenge_view', $result->metadata['action']);
        $this->assertStringContainsString('Aucun defi', $result->reply);
    }

    public function test_streak_challenge_zero_removes_challenge(): void
    {
        $agent   = new HabitAgent();
        $context = $this->makeContext('supprimer mon defi sport');
        $habit   = $this->createHabit($context, 'Sport');

        \App\Models\AppSetting::set('habit_challenge_' . $habit->id, '30');

        $habits = Habit::where('user_phone', $this->testPhone)->orderBy('name')->get();
        $result = $this->callHandleStreakChallenge($agent, $context, $habits, [
            'action' => 'streak_challenge',
            'item'   => 1,
            'target' => 0,
        ]);

        $this->assertEquals('habit_challenge_removed', $result->metadata['action']);
    }

    public function test_streak_challenge_shows_accomplished_when_streak_meets_target(): void
    {
        $agent   = new HabitAgent();
        $context = $this->makeContext('mon defi streak meditation');
        $habit   = $this->createHabit($context, 'Meditation');

        // Log 5 consecutive days
        for ($i = 0; $i < 5; $i++) {
            HabitLog::create([
                'habit_id'       => $habit->id,
                'completed_date' => now()->subDays($i)->toDateString(),
                'streak_count'   => $i + 1,
                'best_streak'    => 5,
            ]);
        }

        // Set challenge at 5 (already met)
        \App\Models\AppSetting::set('habit_challenge_' . $habit->id, '5');

        $habits = Habit::where('user_phone', $this->testPhone)->orderBy('name')->get();
        $result = $this->callHandleStreakChallenge($agent, $context, $habits, [
            'action' => 'streak_challenge',
            'item'   => 1,
            'target' => null,
        ]);

        $this->assertStringContainsString('ACCOMPLI', $result->reply);
    }

    public function test_streak_challenge_returns_error_for_no_item(): void
    {
        $agent   = new HabitAgent();
        $context = $this->makeContext('defi streak');
        $habits  = collect();

        $result = $this->callHandleStreakChallenge($agent, $context, $habits, [
            'action' => 'streak_challenge',
            'item'   => null,
            'target' => 30,
        ]);

        $this->assertEquals('habit_challenge_no_item', $result->metadata['action']);
    }

    public function test_log_shows_challenge_progress_when_challenge_set(): void
    {
        $agent   = new HabitAgent();
        $context = $this->makeContext("j'ai medite");
        $habit   = $this->createHabit($context, 'Meditation');

        \App\Models\AppSetting::set('habit_challenge_' . $habit->id, '30');

        $habits = Habit::where('user_phone', $this->testPhone)->orderBy('name')->get();
        $result = $this->callHandleLog($agent, $context, $habits, ['action' => 'log', 'item' => 1]);

        $this->assertStringContainsString('Defi', $result->reply);
    }

    public function test_keywords_include_hier(): void
    {
        $this->assertContains('hier', (new HabitAgent())->keywords());
    }

    public function test_keywords_include_defi_streak(): void
    {
        $this->assertContains('defi streak', (new HabitAgent())->keywords());
    }

    // ── Keywords ─────────────────────────────────────────────────────────────

    public function test_keywords_include_rapport_mensuel(): void
    {
        $this->assertContains('rapport mensuel', (new HabitAgent())->keywords());
    }

    public function test_keywords_include_meilleur_jour(): void
    {
        $this->assertContains('meilleur jour', (new HabitAgent())->keywords());
    }

    // ── Calculate Streak ─────────────────────────────────────────────────────

    public function test_calculate_streak_returns_0_when_no_logs(): void
    {
        $agent   = new HabitAgent();
        $context = $this->makeContext('streak');
        $habit   = $this->createHabit($context, 'Meditation');

        $streak = $agent->calculateStreak($habit->id, 'daily');

        $this->assertEquals(0, $streak);
    }

    public function test_calculate_streak_returns_1_for_today_log(): void
    {
        $agent   = new HabitAgent();
        $context = $this->makeContext('streak');
        $habit   = $this->createHabit($context, 'Meditation');

        HabitLog::create([
            'habit_id'       => $habit->id,
            'completed_date' => now()->toDateString(),
            'streak_count'   => 1,
            'best_streak'    => 1,
        ]);

        Cache::forget("habit_streak:{$habit->id}");
        $streak = $agent->calculateStreak($habit->id, 'daily');

        $this->assertEquals(1, $streak);
    }

    public function test_calculate_streak_counts_consecutive_days(): void
    {
        $agent   = new HabitAgent();
        $context = $this->makeContext('streak');
        $habit   = $this->createHabit($context, 'Meditation');

        for ($i = 0; $i <= 4; $i++) {
            HabitLog::create([
                'habit_id'       => $habit->id,
                'completed_date' => now()->subDays($i)->toDateString(),
                'streak_count'   => 5 - $i,
                'best_streak'    => 5,
            ]);
        }

        Cache::forget("habit_streak:{$habit->id}");
        $streak = $agent->calculateStreak($habit->id, 'daily');

        $this->assertEquals(5, $streak);
    }

    public function test_calculate_streak_breaks_on_gap(): void
    {
        $agent   = new HabitAgent();
        $context = $this->makeContext('streak');
        $habit   = $this->createHabit($context, 'Meditation');

        // Log today and 3 days ago (gap on day 1 and 2)
        HabitLog::create([
            'habit_id'       => $habit->id,
            'completed_date' => now()->toDateString(),
            'streak_count'   => 1,
            'best_streak'    => 1,
        ]);
        HabitLog::create([
            'habit_id'       => $habit->id,
            'completed_date' => now()->subDays(3)->toDateString(),
            'streak_count'   => 1,
            'best_streak'    => 1,
        ]);

        Cache::forget("habit_streak:{$habit->id}");
        $streak = $agent->calculateStreak($habit->id, 'daily');

        $this->assertEquals(1, $streak);
    }

    // ── Mini Bar ─────────────────────────────────────────────────────────────

    public function test_build_mini_bar_is_empty_for_zero_done(): void
    {
        $agent = new HabitAgent();
        $bar   = $this->callBuildMiniBar($agent, 0, 5);
        $this->assertEquals('-----', $bar);
    }

    public function test_build_mini_bar_is_full_when_complete(): void
    {
        $agent = new HabitAgent();
        $bar   = $this->callBuildMiniBar($agent, 5, 5);
        $this->assertEquals('#####', $bar);
    }

    public function test_build_mini_bar_handles_zero_total(): void
    {
        $agent = new HabitAgent();
        $bar   = $this->callBuildMiniBar($agent, 0, 0);
        $this->assertEquals('-----', $bar);
    }

    public function test_build_mini_bar_partial(): void
    {
        $agent = new HabitAgent();
        $bar   = $this->callBuildMiniBar($agent, 2, 4);
        // 2/4 = 50% = 3 filled out of 5 (rounded)
        $this->assertStringContainsString('#', $bar);
        $this->assertStringContainsString('-', $bar);
        $this->assertEquals(5, strlen($bar));
    }

    // ── Help ─────────────────────────────────────────────────────────────────

    public function test_help_shows_all_commands(): void
    {
        $agent   = new HabitAgent();
        $context = $this->makeContext('aide habitudes');

        $result = $this->callHandleHelp($agent, $context);

        $this->assertEquals('habit_help', $result->metadata['action']);
        $this->assertStringContainsString('AJOUTER', $result->reply);
        $this->assertStringContainsString('COCHER', $result->reply);
        $this->assertStringContainsString('VOIR', $result->reply);
        $this->assertStringContainsString('GERER', $result->reply);
        $this->assertStringContainsString('Classement streaks', $result->reply);
        $this->assertStringContainsString('Rapport semaine', $result->reply);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function makeContext(string $body, string $routedAgent = 'habit'): AgentContext
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
            agent:       $agentDb,
            session:     $session,
            from:        $this->testPhone,
            senderName:  'Test User',
            body:        $body,
            hasMedia:    false,
            mediaUrl:    null,
            mimetype:    null,
            media:       null,
            routedAgent: $routedAgent,
        );
    }

    private function createHabit(AgentContext $context, string $name, string $frequency = 'daily'): Habit
    {
        return Habit::create([
            'agent_id'       => $context->agent->id,
            'user_phone'     => $this->testPhone,
            'requester_name' => 'Test User',
            'name'           => $name,
            'frequency'      => $frequency,
        ]);
    }

    // ── Private method callers via Reflection ──────────────────────────────

    private function callHandleAdd(HabitAgent $agent, AgentContext $context, array $parsed): \App\Services\Agents\AgentResult
    {
        $method = new \ReflectionMethod($agent, 'handleAdd');
        $method->setAccessible(true);
        return $method->invoke($agent, $context, $parsed);
    }

    private function callHandleLog(HabitAgent $agent, AgentContext $context, $habits, array $parsed): \App\Services\Agents\AgentResult
    {
        $method = new \ReflectionMethod($agent, 'handleLog');
        $method->setAccessible(true);
        return $method->invoke($agent, $context, $habits, $parsed);
    }

    private function callHandleUnlog(HabitAgent $agent, AgentContext $context, $habits, array $parsed): \App\Services\Agents\AgentResult
    {
        $method = new \ReflectionMethod($agent, 'handleUnlog');
        $method->setAccessible(true);
        return $method->invoke($agent, $context, $habits, $parsed);
    }

    private function callHandleList(HabitAgent $agent, AgentContext $context, $habits): \App\Services\Agents\AgentResult
    {
        $method = new \ReflectionMethod($agent, 'handleList');
        $method->setAccessible(true);
        return $method->invoke($agent, $context, $habits);
    }

    private function callHandleToday(HabitAgent $agent, AgentContext $context, $habits): \App\Services\Agents\AgentResult
    {
        $method = new \ReflectionMethod($agent, 'handleToday');
        $method->setAccessible(true);
        return $method->invoke($agent, $context, $habits);
    }

    private function callHandleStats(HabitAgent $agent, AgentContext $context, $habits): \App\Services\Agents\AgentResult
    {
        $method = new \ReflectionMethod($agent, 'handleStats');
        $method->setAccessible(true);
        return $method->invoke($agent, $context, $habits);
    }

    private function callHandleDelete(HabitAgent $agent, AgentContext $context, $habits, array $parsed): \App\Services\Agents\AgentResult
    {
        $method = new \ReflectionMethod($agent, 'handleDelete');
        $method->setAccessible(true);
        return $method->invoke($agent, $context, $habits, $parsed);
    }

    private function callHandleReset(HabitAgent $agent, AgentContext $context, $habits, array $parsed): \App\Services\Agents\AgentResult
    {
        $method = new \ReflectionMethod($agent, 'handleReset');
        $method->setAccessible(true);
        return $method->invoke($agent, $context, $habits, $parsed);
    }

    private function callHandleRename(HabitAgent $agent, AgentContext $context, $habits, array $parsed): \App\Services\Agents\AgentResult
    {
        $method = new \ReflectionMethod($agent, 'handleRename');
        $method->setAccessible(true);
        return $method->invoke($agent, $context, $habits, $parsed);
    }

    private function callHandleChangeFrequency(HabitAgent $agent, AgentContext $context, $habits, array $parsed): \App\Services\Agents\AgentResult
    {
        $method = new \ReflectionMethod($agent, 'handleChangeFrequency');
        $method->setAccessible(true);
        return $method->invoke($agent, $context, $habits, $parsed);
    }

    private function callHandleHistory(HabitAgent $agent, AgentContext $context, $habits, array $parsed): \App\Services\Agents\AgentResult
    {
        $method = new \ReflectionMethod($agent, 'handleHistory');
        $method->setAccessible(true);
        return $method->invoke($agent, $context, $habits, $parsed);
    }

    private function callHandleMotivate(HabitAgent $agent, AgentContext $context, $habits): \App\Services\Agents\AgentResult
    {
        $method = new \ReflectionMethod($agent, 'handleMotivate');
        $method->setAccessible(true);
        return $method->invoke($agent, $context, $habits);
    }

    private function callHandleStreakBoard(HabitAgent $agent, AgentContext $context, $habits): \App\Services\Agents\AgentResult
    {
        $method = new \ReflectionMethod($agent, 'handleStreakBoard');
        $method->setAccessible(true);
        return $method->invoke($agent, $context, $habits);
    }

    private function callHandleWeeklyReport(HabitAgent $agent, AgentContext $context, $habits): \App\Services\Agents\AgentResult
    {
        $method = new \ReflectionMethod($agent, 'handleWeeklyReport');
        $method->setAccessible(true);
        return $method->invoke($agent, $context, $habits);
    }

    private function callBuildMiniBar(HabitAgent $agent, int $done, int $total): string
    {
        $method = new \ReflectionMethod($agent, 'buildMiniBar');
        $method->setAccessible(true);
        return $method->invoke($agent, $done, $total);
    }

    private function callHandleHelp(HabitAgent $agent, AgentContext $context): \App\Services\Agents\AgentResult
    {
        $method = new \ReflectionMethod($agent, 'handleHelp');
        $method->setAccessible(true);
        return $method->invoke($agent, $context);
    }

    private function callHandleMonthlyReport(HabitAgent $agent, AgentContext $context, $habits): \App\Services\Agents\AgentResult
    {
        $method = new \ReflectionMethod($agent, 'handleMonthlyReport');
        $method->setAccessible(true);
        return $method->invoke($agent, $context, $habits);
    }

    private function callHandleBestDay(HabitAgent $agent, AgentContext $context, $habits): \App\Services\Agents\AgentResult
    {
        $method = new \ReflectionMethod($agent, 'handleBestDay');
        $method->setAccessible(true);
        return $method->invoke($agent, $context, $habits);
    }

    private function callHandleLogMultiple(HabitAgent $agent, AgentContext $context, $habits, array $parsed): \App\Services\Agents\AgentResult
    {
        $method = new \ReflectionMethod($agent, 'handleLogMultiple');
        $method->setAccessible(true);
        return $method->invoke($agent, $context, $habits, $parsed);
    }

    private function callHandlePause(HabitAgent $agent, AgentContext $context, $habits, array $parsed): \App\Services\Agents\AgentResult
    {
        $method = new \ReflectionMethod($agent, 'handlePause');
        $method->setAccessible(true);
        return $method->invoke($agent, $context, $habits, $parsed);
    }

    private function callHandleResume(HabitAgent $agent, AgentContext $context, $habits, array $parsed): \App\Services\Agents\AgentResult
    {
        $method = new \ReflectionMethod($agent, 'handleResume');
        $method->setAccessible(true);
        return $method->invoke($agent, $context, $habits, $parsed);
    }

    private function callHandleSetGoal(HabitAgent $agent, AgentContext $context, array $parsed): \App\Services\Agents\AgentResult
    {
        $method = new \ReflectionMethod($agent, 'handleSetGoal');
        $method->setAccessible(true);
        return $method->invoke($agent, $context, $parsed);
    }

    private function callHandlePerfectStreak(HabitAgent $agent, AgentContext $context, $habits): \App\Services\Agents\AgentResult
    {
        $method = new \ReflectionMethod($agent, 'handlePerfectStreak');
        $method->setAccessible(true);
        return $method->invoke($agent, $context, $habits);
    }

    private function callHandleCompareWeek(HabitAgent $agent, AgentContext $context, $habits): \App\Services\Agents\AgentResult
    {
        $method = new \ReflectionMethod($agent, 'handleCompareWeek');
        $method->setAccessible(true);
        return $method->invoke($agent, $context, $habits);
    }

    private function callHandleTopHabits(HabitAgent $agent, AgentContext $context, $habits): \App\Services\Agents\AgentResult
    {
        $method = new \ReflectionMethod($agent, 'handleTopHabits');
        $method->setAccessible(true);
        return $method->invoke($agent, $context, $habits);
    }

    private function callHandleSuggest(HabitAgent $agent, AgentContext $context, $habits): \App\Services\Agents\AgentResult
    {
        $method = new \ReflectionMethod($agent, 'handleSuggest');
        $method->setAccessible(true);
        return $method->invoke($agent, $context, $habits);
    }

    private function callHandleHeatmap(HabitAgent $agent, AgentContext $context, $habits, array $parsed): \App\Services\Agents\AgentResult
    {
        $method = new \ReflectionMethod($agent, 'handleHeatmap');
        $method->setAccessible(true);
        return $method->invoke($agent, $context, $habits, $parsed);
    }

    private function callHandleBackdate(HabitAgent $agent, AgentContext $context, $habits, array $parsed): \App\Services\Agents\AgentResult
    {
        $method = new \ReflectionMethod($agent, 'handleBackdate');
        $method->setAccessible(true);
        return $method->invoke($agent, $context, $habits, $parsed);
    }

    private function callHandleStreakChallenge(HabitAgent $agent, AgentContext $context, $habits, array $parsed): \App\Services\Agents\AgentResult
    {
        $method = new \ReflectionMethod($agent, 'handleStreakChallenge');
        $method->setAccessible(true);
        return $method->invoke($agent, $context, $habits, $parsed);
    }
}
