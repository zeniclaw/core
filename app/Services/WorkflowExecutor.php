<?php

namespace App\Services;

use App\Models\Workflow;
use App\Services\Agents\AgentResult;
use Illuminate\Support\Facades\Log;

class WorkflowExecutor
{
    private AgentOrchestrator $orchestrator;

    public function __construct(AgentOrchestrator $orchestrator)
    {
        $this->orchestrator = $orchestrator;
    }

    /**
     * Execute a workflow by running each step sequentially.
     * Each step's output is passed as context to the next step.
     */
    public function execute(Workflow $workflow, AgentContext $baseContext): array
    {
        $steps = $workflow->steps ?? [];
        $results = [];
        $previousOutput = null;
        $failed = false;

        foreach ($steps as $index => $step) {
            $stepNumber = $index + 1;
            $agentName = $step['agent'] ?? null;
            $message = $step['message'] ?? '';
            $condition = $step['condition'] ?? null;

            // Evaluate condition if present
            if ($condition && !$this->evaluateCondition($condition, $previousOutput)) {
                $results[] = [
                    'step' => $stepNumber,
                    'agent' => $agentName,
                    'status' => 'skipped',
                    'reason' => "Condition not met: {$condition}",
                ];
                continue;
            }

            // Inject previous output into message if placeholder exists
            if ($previousOutput && str_contains($message, '{previous}')) {
                $message = str_replace('{previous}', $previousOutput, $message);
            } elseif ($previousOutput && $stepNumber > 1) {
                $message = $message . "\n\nContexte de l'etape precedente: " . $previousOutput;
            }

            try {
                // Create a new context with the step's message
                $stepContext = new AgentContext(
                    agent: $baseContext->agent,
                    session: $baseContext->session,
                    from: $baseContext->from,
                    senderName: $baseContext->senderName,
                    body: $message,
                    hasMedia: false,
                    mediaUrl: null,
                    mimetype: null,
                    media: null,
                );

                $result = $this->orchestrator->process($stepContext);

                $results[] = [
                    'step' => $stepNumber,
                    'agent' => $agentName ?? 'auto',
                    'status' => 'success',
                    'reply' => $result->reply,
                ];

                $previousOutput = $result->reply;
            } catch (\Throwable $e) {
                Log::error("WorkflowExecutor: step {$stepNumber} failed", [
                    'workflow' => $workflow->name,
                    'error' => $e->getMessage(),
                ]);

                $results[] = [
                    'step' => $stepNumber,
                    'agent' => $agentName ?? 'auto',
                    'status' => 'error',
                    'error' => $e->getMessage(),
                ];

                $failed = true;

                // Check if step has on_error handling
                $onError = $step['on_error'] ?? 'stop';
                if ($onError === 'stop') {
                    break;
                }
                // on_error = 'continue' → proceed to next step
            }
        }

        // Update workflow stats
        $workflow->update([
            'last_run_at' => now(),
            'run_count' => $workflow->run_count + 1,
        ]);

        return [
            'workflow' => $workflow->name,
            'status' => $failed ? 'partial' : 'completed',
            'steps_executed' => count($results),
            'steps_total' => count($steps),
            'results' => $results,
        ];
    }

    /**
     * Evaluate a simple condition against previous output.
     */
    private function evaluateCondition(string $condition, ?string $previousOutput): bool
    {
        if (!$previousOutput) {
            return $condition === 'always';
        }

        $lower = mb_strtolower($previousOutput);

        // Simple keyword conditions
        if (str_starts_with($condition, 'contains:')) {
            $keyword = mb_strtolower(trim(substr($condition, 9)));
            return str_contains($lower, $keyword);
        }

        if (str_starts_with($condition, 'not_contains:')) {
            $keyword = mb_strtolower(trim(substr($condition, 13)));
            return !str_contains($lower, $keyword);
        }

        if ($condition === 'success') {
            return !str_contains($lower, 'erreur') && !str_contains($lower, 'error') && !str_contains($lower, 'echoue');
        }

        if ($condition === 'always') {
            return true;
        }

        return true;
    }

    /**
     * Format workflow execution results as a readable message.
     */
    public static function formatResults(array $executionResult): string
    {
        $lines = [];
        $status = $executionResult['status'] === 'completed' ? 'Termine' : 'Partiel';
        $lines[] = "Workflow \"{$executionResult['workflow']}\" — {$status}";
        $lines[] = "Etapes: {$executionResult['steps_executed']}/{$executionResult['steps_total']}";
        $lines[] = '';

        foreach ($executionResult['results'] as $r) {
            $icon = match ($r['status']) {
                'success' => '[OK]',
                'skipped' => '[SKIP]',
                'error' => '[ERR]',
                default => '[?]',
            };

            $lines[] = "{$icon} Etape {$r['step']} ({$r['agent']})";

            if (!empty($r['reply'])) {
                $lines[] = "  → " . mb_substr($r['reply'], 0, 200);
            }
            if (!empty($r['reason'])) {
                $lines[] = "  → {$r['reason']}";
            }
            if (!empty($r['error'])) {
                $lines[] = "  → Erreur: {$r['error']}";
            }
        }

        return implode("\n", $lines);
    }
}
