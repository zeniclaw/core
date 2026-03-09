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

    public function test_agent_version_is_1_2_0(): void
    {
        $this->assertEquals('1.2.0', (new FinanceAgent())->version());
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
