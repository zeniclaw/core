<?php

namespace Tests\Unit;

use App\Services\ContextMemoryBridge;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class ContextMemoryBridgeTest extends TestCase
{
    private ContextMemoryBridge $bridge;
    private string $testUserId = 'test-user-123@s.whatsapp.net';

    protected function setUp(): void
    {
        parent::setUp();
        $this->bridge = ContextMemoryBridge::getInstance();
        // Clean up before each test
        $this->bridge->clearContext($this->testUserId);
    }

    protected function tearDown(): void
    {
        $this->bridge->clearContext($this->testUserId);
        parent::tearDown();
    }

    public function test_default_context_structure(): void
    {
        $context = $this->bridge->getContext($this->testUserId);

        $this->assertIsArray($context);
        $this->assertArrayHasKey('activeProjects', $context);
        $this->assertArrayHasKey('preferences', $context);
        $this->assertArrayHasKey('recentTags', $context);
        $this->assertArrayHasKey('conversationHistory', $context);
        $this->assertArrayHasKey('lastAgent', $context);
        $this->assertArrayHasKey('timeZone', $context);
        $this->assertEmpty($context['activeProjects']);
        $this->assertEmpty($context['recentTags']);
    }

    public function test_update_context_merges_data(): void
    {
        $this->bridge->updateContext($this->testUserId, [
            'preferences' => ['language' => 'fr'],
        ]);

        $context = $this->bridge->getContext($this->testUserId);
        $this->assertEquals('fr', $context['preferences']['language']);

        // Second update should merge, not overwrite
        $this->bridge->updateContext($this->testUserId, [
            'preferences' => ['timezone' => 'Europe/Paris'],
        ]);

        $context = $this->bridge->getContext($this->testUserId);
        $this->assertEquals('fr', $context['preferences']['language']);
        $this->assertEquals('Europe/Paris', $context['preferences']['timezone']);
    }

    public function test_add_active_project(): void
    {
        $this->bridge->addActiveProject($this->testUserId, 'ProjectA');
        $this->bridge->addActiveProject($this->testUserId, 'ProjectB');

        $context = $this->bridge->getContext($this->testUserId);
        $this->assertCount(2, $context['activeProjects']);
        // Most recent first
        $this->assertEquals('ProjectB', $context['activeProjects'][0]);
        $this->assertEquals('ProjectA', $context['activeProjects'][1]);
    }

    public function test_add_duplicate_project_moves_to_front(): void
    {
        $this->bridge->addActiveProject($this->testUserId, 'ProjectA');
        $this->bridge->addActiveProject($this->testUserId, 'ProjectB');
        $this->bridge->addActiveProject($this->testUserId, 'ProjectA');

        $context = $this->bridge->getContext($this->testUserId);
        $this->assertCount(2, $context['activeProjects']);
        $this->assertEquals('ProjectA', $context['activeProjects'][0]);
    }

    public function test_add_tags_deduplicates(): void
    {
        $this->bridge->addTags($this->testUserId, ['php', 'laravel']);
        $this->bridge->addTags($this->testUserId, ['laravel', 'redis']);

        $context = $this->bridge->getContext($this->testUserId);
        $tags = $context['recentTags'];

        $this->assertContains('php', $tags);
        $this->assertContains('laravel', $tags);
        $this->assertContains('redis', $tags);
        // No duplicates
        $this->assertEquals(count(array_unique($tags)), count($tags));
    }

    public function test_tags_limited_to_20(): void
    {
        $manyTags = [];
        for ($i = 0; $i < 25; $i++) {
            $manyTags[] = "tag_{$i}";
        }
        $this->bridge->addTags($this->testUserId, $manyTags);

        $context = $this->bridge->getContext($this->testUserId);
        $this->assertLessThanOrEqual(20, count($context['recentTags']));
    }

    public function test_set_last_agent(): void
    {
        $this->bridge->setLastAgent($this->testUserId, 'todo');

        $context = $this->bridge->getContext($this->testUserId);
        $this->assertEquals('todo', $context['lastAgent']['name']);
        $this->assertArrayHasKey('at', $context['lastAgent']);
    }

    public function test_add_conversation_entry(): void
    {
        $this->bridge->addConversationEntry($this->testUserId, 'chat', 'hello', 'Hi there!');
        $this->bridge->addConversationEntry($this->testUserId, 'todo', 'add task', 'Task added');

        $context = $this->bridge->getContext($this->testUserId);
        $this->assertCount(2, $context['conversationHistory']);
        $this->assertEquals('chat', $context['conversationHistory'][0]['agent']);
        $this->assertEquals('todo', $context['conversationHistory'][1]['agent']);
    }

    public function test_conversation_history_limited_to_10(): void
    {
        for ($i = 0; $i < 15; $i++) {
            $this->bridge->addConversationEntry($this->testUserId, 'chat', "msg {$i}", "reply {$i}");
        }

        $context = $this->bridge->getContext($this->testUserId);
        $this->assertCount(10, $context['conversationHistory']);
    }

    public function test_format_for_prompt_empty_context(): void
    {
        $result = $this->bridge->formatForPrompt($this->testUserId);
        $this->assertEmpty($result);
    }

    public function test_format_for_prompt_with_data(): void
    {
        $this->bridge->addActiveProject($this->testUserId, 'MyApp');
        $this->bridge->addTags($this->testUserId, ['api', 'rest']);
        $this->bridge->setLastAgent($this->testUserId, 'dev');

        $result = $this->bridge->formatForPrompt($this->testUserId);

        $this->assertStringContainsString('CONTEXTE PARTAGE', $result);
        $this->assertStringContainsString('MyApp', $result);
        $this->assertStringContainsString('api', $result);
        $this->assertStringContainsString('dev', $result);
    }

    public function test_clear_context(): void
    {
        $this->bridge->updateContext($this->testUserId, [
            'preferences' => ['lang' => 'fr'],
        ]);
        $this->assertTrue($this->bridge->hasContext($this->testUserId));

        $this->bridge->clearContext($this->testUserId);
        $this->assertFalse($this->bridge->hasContext($this->testUserId));
    }

    public function test_has_context(): void
    {
        $this->assertFalse($this->bridge->hasContext($this->testUserId));

        $this->bridge->updateContext($this->testUserId, ['preferences' => ['x' => 'y']]);
        $this->assertTrue($this->bridge->hasContext($this->testUserId));
    }

    public function test_get_section(): void
    {
        $this->bridge->addTags($this->testUserId, ['php', 'laravel']);

        $tags = $this->bridge->getSection($this->testUserId, 'recentTags');
        $this->assertIsArray($tags);
        $this->assertContains('php', $tags);

        $missing = $this->bridge->getSection($this->testUserId, 'nonexistent');
        $this->assertNull($missing);
    }
}
