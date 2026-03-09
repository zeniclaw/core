<?php

namespace Tests\Unit\Services;

use App\Models\Workflow;
use App\Services\AgentContext;
use App\Services\AgentOrchestrator;
use App\Services\Agents\AgentResult;
use App\Services\WorkflowExecutor;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;

class WorkflowExecutorTest extends TestCase
{
    use RefreshDatabase;

    private function makeExecutor(?AgentOrchestrator $orchestrator = null): WorkflowExecutor
    {
        $orchestrator = $orchestrator ?? Mockery::mock(AgentOrchestrator::class);
        return new WorkflowExecutor($orchestrator);
    }

    private function makeContext(): AgentContext
    {
        $agent = \App\Models\Agent::factory()->create();
        $session = \App\Models\AgentSession::factory()->create(['agent_id' => $agent->id]);

        return new AgentContext(
            agent: $agent,
            session: $session,
            from: '33612345678@s.whatsapp.net',
            senderName: 'TestUser',
            body: 'test',
            hasMedia: false,
            mediaUrl: null,
            mimetype: null,
            media: null,
        );
    }

    /** @test */
    public function it_executes_workflow_steps_sequentially()
    {
        $orchestrator = Mockery::mock(AgentOrchestrator::class);
        $orchestrator->shouldReceive('process')
            ->times(3)
            ->andReturn(
                AgentResult::reply('Result step 1'),
                AgentResult::reply('Result step 2'),
                AgentResult::reply('Result step 3')
            );

        $executor = new WorkflowExecutor($orchestrator);
        $context = $this->makeContext();

        $workflow = Workflow::create([
            'user_phone' => '33612345678@s.whatsapp.net',
            'name' => 'test-workflow',
            'steps' => [
                ['message' => 'Do step 1', 'agent' => 'todo'],
                ['message' => 'Do step 2', 'agent' => 'reminder'],
                ['message' => 'Do step 3', 'agent' => 'chat'],
            ],
        ]);

        $result = $executor->execute($workflow, $context);

        $this->assertEquals('completed', $result['status']);
        $this->assertEquals(3, $result['steps_executed']);
        $this->assertEquals(3, $result['steps_total']);
        $this->assertCount(3, $result['results']);
        $this->assertEquals('success', $result['results'][0]['status']);
        $this->assertEquals('Result step 1', $result['results'][0]['reply']);
    }

    /** @test */
    public function it_passes_previous_output_to_next_step()
    {
        $orchestrator = Mockery::mock(AgentOrchestrator::class);
        $orchestrator->shouldReceive('process')
            ->twice()
            ->andReturnUsing(function (AgentContext $ctx) {
                return AgentResult::reply('Processed: ' . $ctx->body);
            });

        $executor = new WorkflowExecutor($orchestrator);
        $context = $this->makeContext();

        $workflow = Workflow::create([
            'user_phone' => '33612345678@s.whatsapp.net',
            'name' => 'context-pass-test',
            'steps' => [
                ['message' => 'First action', 'agent' => 'todo'],
                ['message' => 'Based on: {previous}', 'agent' => 'chat'],
            ],
        ]);

        $result = $executor->execute($workflow, $context);

        $this->assertEquals('completed', $result['status']);
        $this->assertEquals(2, $result['steps_executed']);
    }

    /** @test */
    public function it_skips_steps_when_condition_not_met()
    {
        $orchestrator = Mockery::mock(AgentOrchestrator::class);
        $orchestrator->shouldReceive('process')
            ->once()
            ->andReturn(AgentResult::reply('No errors here'));

        $executor = new WorkflowExecutor($orchestrator);
        $context = $this->makeContext();

        $workflow = Workflow::create([
            'user_phone' => '33612345678@s.whatsapp.net',
            'name' => 'condition-test',
            'steps' => [
                ['message' => 'Check status', 'agent' => 'chat'],
                ['message' => 'Handle error', 'agent' => 'chat', 'condition' => 'contains:erreur'],
            ],
        ]);

        $result = $executor->execute($workflow, $context);

        $this->assertEquals('completed', $result['status']);
        $this->assertEquals(2, $result['steps_executed']);
        $this->assertEquals('success', $result['results'][0]['status']);
        $this->assertEquals('skipped', $result['results'][1]['status']);
    }

