<?php

namespace App\Jobs;

use App\Models\SubAgent;
use App\Services\AgentContext;
use App\Services\AgenticLoop;
use App\Services\AgentTools;
use App\Services\Agents\DocumentAgent;
use App\Services\Agents\WebSearchAgent;
use App\Services\ModelResolver;
use App\Services\ToolRegistry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * General-purpose background task — runs an AgenticLoop with all tools.
 * Used for research, data collection, file creation, and any autonomous task
 * that doesn't need a GitLab project.
 */
class RunTaskJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300; // 5 minutes max
    public int $tries = 1;

    public function __construct(
        public SubAgent $subAgent
    ) {
        $this->timeout = ($this->subAgent->timeout_minutes ?: 5) * 60;
    }

    public function handle(): void
    {
        $this->subAgent->update([
            'status' => 'running',
            'started_at' => now(),
        ]);

        $this->subAgent->appendLog("[START] Task #{$this->subAgent->id}: {$this->subAgent->task_description}");
        $this->notifyRequester("⏳ Tache en cours...");

        try {
            $result = $this->runAgenticLoop();

            $this->subAgent->update([
                'status' => 'completed',
                'result' => $result,
                'completed_at' => now(),
            ]);
            $this->subAgent->appendLog("[DONE] Task completed");

            // Send result to user
            $this->notifyRequester($result ?: "Tache terminee mais aucun resultat.");
        } catch (\Throwable $e) {
            Log::error('RunTaskJob failed', [
                'task_id' => $this->subAgent->id,
                'error' => $e->getMessage(),
            ]);

            $this->subAgent->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'completed_at' => now(),
            ]);
            $this->subAgent->appendLog("[ERROR] " . $e->getMessage());
            $this->notifyRequester("❌ Erreur: " . mb_substr($e->getMessage(), 0, 200));
        }
    }

    public function failed(?\Throwable $exception): void
    {
        $this->subAgent->refresh();
        if (in_array($this->subAgent->status, ['running', 'queued'])) {
            $this->subAgent->update([
                'status' => 'failed',
                'error_message' => 'Job interrompu: ' . ($exception?->getMessage() ?? 'timeout'),
                'completed_at' => now(),
            ]);
        }
    }

    private function runAgenticLoop(): ?string
    {
        // Build a tool registry with all available tools
        $registry = new ToolRegistry();

        // Register tool-providing agents
        $agents = [
            new WebSearchAgent(),
            new DocumentAgent(),
        ];
        foreach ($agents as $agent) {
            if (!empty($agent->tools())) {
                $registry->register($agent);
            }
        }

        // Build a minimal AgentContext for tool execution
        $context = $this->buildContext($registry);

        // Run the agentic loop with high iteration count for thorough research
        $loop = new AgenticLoop(maxIterations: 15, debug: true);

        $systemPrompt = $this->buildSystemPrompt();

        $result = $loop->run(
            userMessage: $this->subAgent->task_description,
            systemPrompt: $systemPrompt,
            model: ModelResolver::powerful(),
            context: $context,
        );

        $this->subAgent->update([
            'api_calls_count' => count($result->toolsUsed),
        ]);

        $this->subAgent->appendLog("Tools used: " . implode(', ', array_unique($result->toolsUsed)));
        $this->subAgent->appendLog("Iterations: {$result->iterations}");

        return $result->reply;
    }

    private function buildSystemPrompt(): string
    {
        $tz = \App\Models\AppSetting::timezone();
        $now = now()->timezone($tz)->format('l j F Y, H:i');

        return <<<PROMPT
Tu es un agent autonome qui execute des taches en arriere-plan pour l'utilisateur.
Date: {$now}

Tu as acces a ces outils:
- web_search: chercher sur le web (utilise plusieurs recherches pour etre exhaustif)
- create_document: creer un fichier (XLSX, CSV, PDF, DOCX) et l'envoyer a l'utilisateur
- store_knowledge: sauvegarder des donnees pour l'utilisateur
- get_current_datetime: obtenir la date/heure actuelle

REGLES:
1. Sois EXHAUSTIF. Fais PLUSIEURS recherches web pour couvrir le sujet completement.
2. STRUCTURE tes resultats: listes, tableaux, categories.
3. Si la tache implique des donnees tabulaires, cree automatiquement un fichier XLSX avec create_document.
4. Si tu trouves des donnees utiles, sauvegarde-les avec store_knowledge.
5. Formate ta reponse finale pour WhatsApp (*gras*, listes).
6. Si les resultats sont nombreux (>10), cree TOUJOURS un fichier XLSX en plus du texte.

IMPORTANT: Tu tournes en ARRIERE-PLAN. L'utilisateur ne peut pas te repondre. Tu dois tout faire de maniere autonome.
PROMPT;
    }

    private function buildContext(ToolRegistry $registry): AgentContext
    {
        // Find the default agent for logging
        $agent = \App\Models\Agent::where('slug', 'chat')->first()
            ?? \App\Models\Agent::first();

        // Find or create a session
        $session = \App\Models\AgentSession::firstOrCreate(
            ['phone' => $this->subAgent->requester_phone ?? 'system', 'agent_id' => $agent->id],
            ['preferences' => []]
        );

        return new AgentContext(
            agent: $agent,
            session: $session,
            from: $this->subAgent->requester_phone ?? 'system',
            senderName: 'TaskAgent',
            body: $this->subAgent->task_description,
            hasMedia: false,
            mediaUrl: null,
            mimetype: null,
            media: null,
            toolRegistry: $registry,
        );
    }

    private function notifyRequester(string $message): void
    {
        $phone = $this->subAgent->requester_phone;
        if (!$phone) return;

        // Web chat → store result for polling
        if (str_starts_with($phone, 'web-')) {
            return;
        }

        try {
            Http::timeout(10)->post('http://waha:3000/api/sendText', [
                'chatId' => $phone,
                'text' => "🤖 [Tache] {$message}",
                'session' => 'default',
            ]);
        } catch (\Exception $e) {
            Log::warning('RunTaskJob: failed to notify requester', ['error' => $e->getMessage()]);
        }
    }
}
