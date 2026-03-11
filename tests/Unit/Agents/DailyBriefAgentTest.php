<?php

namespace Tests\Unit\Agents;

use App\Models\Agent;
use App\Models\AgentSession;
use App\Models\Reminder;
use App\Models\Todo;
use App\Models\User;
use App\Models\UserBriefPreference;
use App\Services\AgentContext;
use App\Services\Agents\DailyBriefAgent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DailyBriefAgentTest extends TestCase
{
    use RefreshDatabase;

    /** Full WA from address used in AgentContext */
    private string $testPhone = '33612345678@s.whatsapp.net';

    /** Stripped phone as returned by AgentContext::phone() — used for DB records */
    private string $userPhone = '33612345678';

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake(['*' => Http::response(['success' => true], 200)]);
    }

    // ── Basics ────────────────────────────────────────────────────────────────

    public function test_agent_name_is_daily_brief(): void
    {
        $this->assertEquals('daily_brief', (new DailyBriefAgent())->name());
    }

    public function test_agent_version_is_1_2_0(): void
    {
        $this->assertEquals('1.2.0', (new DailyBriefAgent())->version());
    }

    public function test_agent_has_description(): void
    {
        $this->assertNotEmpty((new DailyBriefAgent())->description());
    }

    public function test_keywords_include_brief(): void
    {
        $this->assertContains('brief', (new DailyBriefAgent())->keywords());
    }

    public function test_keywords_include_mon_brief(): void
    {
        $this->assertContains('mon brief', (new DailyBriefAgent())->keywords());
    }

    public function test_keywords_include_ajouter_section(): void
    {
        $this->assertContains('ajouter section', (new DailyBriefAgent())->keywords());
    }

    public function test_keywords_include_retirer_section(): void
    {
        $this->assertContains('retirer section', (new DailyBriefAgent())->keywords());
    }

    public function test_keywords_include_reset_brief(): void
    {
        $this->assertContains('reset brief', (new DailyBriefAgent())->keywords());
    }

    // ── canHandle ─────────────────────────────────────────────────────────────

    public function test_can_handle_mon_brief(): void
    {
        $agent   = new DailyBriefAgent();
        $context = $this->makeContext('mon brief');
        $this->assertTrue($agent->canHandle($context));
    }

    public function test_can_handle_daily_brief(): void
    {
        $agent   = new DailyBriefAgent();
        $context = $this->makeContext('daily brief');
        $this->assertTrue($agent->canHandle($context));
    }

    public function test_can_handle_resume_du_jour(): void
    {
        $agent   = new DailyBriefAgent();
        $context = $this->makeContext('resume du jour');
        $this->assertTrue($agent->canHandle($context));
    }

    public function test_can_handle_statut_brief(): void
    {
        $agent   = new DailyBriefAgent();
        $context = $this->makeContext('statut brief');
        $this->assertTrue($agent->canHandle($context));
    }

    public function test_can_handle_brief_demain(): void
    {
        $agent   = new DailyBriefAgent();
        $context = $this->makeContext('brief demain');
        $this->assertTrue($agent->canHandle($context));
    }

    public function test_can_handle_ajouter_section(): void
    {
        $agent   = new DailyBriefAgent();
        $context = $this->makeContext('ajouter section productivite');
        $this->assertTrue($agent->canHandle($context));
    }

    public function test_can_handle_retirer_section(): void
    {
        $agent   = new DailyBriefAgent();
        $context = $this->makeContext('retirer section news');
        $this->assertTrue($agent->canHandle($context));
    }

    public function test_can_handle_reset_brief(): void
    {
        $agent   = new DailyBriefAgent();
        $context = $this->makeContext('reset brief sections');
        $this->assertTrue($agent->canHandle($context));
    }

    public function test_can_handle_returns_false_for_unrelated_message(): void
    {
        $agent   = new DailyBriefAgent();
        $context = $this->makeContext('quelle heure est-il');
        $this->assertFalse($agent->canHandle($context));
    }

    public function test_can_handle_returns_false_for_empty_body(): void
    {
        $agent   = new DailyBriefAgent();
        $context = $this->makeContext('');
        $this->assertFalse($agent->canHandle($context));
    }

    // ── Configure time ────────────────────────────────────────────────────────

    public function test_configure_time_creates_preference(): void
    {
        $agent   = new DailyBriefAgent();
        $context = $this->makeContext('configure brief 07:30');
        $result  = $agent->handle($context);

        $this->assertEquals('reply', $result->action);
        $this->assertStringContainsString('07:30', $result->reply);
        $this->assertDatabaseHas('user_brief_preferences', [
            'user_phone' => $this->userPhone,
            'brief_time' => '07:30',
        ]);
    }

    public function test_configure_time_accepts_hour_format(): void
    {
        $agent   = new DailyBriefAgent();
        $context = $this->makeContext('configure brief 8h00');
        $agent->handle($context);

        $this->assertDatabaseHas('user_brief_preferences', [
            'user_phone' => $this->userPhone,
            'brief_time' => '08:00',
        ]);
    }

    public function test_configure_time_clamps_hour_to_23(): void
    {
        $agent   = new DailyBriefAgent();
        $context = $this->makeContext('configure brief 25:00');
        $agent->handle($context);

        $this->assertDatabaseHas('user_brief_preferences', [
            'user_phone' => $this->userPhone,
            'brief_time' => '23:00',
        ]);
    }

    public function test_configure_time_updates_existing_preference(): void
    {
        UserBriefPreference::create(['user_phone' => $this->userPhone, 'brief_time' => '06:00', 'enabled' => true]);

        $agent   = new DailyBriefAgent();
        $context = $this->makeContext('configure brief 09:00');
        $agent->handle($context);

        $this->assertEquals(1, UserBriefPreference::where('user_phone', $this->userPhone)->count());
        $this->assertDatabaseHas('user_brief_preferences', ['brief_time' => '09:00']);
    }

    public function test_configure_time_reply_mentions_new_commands(): void
    {
        $agent   = new DailyBriefAgent();
        $context = $this->makeContext('configure brief 07:00');
        $result  = $agent->handle($context);

        $this->assertStringContainsString('ajouter section', $result->reply);
        $this->assertStringContainsString('retirer section', $result->reply);
        $this->assertStringContainsString('reset brief', $result->reply);
    }

    // ── Configure city ────────────────────────────────────────────────────────

    public function test_configure_city_saves_value(): void
    {
        $agent   = new DailyBriefAgent();
        $context = $this->makeContext('configure brief ville Lyon');
        $result  = $agent->handle($context);

        $this->assertEquals('reply', $result->action);
        $this->assertStringContainsString('Lyon', $result->reply);
        $this->assertDatabaseHas('user_brief_preferences', [
            'user_phone'   => $this->userPhone,
            'weather_city' => 'Lyon',
        ]);
    }

    public function test_configure_city_rejects_invalid_value(): void
    {
        $agent   = new DailyBriefAgent();
        $context = $this->makeContext('configure brief ville 12345!!!');
        $result  = $agent->handle($context);

        $this->assertStringContainsString('invalide', $result->reply);
    }

    // ── Disable / Enable ──────────────────────────────────────────────────────

    public function test_disable_brief(): void
    {
        UserBriefPreference::create(['user_phone' => $this->userPhone, 'brief_time' => '07:00', 'enabled' => true]);

        $agent   = new DailyBriefAgent();
        $context = $this->makeContext('disable brief');
        $result  = $agent->handle($context);

        $this->assertStringContainsString('desactive', mb_strtolower($result->reply));
        $this->assertDatabaseHas('user_brief_preferences', [
            'user_phone' => $this->userPhone,
            'enabled'    => false,
        ]);
    }

    public function test_enable_creates_preference_if_absent(): void
    {
        $agent   = new DailyBriefAgent();
        $context = $this->makeContext('enable brief');
        $result  = $agent->handle($context);

        $this->assertEquals('reply', $result->action);
        $this->assertDatabaseHas('user_brief_preferences', [
            'user_phone' => $this->userPhone,
            'enabled'    => true,
        ]);
    }

    public function test_enable_reactivates_disabled_brief(): void
    {
        UserBriefPreference::create(['user_phone' => $this->userPhone, 'brief_time' => '07:00', 'enabled' => false]);

        $agent   = new DailyBriefAgent();
        $context = $this->makeContext('enable brief');
        $agent->handle($context);

        $this->assertDatabaseHas('user_brief_preferences', [
            'user_phone' => $this->userPhone,
            'enabled'    => true,
        ]);
    }

    // ── Status ────────────────────────────────────────────────────────────────

    public function test_status_returns_no_config_message_when_absent(): void
    {
        $agent   = new DailyBriefAgent();
        $context = $this->makeContext('statut brief');
        $result  = $agent->handle($context);

        $this->assertStringContainsString('Aucune configuration', $result->reply);
    }

    public function test_status_shows_configuration(): void
    {
        UserBriefPreference::create([
            'user_phone'         => $this->userPhone,
            'brief_time'         => '08:00',
            'enabled'            => true,
            'preferred_sections' => ['tasks', 'weather'],
            'weather_city'       => 'Bordeaux',
        ]);

        $agent   = new DailyBriefAgent();
        $context = $this->makeContext('statut brief');
        $result  = $agent->handle($context);

        $this->assertStringContainsString('08:00', $result->reply);
        $this->assertStringContainsString('Bordeaux', $result->reply);
        $this->assertStringContainsString('tasks', $result->reply);
    }

    public function test_status_reply_mentions_new_commands(): void
    {
        UserBriefPreference::create(['user_phone' => $this->userPhone, 'brief_time' => '07:00', 'enabled' => true]);

        $agent   = new DailyBriefAgent();
        $context = $this->makeContext('statut brief');
        $result  = $agent->handle($context);

        $this->assertStringContainsString('ajouter section', $result->reply);
        $this->assertStringContainsString('reset brief', $result->reply);
    }

    // ── Configure sections ────────────────────────────────────────────────────

    public function test_configure_sections_with_valid_sections(): void
    {
        $agent   = new DailyBriefAgent();
        $context = $this->makeContext('configure brief sections tasks,weather');
        $result  = $agent->handle($context);

        $this->assertStringContainsString('tasks', $result->reply);
        $pref = UserBriefPreference::where('user_phone', $this->userPhone)->first();
        $this->assertEquals(['tasks', 'weather'], $pref->preferred_sections);
    }

    public function test_configure_sections_with_french_aliases(): void
    {
        $agent   = new DailyBriefAgent();
        $context = $this->makeContext('configure brief sections taches,meteo,citation');
        $agent->handle($context);

        $pref = UserBriefPreference::where('user_phone', $this->userPhone)->first();
        $this->assertEquals(['tasks', 'weather', 'quote'], $pref->preferred_sections);
    }

    public function test_configure_sections_accepts_productivity(): void
    {
        $agent   = new DailyBriefAgent();
        $context = $this->makeContext('configure brief sections tasks,productivite');
        $agent->handle($context);

        $pref = UserBriefPreference::where('user_phone', $this->userPhone)->first();
        $this->assertContains('productivity', $pref->preferred_sections);
    }

    public function test_configure_sections_rejects_invalid(): void
    {
        $agent   = new DailyBriefAgent();
        $context = $this->makeContext('configure brief sections horoscope,crypto');
        $result  = $agent->handle($context);

        $this->assertStringContainsString('invalides', mb_strtolower($result->reply));
    }

    public function test_configure_sections_deduplicates(): void
    {
        $agent   = new DailyBriefAgent();
        $context = $this->makeContext('configure brief sections tasks,taches');
        $agent->handle($context);

        $pref = UserBriefPreference::where('user_phone', $this->userPhone)->first();
        $this->assertCount(1, $pref->preferred_sections);
        $this->assertEquals(['tasks'], $pref->preferred_sections);
    }

    // ── Reset sections ────────────────────────────────────────────────────────

    public function test_reset_sections_restores_defaults(): void
    {
        UserBriefPreference::create([
            'user_phone'         => $this->userPhone,
            'brief_time'         => '07:00',
            'enabled'            => true,
            'preferred_sections' => ['tasks'],
        ]);

        $agent   = new DailyBriefAgent();
        $context = $this->makeContext('reset brief sections');
        $result  = $agent->handle($context);

        $this->assertEquals('reply', $result->action);
        $this->assertStringContainsString('defaut', mb_strtolower($result->reply));

        $pref = UserBriefPreference::where('user_phone', $this->userPhone)->first();
        $this->assertEquals(['reminders', 'tasks', 'weather', 'news', 'quote'], $pref->preferred_sections);
    }

    public function test_reset_sections_creates_preference_if_absent(): void
    {
        $agent   = new DailyBriefAgent();
        $context = $this->makeContext('reset brief sections');
        $agent->handle($context);

        $pref = UserBriefPreference::where('user_phone', $this->userPhone)->first();
        $this->assertNotNull($pref);
        $this->assertEquals(['reminders', 'tasks', 'weather', 'news', 'quote'], $pref->preferred_sections);
    }

    // ── Add section ───────────────────────────────────────────────────────────

    public function test_add_section_appends_to_existing(): void
    {
        UserBriefPreference::create([
            'user_phone'         => $this->userPhone,
            'brief_time'         => '07:00',
            'enabled'            => true,
            'preferred_sections' => ['tasks', 'weather'],
        ]);

        $agent   = new DailyBriefAgent();
        $context = $this->makeContext('ajouter section news');
        $result  = $agent->handle($context);

        $this->assertStringContainsString('news', $result->reply);
        $pref = UserBriefPreference::where('user_phone', $this->userPhone)->first();
        $this->assertContains('news', $pref->preferred_sections);
    }

    public function test_add_section_productivity_alias(): void
    {
        UserBriefPreference::create([
            'user_phone'         => $this->userPhone,
            'brief_time'         => '07:00',
            'enabled'            => true,
            'preferred_sections' => ['tasks'],
        ]);

        $agent   = new DailyBriefAgent();
        $context = $this->makeContext('ajouter section productivite');
        $agent->handle($context);

        $pref = UserBriefPreference::where('user_phone', $this->userPhone)->first();
        $this->assertContains('productivity', $pref->preferred_sections);
    }

    public function test_add_section_rejects_unknown_section(): void
    {
        $agent   = new DailyBriefAgent();
        $context = $this->makeContext('ajouter section horoscope');
        $result  = $agent->handle($context);

        $this->assertStringContainsString('inconnue', mb_strtolower($result->reply));
    }

    public function test_add_section_already_present(): void
    {
        UserBriefPreference::create([
            'user_phone'         => $this->userPhone,
            'brief_time'         => '07:00',
            'enabled'            => true,
            'preferred_sections' => ['tasks', 'weather'],
        ]);

        $agent   = new DailyBriefAgent();
        $context = $this->makeContext('ajouter section weather');
        $result  = $agent->handle($context);

        $this->assertStringContainsString('deja', mb_strtolower($result->reply));
    }

    // ── Remove section ────────────────────────────────────────────────────────

    public function test_remove_section_from_existing(): void
    {
        UserBriefPreference::create([
            'user_phone'         => $this->userPhone,
            'brief_time'         => '07:00',
            'enabled'            => true,
            'preferred_sections' => ['tasks', 'weather', 'news'],
        ]);

        $agent   = new DailyBriefAgent();
        $context = $this->makeContext('retirer section news');
        $result  = $agent->handle($context);

        $this->assertStringContainsString('news', $result->reply);
        $pref = UserBriefPreference::where('user_phone', $this->userPhone)->first();
        $this->assertNotContains('news', $pref->preferred_sections);
    }

    public function test_remove_section_with_french_alias(): void
    {
        UserBriefPreference::create([
            'user_phone'         => $this->userPhone,
            'brief_time'         => '07:00',
            'enabled'            => true,
            'preferred_sections' => ['tasks', 'weather', 'news'],
        ]);

        $agent   = new DailyBriefAgent();
        $context = $this->makeContext('retirer section meteo');
        $agent->handle($context);

        $pref = UserBriefPreference::where('user_phone', $this->userPhone)->first();
        $this->assertNotContains('weather', $pref->preferred_sections);
    }

    public function test_remove_section_rejects_unknown_section(): void
    {
        $agent   = new DailyBriefAgent();
        $context = $this->makeContext('retirer section horoscope');
        $result  = $agent->handle($context);

        $this->assertStringContainsString('inconnue', mb_strtolower($result->reply));
    }

    public function test_remove_section_not_present(): void
    {
        UserBriefPreference::create([
            'user_phone'         => $this->userPhone,
            'brief_time'         => '07:00',
            'enabled'            => true,
            'preferred_sections' => ['tasks', 'weather'],
        ]);

        $agent   = new DailyBriefAgent();
        $context = $this->makeContext('retirer section news');
        $result  = $agent->handle($context);

        $this->assertStringContainsString("n'est pas", mb_strtolower($result->reply));
    }

    public function test_remove_section_refuses_to_empty_all(): void
    {
        UserBriefPreference::create([
            'user_phone'         => $this->userPhone,
            'brief_time'         => '07:00',
            'enabled'            => true,
            'preferred_sections' => ['tasks'],
        ]);

        $agent   = new DailyBriefAgent();
        $context = $this->makeContext('retirer section tasks');
        $result  = $agent->handle($context);

        $this->assertStringContainsString('impossible', mb_strtolower($result->reply));
    }

    // ── Generate brief ────────────────────────────────────────────────────────

    public function test_generate_brief_returns_reply(): void
    {
        $agent   = new DailyBriefAgent();
        $context = $this->makeContext('mon brief');
        $result  = $agent->generateBrief($context, $this->userPhone);

        $this->assertEquals('reply', $result->action);
        $this->assertNotEmpty($result->reply);
    }

    public function test_generate_brief_includes_date(): void
    {
        $agent   = new DailyBriefAgent();
        $context = $this->makeContext('mon brief');
        $result  = $agent->generateBrief($context, $this->userPhone);

        $this->assertStringContainsString(now()->format('Y'), $result->reply);
    }

    public function test_generate_brief_includes_reminders_section(): void
    {
        $agent   = new DailyBriefAgent();
        $context = $this->makeContext('mon brief');
        $result  = $agent->generateBrief($context, $this->userPhone);

        $this->assertStringContainsString('RAPPELS', $result->reply);
    }

    public function test_generate_brief_includes_tasks_section(): void
    {
        $agent   = new DailyBriefAgent();
        $context = $this->makeContext('mon brief');
        $result  = $agent->generateBrief($context, $this->userPhone);

        $this->assertStringContainsString('TACHES', $result->reply);
    }

    public function test_generate_brief_shows_reminder_content(): void
    {
        $agent   = new DailyBriefAgent();
        $dbAgent = Agent::factory()->create();
        Reminder::create([
            'agent_id'        => $dbAgent->id,
            'requester_phone' => $this->userPhone,
            'requester_name'  => 'Test User',
            'message'         => 'Appel dentiste',
            'scheduled_at'    => now()->setTime(10, 0),
            'status'          => 'pending',
            'channel'         => 'whatsapp',
        ]);

        $context = $this->makeContext('mon brief');
        $result  = $agent->generateBrief($context, $this->userPhone);

        $this->assertStringContainsString('Appel dentiste', $result->reply);
    }

    public function test_generate_brief_shows_task_content(): void
    {
        $agent   = new DailyBriefAgent();
        $dbAgent = Agent::factory()->create();
        Todo::create([
            'agent_id'        => $dbAgent->id,
            'requester_phone' => $this->userPhone,
            'requester_name'  => 'Test User',
            'title'           => 'Finir le rapport',
            'priority'        => 'high',
            'is_done'         => false,
        ]);

        $context = $this->makeContext('mon brief');
        $result  = $agent->generateBrief($context, $this->userPhone);

        $this->assertStringContainsString('Finir le rapport', $result->reply);
    }

    public function test_generate_brief_respects_preferred_sections(): void
    {
        UserBriefPreference::create([
            'user_phone'         => $this->userPhone,
            'brief_time'         => '07:00',
            'enabled'            => true,
            'preferred_sections' => ['tasks'],
        ]);

        $agent   = new DailyBriefAgent();
        $context = $this->makeContext('mon brief');
        $result  = $agent->generateBrief($context, $this->userPhone);

        $this->assertStringContainsString('TACHES', $result->reply);
        $this->assertStringNotContainsString('RAPPELS', $result->reply);
        $this->assertStringNotContainsString('METEO', $result->reply);
    }

    public function test_generate_brief_shows_overflow_reminders(): void
    {
        $dbAgent = Agent::factory()->create();
        for ($i = 1; $i <= 7; $i++) {
            Reminder::create([
                'agent_id'        => $dbAgent->id,
                'requester_phone' => $this->userPhone,
                'requester_name'  => 'Test User',
                'message'         => "Rappel {$i}",
                'scheduled_at'    => now()->setTime($i + 7, 0),
                'status'          => 'pending',
                'channel'         => 'whatsapp',
            ]);
        }

        $agent   = new DailyBriefAgent();
        $context = $this->makeContext('mon brief');
        $result  = $agent->generateBrief($context, $this->userPhone);

        $this->assertStringContainsString('+ 2 autre(s) rappel(s)', $result->reply);
    }

    public function test_generate_brief_shows_overflow_tasks(): void
    {
        $dbAgent = Agent::factory()->create();
        for ($i = 1; $i <= 7; $i++) {
            Todo::create([
                'agent_id'        => $dbAgent->id,
                'requester_phone' => $this->userPhone,
                'requester_name'  => 'Test User',
                'title'           => "Tache {$i}",
                'priority'        => 'low',
                'is_done'         => false,
            ]);
        }

        $agent   = new DailyBriefAgent();
        $context = $this->makeContext('mon brief');
        $result  = $agent->generateBrief($context, $this->userPhone);

        $this->assertStringContainsString('+ 2 autre(s) tache(s)', $result->reply);
    }

    // ── Overdue tasks ─────────────────────────────────────────────────────────

    public function test_generate_brief_shows_overdue_task_warning(): void
    {
        $dbAgent = Agent::factory()->create();
        Todo::create([
            'agent_id'        => $dbAgent->id,
            'requester_phone' => $this->userPhone,
            'requester_name'  => 'Test User',
            'title'           => 'Tache en retard',
            'priority'        => 'low',
            'is_done'         => false,
            'due_at'          => now()->subDays(2),
        ]);

        $agent   = new DailyBriefAgent();
        $context = $this->makeContext('mon brief');
        $result  = $agent->generateBrief($context, $this->userPhone);

        $this->assertStringContainsString('retard', mb_strtolower($result->reply));
    }

    // ── Tomorrow brief ────────────────────────────────────────────────────────

    public function test_tomorrow_brief_returns_reply(): void
    {
        $agent   = new DailyBriefAgent();
        $context = $this->makeContext('brief demain');
        $result  = $agent->generateTomorrowBrief($context, $this->userPhone);

        $this->assertEquals('reply', $result->action);
        $this->assertNotEmpty($result->reply);
    }

    public function test_tomorrow_brief_shows_tomorrow_header(): void
    {
        $agent   = new DailyBriefAgent();
        $context = $this->makeContext('brief demain');
        $result  = $agent->generateTomorrowBrief($context, $this->userPhone);

        $this->assertStringContainsString('DEMAIN', $result->reply);
    }

    public function test_tomorrow_brief_shows_reminder_for_tomorrow(): void
    {
        $dbAgent = Agent::factory()->create();
        Reminder::create([
            'agent_id'        => $dbAgent->id,
            'requester_phone' => $this->userPhone,
            'requester_name'  => 'Test User',
            'message'         => 'RDV medecin demain',
            'scheduled_at'    => now()->addDay()->setTime(9, 0),
            'status'          => 'pending',
            'channel'         => 'whatsapp',
        ]);

        $agent   = new DailyBriefAgent();
        $context = $this->makeContext('brief demain');
        $result  = $agent->generateTomorrowBrief($context, $this->userPhone);

        $this->assertStringContainsString('RDV medecin demain', $result->reply);
    }

    public function test_tomorrow_brief_does_not_show_todays_reminders(): void
    {
        $dbAgent = Agent::factory()->create();
        Reminder::create([
            'agent_id'        => $dbAgent->id,
            'requester_phone' => $this->userPhone,
            'requester_name'  => 'Test User',
            'message'         => 'Rappel ce soir',
            'scheduled_at'    => now()->setTime(20, 0),
            'status'          => 'pending',
            'channel'         => 'whatsapp',
        ]);

        $agent   = new DailyBriefAgent();
        $context = $this->makeContext('brief demain');
        $result  = $agent->generateTomorrowBrief($context, $this->userPhone);

        $this->assertStringNotContainsString('Rappel ce soir', $result->reply);
    }

    public function test_tomorrow_brief_does_not_show_news_section(): void
    {
        $agent   = new DailyBriefAgent();
        $context = $this->makeContext('brief demain');
        $result  = $agent->generateTomorrowBrief($context, $this->userPhone);

        $this->assertStringNotContainsString('HEADLINES', $result->reply);
    }

    public function test_tomorrow_brief_does_not_show_quote_section(): void
    {
        $agent   = new DailyBriefAgent();
        $context = $this->makeContext('brief demain');
        $result  = $agent->generateTomorrowBrief($context, $this->userPhone);

        $this->assertStringNotContainsString('CITATION', $result->reply);
    }

    public function test_tomorrow_brief_does_not_show_productivity_section(): void
    {
        UserBriefPreference::create([
            'user_phone'         => $this->userPhone,
            'brief_time'         => '07:00',
            'enabled'            => true,
            'preferred_sections' => ['tasks', 'productivity'],
        ]);

        $agent   = new DailyBriefAgent();
        $context = $this->makeContext('brief demain');
        $result  = $agent->generateTomorrowBrief($context, $this->userPhone);

        $this->assertStringNotContainsString('PRODUCTIVITE', mb_strtoupper($result->reply));
    }

    // ── generateBriefForPhone ─────────────────────────────────────────────────

    public function test_generate_brief_for_phone_returns_non_empty_string(): void
    {
        $agent  = new DailyBriefAgent();
        $result = $agent->generateBriefForPhone($this->userPhone);

        $this->assertIsString($result);
        $this->assertNotEmpty($result);
    }

    // ── Helper ────────────────────────────────────────────────────────────────

    private function makeContext(string $body): AgentContext
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
            routedAgent: 'daily_brief',
        );
    }
}
