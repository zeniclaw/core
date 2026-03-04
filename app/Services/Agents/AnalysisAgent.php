<?php

namespace App\Services\Agents;

use App\Models\Project;
use App\Services\AgentContext;

class AnalysisAgent extends BaseAgent
{
    public function name(): string
    {
        return 'analysis';
    }

    public function canHandle(AgentContext $context): bool
    {
        return $context->routedAgent === 'analysis';
    }

    public function handle(AgentContext $context): AgentResult
    {
        $model = $this->resolveModel($context);
        $systemPrompt = $this->buildSystemPrompt($context);

        $reply = $this->claude->chat(
            $context->body ?? '',
            $model,
            $systemPrompt
        );

        if (!$reply) {
            return AgentResult::reply('Désolé, je n\'ai pas pu générer l\'analyse. Réessaie !');
        }

        $this->sendText($context->from, $reply);

        $this->log($context, 'Analysis reply sent', [
            'model' => $model,
            'complexity' => $context->complexity,
            'reply_length' => mb_strlen($reply),
        ]);

        return AgentResult::reply($reply, ['model' => $model, 'complexity' => $context->complexity]);
    }

    private function buildSystemPrompt(AgentContext $context): string
    {
        $systemPrompt =
            "Tu es ZeniClaw, un assistant analytique expert. " .
            "Tu fournis des analyses approfondies, structurees et argumentees. " .
            "Tu utilises des listes, des titres et une organisation claire. " .
            "Tu cites tes sources de raisonnement. " .
            "Tu es precis et factuel. " .
            "Tu peux etre plus long que pour une conversation normale si l'analyse le demande. " .
            "Le message vient de {$context->senderName}.";

        // Add project context if relevant
        $projectContext = $this->buildProjectContext($context);
        if ($projectContext) {
            $systemPrompt .= "\n\n" . $projectContext;
        }

        $memoryContext = $this->memory->formatForPrompt($context->agent->id, $context->from);
        if ($memoryContext) {
            $systemPrompt .= "\n\n" . $memoryContext;
        }

        return $systemPrompt;
    }

    private function buildProjectContext(AgentContext $context): ?string
    {
        $phone = $context->from;

        $projects = Project::where('requester_phone', $phone)
            ->whereIn('status', ['approved', 'in_progress', 'completed'])
            ->orderByDesc('created_at')
            ->get();

        if ($projects->isEmpty()) return null;

        $lines = ["PROJETS DE L'UTILISATEUR:"];
        foreach ($projects as $project) {
            $lines[] = "- {$project->name} ({$project->gitlab_url})";
        }

        if ($context->session->active_project_id) {
            $activeProject = $projects->firstWhere('id', $context->session->active_project_id);
            if ($activeProject) {
                $lines[] = "\nPROJET ACTIF: {$activeProject->name}";
            }
        }

        return implode("\n", $lines);
    }
}
