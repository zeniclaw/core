<?php

namespace App\Services\Agents;

use App\Models\Workflow;
use App\Services\AgentContext;
use App\Services\AgentOrchestrator;
use App\Services\WorkflowExecutor;
use Illuminate\Support\Facades\Log;

class StreamlineAgent extends BaseAgent
{
    public function name(): string
    {
        return 'streamline';
    }

    public function description(): string
    {
        return 'Agent de workflows automatises. Permet de chainer plusieurs agents en sequence, creer des workflows reutilisables, executer des pipelines multi-etapes avec passage de contexte entre agents, gerer des conditions et branches.';
    }

    public function keywords(): array
    {
        return [
            'workflow', 'chain', 'chainer', 'then', 'ensuite', 'apres',
            'pipeline', 'automatiser', 'automate', 'sequence',
            'enchainer', 'etape', 'step', 'puis', 'and then',
            '/workflow', 'workflow create', 'workflow list', 'workflow trigger',
            'workflow delete', 'workflow run', 'lancer workflow',
            'creer workflow', 'mes workflows', 'supprimer workflow',
            'when done', 'quand fini', 'after that', 'apres ca',
        ];
    }

    public function version(): string
    {
        return '1.0.0';
    }

    public function canHandle(AgentContext $context): bool
    {
        if (!$context->body) return false;
        $lower = mb_strtolower($context->body);
        return (bool) preg_match('/\b(workflow|chain|pipeline|enchainer|chainer|\/workflow)\b/iu', $lower);
    }

    public function handle(AgentContext $context): AgentResult
    {
        $body = trim($context->body ?? '');

        if (empty($body)) {
            return $this->showHelp($context);
        }

        $lower = mb_strtolower($body);

        // Parse /workflow commands
        if (str_starts_with($lower, '/workflow')) {
            return $this->handleCommand($context, $body);
        }

        // Detect inline workflow patterns (then/chain/after)
        if ($this->isInlineChain($lower)) {
            return $this->handleInlineChain($context, $body);
        }

        // Detect workflow-related keywords
        if (preg_match('/\b(workflow|pipeline)\b/iu', $lower)) {
            return $this->handleNaturalLanguage($context, $body);
        }

        return $this->showHelp($context);
    }

    public function handlePendingContext(AgentContext $context, array $pendingContext): ?AgentResult
    {
        $type = $pendingContext['type'] ?? '';
        $data = $pendingContext['data'] ?? [];

        if ($type === 'confirm_workflow') {
            $lower = mb_strtolower(trim($context->body ?? ''));
            if (in_array($lower, ['oui', 'yes', 'ok', 'go', 'lance', 'confirme'])) {
                $this->clearPendingContext($context);
                return $this->createWorkflowFromParsed($context, $data);
            }
            $this->clearPendingContext($context);
            return AgentResult::reply('Workflow annule.');
        }

        if ($type === 'select_workflow') {
            $this->clearPendingContext($context);
            $selection = trim($context->body ?? '');
            if (is_numeric($selection)) {
                $workflows = Workflow::forUser($context->from)->active()->get();
                $index = (int) $selection - 1;
                if (isset($workflows[$index])) {
                    return $this->triggerWorkflow($context, $workflows[$index]);
                }
            }
            return AgentResult::reply('Selection invalide.');
        }

        return null;
    }

    /**
     * Handle /workflow commands.
     */
    private function handleCommand(AgentContext $context, string $body): AgentResult
    {
        $parts = preg_split('/\s+/', trim($body), 3);
        $action = mb_strtolower($parts[1] ?? 'help');
        $arg = $parts[2] ?? '';

        return match ($action) {
            'create' => $this->commandCreate($context, $arg),
            'list' => $this->commandList($context),
            'trigger', 'run' => $this->commandTrigger($context, $arg),
            'delete', 'remove' => $this->commandDelete($context, $arg),
            'show', 'detail' => $this->commandShow($context, $arg),
            default => $this->showHelp($context),
        };
    }

