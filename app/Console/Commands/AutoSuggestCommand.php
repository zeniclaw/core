<?php

namespace App\Console\Commands;

use App\Jobs\RunSubAgentJob;
use App\Models\Agent;
use App\Models\AppSetting;
use App\Models\Project;
use App\Models\SelfImprovement;
use App\Models\SubAgent;
use App\Services\LLMClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class AutoSuggestCommand extends Command
{
    protected $signature = 'zeniclaw:auto-suggest';
    protected $description = 'Generate a creative sub-agent improvement idea and add it to the backlog as approved';

    public function handle(): int
    {
        $this->info('🔍 Generating improvement idea...');

        // 1. Get existing improvement titles to avoid duplicates
        $existingTitles = SelfImprovement::whereNotNull('improvement_title')
            ->pluck('improvement_title')
            ->toArray();

        // 2. Get recent suggestions from cache (extra anti-duplicate layer)
        $history = Cache::get('auto_suggest_history', []);
        $exclusions = array_unique(array_merge($existingTitles, $history));

        $exclusionText = !empty($exclusions)
            ? "AMÉLIORATIONS DÉJÀ PROPOSÉES (NE PAS RÉPÉTER) :\n- " . implode("\n- ", array_slice($exclusions, -50))
            : '';

        // 3. Call Claude Haiku for the idea
        $claude = new LLMClient();
        $response = $claude->chat(
            "Propose UNE amélioration créative pour ZeniClaw.\n\n{$exclusionText}\n\nRéponds UNIQUEMENT en JSON valide avec ce format :\n{\"title\": \"...\", \"analysis\": \"...\", \"plan\": \"...\"}",
            'claude-haiku-4-5-20251001',
            $this->buildSystemPrompt()
        );

        if (!$response) {
            $this->error('Failed to generate idea from Claude.');
            return self::FAILURE;
        }

        // 4. Parse JSON response
        $response = trim($response);
        // Strip markdown code fences if present
        $response = preg_replace('/^```json?\s*/', '', $response);
        $response = preg_replace('/\s*```$/', '', $response);

        $idea = json_decode($response, true);
        if (!$idea || empty($idea['title']) || empty($idea['plan'])) {
            $this->error("Invalid response from Claude: {$response}");
            Log::warning('AutoSuggest: invalid Claude response', ['response' => $response]);
            return self::FAILURE;
        }

        // 4b. Check for similar existing improvement (anti-doublon)
        $similar = $this->findSimilarImprovement($idea['title'], $exclusions, $claude);
        if ($similar) {
            $this->warn("⚠ Similar improvement exists: \"{$similar}\" — skipping.");
            Log::info('AutoSuggest: skipped duplicate', ['proposed' => $idea['title'], 'similar' => $similar]);
            return self::SUCCESS;
        }

        // 5. Create improvement + SubAgent + dispatch job
        $agent = Agent::first();
        if (!$agent) {
            $this->error('No agent found in database.');
            return self::FAILURE;
        }

        // 5. Get or create the ZeniClaw project
        $project = $this->getOrCreateZeniclawProject($agent);

        // 6. Create SubAgent
        $defaultTimeout = (int) (AppSetting::get('subagent_default_timeout') ?: 10);
        $subAgent = SubAgent::create([
            'project_id' => $project->id,
            'status' => 'queued',
            'task_description' => "Auto-amelioration: {$idea['title']}\n\n{$idea['plan']}",
            'timeout_minutes' => $defaultTimeout,
        ]);

        // 7. Create improvement linked to SubAgent, status in_progress
        $improvement = SelfImprovement::create([
            'agent_id' => $agent->id,
            'trigger_message' => '[Auto-Suggest] ' . $idea['title'],
            'agent_response' => 'Suggestion automatique générée par l\'Improvement Scout',
            'routed_agent' => 'auto-suggest',
            'analysis' => [
                'improve' => true,
                'title' => $idea['title'],
                'analysis' => $idea['analysis'] ?? $idea['title'],
                'plan' => $idea['plan'],
            ],
            'improvement_title' => $idea['title'],
            'development_plan' => $idea['plan'],
            'status' => 'in_progress',
            'sub_agent_id' => $subAgent->id,
        ]);

        // 8. Dispatch via the standard SubAgent pipeline (clone, branch, code, commit, push)
        RunSubAgentJob::dispatch($subAgent);

        // 9. Update cache history
        $history[] = $idea['title'];
        if (count($history) > 50) {
            $history = array_slice($history, -50);
        }
        Cache::put('auto_suggest_history', $history);

        $this->info("✅ Improvement #{$improvement->id} created + SubAgent #{$subAgent->id} launched: {$idea['title']}");

        return self::SUCCESS;
    }

    private function findSimilarImprovement(string $title, array $existingTitles, LLMClient $claude): ?string
    {
        if (empty($existingTitles)) {
            return null;
        }

        // Quick exact match
        foreach ($existingTitles as $existing) {
            if (mb_strtolower(trim($existing)) === mb_strtolower(trim($title))) {
                return $existing;
            }
        }

        // Use Claude to detect semantic duplicates
        $titlesList = implode("\n", array_map(fn($t, $i) => ($i + 1) . ". {$t}", array_values($existingTitles), array_keys($existingTitles)));
        $response = $claude->chat(
            "Nouvelle proposition: \"{$title}\"\n\nListe existante:\n{$titlesList}\n\n"
            . "Y a-t-il une entrée dans la liste qui couvre EXACTEMENT le même sujet ou la même fonctionnalité ? "
            . "Réponds UNIQUEMENT par le numéro de l'entrée similaire, ou \"0\" si aucune n'est similaire.",
            'claude-haiku-4-5-20251001',
            "Tu détectes les doublons sémantiques. Deux entrées sont similaires si elles proposent la même fonctionnalité, "
            . "même si les mots diffèrent. Exemple: 'agent météo' et 'WeatherAgent pour prévisions' = SIMILAIRE. "
            . "Mais 'agent météo' et 'agent traduction' = PAS SIMILAIRE. Réponds UNIQUEMENT par un numéro."
        );

        $num = (int) trim($response ?? '0');
        if ($num > 0 && $num <= count($existingTitles)) {
            return array_values($existingTitles)[$num - 1];
        }

        return null;
    }

    private function getOrCreateZeniclawProject(Agent $agent): Project
    {
        $projectId = AppSetting::get('zeniclaw_project_id');
        if ($projectId) {
            $project = Project::find($projectId);
            if ($project) return $project;
        }

        $project = Project::where('name', 'ZeniClaw (Auto-Improve)')->first();
        if ($project) return $project;

        $project = Project::create([
            'name' => 'ZeniClaw (Auto-Improve)',
            'gitlab_url' => 'https://github.com/zeniclaw/core.git',
            'request_description' => 'Projet auto-genere pour les auto-ameliorations de ZeniClaw.',
            'requester_phone' => 'system',
            'requester_name' => 'ZeniClaw Auto-Improve',
            'agent_id' => $agent->id,
            'status' => 'approved',
            'approved_at' => now(),
        ]);

        AppSetting::set('zeniclaw_project_id', (string) $project->id);

        return $project;
    }

    private function buildSystemPrompt(): string
    {
        return <<<'PROMPT'
Tu es l'Improvement Scout de ZeniClaw, un assistant WhatsApp intelligent basé sur Laravel + Claude.

TON RÔLE : Proposer des améliorations concrètes et développables — soit améliorer un agent existant, soit créer un nouveau sub-agent utile et original.

ARCHITECTURE ACTUELLE (6 agents) :
- ChatAgent : conversation générale, Q&A, humour, culture générale
- DevAgent : gestion de projets dev, création de tâches, sous-agents autonomes qui codent
- ReminderAgent : rappels, alarmes, récurrences (cron-based)
- TodoAgent : listes de tâches multi-listes, courses, checklists
- AnalysisAgent : analyse d'images (OCR, description) et de PDF
- ProjectAgent : gestion et suivi de projets, statuts, assignations
- RouterAgent : routing intelligent des messages vers le bon agent

STACK TECHNIQUE :
- Laravel 11, PHP 8.4, PostgreSQL, Redis
- Claude API (Haiku pour le routing, Sonnet pour les réponses)
- WAHA pour WhatsApp (envoi/réception de messages, groupes)
- Docker, queue workers, scheduler

TYPES D'AMÉLIORATIONS À PROPOSER :
1. **Nouveaux sub-agents** : météo, traduction, finance, recettes, sport scores, musique, actualités, email, calendrier, domotique, santé/fitness, voyages, jeux, quiz, résumé de liens/articles, génération d'images, text-to-speech, dictionnaire, calculs avancés, suivi de colis, alertes prix, résumé YouTube, bookmarks, pomodoro, habitudes tracker, journal intime, flashcards/apprentissage, générateur de mots de passe, conversion devises, horoscope, citations du jour, blagues du jour, code review, regex helper, cron expression builder, color palette generator, QR code generator
2. **Améliorations d'agents existants** : meilleure compréhension du contexte, nouvelles commandes, intégrations, formats de réponse plus riches, gestion d'erreurs améliorée, personnalisation utilisateur
3. **Features transversales** : système de plugins, préférences utilisateur, multi-langue, analytics, notifications proactives, gamification

RÈGLES POUR LE PLAN DE DÉVELOPPEMENT :
- Le plan doit être CONCRET et IMPLÉMENTABLE : noms de fichiers Laravel à créer/modifier, classes, méthodes
- Suivre les patterns existants (ex: créer un nouvel agent = hériter de BaseAgent, implémenter handle())
- Inclure les étapes : création du fichier, modification du RouterAgent, tests
- 4-8 étapes numérotées, chaque étape = une action précise
- Si c'est un nouvel agent, TOUJOURS inclure ces 4 étapes obligatoires :
  a) Créer app/Services/Agents/XxxAgent.php (hériter de BaseAgent, implémenter name() et handle())
  b) Modifier app/Services/Agents/RouterAgent.php pour router les messages vers le nouvel agent
  c) Modifier app/Services/AgentOrchestrator.php pour enregistrer le nouvel agent dans registerAgents()
  d) Modifier app/Http/Controllers/AgentController.php pour ajouter le nouvel agent dans le const SUB_AGENTS (clé = nom retourné par name(), avec label, icon emoji, color parmi blue/purple/orange/green/red/teal/indigo/pink/cyan/amber, et description courte)

FORMAT DE RÉPONSE (JSON strict) :
{
  "title": "Titre court et descriptif (max 80 chars)",
  "analysis": "Pourquoi cette amélioration est utile — 2-3 phrases expliquant le besoin et la valeur ajoutée",
  "plan": "1. Créer app/Services/Agents/XxxAgent.php...\n2. Implémenter handle()...\n3. ..."
}

SOIS CRÉATIF ET VARIÉ. Alterne entre nouveaux agents, améliorations d'existants, et features transversales.
PROMPT;
    }
}
