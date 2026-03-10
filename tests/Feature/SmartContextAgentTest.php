<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\AgentSession;
use App\Models\User;
use App\Models\UserContextProfile;
use App\Services\AgentContext;
use App\Services\Agents\SmartContextAgent;
use App\Services\ContextMemory\ContextStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class SmartContextAgentTest extends TestCase
{
    use RefreshDatabase;

    private string $testPhone = '33612345678@s.whatsapp.net';

    protected function tearDown(): void
    {
        // Clean up Redis test keys
        Redis::del("context_memory:{$this->testPhone}");
        parent::tearDown();
    }

    // ── ContextStore ──────────────────────────────────────────────────────────

    public function test_context_store_can_store_and_retrieve_facts(): void
    {
        $store = new ContextStore();

        $facts = [
            ['key' => 'profession', 'value' => 'Developpeur Laravel', 'category' => 'profession', 'score' => 1.0],
            ['key' => 'humor_style', 'value' => 'Humour noir', 'category' => 'preference', 'score' => 0.9],
        ];

        $store->store($this->testPhone, $facts);

        $retrieved = $store->retrieve($this->testPhone);

        $this->assertCount(2, $retrieved);
        $this->assertEquals('profession', $retrieved[0]['key']);
        $this->assertEquals('Developpeur Laravel', $retrieved[0]['value']);
    }

    public function test_context_store_merges_facts_without_duplicates(): void
    {
        $store = new ContextStore();

        $store->store($this->testPhone, [
            ['key' => 'profession', 'value' => 'Dev PHP', 'category' => 'profession', 'score' => 0.8],
        ]);

        // Store updated fact with same key
        $store->store($this->testPhone, [
            ['key' => 'profession', 'value' => 'Dev Laravel Senior', 'category' => 'profession', 'score' => 1.0],
        ]);

        $retrieved = $store->retrieve($this->testPhone);

        $this->assertCount(1, $retrieved);
        $this->assertEquals('Dev Laravel Senior', $retrieved[0]['value']);
    }

    public function test_context_store_persists_to_database_as_fallback(): void
    {
        $store = new ContextStore();

        $store->store($this->testPhone, [
            ['key' => 'location', 'value' => 'Paris', 'category' => 'personal', 'score' => 0.9],
        ]);

        // Verify DB record exists
        $profile = UserContextProfile::where('user_phone', $this->testPhone)->first();
        $this->assertNotNull($profile);
        $this->assertIsArray($profile->facts);
        $this->assertCount(1, $profile->facts);
        $this->assertEquals('Paris', $profile->facts[0]['value']);
    }

    public function test_context_store_loads_from_database_when_redis_empty(): void
    {
        // Insert directly into DB
        UserContextProfile::create([
            'user_phone' => $this->testPhone,
            'facts' => [
                ['key' => 'tech_stack', 'value' => 'Vue.js + Laravel', 'category' => 'project', 'score' => 0.8, 'timestamp' => time()],
            ],
            'last_updated_at' => now(),
        ]);

        // Ensure Redis is empty
        Redis::del("context_memory:{$this->testPhone}");

        $store = new ContextStore();
        $retrieved = $store->retrieve($this->testPhone);

        $this->assertCount(1, $retrieved);
        $this->assertEquals('Vue.js + Laravel', $retrieved[0]['value']);
    }

    public function test_context_store_cleanup_removes_old_entries(): void
    {
        $store = new ContextStore();

        // Store with old timestamp
        $oldFacts = [
            ['key' => 'old_fact', 'value' => 'Ancient fact', 'category' => 'personal', 'score' => 0.5, 'timestamp' => time() - 200],
            ['key' => 'new_fact', 'value' => 'Recent fact', 'category' => 'personal', 'score' => 0.8, 'timestamp' => time()],
        ];

        $key = "context_memory:{$this->testPhone}";
        Redis::setex($key, 86400, serialize($oldFacts));

        $removed = $store->cleanup($this->testPhone, 100); // 100 seconds max age

        $this->assertEquals(1, $removed);

        $remaining = $store->retrieve($this->testPhone);
        $this->assertCount(1, $remaining);
        $this->assertEquals('new_fact', $remaining[0]['key']);
    }

    public function test_context_store_flush_removes_everything(): void
    {
        $store = new ContextStore();

        $store->store($this->testPhone, [
            ['key' => 'test', 'value' => 'Test fact', 'category' => 'general', 'score' => 0.5],
        ]);

        $store->flush($this->testPhone);

        $this->assertEmpty($store->retrieve($this->testPhone));
        $this->assertNull(UserContextProfile::where('user_phone', $this->testPhone)->first());
    }

    public function test_context_store_ttl_is_set_in_redis(): void
    {
        $store = new ContextStore();

        $store->store($this->testPhone, [
            ['key' => 'test', 'value' => 'TTL test', 'category' => 'general', 'score' => 0.5],
        ]);

        $ttl = Redis::ttl("context_memory:{$this->testPhone}");
        $this->assertGreaterThan(0, $ttl);
        $this->assertLessThanOrEqual(86400 * 30, $ttl);
    }

    // ── SmartContextAgent ─────────────────────────────────────────────────────

    public function test_smart_context_agent_returns_correct_name(): void
    {
        $agent = new SmartContextAgent();
        $this->assertEquals('smart_context', $agent->name());
    }

    public function test_smart_context_agent_can_always_handle(): void
    {
        $agent = new SmartContextAgent();
        $context = $this->makeContext('test message');
        $this->assertTrue($agent->canHandle($context));
    }

    public function test_smart_context_agent_silent_on_short_messages(): void
    {
        $agent = new SmartContextAgent();
        $context = $this->makeContext('abc');

        $result = $agent->handle($context);

        $this->assertEquals('silent', $result->action);
        $this->assertEquals('message_too_short', $result->metadata['reason']);
    }

    // ── Multi-agent coherence ────────────────────────────────────────────────

    public function test_context_memory_accessible_from_base_agent(): void
    {
        $store = new ContextStore();
        $store->store($this->testPhone, [
            ['key' => 'profession', 'value' => 'Developpeur PHP', 'category' => 'profession', 'score' => 1.0],
        ]);

        // SmartContextAgent extends BaseAgent, so getStoredContext should work
        $agent = new SmartContextAgent();
        $facts = $agent->getStoredContext($this->testPhone);

        $this->assertCount(1, $facts);
        $this->assertEquals('Developpeur PHP', $facts[0]['value']);
    }

    public function test_context_store_limits_to_50_facts(): void
    {
        $store = new ContextStore();

        $facts = [];
        for ($i = 0; $i < 60; $i++) {
            $facts[] = ['key' => "fact_{$i}", 'value' => "Fact number {$i}", 'category' => 'general', 'score' => 0.5];
        }

        $store->store($this->testPhone, $facts);
        $retrieved = $store->retrieve($this->testPhone);

        $this->assertCount(50, $retrieved);
    }

    public function test_agent_controller_includes_smart_context_in_sub_agents(): void
    {
        $user = User::factory()->create(['role' => 'admin']);

        $this->actingAs($user);

        // Create an agent to view
        $agent = Agent::factory()->create(['user_id' => $user->id]);

        $response = $this->get(route('agents.index'));
        $response->assertStatus(200);

        // The SUB_AGENTS constant should include smart_context
        $reflection = new \ReflectionClass(\App\Http\Controllers\AgentController::class);
        $constant = $reflection->getReflectionConstant('SUB_AGENTS');
        $subAgents = $constant->getValue();

        $this->assertArrayHasKey('smart_context', $subAgents);
        $this->assertEquals('SmartContextAgent', $subAgents['smart_context']['label']);
        $this->assertEquals('🧠', $subAgents['smart_context']['icon']);
        $this->assertEquals('blue', $subAgents['smart_context']['color']);
    }

    // ── New capabilities v1.1.0 ───────────────────────────────────────────────

    public function test_smart_context_agent_skips_commands(): void
    {
        $agent = new SmartContextAgent();
        $context = $this->makeContext('/help something long enough to pass');

        $result = $agent->handle($context);

        $this->assertEquals('silent', $result->action);
        $this->assertEquals('command_skipped', $result->metadata['reason']);
    }

    public function test_smart_context_agent_summarize_profile_empty(): void
    {
        $agent = new SmartContextAgent();
        $this->assertEquals('', $agent->summarizeProfile($this->testPhone));
    }

    public function test_smart_context_agent_summarize_profile_with_facts(): void
    {
        $store = new ContextStore();
        $store->store($this->testPhone, [
            ['key' => 'profession', 'value' => 'Dev Laravel', 'category' => 'profession', 'score' => 1.0],
            ['key' => 'location', 'value' => 'Paris', 'category' => 'personal', 'score' => 0.9],
            ['key' => 'career_goal', 'value' => 'Devenir CTO', 'category' => 'goal', 'score' => 0.8],
        ]);

        $agent = new SmartContextAgent();
        $summary = $agent->summarizeProfile($this->testPhone);

        $this->assertStringContainsString('Profession', $summary);
        $this->assertStringContainsString('Dev Laravel', $summary);
        $this->assertStringContainsString('Paris', $summary);
    }

    public function test_smart_context_agent_forget_fact_removes_it(): void
    {
        $store = new ContextStore();
        $store->store($this->testPhone, [
            ['key' => 'profession', 'value' => 'Dev PHP', 'category' => 'profession', 'score' => 1.0],
            ['key' => 'location', 'value' => 'Lyon', 'category' => 'personal', 'score' => 0.9],
        ]);

        $agent = new SmartContextAgent();
        $result = $agent->forgetFact($this->testPhone, 'profession');

        $this->assertTrue($result);
        $remaining = $agent->getStoredContext($this->testPhone);
        $this->assertCount(1, $remaining);
        $this->assertEquals('location', $remaining[0]['key']);
    }

    public function test_smart_context_agent_forget_nonexistent_fact_returns_false(): void
    {
        $agent = new SmartContextAgent();
        $result = $agent->forgetFact($this->testPhone, 'nonexistent_key');

        $this->assertFalse($result);
    }

    public function test_smart_context_agent_get_profile_stats_empty(): void
    {
        $agent = new SmartContextAgent();
        $stats = $agent->getProfileStats($this->testPhone);

        $this->assertEquals(0, $stats['total']);
        $this->assertEmpty($stats['by_category']);
        $this->assertEquals(0.0, $stats['avg_score']);
    }

    public function test_smart_context_agent_get_profile_stats_with_facts(): void
    {
        $store = new ContextStore();
        $store->store($this->testPhone, [
            ['key' => 'profession', 'value' => 'Dev Laravel', 'category' => 'profession', 'score' => 1.0, 'timestamp' => time()],
            ['key' => 'humor_style', 'value' => 'Humour noir', 'category' => 'preference', 'score' => 0.8, 'timestamp' => time()],
        ]);

        $agent = new SmartContextAgent();
        $stats = $agent->getProfileStats($this->testPhone);

        $this->assertEquals(2, $stats['total']);
        $this->assertArrayHasKey('profession', $stats['by_category']);
        $this->assertArrayHasKey('preference', $stats['by_category']);
        $this->assertEquals(0.9, $stats['avg_score']);
        $this->assertNotNull($stats['oldest_fact']);
    }

    public function test_smart_context_agent_version_is_1_6_0(): void
    {
        $agent = new SmartContextAgent();
        $this->assertEquals('1.6.0', $agent->version());
    }

    // ── New capabilities v1.2.0 ───────────────────────────────────────────────

    public function test_get_facts_by_category_returns_matching_facts(): void
    {
        $store = new ContextStore();
        $store->store($this->testPhone, [
            ['key' => 'profession', 'value' => 'Dev Laravel', 'category' => 'profession', 'score' => 1.0],
            ['key' => 'tech_stack', 'value' => 'Laravel, Vue.js', 'category' => 'skill', 'score' => 0.9],
            ['key' => 'location', 'value' => 'Paris', 'category' => 'personal', 'score' => 0.9],
        ]);

        $agent = new SmartContextAgent();

        $skills = $agent->getFactsByCategory($this->testPhone, 'skill');
        $this->assertCount(1, $skills);
        $this->assertEquals('tech_stack', $skills[0]['key']);

        $profession = $agent->getFactsByCategory($this->testPhone, 'profession');
        $this->assertCount(1, $profession);
        $this->assertEquals('Dev Laravel', $profession[0]['value']);
    }

    public function test_get_facts_by_category_returns_empty_for_unknown_category(): void
    {
        $agent = new SmartContextAgent();
        $result = $agent->getFactsByCategory($this->testPhone, 'unknown_category');
        $this->assertEmpty($result);
    }

    public function test_get_facts_by_category_returns_empty_when_no_match(): void
    {
        $store = new ContextStore();
        $store->store($this->testPhone, [
            ['key' => 'profession', 'value' => 'Dev Laravel', 'category' => 'profession', 'score' => 1.0],
        ]);

        $agent = new SmartContextAgent();
        $goals = $agent->getFactsByCategory($this->testPhone, 'goal');
        $this->assertEmpty($goals);
    }

    public function test_forget_category_removes_all_facts_of_category(): void
    {
        $store = new ContextStore();
        $store->store($this->testPhone, [
            ['key' => 'tech_stack', 'value' => 'Laravel, Vue.js', 'category' => 'skill', 'score' => 0.9],
            ['key' => 'learning_rust', 'value' => 'Apprend Rust', 'category' => 'skill', 'score' => 0.7],
            ['key' => 'profession', 'value' => 'Dev Backend', 'category' => 'profession', 'score' => 1.0],
        ]);

        $agent = new SmartContextAgent();
        $removed = $agent->forgetCategory($this->testPhone, 'skill');

        $this->assertEquals(2, $removed);

        $remaining = $agent->getStoredContext($this->testPhone);
        $this->assertCount(1, $remaining);
        $this->assertEquals('profession', $remaining[0]['key']);
    }

    public function test_forget_category_returns_zero_for_unknown_category(): void
    {
        $agent = new SmartContextAgent();
        $result = $agent->forgetCategory($this->testPhone, 'invalid_cat');
        $this->assertEquals(0, $result);
    }

    public function test_forget_category_returns_zero_when_no_facts_in_category(): void
    {
        $store = new ContextStore();
        $store->store($this->testPhone, [
            ['key' => 'profession', 'value' => 'Dev Laravel', 'category' => 'profession', 'score' => 1.0],
        ]);

        $agent = new SmartContextAgent();
        $removed = $agent->forgetCategory($this->testPhone, 'goal');
        $this->assertEquals(0, $removed);
    }

    public function test_summarize_profile_shows_skill_category(): void
    {
        $store = new ContextStore();
        $store->store($this->testPhone, [
            ['key' => 'tech_stack', 'value' => 'Laravel, PostgreSQL', 'category' => 'skill', 'score' => 1.0],
        ]);

        $agent = new SmartContextAgent();
        $summary = $agent->summarizeProfile($this->testPhone);

        $this->assertStringContainsString('Competences', $summary);
        $this->assertStringContainsString('Laravel, PostgreSQL', $summary);
    }

    public function test_summarize_profile_marks_low_confidence_facts(): void
    {
        $store = new ContextStore();
        $store->store($this->testPhone, [
            ['key' => 'location', 'value' => 'Quelque part en France', 'category' => 'personal', 'score' => 0.4],
        ]);

        $agent = new SmartContextAgent();
        $summary = $agent->summarizeProfile($this->testPhone);

        $this->assertStringContainsString('_(?)', $summary);
    }

    public function test_smart_context_agent_skips_numeric_only_messages(): void
    {
        $agent = new SmartContextAgent();
        $context = $this->makeContext('123456789012');

        $result = $agent->handle($context);

        $this->assertEquals('silent', $result->action);
        $this->assertEquals('numeric_only_skipped', $result->metadata['reason']);
    }

    // ── New capabilities v1.3.0 ───────────────────────────────────────────────

    public function test_smart_context_agent_skips_greeting_noise(): void
    {
        $agent = new SmartContextAgent();

        foreach (['bonjour', 'salut', 'ok', 'merci', 'super', 'cool'] as $greeting) {
            $context = $this->makeContext($greeting);
            $result  = $agent->handle($context);
            $this->assertEquals('silent', $result->action, "Expected silent for greeting: {$greeting}");
            $this->assertEquals('noise_skipped', $result->metadata['reason'] ?? null, "Expected noise_skipped for: {$greeting}");
        }
    }

    public function test_smart_context_agent_skips_pure_url(): void
    {
        $agent   = new SmartContextAgent();
        $context = $this->makeContext('https://example.com/some/path');
        $result  = $agent->handle($context);

        $this->assertEquals('silent', $result->action);
        $this->assertEquals('noise_skipped', $result->metadata['reason']);
    }

    public function test_get_recent_facts_returns_sorted_by_timestamp(): void
    {
        $store = new ContextStore();
        $now   = time();

        $key = "context_memory:{$this->testPhone}";
        $facts = [
            ['key' => 'old_fact',    'value' => 'Old',    'category' => 'general',    'score' => 0.5, 'timestamp' => $now - 300],
            ['key' => 'newest_fact', 'value' => 'Newest', 'category' => 'preference', 'score' => 0.8, 'timestamp' => $now],
            ['key' => 'middle_fact', 'value' => 'Middle', 'category' => 'skill',      'score' => 0.7, 'timestamp' => $now - 100],
        ];
        \Illuminate\Support\Facades\Redis::setex($key, 86400, serialize($facts));

        $agent  = new SmartContextAgent();
        $recent = $agent->getRecentFacts($this->testPhone, 2);

        $this->assertCount(2, $recent);
        $this->assertEquals('newest_fact', $recent[0]['key']);
        $this->assertEquals('middle_fact', $recent[1]['key']);
    }

    public function test_get_recent_facts_returns_empty_when_no_facts(): void
    {
        $agent = new SmartContextAgent();
        $this->assertEmpty($agent->getRecentFacts($this->testPhone));
    }

    public function test_get_recent_facts_respects_limit(): void
    {
        $store = new ContextStore();
        $facts = [];
        for ($i = 0; $i < 10; $i++) {
            $facts[] = ['key' => "fact_{$i}", 'value' => "Fact {$i}", 'category' => 'general', 'score' => 0.5, 'timestamp' => time() - $i];
        }
        $key = "context_memory:{$this->testPhone}";
        \Illuminate\Support\Facades\Redis::setex($key, 86400, serialize($facts));

        $agent  = new SmartContextAgent();
        $recent = $agent->getRecentFacts($this->testPhone, 3);

        $this->assertCount(3, $recent);
    }

    public function test_get_tags_compact_returns_empty_for_no_facts(): void
    {
        $agent = new SmartContextAgent();
        $this->assertEquals('', $agent->getTagsCompact($this->testPhone));
    }

    public function test_get_tags_compact_returns_profession_and_skill_facts(): void
    {
        $store = new ContextStore();
        $store->store($this->testPhone, [
            ['key' => 'profession', 'value' => 'Dev backend',       'category' => 'profession', 'score' => 1.0],
            ['key' => 'tech_stack', 'value' => 'Laravel, Vue.js',   'category' => 'skill',      'score' => 0.9],
            ['key' => 'location',   'value' => 'Paris',              'category' => 'personal',   'score' => 0.9],
        ]);

        $agent  = new SmartContextAgent();
        $tags   = $agent->getTagsCompact($this->testPhone);

        $this->assertStringContainsString('Dev backend', $tags);
        $this->assertStringContainsString('Laravel, Vue.js', $tags);
        // personal facts should not appear
        $this->assertStringNotContainsString('Paris', $tags);
    }

    public function test_get_tags_compact_excludes_low_confidence_facts(): void
    {
        $store = new ContextStore();
        $store->store($this->testPhone, [
            ['key' => 'profession',    'value' => 'Dev Laravel',  'category' => 'profession', 'score' => 1.0],
            ['key' => 'uncertain_job', 'value' => 'Peut-etre CTO', 'category' => 'profession', 'score' => 0.4],
        ]);

        $agent = new SmartContextAgent();
        $tags  = $agent->getTagsCompact($this->testPhone);

        $this->assertStringContainsString('Dev Laravel', $tags);
        $this->assertStringNotContainsString('Peut-etre CTO', $tags);
    }

    public function test_get_tags_compact_truncates_long_values(): void
    {
        $store = new ContextStore();
        $store->store($this->testPhone, [
            ['key' => 'project', 'value' => 'Un projet tres long avec beaucoup de mots qui depasse les quarante caracteres', 'category' => 'project', 'score' => 0.9],
        ]);

        $agent = new SmartContextAgent();
        $tags  = $agent->getTagsCompact($this->testPhone);

        $this->assertLessThanOrEqual(43, mb_strlen($tags)); // 40 chars + "..."
        $this->assertStringContainsString('...', $tags);
    }

    // ── New capabilities v1.4.0 ───────────────────────────────────────────────

    public function test_search_facts_finds_by_value_substring(): void
    {
        $store = new ContextStore();
        $store->store($this->testPhone, [
            ['key' => 'profession', 'value' => 'Developpeur Laravel senior', 'category' => 'profession', 'score' => 1.0],
            ['key' => 'location',   'value' => 'Habite a Paris',             'category' => 'personal',   'score' => 0.9],
            ['key' => 'tech_stack', 'value' => 'Laravel, Vue.js',            'category' => 'skill',      'score' => 0.9],
        ]);

        $agent  = new SmartContextAgent();
        $result = $agent->searchFacts($this->testPhone, 'Laravel');

        $this->assertNotEmpty($result);
        // Both "profession" and "tech_stack" contain "Laravel" in value
        $keys = array_column($result, 'key');
        $this->assertContains('profession', $keys);
        $this->assertContains('tech_stack', $keys);
    }

    public function test_search_facts_returns_empty_when_no_match(): void
    {
        $store = new ContextStore();
        $store->store($this->testPhone, [
            ['key' => 'profession', 'value' => 'Dev PHP', 'category' => 'profession', 'score' => 1.0],
        ]);

        $agent  = new SmartContextAgent();
        $result = $agent->searchFacts($this->testPhone, 'quantum physics');
        $this->assertEmpty($result);
    }

    public function test_search_facts_returns_empty_for_empty_query(): void
    {
        $store = new ContextStore();
        $store->store($this->testPhone, [
            ['key' => 'profession', 'value' => 'Dev PHP', 'category' => 'profession', 'score' => 1.0],
        ]);

        $agent  = new SmartContextAgent();
        $result = $agent->searchFacts($this->testPhone, '   ');
        $this->assertEmpty($result);
    }

    public function test_search_facts_returns_empty_when_no_facts(): void
    {
        $agent  = new SmartContextAgent();
        $result = $agent->searchFacts($this->testPhone, 'Laravel');
        $this->assertEmpty($result);
    }

    public function test_get_high_confidence_facts_filters_by_score(): void
    {
        $store = new ContextStore();
        $store->store($this->testPhone, [
            ['key' => 'profession',    'value' => 'Dev Laravel',    'category' => 'profession', 'score' => 1.0],
            ['key' => 'uncertain_loc', 'value' => 'Peut-etre Lyon', 'category' => 'personal',   'score' => 0.4],
            ['key' => 'tech_stack',    'value' => 'Laravel, Vue.js','category' => 'skill',       'score' => 0.8],
        ]);

        $agent  = new SmartContextAgent();
        $result = $agent->getHighConfidenceFacts($this->testPhone, 0.7);

        $keys = array_column($result, 'key');
        $this->assertContains('profession', $keys);
        $this->assertContains('tech_stack', $keys);
        $this->assertNotContains('uncertain_loc', $keys);
    }

    public function test_get_high_confidence_facts_returns_empty_when_none_qualify(): void
    {
        $store = new ContextStore();
        $store->store($this->testPhone, [
            ['key' => 'vague', 'value' => 'Quelque chose', 'category' => 'general', 'score' => 0.3],
        ]);

        $agent  = new SmartContextAgent();
        $result = $agent->getHighConfidenceFacts($this->testPhone, 0.7);
        $this->assertEmpty($result);
    }

    public function test_get_high_confidence_facts_sorted_by_score_descending(): void
    {
        $store = new ContextStore();
        $store->store($this->testPhone, [
            ['key' => 'fact_a', 'value' => 'A', 'category' => 'general', 'score' => 0.8],
            ['key' => 'fact_b', 'value' => 'B', 'category' => 'general', 'score' => 1.0],
            ['key' => 'fact_c', 'value' => 'C', 'category' => 'general', 'score' => 0.9],
        ]);

        $agent  = new SmartContextAgent();
        $result = $agent->getHighConfidenceFacts($this->testPhone, 0.7);

        $this->assertCount(3, $result);
        $this->assertEquals('fact_b', $result[0]['key']); // score 1.0 first
        $this->assertEquals('fact_c', $result[1]['key']); // score 0.9 second
    }

    public function test_get_profile_stats_includes_confidence_breakdown(): void
    {
        $store = new ContextStore();
        $store->store($this->testPhone, [
            ['key' => 'profession', 'value' => 'Dev Laravel', 'category' => 'profession', 'score' => 1.0, 'timestamp' => time()],
            ['key' => 'vague',      'value' => 'Quelque chose','category' => 'general',   'score' => 0.4, 'timestamp' => time()],
        ]);

        $agent = new SmartContextAgent();
        $stats = $agent->getProfileStats($this->testPhone);

        $this->assertArrayHasKey('high_confidence', $stats);
        $this->assertArrayHasKey('low_confidence', $stats);
        $this->assertEquals(1, $stats['high_confidence']); // score 1.0 >= 0.7
        $this->assertEquals(1, $stats['low_confidence']);  // score 0.4 < 0.7
    }

    public function test_summarize_profile_marks_stale_facts(): void
    {
        $store = new ContextStore();
        $key = "context_memory:{$this->testPhone}";
        $oldTimestamp = time() - (86400 * 35); // 35 days ago = stale

        $facts = [
            ['key' => 'profession', 'value' => 'Dev Laravel', 'category' => 'profession', 'score' => 1.0, 'timestamp' => $oldTimestamp],
        ];
        \Illuminate\Support\Facades\Redis::setex($key, 86400, serialize($facts));

        $agent   = new SmartContextAgent();
        $summary = $agent->summarizeProfile($this->testPhone);

        $this->assertStringContainsString('_(ancien)_', $summary);
    }

    // ── New capabilities v1.5.0 ───────────────────────────────────────────────

    public function test_detect_profile_command_view(): void
    {
        $agent = new SmartContextAgent();

        foreach (['mon profil', 'voir profil', 'mon contexte', 'MON PROFIL'] as $cmd) {
            $this->assertEquals('view', $agent->detectProfileCommand($cmd), "Expected 'view' for: {$cmd}");
        }
    }

    public function test_detect_profile_command_stats(): void
    {
        $agent = new SmartContextAgent();

        foreach (['profil stats', 'profil stat', 'stats profil'] as $cmd) {
            $this->assertEquals('stats', $agent->detectProfileCommand($cmd), "Expected 'stats' for: {$cmd}");
        }
    }

    public function test_detect_profile_command_reset(): void
    {
        $agent = new SmartContextAgent();

        foreach (['profil reset', 'reset profil', 'oublie mon profil', 'efface profil'] as $cmd) {
            $this->assertEquals('reset', $agent->detectProfileCommand($cmd), "Expected 'reset' for: {$cmd}");
        }
    }

    public function test_detect_profile_command_returns_null_for_regular_message(): void
    {
        $agent = new SmartContextAgent();
        $this->assertNull($agent->detectProfileCommand('je suis developpeur Laravel'));
        $this->assertNull($agent->detectProfileCommand('bonjour comment tu vas'));
    }

    public function test_profile_view_command_returns_reply(): void
    {
        \Illuminate\Support\Facades\Http::fake(['*' => \Illuminate\Support\Facades\Http::response(['success' => true], 200)]);

        $store = new ContextStore();
        $store->store($this->testPhone, [
            ['key' => 'profession', 'value' => 'Dev Laravel', 'category' => 'profession', 'score' => 1.0],
        ]);

        $agent   = new SmartContextAgent();
        $context = $this->makeContext('mon profil');
        $result  = $agent->handle($context);

        $this->assertEquals('reply', $result->action);
        $this->assertEquals('profile_view', $result->metadata['command']);
        $this->assertStringContainsString('profil contextuel', $result->reply);
    }

    public function test_profile_view_command_empty_profile(): void
    {
        \Illuminate\Support\Facades\Http::fake(['*' => \Illuminate\Support\Facades\Http::response(['success' => true], 200)]);

        $agent   = new SmartContextAgent();
        $context = $this->makeContext('mon profil');
        $result  = $agent->handle($context);

        $this->assertEquals('reply', $result->action);
        $this->assertStringContainsString('Aucun fait', $result->reply);
    }

    public function test_profile_stats_command_returns_reply(): void
    {
        \Illuminate\Support\Facades\Http::fake(['*' => \Illuminate\Support\Facades\Http::response(['success' => true], 200)]);

        $store = new ContextStore();
        $store->store($this->testPhone, [
            ['key' => 'profession', 'value' => 'Dev Laravel', 'category' => 'profession', 'score' => 1.0],
            ['key' => 'tech_stack', 'value' => 'Laravel, Vue.js', 'category' => 'skill', 'score' => 0.9],
        ]);

        $agent   = new SmartContextAgent();
        $context = $this->makeContext('profil stats');
        $result  = $agent->handle($context);

        $this->assertEquals('reply', $result->action);
        $this->assertEquals('profile_stats', $result->metadata['command']);
        $this->assertStringContainsString('Statistiques', $result->reply);
        $this->assertStringContainsString('2', $result->reply);
    }

    public function test_profile_reset_command_clears_facts(): void
    {
        \Illuminate\Support\Facades\Http::fake(['*' => \Illuminate\Support\Facades\Http::response(['success' => true], 200)]);

        $store = new ContextStore();
        $store->store($this->testPhone, [
            ['key' => 'profession', 'value' => 'Dev Laravel', 'category' => 'profession', 'score' => 1.0],
        ]);

        $agent   = new SmartContextAgent();
        $context = $this->makeContext('profil reset');
        $result  = $agent->handle($context);

        $this->assertEquals('reply', $result->action);
        $this->assertEquals('profile_reset', $result->metadata['command']);
        $this->assertEquals(1, $result->metadata['facts_removed']);
        $this->assertEmpty($agent->getStoredContext($this->testPhone));
    }

    public function test_profile_reset_command_on_empty_profile(): void
    {
        \Illuminate\Support\Facades\Http::fake(['*' => \Illuminate\Support\Facades\Http::response(['success' => true], 200)]);

        $agent   = new SmartContextAgent();
        $context = $this->makeContext('profil reset');
        $result  = $agent->handle($context);

        $this->assertEquals('reply', $result->action);
        $this->assertEquals(0, $result->metadata['facts_removed']);
        $this->assertStringContainsString('deja vide', $result->reply);
    }

    public function test_detect_profile_gaps_returns_empty_when_all_categories_filled(): void
    {
        $store = new ContextStore();
        $store->store($this->testPhone, [
            ['key' => 'profession',   'value' => 'Dev',    'category' => 'profession', 'score' => 1.0],
            ['key' => 'tech_stack',   'value' => 'PHP',    'category' => 'skill',      'score' => 0.9],
            ['key' => 'career_goal',  'value' => 'CTO',    'category' => 'goal',       'score' => 0.8],
            ['key' => 'location',     'value' => 'Paris',  'category' => 'personal',   'score' => 0.9],
        ]);

        $agent = new SmartContextAgent();
        $gaps  = $agent->detectProfileGaps($this->testPhone);

        $this->assertEmpty($gaps);
    }

    public function test_detect_profile_gaps_returns_missing_priority_categories(): void
    {
        $store = new ContextStore();
        $store->store($this->testPhone, [
            ['key' => 'profession', 'value' => 'Dev Laravel', 'category' => 'profession', 'score' => 1.0],
        ]);

        $agent = new SmartContextAgent();
        $gaps  = $agent->detectProfileGaps($this->testPhone);

        // skill, goal, personal should be missing
        $this->assertContains('Competences', $gaps);
        $this->assertContains('Objectifs', $gaps);
        $this->assertContains('Infos personnelles', $gaps);
        // profession is filled
        $this->assertNotContains('Profession', $gaps);
    }

    public function test_detect_profile_gaps_returns_all_when_empty_profile(): void
    {
        $agent = new SmartContextAgent();
        $gaps  = $agent->detectProfileGaps($this->testPhone);

        // All priority categories are missing
        $this->assertContains('Profession', $gaps);
        $this->assertContains('Competences', $gaps);
    }

    public function test_compute_decayed_score_no_decay_for_fresh_fact(): void
    {
        $agent = new SmartContextAgent();
        $fact  = ['score' => 0.9, 'timestamp' => time()];

        $this->assertEquals(0.9, $agent->computeDecayedScore($fact));
    }

    public function test_compute_decayed_score_applies_decay_for_old_fact(): void
    {
        $agent = new SmartContextAgent();
        // 90 days old (30 days past the 60-day decay start)
        $fact  = ['score' => 0.9, 'timestamp' => time() - (86400 * 90)];

        $decayed = $agent->computeDecayedScore($fact);
        $this->assertLessThan(0.9, $decayed);
        $this->assertGreaterThanOrEqual(0.3, $decayed); // never below MIN_SCORE
    }

    public function test_compute_decayed_score_does_not_go_below_min_score(): void
    {
        $agent = new SmartContextAgent();
        // 200 days old (well past decay start)
        $fact  = ['score' => 0.4, 'timestamp' => time() - (86400 * 200)];

        $decayed = $agent->computeDecayedScore($fact);
        $this->assertGreaterThanOrEqual(0.3, $decayed);
    }

    public function test_compute_decayed_score_returns_score_when_no_timestamp(): void
    {
        $agent = new SmartContextAgent();
        $fact  = ['score' => 0.8]; // no timestamp

        $this->assertEquals(0.8, $agent->computeDecayedScore($fact));
    }

    public function test_summarize_profile_includes_icons(): void
    {
        $store = new ContextStore();
        $store->store($this->testPhone, [
            ['key' => 'profession', 'value' => 'Dev Laravel', 'category' => 'profession', 'score' => 1.0],
        ]);

        $agent   = new SmartContextAgent();
        $summary = $agent->summarizeProfile($this->testPhone);

        $this->assertStringContainsString('💼', $summary);
    }

    public function test_profile_stats_command_includes_gaps(): void
    {
        \Illuminate\Support\Facades\Http::fake(['*' => \Illuminate\Support\Facades\Http::response(['success' => true], 200)]);

        $store = new ContextStore();
        $store->store($this->testPhone, [
            ['key' => 'profession', 'value' => 'Dev', 'category' => 'profession', 'score' => 1.0],
        ]);

        $agent   = new SmartContextAgent();
        $context = $this->makeContext('profil stats');
        $result  = $agent->handle($context);

        // Should mention missing categories since skill/goal/personal are empty
        $this->assertStringContainsString('manquantes', $result->reply);
    }

    // ── New capabilities v1.6.0 ───────────────────────────────────────────────

    public function test_detect_profile_command_search(): void
    {
        $agent = new SmartContextAgent();

        foreach (['profil chercher Laravel', 'profil cherche php', 'chercher dans mon profil python'] as $cmd) {
            $this->assertEquals('search', $agent->detectProfileCommand($cmd), "Expected 'search' for: {$cmd}");
        }
    }

    public function test_detect_profile_command_forget_category(): void
    {
        $agent = new SmartContextAgent();

        foreach (['oublie categorie competences', 'efface mes preferences', 'supprime mes projets', 'oublie mes objectifs'] as $cmd) {
            $this->assertEquals('forget_category', $agent->detectProfileCommand($cmd), "Expected 'forget_category' for: {$cmd}");
        }
    }

    public function test_detect_profile_command_remember(): void
    {
        $agent = new SmartContextAgent();

        foreach (['retiens que je suis dev senior', 'note: je travaille a Paris', 'rappelle-toi que j\'utilise Rust'] as $cmd) {
            $this->assertEquals('remember', $agent->detectProfileCommand($cmd), "Expected 'remember' for: {$cmd}");
        }
    }

    public function test_detect_profile_command_help(): void
    {
        $agent = new SmartContextAgent();

        foreach (['profil aide', 'profil help', 'aide profil'] as $cmd) {
            $this->assertEquals('help', $agent->detectProfileCommand($cmd), "Expected 'help' for: {$cmd}");
        }
    }

    public function test_profile_search_command_finds_facts(): void
    {
        \Illuminate\Support\Facades\Http::fake(['*' => \Illuminate\Support\Facades\Http::response(['success' => true], 200)]);

        $store = new ContextStore();
        $store->store($this->testPhone, [
            ['key' => 'tech_stack', 'value' => 'Laravel, Vue.js', 'category' => 'skill', 'score' => 0.9],
            ['key' => 'profession', 'value' => 'Dev backend',     'category' => 'profession', 'score' => 1.0],
        ]);

        $agent   = new SmartContextAgent();
        $context = $this->makeContext('profil chercher Laravel');
        $result  = $agent->handle($context);

        $this->assertEquals('reply', $result->action);
        $this->assertEquals('profile_search', $result->metadata['command']);
        $this->assertStringContainsString('Laravel', $result->reply);
        $this->assertGreaterThan(0, $result->metadata['results_count']);
    }

    public function test_profile_search_command_no_query_returns_error(): void
    {
        \Illuminate\Support\Facades\Http::fake(['*' => \Illuminate\Support\Facades\Http::response(['success' => true], 200)]);

        $agent   = new SmartContextAgent();
        $context = $this->makeContext('profil chercher');
        $result  = $agent->handle($context);

        $this->assertEquals('reply', $result->action);
        $this->assertEquals('profile_search', $result->metadata['command']);
        $this->assertEquals('no_query', $result->metadata['error']);
    }

    public function test_profile_search_command_no_match_returns_reply(): void
    {
        \Illuminate\Support\Facades\Http::fake(['*' => \Illuminate\Support\Facades\Http::response(['success' => true], 200)]);

        $store = new ContextStore();
        $store->store($this->testPhone, [
            ['key' => 'profession', 'value' => 'Dev PHP', 'category' => 'profession', 'score' => 1.0],
        ]);

        $agent   = new SmartContextAgent();
        $context = $this->makeContext('profil chercher quantumphysics');
        $result  = $agent->handle($context);

        $this->assertEquals('reply', $result->action);
        $this->assertStringContainsString('Aucun fait', $result->reply);
        $this->assertEquals(0, $result->metadata['results_count']);
    }

    public function test_forget_category_command_removes_matching_facts(): void
    {
        \Illuminate\Support\Facades\Http::fake(['*' => \Illuminate\Support\Facades\Http::response(['success' => true], 200)]);

        $store = new ContextStore();
        $store->store($this->testPhone, [
            ['key' => 'tech_stack',   'value' => 'Laravel', 'category' => 'skill',      'score' => 0.9],
            ['key' => 'learning_py',  'value' => 'Python',  'category' => 'skill',      'score' => 0.7],
            ['key' => 'profession',   'value' => 'Dev',     'category' => 'profession', 'score' => 1.0],
        ]);

        $agent   = new SmartContextAgent();
        $context = $this->makeContext('oublie categorie competences');
        $result  = $agent->handle($context);

        $this->assertEquals('reply', $result->action);
        $this->assertEquals('forget_category', $result->metadata['command']);
        $this->assertEquals('skill', $result->metadata['category']);
        $this->assertEquals(2, $result->metadata['removed']);

        $remaining = $agent->getStoredContext($this->testPhone);
        $this->assertCount(1, $remaining);
        $this->assertEquals('profession', $remaining[0]['key']);
    }

    public function test_forget_category_command_unknown_category_returns_error(): void
    {
        \Illuminate\Support\Facades\Http::fake(['*' => \Illuminate\Support\Facades\Http::response(['success' => true], 200)]);

        $agent   = new SmartContextAgent();
        $context = $this->makeContext('oublie categorie inexistante_xyz');
        $result  = $agent->handle($context);

        $this->assertEquals('reply', $result->action);
        $this->assertEquals('unknown_category', $result->metadata['error']);
    }

    public function test_forget_category_via_french_word_preference(): void
    {
        \Illuminate\Support\Facades\Http::fake(['*' => \Illuminate\Support\Facades\Http::response(['success' => true], 200)]);

        $store = new ContextStore();
        $store->store($this->testPhone, [
            ['key' => 'hobbies', 'value' => 'Randonnee', 'category' => 'preference', 'score' => 0.9],
            ['key' => 'sport',   'value' => 'Tennis',    'category' => 'preference', 'score' => 0.8],
        ]);

        $agent   = new SmartContextAgent();
        $context = $this->makeContext('efface mes preferences');
        $result  = $agent->handle($context);

        $this->assertEquals('forget_category', $result->metadata['command']);
        $this->assertEquals('preference', $result->metadata['category']);
        $this->assertEquals(2, $result->metadata['removed']);
    }

    public function test_profile_help_command_returns_commands_list(): void
    {
        \Illuminate\Support\Facades\Http::fake(['*' => \Illuminate\Support\Facades\Http::response(['success' => true], 200)]);

        $agent   = new SmartContextAgent();
        $context = $this->makeContext('profil aide');
        $result  = $agent->handle($context);

        $this->assertEquals('reply', $result->action);
        $this->assertEquals('profile_help', $result->metadata['command']);
        $this->assertStringContainsString('mon profil', $result->reply);
        $this->assertStringContainsString('retiens que', $result->reply);
        $this->assertStringContainsString('oublie categorie', $result->reply);
    }

    public function test_profile_stats_shows_date_info(): void
    {
        \Illuminate\Support\Facades\Http::fake(['*' => \Illuminate\Support\Facades\Http::response(['success' => true], 200)]);

        $store = new ContextStore();
        $key = "context_memory:{$this->testPhone}";
        \Illuminate\Support\Facades\Redis::setex($key, 86400, serialize([
            ['key' => 'profession', 'value' => 'Dev', 'category' => 'profession', 'score' => 1.0, 'timestamp' => time() - 3600],
        ]));

        $agent   = new SmartContextAgent();
        $context = $this->makeContext('profil stats');
        $result  = $agent->handle($context);

        $this->assertEquals('reply', $result->action);
        // Should contain "Premier fait" or "Dernier fait" with human-readable date
        $this->assertStringContainsString('fait', $result->reply);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function makeContext(string $body): AgentContext
    {
        $user = User::factory()->create();
        $agent = Agent::factory()->create(['user_id' => $user->id]);
        $session = AgentSession::create([
            'agent_id'     => $agent->id,
            'session_key'  => AgentSession::keyFor($agent->id, 'whatsapp', $this->testPhone),
            'channel'      => 'whatsapp',
            'peer_id'      => $this->testPhone,
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
        );
    }
}