    /**
     * Create a workflow from command: /workflow create [name] [step1 then step2 then step3]
     */
    private function commandCreate(AgentContext $context, string $arg): AgentResult
    {
        if (empty($arg)) {
            return AgentResult::reply(
                "Pour creer un workflow:\n"
                . "/workflow create [nom] [etape1] then [etape2] then [etape3]\n\n"
                . "Exemple:\n"
                . "/workflow create morning-brief resume mes todos then check mes rappels then meteo du jour"
            );
        }

        $parsed = $this->parseWorkflowDefinition($arg);
        if (!$parsed) {
            return AgentResult::reply('Je n\'ai pas compris la definition du workflow. Utilise "then" pour separer les etapes.');
        }

        // Ask for confirmation
        $preview = $this->formatWorkflowPreview($parsed);
        $this->setPendingContext($context, 'confirm_workflow', $parsed, 3);

        return AgentResult::reply(
            "Workflow a creer:\n{$preview}\n\nConfirmer? (oui/non)"
        );
    }

    /**
     * List user's workflows.
     */
    private function commandList(AgentContext $context): AgentResult
    {
        $workflows = Workflow::forUser($context->from)->orderByDesc('updated_at')->get();

        if ($workflows->isEmpty()) {
            return AgentResult::reply(
                "Aucun workflow enregistre.\n\n"
                . "Cree-en un avec:\n"
                . "/workflow create [nom] [etape1] then [etape2]"
            );
        }

        $lines = ["Tes workflows ({$workflows->count()}):\n"];
        foreach ($workflows->values() as $i => $wf) {
            $status = $wf->is_active ? 'actif' : 'inactif';
            $runs = $wf->run_count;
            $stepCount = count($wf->steps ?? []);
            $lastRun = $wf->last_run_at ? $wf->last_run_at->diffForHumans() : 'jamais';
            $lines[] = ($i + 1) . ". {$wf->name} ({$stepCount} etapes, {$runs} executions, dernier: {$lastRun}) [{$status}]";
        }

        $lines[] = "\nUtilise /workflow trigger [nom] pour lancer un workflow.";

        return AgentResult::reply(implode("\n", $lines));
    }

    /**
     * Trigger a workflow by name.
     */
    private function commandTrigger(AgentContext $context, string $name): AgentResult
    {
        if (empty($name)) {
            $workflows = Workflow::forUser($context->from)->active()->get();
            if ($workflows->isEmpty()) {
                return AgentResult::reply('Aucun workflow actif. Cree-en un avec /workflow create.');
            }

            $lines = ["Quel workflow lancer?\n"];
            foreach ($workflows->values() as $i => $wf) {
                $lines[] = ($i + 1) . ". {$wf->name}";
            }

            $this->setPendingContext($context, 'select_workflow', [], 3);
            return AgentResult::reply(implode("\n", $lines));
        }

        $workflow = Workflow::forUser($context->from)
            ->where('name', 'like', "%{$name}%")
            ->first();

        if (!$workflow) {
            return AgentResult::reply("Workflow \"{$name}\" introuvable. Utilise /workflow list pour voir tes workflows.");
        }

        return $this->triggerWorkflow($context, $workflow);
    }

    /**
     * Delete a workflow by name.
     */
    private function commandDelete(AgentContext $context, string $name): AgentResult
    {
        if (empty($name)) {
            return AgentResult::reply('Precise le nom du workflow a supprimer: /workflow delete [nom]');
        }

        $workflow = Workflow::forUser($context->from)
            ->where('name', 'like', "%{$name}%")
            ->first();

        if (!$workflow) {
            return AgentResult::reply("Workflow \"{$name}\" introuvable.");
        }

        $wfName = $workflow->name;
        $workflow->delete();

        $this->log($context, "Workflow supprime: {$wfName}");
        return AgentResult::reply("Workflow \"{$wfName}\" supprime.");
    }

    /**
     * Show workflow details.
     */
    private function commandShow(AgentContext $context, string $name): AgentResult
    {
        if (empty($name)) {
            return AgentResult::reply('Precise le nom: /workflow show [nom]');
        }

        $workflow = Workflow::forUser($context->from)
            ->where('name', 'like', "%{$name}%")
            ->first();

        if (!$workflow) {
            return AgentResult::reply("Workflow \"{$name}\" introuvable.");
        }

        $lines = [
            "Workflow: {$workflow->name}",
            "Status: " . ($workflow->is_active ? 'Actif' : 'Inactif'),
            "Executions: {$workflow->run_count}",
            "Dernier lancement: " . ($workflow->last_run_at ? $workflow->last_run_at->format('d/m/Y H:i') : 'jamais'),
            '',
            'Etapes:',
        ];

        foreach ($workflow->steps as $i => $step) {
            $agent = $step['agent'] ?? 'auto';
            $msg = mb_substr($step['message'] ?? '', 0, 80);
            $condition = !empty($step['condition']) ? " [si: {$step['condition']}]" : '';
            $lines[] = "  " . ($i + 1) . ". [{$agent}] {$msg}{$condition}";
        }

        return AgentResult::reply(implode("\n", $lines));
    }