    /** @test */
    public function it_stops_on_error_by_default()
    {
        $orchestrator = Mockery::mock(AgentOrchestrator::class);
        $orchestrator->shouldReceive('process')
            ->once()
            ->andThrow(new \RuntimeException('Step failed'));

        $executor = new WorkflowExecutor($orchestrator);
        $context = $this->makeContext();

        $workflow = Workflow::create([
            'user_phone' => '33612345678@s.whatsapp.net',
            'name' => 'error-test',
            'steps' => [
                ['message' => 'Will fail', 'agent' => 'chat'],
                ['message' => 'Should not run', 'agent' => 'chat'],
            ],
        ]);

        $result = $executor->execute($workflow, $context);

        $this->assertEquals('partial', $result['status']);
        $this->assertEquals(1, $result['steps_executed']);
        $this->assertEquals('error', $result['results'][0]['status']);
    }

    /** @test */
    public function it_continues_on_error_when_configured()
    {
        $orchestrator = Mockery::mock(AgentOrchestrator::class);
        $orchestrator->shouldReceive('process')
            ->twice()
            ->andReturnUsing(function (AgentContext $ctx) {
                if (str_contains($ctx->body, 'fail')) {
                    throw new \RuntimeException('Intentional failure');
                }
                return AgentResult::reply('OK');
            });

        $executor = new WorkflowExecutor($orchestrator);
        $context = $this->makeContext();

        $workflow = Workflow::create([
            'user_phone' => '33612345678@s.whatsapp.net',
            'name' => 'continue-on-error-test',
            'steps' => [
                ['message' => 'This will fail', 'agent' => 'chat', 'on_error' => 'continue'],
                ['message' => 'This should still run', 'agent' => 'chat'],
            ],
        ]);

        $result = $executor->execute($workflow, $context);

        $this->assertEquals('partial', $result['status']);
        $this->assertEquals(2, $result['steps_executed']);
    }

    /** @test */
    public function it_updates_workflow_run_stats()
    {
        $orchestrator = Mockery::mock(AgentOrchestrator::class);
        $orchestrator->shouldReceive('process')
            ->once()
            ->andReturn(AgentResult::reply('Done'));

        $executor = new WorkflowExecutor($orchestrator);
        $context = $this->makeContext();

        $workflow = Workflow::create([
            'user_phone' => '33612345678@s.whatsapp.net',
            'name' => 'stats-test',
            'steps' => [
                ['message' => 'Single step', 'agent' => 'chat'],
            ],
        ]);

        $this->assertEquals(0, $workflow->run_count);
        $this->assertNull($workflow->last_run_at);

        $executor->execute($workflow, $context);

        $workflow->refresh();
        $this->assertEquals(1, $workflow->run_count);
        $this->assertNotNull($workflow->last_run_at);
    }

    /** @test */
    public function format_results_produces_readable_output()
    {
        $executionResult = [
            'workflow' => 'test-workflow',
            'status' => 'completed',
            'steps_executed' => 2,
            'steps_total' => 2,
            'results' => [
                ['step' => 1, 'agent' => 'todo', 'status' => 'success', 'reply' => 'Todos listed'],
                ['step' => 2, 'agent' => 'chat', 'status' => 'success', 'reply' => 'Summary done'],
            ],
        ];

        $output = WorkflowExecutor::formatResults($executionResult);

        $this->assertStringContainsString('test-workflow', $output);
        $this->assertStringContainsString('Termine', $output);
        $this->assertStringContainsString('[OK]', $output);
        $this->assertStringContainsString('Todos listed', $output);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
