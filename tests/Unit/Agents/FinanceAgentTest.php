<?php

namespace Tests\Unit\Agents;

use App\Models\Agent;
use App\Models\AgentSession;
use App\Models\Budget;
use App\Models\Expense;
use App\Models\User;
use App\Services\AgentContext;
use App\Services\Agents\FinanceAgent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class FinanceAgentTest extends TestCase
{
    use RefreshDatabase;

    private string $testPhone = '33612345678@s.whatsapp.net';

    // ── Agent basics ──────────────────────────────────────────────────────────

    public function test_agent_returns_correct_name(): void
    {
        $this->assertEquals('finance', (new FinanceAgent())->name());
    }

    public function test_agent_version_is_1_6_0(): void
    {
        $this->assertEquals('1.6.0', (new FinanceAgent())->version());
    }

    public function test_agent_has_description(): void
    {
        $this->assertNotEmpty((new FinanceAgent())->description());
    }

    public function test_keywords_include_finance(): void
    {
        $this->assertContains('finance', (new FinanceAgent())->keywords());
    }

    public function test_keywords_include_budget(): void
    {
        $this->assertContains('budget', (new FinanceAgent())->keywords());
    }

    public function test_keywords_include_projection(): void
    {
        $this->assertContains('projection', (new FinanceAgent())->keywords());
    }

    public function test_keywords_include_detail(): void
    {
        $this->assertContains('detail', (new FinanceAgent())->keywords());
    }

    public function test_keywords_include_supprimer_budget(): void
    {
        $this->assertContains('supprimer budget', (new FinanceAgent())->keywords());
    }

    // ── canHandle ─────────────────────────────────────────────────────────────

    public function test_can_handle_budget_message(): void
    {
        $agent = new FinanceAgent();
        $this->assertTrue($agent->canHandle($this->makeContext('budget alimentation 300')));
    }

    public function test_can_handle_depense_message(): void
    {
        $agent = new FinanceAgent();
        $this->assertTrue($agent->canHandle($this->makeContext('depense 45 alimentation courses')));
    }

    public function test_can_handle_historique(): void
    {
        $agent = new FinanceAgent();
        $this->assertTrue($agent->canHandle($this->makeContext('historique')));
    }

    public function test_can_handle_projection(): void
    {
        $agent = new FinanceAgent();
        $this->assertTrue($agent->canHandle($this->makeContext('projection fin de mois')));
    }

    public function test_can_handle_detail(): void
    {
        $agent = new FinanceAgent();
        $this->assertTrue($agent->canHandle($this->makeContext('detail alimentation')));
    }

    public function test_cannot_handle_empty_body(): void
    {
        $agent = new FinanceAgent();
        $this->assertFalse($agent->canHandle($this->makeContext('')));
    }

    public function test_cannot_handle_null_body(): void
    {
        $agent = new FinanceAgent();
        $this->assertFalse($agent->canHandle($this->makeContext(null)));
    }

    // ── parseCommand (via reflection) ────────────────────────────────────────

    private function parseCommand(string $body): ?array
    {
        $agent      = new FinanceAgent();
        $reflection = new \ReflectionClass($agent);
        $method     = $reflection->getMethod('parseCommand');
        $method->setAccessible(true);
        return $method->invoke($agent, $body);
    }

    public function test_parse_command_log_expense(): void
    {
        $cmd = $this->parseCommand('depense 45.50 alimentation courses du weekend');
        $this->assertNotNull($cmd);
        $this->assertEquals('log_expense', $cmd['action']);
        $this->assertEquals(45.50, $cmd['amount']);
        $this->assertEquals('alimentation', $cmd['category']);
        $this->assertEquals('courses du weekend', $cmd['description']);
    }

    public function test_parse_command_log_expense_no_description(): void
    {
        $cmd = $this->parseCommand('depense 12 transport');
        $this->assertNotNull($cmd);
        $this->assertEquals('log_expense', $cmd['action']);
        $this->assertEquals(12.0, $cmd['amount']);
        $this->assertNull($cmd['description']);
    }

    public function test_parse_command_log_expense_with_comma(): void
    {
        $cmd = $this->parseCommand('depense 87,50 alimentation supermarche');
        $this->assertNotNull($cmd);
        $this->assertEquals('log_expense', $cmd['action']);
        $this->assertEquals(87.50, $cmd['amount']);
    }

    public function test_parse_command_set_budget(): void
    {
        $cmd = $this->parseCommand('budget alimentation 300');
        $this->assertNotNull($cmd);
        $this->assertEquals('set_budget', $cmd['action']);
        $this->assertEquals('alimentation', $cmd['category']);
        $this->assertEquals(300.0, $cmd['amount']);
    }

    public function test_parse_command_delete_budget(): void
    {
        $cmd = $this->parseCommand('supprimer budget transport');
        $this->assertNotNull($cmd);
        $this->assertEquals('delete_budget', $cmd['action']);
        $this->assertEquals('transport', $cmd['category']);
    }

    public function test_parse_command_delete_budget_variant(): void
    {
        $cmd = $this->parseCommand('annuler budget alimentation');
        $this->assertNotNull($cmd);
        $this->assertEquals('delete_budget', $cmd['action']);
        $this->assertEquals('alimentation', $cmd['category']);
    }

    public function test_parse_command_balance(): void
    {
        $cmd = $this->parseCommand('solde');
        $this->assertNotNull($cmd);
        $this->assertEquals('balance', $cmd['action']);
    }

    public function test_parse_command_stats(): void
    {
        $cmd = $this->parseCommand('stats');
        $this->assertNotNull($cmd);
        $this->assertEquals('stats', $cmd['action']);
    }

    public function test_parse_command_alerts(): void
    {
        $cmd = $this->parseCommand('alertes');
        $this->assertNotNull($cmd);
        $this->assertEquals('alerts', $cmd['action']);
    }

    public function test_parse_command_history_default_limit(): void
    {
        $cmd = $this->parseCommand('historique');
        $this->assertNotNull($cmd);
        $this->assertEquals('history', $cmd['action']);
        $this->assertEquals(10, $cmd['limit']);
    }

    public function test_parse_command_history_with_limit(): void
    {
        $cmd = $this->parseCommand('historique 5');
        $this->assertNotNull($cmd);
        $this->assertEquals('history', $cmd['action']);
        $this->assertEquals(5, $cmd['limit']);
    }

    public function test_parse_command_history_limit_capped_at_20(): void
    {
        $cmd = $this->parseCommand('historique 50');
        $this->assertNotNull($cmd);
        $this->assertEquals('history', $cmd['action']);
        $this->assertEquals(20, $cmd['limit']);
    }

    public function test_parse_command_history_does_not_grab_year(): void
    {
        // "historique mars 2026" should use default limit 10, not 20 (from 2026 capped)
        $cmd = $this->parseCommand('historique mars 2026');
        $this->assertNotNull($cmd);
        $this->assertEquals('history', $cmd['action']);
        $this->assertEquals(10, $cmd['limit']); // default, year not captured
    }

    public function test_parse_command_delete_last(): void
    {
        $cmd = $this->parseCommand('supprimer derniere depense');
        $this->assertNotNull($cmd);
        $this->assertEquals('delete_last', $cmd['action']);
    }

    public function test_parse_command_help(): void
    {
        $cmd = $this->parseCommand('aide finance');
        $this->assertNotNull($cmd);
        $this->assertEquals('help', $cmd['action']);
    }

    public function test_parse_command_projection(): void
    {
        $cmd = $this->parseCommand('projection');
        $this->assertNotNull($cmd);
        $this->assertEquals('projection', $cmd['action']);
    }

    public function test_parse_command_prevision(): void
    {
        $cmd = $this->parseCommand('prevision fin de mois');
        $this->assertNotNull($cmd);
        $this->assertEquals('projection', $cmd['action']);
    }

    public function test_parse_command_category_detail(): void
    {
        $cmd = $this->parseCommand('detail alimentation');
        $this->assertNotNull($cmd);
        $this->assertEquals('category_detail', $cmd['action']);
        $this->assertEquals('alimentation', $cmd['category']);
    }

    public function test_parse_command_unknown_returns_null(): void
    {
        $this->assertNull($this->parseCommand('bonjour comment tu vas'));
    }

    // ── logExpense (via reflection) ───────────────────────────────────────────

    private function callLogExpense(float $amount, string $category, ?string $description = null): string
    {
        $agent      = new FinanceAgent();
        $reflection = new \ReflectionClass($agent);
        $method     = $reflection->getMethod('logExpense');
        $method->setAccessible(true);
        return $method->invoke($agent, $this->testPhone, $amount, $category, $description);
    }

    public function test_log_expense_creates_record(): void
    {
        $this->callLogExpense(45.0, 'alimentation', 'courses');
        $this->assertDatabaseHas('finances_expenses', [
            'user_phone' => $this->testPhone,
            'amount'     => 45.0,
            'category'   => 'alimentation',
        ]);
    }

    public function test_log_expense_returns_confirmation(): void
    {
        $result = $this->callLogExpense(12.5, 'transport', 'taxi');
        $this->assertStringContainsString('✅', $result);
        $this->assertStringContainsString('12', $result);
        $this->assertStringContainsString('transport', $result);
    }

    public function test_log_expense_rejects_zero_amount(): void
    {
        $result = $this->callLogExpense(0, 'alimentation');
        $this->assertStringContainsString('❌', $result);
    }

    public function test_log_expense_rejects_negative_amount(): void
    {
        $result = $this->callLogExpense(-10, 'transport');
        $this->assertStringContainsString('❌', $result);
    }

    public function test_log_expense_rejects_excessive_amount(): void
    {
        $result = $this->callLogExpense(200000, 'logement');
        $this->assertStringContainsString('❌', $result);
    }

    public function test_log_expense_shows_budget_warning_when_exceeded(): void
    {
        // Create a tight budget
        Budget::create([
            'user_phone'    => $this->testPhone,
            'category'      => 'alimentation',
            'monthly_limit' => 10.0,
        ]);

        $result = $this->callLogExpense(15.0, 'alimentation', 'depasse');
        $this->assertStringContainsString('🚨', $result);
    }

    // ── setBudget (via reflection) ────────────────────────────────────────────

    private function callSetBudget(string $category, float $amount): string
    {
        $agent      = new FinanceAgent();
        $reflection = new \ReflectionClass($agent);
        $method     = $reflection->getMethod('setBudget');
        $method->setAccessible(true);
        return $method->invoke($agent, $this->testPhone, $category, $amount);
    }

    public function test_set_budget_creates_record(): void
    {
        $this->callSetBudget('alimentation', 300.0);
        $this->assertDatabaseHas('finances_budgets', [
            'user_phone'    => $this->testPhone,
            'category'      => 'alimentation',
            'monthly_limit' => 300.0,
        ]);
    }

    public function test_set_budget_returns_confirmation(): void
    {
        $result = $this->callSetBudget('transport', 150.0);
        $this->assertStringContainsString('✅', $result);
        $this->assertStringContainsString('150', $result);
        $this->assertStringContainsString('transport', $result);
    }

    public function test_set_budget_rejects_zero_amount(): void
    {
        $result = $this->callSetBudget('alimentation', 0.0);
        $this->assertStringContainsString('❌', $result);
    }

    public function test_set_budget_creates_alert(): void
    {
        $this->callSetBudget('loisirs', 200.0);
        $this->assertDatabaseHas('finances_alerts', [
            'user_phone'           => $this->testPhone,
            'category'             => 'loisirs',
            'threshold_percentage' => 80,
        ]);
    }

    // ── deleteBudget (via reflection) ─────────────────────────────────────────

    private function callDeleteBudget(string $category): string
    {
        $agent      = new FinanceAgent();
        $reflection = new \ReflectionClass($agent);
        $method     = $reflection->getMethod('deleteBudget');
        $method->setAccessible(true);
        return $method->invoke($agent, $this->testPhone, $category);
    }

    public function test_delete_budget_removes_existing_budget(): void
    {
        Budget::create([
            'user_phone'    => $this->testPhone,
            'category'      => 'transport',
            'monthly_limit' => 150.0,
        ]);

        $result = $this->callDeleteBudget('transport');

        $this->assertStringContainsString('🗑️', $result);
        $this->assertDatabaseMissing('finances_budgets', [
            'user_phone' => $this->testPhone,
            'category'   => 'transport',
        ]);
    }

    public function test_delete_budget_returns_error_when_not_found(): void
    {
        $result = $this->callDeleteBudget('inexistant');
        $this->assertStringContainsString('❌', $result);
    }

    public function test_delete_budget_removes_associated_alert(): void
    {
        Budget::create([
            'user_phone'    => $this->testPhone,
            'category'      => 'loisirs',
            'monthly_limit' => 200.0,
        ]);
        \Illuminate\Support\Facades\DB::table('finances_alerts')->insert([
            'user_phone'           => $this->testPhone,
            'category'             => 'loisirs',
            'threshold_percentage' => 80,
            'enabled'              => true,
            'created_at'           => now(),
            'updated_at'           => now(),
        ]);

        $this->callDeleteBudget('loisirs');

        $this->assertDatabaseMissing('finances_alerts', [
            'user_phone' => $this->testPhone,
            'category'   => 'loisirs',
        ]);
    }

    // ── getHistory (via reflection) ───────────────────────────────────────────

    private function callGetHistory(int $limit = 10): string
    {
        $agent      = new FinanceAgent();
        $reflection = new \ReflectionClass($agent);
        $method     = $reflection->getMethod('getHistory');
        $method->setAccessible(true);
        return $method->invoke($agent, $this->testPhone, $limit);
    }

    public function test_get_history_empty_message(): void
    {
        $result = $this->callGetHistory();
        $this->assertStringContainsString('Aucune depense', $result);
    }

    public function test_get_history_shows_expenses(): void
    {
        Expense::create([
            'user_phone'  => $this->testPhone,
            'amount'      => 55.0,
            'category'    => 'alimentation',
            'description' => 'supermarche',
            'date'        => Carbon::today()->toDateString(),
        ]);

        $result = $this->callGetHistory();
        $this->assertStringContainsString('55', $result);
        $this->assertStringContainsString('alimentation', $result);
    }

    // ── getCategoryDetail (via reflection) ───────────────────────────────────

    private function callGetCategoryDetail(string $category): string
    {
        $agent      = new FinanceAgent();
        $reflection = new \ReflectionClass($agent);
        $method     = $reflection->getMethod('getCategoryDetail');
        $method->setAccessible(true);
        return $method->invoke($agent, $this->testPhone, $category);
    }

    public function test_category_detail_no_expenses(): void
    {
        $result = $this->callGetCategoryDetail('alimentation');
        $this->assertStringContainsString('alimentation', $result);
        $this->assertStringContainsString('Aucune depense', $result);
    }

    public function test_category_detail_with_expenses(): void
    {
        Expense::create([
            'user_phone'  => $this->testPhone,
            'amount'      => 45.0,
            'category'    => 'alimentation',
            'description' => 'courses',
            'date'        => Carbon::today()->toDateString(),
        ]);

        $result = $this->callGetCategoryDetail('alimentation');
        $this->assertStringContainsString('45', $result);
        $this->assertStringContainsString('alimentation', $result);
    }

    public function test_category_detail_shows_budget_when_set(): void
    {
        Budget::create([
            'user_phone'    => $this->testPhone,
            'category'      => 'transport',
            'monthly_limit' => 150.0,
        ]);
        Expense::create([
            'user_phone' => $this->testPhone,
            'amount'     => 30.0,
            'category'   => 'transport',
            'date'       => Carbon::today()->toDateString(),
        ]);

        $result = $this->callGetCategoryDetail('transport');
        $this->assertStringContainsString('150', $result);
        $this->assertStringContainsString('Budget', $result);
    }

    // ── getProjectionReport (via reflection) ─────────────────────────────────

    private function callGetProjectionReport(): string
    {
        $agent      = new FinanceAgent();
        $reflection = new \ReflectionClass($agent);
        $method     = $reflection->getMethod('getProjectionReport');
        $method->setAccessible(true);
        return $method->invoke($agent, $this->testPhone);
    }

    public function test_projection_report_no_expenses(): void
    {
        $result = $this->callGetProjectionReport();
        $this->assertStringContainsString('🔮', $result);
        $this->assertStringContainsString('Aucune depense', $result);
    }

    public function test_projection_report_with_expenses(): void
    {
        // Insert expense 5 days ago to ensure day >= 3 scenario
        Expense::create([
            'user_phone' => $this->testPhone,
            'amount'     => 100.0,
            'category'   => 'alimentation',
            'date'       => Carbon::now()->startOfMonth()->toDateString(),
        ]);

        $result = $this->callGetProjectionReport();
        $this->assertStringContainsString('🔮', $result);
    }

    // ── getMonthlyProjection (via reflection) ────────────────────────────────

    private function callGetMonthlyProjection(float $spent): ?float
    {
        $agent      = new FinanceAgent();
        $reflection = new \ReflectionClass($agent);
        $method     = $reflection->getMethod('getMonthlyProjection');
        $method->setAccessible(true);
        return $method->invoke($agent, $spent);
    }

    public function test_monthly_projection_returns_null_for_zero_spent(): void
    {
        $this->assertNull($this->callGetMonthlyProjection(0));
    }

    public function test_monthly_projection_returns_float_when_data_available(): void
    {
        // Fake day >= 3 by checking result only when in that context
        Carbon::setTestNow(Carbon::now()->setDay(10));
        $result = $this->callGetMonthlyProjection(200.0);
        $this->assertIsFloat($result);
        $this->assertGreaterThan(200.0, $result);
        Carbon::setTestNow(null);
    }

    // ── buildProgressBar (via reflection) ────────────────────────────────────

    private function callBuildProgressBar(float $percentage): string
    {
        $agent      = new FinanceAgent();
        $reflection = new \ReflectionClass($agent);
        $method     = $reflection->getMethod('buildProgressBar');
        $method->setAccessible(true);
        return $method->invoke($agent, $percentage);
    }

    public function test_progress_bar_empty_at_zero(): void
    {
        $bar = $this->callBuildProgressBar(0);
        $this->assertEquals('[░░░░░░░░░░]', $bar);
    }

    public function test_progress_bar_full_at_100(): void
    {
        $bar = $this->callBuildProgressBar(100);
        $this->assertEquals('[██████████]', $bar);
    }

    public function test_progress_bar_half_at_50(): void
    {
        $bar = $this->callBuildProgressBar(50);
        $this->assertEquals('[█████░░░░░]', $bar);
    }

    public function test_progress_bar_clamped_above_100(): void
    {
        $bar = $this->callBuildProgressBar(150);
        $this->assertEquals('[██████████]', $bar);
    }

    // ── getHelp ───────────────────────────────────────────────────────────────

    public function test_help_contains_detail_command(): void
    {
        $agent      = new FinanceAgent();
        $reflection = new \ReflectionClass($agent);
        $method     = $reflection->getMethod('getHelp');
        $method->setAccessible(true);
        $help = $method->invoke($agent);

        $this->assertStringContainsString('detail', $help);
        $this->assertStringContainsString('projection', $help);
        $this->assertStringContainsString('supprimer budget', $help);
    }

    // ── keywords v1.3.0 ──────────────────────────────────────────────────────

    public function test_keywords_include_semaine(): void
    {
        $this->assertContains('semaine', (new FinanceAgent())->keywords());
    }

    public function test_keywords_include_top_depenses(): void
    {
        $this->assertContains('top depenses', (new FinanceAgent())->keywords());
    }

    // ── canHandle v1.3.0 ─────────────────────────────────────────────────────

    public function test_can_handle_resume_semaine(): void
    {
        $agent = new FinanceAgent();
        $this->assertTrue($agent->canHandle($this->makeContext('resume semaine')));
    }

    public function test_can_handle_cette_semaine(): void
    {
        $agent = new FinanceAgent();
        $this->assertTrue($agent->canHandle($this->makeContext('cette semaine')));
    }

    public function test_can_handle_top_depenses(): void
    {
        $agent = new FinanceAgent();
        $this->assertTrue($agent->canHandle($this->makeContext('top depenses')));
    }

    public function test_can_handle_grosses_depenses(): void
    {
        $agent = new FinanceAgent();
        $this->assertTrue($agent->canHandle($this->makeContext('grosses depenses du mois')));
    }

    // ── parseCommand v1.3.0 ──────────────────────────────────────────────────

    public function test_parse_command_weekly_summary(): void
    {
        $cmd = $this->parseCommand('resume semaine');
        $this->assertNotNull($cmd);
        $this->assertEquals('weekly_summary', $cmd['action']);
    }

    public function test_parse_command_weekly_summary_cette_semaine(): void
    {
        $cmd = $this->parseCommand('cette semaine');
        $this->assertNotNull($cmd);
        $this->assertEquals('weekly_summary', $cmd['action']);
    }

    public function test_parse_command_weekly_summary_hebdo(): void
    {
        $cmd = $this->parseCommand('hebdo');
        $this->assertNotNull($cmd);
        $this->assertEquals('weekly_summary', $cmd['action']);
    }

    public function test_parse_command_top_expenses_default(): void
    {
        $cmd = $this->parseCommand('top depenses');
        $this->assertNotNull($cmd);
        $this->assertEquals('top_expenses', $cmd['action']);
        $this->assertEquals(5, $cmd['limit']);
    }

    public function test_parse_command_top_expenses_with_limit(): void
    {
        $cmd = $this->parseCommand('top 3 depenses');
        $this->assertNotNull($cmd);
        $this->assertEquals('top_expenses', $cmd['action']);
        $this->assertEquals(3, $cmd['limit']);
    }

    public function test_parse_command_grosses_depenses(): void
    {
        $cmd = $this->parseCommand('grosses depenses');
        $this->assertNotNull($cmd);
        $this->assertEquals('top_expenses', $cmd['action']);
    }

    // ── getWeeklySummary (via reflection) ─────────────────────────────────────

    private function callGetWeeklySummary(): string
    {
        $agent      = new FinanceAgent();
        $reflection = new \ReflectionClass($agent);
        $method     = $reflection->getMethod('getWeeklySummary');
        $method->setAccessible(true);
        return $method->invoke($agent, $this->testPhone);
    }

    public function test_weekly_summary_no_expenses(): void
    {
        $result = $this->callGetWeeklySummary();
        $this->assertStringContainsString('📅', $result);
        $this->assertStringContainsString('Aucune depense', $result);
    }

    public function test_weekly_summary_with_expenses(): void
    {
        Expense::create([
            'user_phone' => $this->testPhone,
            'amount'     => 30.0,
            'category'   => 'transport',
            'date'       => Carbon::now()->toDateString(),
        ]);

        $result = $this->callGetWeeklySummary();
        $this->assertStringContainsString('transport', $result);
        $this->assertStringContainsString('30', $result);
    }

    public function test_weekly_summary_shows_daily_average(): void
    {
        Expense::create([
            'user_phone' => $this->testPhone,
            'amount'     => 70.0,
            'category'   => 'alimentation',
            'date'       => Carbon::now()->toDateString(),
        ]);

        $result = $this->callGetWeeklySummary();
        $this->assertStringContainsString('Moyenne', $result);
    }

    // ── getTopExpenses (via reflection) ───────────────────────────────────────

    private function callGetTopExpenses(int $limit = 5): string
    {
        $agent      = new FinanceAgent();
        $reflection = new \ReflectionClass($agent);
        $method     = $reflection->getMethod('getTopExpenses');
        $method->setAccessible(true);
        return $method->invoke($agent, $this->testPhone, $limit);
    }

    public function test_top_expenses_no_expenses(): void
    {
        $result = $this->callGetTopExpenses();
        $this->assertStringContainsString('🏆', $result);
        $this->assertStringContainsString('Aucune depense', $result);
    }

    public function test_top_expenses_shows_sorted_by_amount(): void
    {
        Expense::create([
            'user_phone' => $this->testPhone,
            'amount'     => 10.0,
            'category'   => 'transport',
            'date'       => Carbon::now()->toDateString(),
        ]);
        Expense::create([
            'user_phone' => $this->testPhone,
            'amount'     => 150.0,
            'category'   => 'logement',
            'date'       => Carbon::now()->toDateString(),
        ]);
        Expense::create([
            'user_phone' => $this->testPhone,
            'amount'     => 45.0,
            'category'   => 'alimentation',
            'date'       => Carbon::now()->toDateString(),
        ]);

        $result = $this->callGetTopExpenses(3);
        $this->assertStringContainsString('150', $result);
        // logement (150) should appear before alimentation (45) in output
        $posLogement     = strpos($result, 'logement');
        $posAlimentation = strpos($result, 'alimentation');
        $this->assertLessThan($posAlimentation, $posLogement);
    }

    public function test_top_expenses_shows_percentage_of_total(): void
    {
        Expense::create([
            'user_phone' => $this->testPhone,
            'amount'     => 100.0,
            'category'   => 'alimentation',
            'date'       => Carbon::now()->toDateString(),
        ]);

        $result = $this->callGetTopExpenses(5);
        $this->assertStringContainsString('%', $result);
    }

    public function test_top_expenses_limit_capped_at_10(): void
    {
        for ($i = 1; $i <= 12; $i++) {
            Expense::create([
                'user_phone' => $this->testPhone,
                'amount'     => (float) ($i * 10),
                'category'   => 'test',
                'date'       => Carbon::now()->toDateString(),
            ]);
        }

        $agent      = new FinanceAgent();
        $reflection = new \ReflectionClass($agent);
        $method     = $reflection->getMethod('getTopExpenses');
        $method->setAccessible(true);
        $result = $method->invoke($agent, $this->testPhone, 20); // 20 requested, max is 10

        // Should show at most 10 entries (lines starting with digit.)
        $matches = preg_match_all('/^\d+\./m', $result);
        $this->assertLessThanOrEqual(10, $matches);
    }

    // ── getBalance improvements v1.3.0 ────────────────────────────────────────

    public function test_balance_shows_day_of_month(): void
    {
        $agent      = new FinanceAgent();
        $reflection = new \ReflectionClass($agent);
        $method     = $reflection->getMethod('getBalance');
        $method->setAccessible(true);
        $result = $method->invoke($agent, $this->testPhone);

        $this->assertStringContainsString('Jour', $result);
    }

    // ── getHelp v1.3.0 ───────────────────────────────────────────────────────

    public function test_help_contains_resume_semaine_command(): void
    {
        $agent      = new FinanceAgent();
        $reflection = new \ReflectionClass($agent);
        $method     = $reflection->getMethod('getHelp');
        $method->setAccessible(true);
        $help = $method->invoke($agent);

        $this->assertStringContainsString('resume semaine', $help);
        $this->assertStringContainsString('top depenses', $help);
    }

    // ── keywords v1.4.0 ──────────────────────────────────────────────────────

    public function test_keywords_include_comparer_mois(): void
    {
        $this->assertContains('comparer mois', (new FinanceAgent())->keywords());
    }

    public function test_keywords_include_chercher_depense(): void
    {
        $this->assertContains('chercher depense', (new FinanceAgent())->keywords());
    }

    // ── canHandle v1.4.0 ─────────────────────────────────────────────────────

    public function test_can_handle_comparer_mois(): void
    {
        $agent = new FinanceAgent();
        $this->assertTrue($agent->canHandle($this->makeContext('comparer mois')));
    }

    public function test_can_handle_comparaison(): void
    {
        $agent = new FinanceAgent();
        $this->assertTrue($agent->canHandle($this->makeContext('comparaison')));
    }

    public function test_can_handle_chercher(): void
    {
        $agent = new FinanceAgent();
        $this->assertTrue($agent->canHandle($this->makeContext('chercher restaurant')));
    }

    public function test_can_handle_rechercher(): void
    {
        $agent = new FinanceAgent();
        $this->assertTrue($agent->canHandle($this->makeContext('rechercher taxi')));
    }

    // ── parseCommand v1.4.0 ──────────────────────────────────────────────────

    public function test_parse_command_compare_months(): void
    {
        $cmd = $this->parseCommand('comparer mois');
        $this->assertNotNull($cmd);
        $this->assertEquals('compare_months', $cmd['action']);
    }

    public function test_parse_command_compare_months_comparaison(): void
    {
        $cmd = $this->parseCommand('comparaison');
        $this->assertNotNull($cmd);
        $this->assertEquals('compare_months', $cmd['action']);
    }

    public function test_parse_command_search_expenses(): void
    {
        $cmd = $this->parseCommand('chercher restaurant');
        $this->assertNotNull($cmd);
        $this->assertEquals('search_expenses', $cmd['action']);
        $this->assertEquals('restaurant', $cmd['query']);
    }

    public function test_parse_command_search_expenses_variant(): void
    {
        $cmd = $this->parseCommand('rechercher courses supermarche');
        $this->assertNotNull($cmd);
        $this->assertEquals('search_expenses', $cmd['action']);
        $this->assertEquals('courses supermarche', $cmd['query']);
    }

    // ── compareMonths (via reflection) ───────────────────────────────────────

    private function callCompareMonths(): string
    {
        $agent      = new FinanceAgent();
        $reflection = new \ReflectionClass($agent);
        $method     = $reflection->getMethod('compareMonths');
        $method->setAccessible(true);
        return $method->invoke($agent, $this->testPhone);
    }

    public function test_compare_months_no_data(): void
    {
        $result = $this->callCompareMonths();
        $this->assertStringContainsString('📊', $result);
        $this->assertStringContainsString('Aucune donnee', $result);
    }

    public function test_compare_months_with_current_month_data(): void
    {
        Expense::create([
            'user_phone' => $this->testPhone,
            'amount'     => 50.0,
            'category'   => 'alimentation',
            'date'       => Carbon::now()->toDateString(),
        ]);

        $result = $this->callCompareMonths();
        $this->assertStringContainsString('📊', $result);
        $this->assertStringContainsString('50', $result);
        $this->assertStringContainsString('alimentation', $result);
    }

    public function test_compare_months_with_both_months_data(): void
    {
        Expense::create([
            'user_phone' => $this->testPhone,
            'amount'     => 100.0,
            'category'   => 'transport',
            'date'       => Carbon::now()->toDateString(),
        ]);
        Expense::create([
            'user_phone' => $this->testPhone,
            'amount'     => 80.0,
            'category'   => 'transport',
            'date'       => Carbon::now()->subMonth()->toDateString(),
        ]);

        $result = $this->callCompareMonths();
        $this->assertStringContainsString('100', $result);
        $this->assertStringContainsString('80', $result);
        $this->assertStringContainsString('transport', $result);
    }

    // ── searchExpenses (via reflection) ──────────────────────────────────────

    private function callSearchExpenses(string $query): string
    {
        $agent      = new FinanceAgent();
        $reflection = new \ReflectionClass($agent);
        $method     = $reflection->getMethod('searchExpenses');
        $method->setAccessible(true);
        return $method->invoke($agent, $this->testPhone, $query);
    }

    public function test_search_expenses_no_results(): void
    {
        $result = $this->callSearchExpenses('taxi');
        $this->assertStringContainsString('🔍', $result);
        $this->assertStringContainsString('Aucune depense', $result);
    }

    public function test_search_expenses_finds_by_description(): void
    {
        Expense::create([
            'user_phone'  => $this->testPhone,
            'amount'      => 15.0,
            'category'    => 'transport',
            'description' => 'taxi gare',
            'date'        => Carbon::today()->toDateString(),
        ]);

        $result = $this->callSearchExpenses('taxi');
        $this->assertStringContainsString('15', $result);
        $this->assertStringContainsString('transport', $result);
    }

    public function test_search_expenses_finds_by_category(): void
    {
        Expense::create([
            'user_phone' => $this->testPhone,
            'amount'     => 45.0,
            'category'   => 'alimentation',
            'date'       => Carbon::today()->toDateString(),
        ]);

        $result = $this->callSearchExpenses('alimentation');
        $this->assertStringContainsString('45', $result);
        $this->assertStringContainsString('alimentation', $result);
    }

    public function test_search_expenses_rejects_short_query(): void
    {
        $result = $this->callSearchExpenses('a');
        $this->assertStringContainsString('❌', $result);
    }

    public function test_search_expenses_rejects_too_long_query(): void
    {
        $result = $this->callSearchExpenses(str_repeat('a', 51));
        $this->assertStringContainsString('❌', $result);
    }

    public function test_search_expenses_shows_total(): void
    {
        Expense::create([
            'user_phone'  => $this->testPhone,
            'amount'      => 12.5,
            'category'    => 'transport',
            'description' => 'bus',
            'date'        => Carbon::today()->toDateString(),
        ]);
        Expense::create([
            'user_phone'  => $this->testPhone,
            'amount'      => 8.0,
            'category'    => 'transport',
            'description' => 'bus ligne 12',
            'date'        => Carbon::today()->toDateString(),
        ]);

        $result = $this->callSearchExpenses('bus');
        $this->assertStringContainsString('2 resultat', $result);
        $this->assertStringContainsString('20.5', $result);
    }

    // ── logExpense v1.4.0 — category max length ───────────────────────────────

    public function test_log_expense_rejects_too_long_category(): void
    {
        $result = $this->callLogExpense(10.0, str_repeat('a', 31));
        $this->assertStringContainsString('❌', $result);
    }

    // ── generateMonthlyReport v1.4.0 — daily average ─────────────────────────

    public function test_monthly_report_shows_daily_average(): void
    {
        Expense::create([
            'user_phone' => $this->testPhone,
            'amount'     => 90.0,
            'category'   => 'alimentation',
            'date'       => Carbon::now()->toDateString(),
        ]);

        $agent      = new FinanceAgent();
        $reflection = new \ReflectionClass($agent);
        $method     = $reflection->getMethod('generateMonthlyReport');
        $method->setAccessible(true);
        $result = $method->invoke($agent, $this->testPhone);

        $this->assertStringContainsString('Moyenne journaliere', $result);
    }

    // ── getHelp v1.4.0 ────────────────────────────────────────────────────────

    public function test_help_contains_comparer_mois_command(): void
    {
        $agent      = new FinanceAgent();
        $reflection = new \ReflectionClass($agent);
        $method     = $reflection->getMethod('getHelp');
        $method->setAccessible(true);
        $help = $method->invoke($agent);

        $this->assertStringContainsString('comparer mois', $help);
        $this->assertStringContainsString('chercher', $help);
    }

    // ── keywords v1.5.0 ──────────────────────────────────────────────────────

    public function test_keywords_include_tendance(): void
    {
        $this->assertContains('tendance', (new FinanceAgent())->keywords());
    }

    public function test_keywords_include_recurrent(): void
    {
        $this->assertContains('recurrent', (new FinanceAgent())->keywords());
    }

    // ── canHandle v1.5.0 ─────────────────────────────────────────────────────

    public function test_can_handle_tendance(): void
    {
        $agent = new FinanceAgent();
        $this->assertTrue($agent->canHandle($this->makeContext('tendance')));
    }

    public function test_can_handle_6_mois(): void
    {
        $agent = new FinanceAgent();
        $this->assertTrue($agent->canHandle($this->makeContext('6 mois')));
    }

    public function test_can_handle_recurrents(): void
    {
        $agent = new FinanceAgent();
        $this->assertTrue($agent->canHandle($this->makeContext('recurrents')));
    }

    public function test_can_handle_depenses_recurrentes(): void
    {
        $agent = new FinanceAgent();
        $this->assertTrue($agent->canHandle($this->makeContext('depenses recurrentes')));
    }

    // ── parseCommand v1.5.0 ──────────────────────────────────────────────────

    public function test_parse_command_monthly_trend(): void
    {
        $cmd = $this->parseCommand('tendance');
        $this->assertNotNull($cmd);
        $this->assertEquals('monthly_trend', $cmd['action']);
    }

    public function test_parse_command_monthly_trend_6_mois(): void
    {
        $cmd = $this->parseCommand('6 mois');
        $this->assertNotNull($cmd);
        $this->assertEquals('monthly_trend', $cmd['action']);
    }

    public function test_parse_command_monthly_trend_evolution(): void
    {
        $cmd = $this->parseCommand('evolution mensuelle');
        $this->assertNotNull($cmd);
        $this->assertEquals('monthly_trend', $cmd['action']);
    }

    public function test_parse_command_recurring_expenses(): void
    {
        $cmd = $this->parseCommand('recurrents');
        $this->assertNotNull($cmd);
        $this->assertEquals('recurring_expenses', $cmd['action']);
    }

    public function test_parse_command_recurring_expenses_variant(): void
    {
        $cmd = $this->parseCommand('depenses recurrentes');
        $this->assertNotNull($cmd);
        $this->assertEquals('recurring_expenses', $cmd['action']);
    }

    // ── getMonthlyTrend (via reflection) ─────────────────────────────────────

    private function callGetMonthlyTrend(): string
    {
        $agent      = new FinanceAgent();
        $reflection = new \ReflectionClass($agent);
        $method     = $reflection->getMethod('getMonthlyTrend');
        $method->setAccessible(true);
        return $method->invoke($agent, $this->testPhone);
    }

    public function test_monthly_trend_no_data(): void
    {
        $result = $this->callGetMonthlyTrend();
        $this->assertStringContainsString('📈', $result);
        $this->assertStringContainsString('Aucune donnee', $result);
    }

    public function test_monthly_trend_with_current_month_expense(): void
    {
        Expense::create([
            'user_phone' => $this->testPhone,
            'amount'     => 120.0,
            'category'   => 'alimentation',
            'date'       => Carbon::now()->toDateString(),
        ]);

        $result = $this->callGetMonthlyTrend();
        $this->assertStringContainsString('📈', $result);
        $this->assertStringContainsString('120', $result);
        $this->assertStringContainsString('maintenant', $result);
    }

    public function test_monthly_trend_shows_average(): void
    {
        Expense::create([
            'user_phone' => $this->testPhone,
            'amount'     => 200.0,
            'category'   => 'alimentation',
            'date'       => Carbon::now()->toDateString(),
        ]);
        Expense::create([
            'user_phone' => $this->testPhone,
            'amount'     => 100.0,
            'category'   => 'transport',
            'date'       => Carbon::now()->subMonth()->toDateString(),
        ]);

        $result = $this->callGetMonthlyTrend();
        $this->assertStringContainsString('Moyenne', $result);
        $this->assertStringContainsString('mois', $result);
    }

    public function test_monthly_trend_shows_trend_direction(): void
    {
        Expense::create([
            'user_phone' => $this->testPhone,
            'amount'     => 50.0,
            'category'   => 'transport',
            'date'       => Carbon::now()->subMonth()->toDateString(),
        ]);
        Expense::create([
            'user_phone' => $this->testPhone,
            'amount'     => 150.0,
            'category'   => 'alimentation',
            'date'       => Carbon::now()->toDateString(),
        ]);

        $result = $this->callGetMonthlyTrend();
        // Should show hausse or baisse
        $this->assertTrue(
            str_contains($result, 'hausse') || str_contains($result, 'baisse') || str_contains($result, 'Stable'),
            "Expected trend direction in: {$result}"
        );
    }

    // ── getRecurringExpenses (via reflection) ─────────────────────────────────

    private function callGetRecurringExpenses(): string
    {
        $agent      = new FinanceAgent();
        $reflection = new \ReflectionClass($agent);
        $method     = $reflection->getMethod('getRecurringExpenses');
        $method->setAccessible(true);
        return $method->invoke($agent, $this->testPhone);
    }

    public function test_recurring_expenses_no_data(): void
    {
        $result = $this->callGetRecurringExpenses();
        $this->assertStringContainsString('🔄', $result);
        $this->assertStringContainsString('Aucune depense', $result);
    }

    public function test_recurring_expenses_no_pattern(): void
    {
        // Only one month of data — not recurring
        Expense::create([
            'user_phone' => $this->testPhone,
            'amount'     => 30.0,
            'category'   => 'loisirs',
            'date'       => Carbon::now()->toDateString(),
        ]);

        $result = $this->callGetRecurringExpenses();
        $this->assertStringContainsString('🔄', $result);
        $this->assertStringContainsString('pattern', $result);
    }

    public function test_recurring_expenses_detects_pattern(): void
    {
        // Same category in 2 different months = recurring
        Expense::create([
            'user_phone' => $this->testPhone,
            'amount'     => 13.99,
            'category'   => 'abonnements',
            'date'       => Carbon::now()->toDateString(),
        ]);
        Expense::create([
            'user_phone' => $this->testPhone,
            'amount'     => 13.99,
            'category'   => 'abonnements',
            'date'       => Carbon::now()->subMonth()->toDateString(),
        ]);

        $result = $this->callGetRecurringExpenses();
        $this->assertStringContainsString('abonnements', $result);
        $this->assertStringContainsString('📌', $result);
        $this->assertStringContainsString('13.99', $result);
    }

    public function test_recurring_expenses_shows_monthly_cost(): void
    {
        Expense::create([
            'user_phone' => $this->testPhone,
            'amount'     => 50.0,
            'category'   => 'transport',
            'date'       => Carbon::now()->toDateString(),
        ]);
        Expense::create([
            'user_phone' => $this->testPhone,
            'amount'     => 45.0,
            'category'   => 'transport',
            'date'       => Carbon::now()->subMonth()->toDateString(),
        ]);

        $result = $this->callGetRecurringExpenses();
        $this->assertStringContainsString('Cout mensuel estime', $result);
    }

    // ── buildTrendBar (via reflection) ───────────────────────────────────────

    private function callBuildTrendBar(float $value, float $max): string
    {
        $agent      = new FinanceAgent();
        $reflection = new \ReflectionClass($agent);
        $method     = $reflection->getMethod('buildTrendBar');
        $method->setAccessible(true);
        return $method->invoke($agent, $value, $max);
    }

    public function test_trend_bar_full_when_value_equals_max(): void
    {
        $bar = $this->callBuildTrendBar(100.0, 100.0);
        $this->assertEquals(str_repeat('█', 8), $bar);
    }

    public function test_trend_bar_empty_when_value_zero(): void
    {
        $bar = $this->callBuildTrendBar(0.0, 100.0);
        $this->assertEquals(str_repeat('░', 8), $bar);
    }

    public function test_trend_bar_half_when_value_half_max(): void
    {
        $bar = $this->callBuildTrendBar(50.0, 100.0);
        $this->assertEquals(str_repeat('█', 4) . str_repeat('░', 4), $bar);
    }

    // ── getHelp v1.5.0 ────────────────────────────────────────────────────────

    public function test_help_contains_tendance_command(): void
    {
        $agent      = new FinanceAgent();
        $reflection = new \ReflectionClass($agent);
        $method     = $reflection->getMethod('getHelp');
        $method->setAccessible(true);
        $help = $method->invoke($agent);

        $this->assertStringContainsString('tendance', $help);
        $this->assertStringContainsString('recurrents', $help);
    }

    // ── keywords v1.6.0 ───────────────────────────────────────────────────────

    public function test_keywords_include_budget_journalier(): void
    {
        $this->assertContains('budget journalier', (new FinanceAgent())->keywords());
    }

    public function test_keywords_include_export(): void
    {
        $this->assertContains('export', (new FinanceAgent())->keywords());
    }

    // ── canHandle v1.6.0 ──────────────────────────────────────────────────────

    public function test_can_handle_budget_journalier(): void
    {
        $this->assertTrue((new FinanceAgent())->canHandle($this->makeContext('budget journalier')));
    }

    public function test_can_handle_combien_par_jour(): void
    {
        $this->assertTrue((new FinanceAgent())->canHandle($this->makeContext('combien par jour')));
    }

    public function test_can_handle_export(): void
    {
        $this->assertTrue((new FinanceAgent())->canHandle($this->makeContext('export')));
    }

    public function test_can_handle_exporter(): void
    {
        $this->assertTrue((new FinanceAgent())->canHandle($this->makeContext('exporter mes depenses')));
    }

    // ── parseCommand v1.6.0 ───────────────────────────────────────────────────

    public function test_parse_command_daily_budget(): void
    {
        $cmd = $this->parseCommand('budget journalier');
        $this->assertNotNull($cmd);
        $this->assertEquals('daily_budget', $cmd['action']);
    }

    public function test_parse_command_daily_budget_combien(): void
    {
        $cmd = $this->parseCommand('combien par jour');
        $this->assertNotNull($cmd);
        $this->assertEquals('daily_budget', $cmd['action']);
    }

    public function test_parse_command_export_month(): void
    {
        $cmd = $this->parseCommand('export');
        $this->assertNotNull($cmd);
        $this->assertEquals('export_month', $cmd['action']);
    }

    public function test_parse_command_export_month_exporter(): void
    {
        $cmd = $this->parseCommand('exporter mes depenses');
        $this->assertNotNull($cmd);
        $this->assertEquals('export_month', $cmd['action']);
    }

    // ── getDailyBudget ────────────────────────────────────────────────────────

    private function callGetDailyBudget(): string
    {
        $agent      = new FinanceAgent();
        $reflection = new \ReflectionClass($agent);
        $method     = $reflection->getMethod('getDailyBudget');
        $method->setAccessible(true);
        return $method->invoke($agent, $this->testPhone);
    }

    public function test_daily_budget_no_budget_defined(): void
    {
        $result = $this->callGetDailyBudget();
        $this->assertStringContainsString('Aucun budget defini', $result);
    }

    public function test_daily_budget_shows_daily_allowance(): void
    {
        Budget::create([
            'user_phone'    => $this->testPhone,
            'category'      => 'alimentation',
            'monthly_limit' => 300.0,
        ]);

        $result = $this->callGetDailyBudget();
        $this->assertStringContainsString('Disponible par jour', $result);
        $this->assertStringContainsString('€/jour', $result);
    }

    public function test_daily_budget_shows_today_expenses(): void
    {
        Budget::create([
            'user_phone'    => $this->testPhone,
            'category'      => 'alimentation',
            'monthly_limit' => 300.0,
        ]);
        Expense::create([
            'user_phone' => $this->testPhone,
            'amount'     => 25.0,
            'category'   => 'alimentation',
            'date'       => Carbon::now()->toDateString(),
        ]);

        $result = $this->callGetDailyBudget();
        $this->assertStringContainsString("Aujourd'hui", $result);
        $this->assertStringContainsString('25', $result);
    }

    public function test_daily_budget_exceeded(): void
    {
        Budget::create([
            'user_phone'    => $this->testPhone,
            'category'      => 'alimentation',
            'monthly_limit' => 10.0,
        ]);
        Expense::create([
            'user_phone' => $this->testPhone,
            'amount'     => 50.0,
            'category'   => 'alimentation',
            'date'       => Carbon::now()->toDateString(),
        ]);

        $result = $this->callGetDailyBudget();
        $this->assertStringContainsString('depasse', $result);
    }

    public function test_daily_budget_multi_category_breakdown(): void
    {
        Budget::create(['user_phone' => $this->testPhone, 'category' => 'alimentation', 'monthly_limit' => 300.0]);
        Budget::create(['user_phone' => $this->testPhone, 'category' => 'transport',    'monthly_limit' => 100.0]);

        $result = $this->callGetDailyBudget();
        $this->assertStringContainsString('Par categorie', $result);
    }

    // ── exportMonth ───────────────────────────────────────────────────────────

    private function callExportMonth(): string
    {
        $agent      = new FinanceAgent();
        $reflection = new \ReflectionClass($agent);
        $method     = $reflection->getMethod('exportMonth');
        $method->setAccessible(true);
        return $method->invoke($agent, $this->testPhone);
    }

    public function test_export_month_no_expenses(): void
    {
        $result = $this->callExportMonth();
        $this->assertStringContainsString('Aucune depense', $result);
    }

    public function test_export_month_shows_all_expenses(): void
    {
        Expense::create(['user_phone' => $this->testPhone, 'amount' => 45.0,  'category' => 'alimentation', 'date' => Carbon::now()->toDateString()]);
        Expense::create(['user_phone' => $this->testPhone, 'amount' => 12.50, 'category' => 'transport',    'date' => Carbon::now()->toDateString()]);

        $result = $this->callExportMonth();
        $this->assertStringContainsString('alimentation', $result);
        $this->assertStringContainsString('transport', $result);
        $this->assertStringContainsString('57.5', $result);
    }

    public function test_export_month_shows_total(): void
    {
        Expense::create(['user_phone' => $this->testPhone, 'amount' => 100.0, 'category' => 'alimentation', 'date' => Carbon::now()->toDateString()]);

        $result = $this->callExportMonth();
        $this->assertStringContainsString('TOTAL', $result);
        $this->assertStringContainsString('100', $result);
    }

    public function test_export_month_shows_budget_if_defined(): void
    {
        Budget::create(['user_phone' => $this->testPhone, 'category' => 'alimentation', 'monthly_limit' => 200.0]);
        Expense::create(['user_phone' => $this->testPhone, 'amount' => 80.0, 'category' => 'alimentation', 'date' => Carbon::now()->toDateString()]);

        $result = $this->callExportMonth();
        $this->assertStringContainsString('Budget', $result);
        $this->assertStringContainsString('200', $result);
    }

    public function test_export_month_groups_by_date(): void
    {
        Expense::create(['user_phone' => $this->testPhone, 'amount' => 30.0, 'category' => 'alimentation', 'date' => Carbon::now()->toDateString()]);
        Expense::create(['user_phone' => $this->testPhone, 'amount' => 20.0, 'category' => 'transport',    'date' => Carbon::now()->subDay()->toDateString()]);

        $result = $this->callExportMonth();
        $this->assertStringContainsString('📅', $result);
        // Both dates should appear
        $this->assertStringContainsString('alimentation', $result);
        $this->assertStringContainsString('transport', $result);
    }

    // ── logExpense today total ─────────────────────────────────────────────────

    public function test_log_expense_shows_today_total_when_multiple(): void
    {
        // First expense today
        Expense::create([
            'user_phone' => $this->testPhone,
            'amount'     => 20.0,
            'category'   => 'transport',
            'date'       => Carbon::now()->toDateString(),
        ]);

        $agent      = new FinanceAgent();
        $reflection = new \ReflectionClass($agent);
        $method     = $reflection->getMethod('logExpense');
        $method->setAccessible(true);
        // Add a second expense for today
        $result = $method->invoke($agent, $this->testPhone, 15.0, 'alimentation', null);

        // Today total should be 20 + 15 = 35
        $this->assertStringContainsString("Aujourd'hui total", $result);
        $this->assertStringContainsString('35', $result);
    }

    // ── getAlerts projection warning ──────────────────────────────────────────

    public function test_alerts_shows_projection_warning_when_over_budget(): void
    {
        Budget::create(['user_phone' => $this->testPhone, 'category' => 'alimentation', 'monthly_limit' => 50.0]);

        // Create enough expenses to project an overrun
        // Spend 40€ on day 5 → daily avg = 8€ → projection = 8 * daysInMonth >> 50
        Carbon::setTestNow(Carbon::create(2026, 3, 5));
        Expense::create([
            'user_phone' => $this->testPhone,
            'amount'     => 40.0,
            'category'   => 'alimentation',
            'date'       => Carbon::now()->toDateString(),
        ]);

        $agent      = new FinanceAgent();
        $reflection = new \ReflectionClass($agent);
        $method     = $reflection->getMethod('getAlerts');
        $method->setAccessible(true);
        $result = $method->invoke($agent, $this->testPhone);

        Carbon::setTestNow(); // reset
        $this->assertStringContainsString('Projection', $result);
    }

    // ── getBalance daily allowance ─────────────────────────────────────────────

    public function test_balance_shows_daily_allowance_when_budget_defined(): void
    {
        Budget::create(['user_phone' => $this->testPhone, 'category' => 'alimentation', 'monthly_limit' => 300.0]);

        $agent      = new FinanceAgent();
        $reflection = new \ReflectionClass($agent);
        $method     = $reflection->getMethod('getBalance');
        $method->setAccessible(true);

        Carbon::setTestNow(Carbon::create(2026, 3, 5)); // day 5, 26 days left
        $result = $method->invoke($agent, $this->testPhone);
        Carbon::setTestNow();

        $this->assertStringContainsString('Budget/jour', $result);
    }

    // ── getHelp v1.6.0 ────────────────────────────────────────────────────────

    public function test_help_contains_daily_budget_command(): void
    {
        $agent      = new FinanceAgent();
        $reflection = new \ReflectionClass($agent);
        $method     = $reflection->getMethod('getHelp');
        $method->setAccessible(true);
        $help = $method->invoke($agent);

        $this->assertStringContainsString('budget journalier', $help);
        $this->assertStringContainsString('export', $help);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function makeContext(?string $body): AgentContext
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
        );
    }
}