    /**
     * Handle inline chain patterns: "do X then do Y then do Z"
     */
    private function handleInlineChain(AgentContext $context, string $body): AgentResult
    {
        $steps = $this->splitChainSteps($body);

        if (count($steps) < 2) {
            return AgentResult::reply('Je n\'ai detecte qu\'une seule etape. Utilise "then", "puis", "ensuite" pour chainer des actions.');
        }

        $this->log($context, 'Inline chain detected', ['steps' => count($steps)]);
        $stepCount = count($steps);
        $this->sendText($context->from, "Execution de {$stepCount} etapes en sequence...");

        // Execute steps inline without saving as workflow
        $orchestrator = new AgentOrchestrator();
        $executor = new WorkflowExecutor($orchestrator);

        $workflow = new Workflow([
            'name' => 'inline-chain',
            'steps' => array_map(fn($s) => ['message' => $s, 'agent' => null], $steps),
        ]);
        $workflow->run_count = 0;

        $executionResult = $executor->execute($workflow, $context);

        return AgentResult::reply(
            WorkflowExecutor::formatResults($executionResult),
            ['workflow_execution' => $executionResult]
        );
    }

    /**
     * Handle natural language workflow requests via Claude.
     */
    private function handleNaturalLanguage(AgentContext $context, string $body): AgentResult
    {
        $model = $this->resolveModel($context);

        $contextMemory = $this->formatContextMemoryForPrompt($context->from, $context);

        $response = $this->claude->chat(
            "Message utilisateur: \"{$body}\"\n\n{$contextMemory}",
            $model,
            "Tu es un assistant specialise dans la creation et gestion de workflows.\n"
            . "L'utilisateur veut creer, modifier ou executer un workflow.\n\n"
            . "Analyse sa demande et reponds en JSON:\n"
            . "{\n"
            . "  \"action\": \"create|list|trigger|help\",\n"
            . "  \"name\": \"nom du workflow\",\n"
            . "  \"steps\": [\n"
            . "    {\"message\": \"instruction pour l'agent\", \"agent\": \"nom_agent ou null\", \"condition\": \"condition optionnelle\"}\n"
            . "  ],\n"
            . "  \"reply\": \"message a afficher a l'utilisateur\"\n"
            . "}\n\n"
            . "Agents disponibles: chat, dev, todo, reminder, event_reminder, finance, music, habit, pomodoro, content_summarizer, code_review, web_search, document, analysis\n"
            . "Si l'action n'est pas claire, utilise action=help et donne une explication dans reply."
        );

        $parsed = json_decode($response, true);
        if (!$parsed) {
            return AgentResult::reply($response ?? 'Je n\'ai pas compris ta demande de workflow. Essaie /workflow help.');
        }

        return match ($parsed['action'] ?? 'help') {
            'create' => $this->handleParsedCreate($context, $parsed),
            'list' => $this->commandList($context),
            'trigger' => $this->commandTrigger($context, $parsed['name'] ?? ''),
            default => AgentResult::reply($parsed['reply'] ?? $this->getHelpText()),
        };
    }

    private function handleParsedCreate(AgentContext $context, array $parsed): AgentResult
    {
        $data = [
            'name' => $parsed['name'] ?? 'workflow-' . now()->format('His'),
            'steps' => $parsed['steps'] ?? [],
        ];

        if (empty($data['steps'])) {
            return AgentResult::reply($parsed['reply'] ?? 'Aucune etape detectee pour le workflow.');
        }

        $preview = $this->formatWorkflowPreview($data);
        $this->setPendingContext($context, 'confirm_workflow', $data, 3);

        return AgentResult::reply("Workflow a creer:\n{$preview}\n\nConfirmer? (oui/non)");
    }

