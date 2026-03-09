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
        $context = $this->makeContext('ok');

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

    public function test_smart_context_agent_version_is_1_2_0(): void
    {
        $agent = new SmartContextAgent();
        $this->assertEquals('1.2.0', $agent->version());
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
