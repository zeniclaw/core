<?php

namespace App\Console\Commands;

use App\Models\Agent;
use App\Models\SelfImprovement;
use App\Services\AnthropicClient;
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
        $claude = new AnthropicClient();
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

        // 5. Create approved SelfImprovement
        $agent = Agent::first();
        if (!$agent) {
            $this->error('No agent found in database.');
            return self::FAILURE;
        }

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
            'status' => 'approved',
        ]);

        // 6. Update cache history
        $history[] = $idea['title'];
        if (count($history) > 50) {
            $history = array_slice($history, -50);
        }
        Cache::put('auto_suggest_history', $history);

        $this->info("✅ Improvement #{$improvement->id} created: {$idea['title']}");

        return self::SUCCESS;
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
- Si c'est un nouvel agent : inclure la création de app/Services/Agents/XxxAgent.php + modification du RouterAgent + enregistrement dans AgentOrchestrator

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