    /**
     * Execute a saved workflow.
     */
    private function triggerWorkflow(AgentContext $context, Workflow $workflow): AgentResult
    {
        $stepCount = count($workflow->steps ?? []);
        $this->sendText($context->from, "Lancement du workflow \"{$workflow->name}\" ({$stepCount} etapes)...");
        $this->log($context, "Workflow triggered: {$workflow->name}", ['id' => $workflow->id]);

        $orchestrator = new AgentOrchestrator();
        $executor = new WorkflowExecutor($orchestrator);

        $executionResult = $executor->execute($workflow, $context);

        return AgentResult::reply(
            WorkflowExecutor::formatResults($executionResult),
            ['workflow_execution' => $executionResult]
        );
    }

    /**
     * Create and save a workflow from parsed data.
     */
    private function createWorkflowFromParsed(AgentContext $context, array $data): AgentResult
    {
        $workflow = Workflow::create([
            'user_phone' => $context->from,
            'agent_id' => $context->agent->id,
            'name' => $data['name'],
            'steps' => $data['steps'],
            'triggers' => $data['triggers'] ?? null,
            'conditions' => $data['conditions'] ?? null,
            'is_active' => true,
        ]);

        $this->log($context, "Workflow cree: {$workflow->name}", ['id' => $workflow->id, 'steps' => count($data['steps'])]);

        return AgentResult::reply(
            "Workflow \"{$workflow->name}\" cree avec " . count($data['steps']) . " etapes.\n"
            . "Lance-le avec: /workflow trigger {$workflow->name}"
        );
    }

    /**
     * Parse a workflow definition string: "name step1 then step2 then step3"
     */
    private function parseWorkflowDefinition(string $input): ?array
    {
        // Extract name (first word before the first step)
        $parts = preg_split('/\s+/', $input, 2);
        $name = $parts[0] ?? 'workflow';
        $rest = $parts[1] ?? '';

        if (empty($rest)) {
            return null;
        }

        $steps = $this->splitChainSteps($rest);

        if (count($steps) < 1) {
            return null;
        }

        return [
            'name' => $name,
            'steps' => array_map(fn($s) => ['message' => trim($s), 'agent' => null], $steps),
        ];
    }

    /**
     * Split text into steps using chain delimiters.
     */
    private function splitChainSteps(string $text): array
    {
        // Split on: then, puis, ensuite, after that, apres ca, >>
        $steps = preg_split('/\b(?:then|puis|ensuite|after\s+that|apr[eè]s\s+[cç]a|et\s+ensuite)\b|>>/iu', $text);

        return array_values(array_filter(array_map('trim', $steps), fn($s) => !empty($s)));
    }

    /**
     * Check if the message contains inline chain patterns.
     */
    private function isInlineChain(string $lower): bool
    {
        return (bool) preg_match('/\b(then|puis|ensuite|after\s+that|apr[eè]s\s+[cç]a)\b/iu', $lower);
    }

    /**
     * Format a workflow preview for confirmation.
     */
    private function formatWorkflowPreview(array $data): string
    {
        $lines = ["Nom: {$data['name']}", ''];
        foreach ($data['steps'] as $i => $step) {
            $msg = mb_substr($step['message'] ?? '', 0, 100);
            $agent = $step['agent'] ?? 'auto';
            $lines[] = "  " . ($i + 1) . ". [{$agent}] {$msg}";
        }
        return implode("\n", $lines);
    }

    /**
     * Show help text.
     */
    private function showHelp(AgentContext $context): AgentResult
    {
        return AgentResult::reply($this->getHelpText());
    }

    private function getHelpText(): string
    {
        return "Streamline — Workflows multi-agents\n\n"
            . "Commandes:\n"
            . "  /workflow create [nom] [etape1] then [etape2]\n"
            . "  /workflow list\n"
            . "  /workflow trigger [nom]\n"
            . "  /workflow show [nom]\n"
            . "  /workflow delete [nom]\n\n"
            . "Inline:\n"
            . "  \"resume mes todos puis check mes rappels\"\n"
            . "  \"analyse ce code then cree un resume\"\n\n"
            . "Conditions:\n"
            . "  Chaque etape peut avoir une condition: contains:mot, success, always\n\n"
            . "Exemple:\n"
            . "  /workflow create daily-brief check mes todos then resume mes rappels then meteo";
    }
}
