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

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function makeContext(string $body): AgentContext
    {
        $user = User::factory()->create();
        $agent = Agent::factory()->create(['user_id' => $user->id]);
        $session = AgentSession::create([
            'agent_id' => $agent->id,
            'phone' => $this->testPhone,
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
