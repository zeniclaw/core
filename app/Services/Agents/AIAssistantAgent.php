<?php

namespace App\Services\Agents;

use App\Models\UserAgentAnalytic;
use App\Services\AgentContext;

class AIAssistantAgent extends BaseAgent
{
    public function name(): string
    {
        return 'assistant';
    }

    public function description(): string
    {
        return 'Coaching IA personnalise avec suggestions d\'agents pertinents, statistiques d\'utilisation et tips proactifs pour optimiser l\'experience utilisateur.';
    }

    public function keywords(): array
    {
        return [
            'assistant', 'coaching', 'coach', 'aide', 'help', 'tips', 'astuce',
            'suggestion', 'suggest', 'recommande', 'recommend',
            'quels agents', 'which agents', 'mes stats', 'my stats',
            'statistiques', 'statistics', 'utilisation', 'usage',
            'comment utiliser', 'how to use', 'fonctionnalites', 'features',
            'que peux-tu faire', 'what can you do', 'guide', 'tutorial',
            'score', 'adoption', 'progression', 'progress',
            'agents suggestions', 'tip hebdo', 'assistant ia',
            'fonctionnalites disponibles', 'astuces agents',
        ];
    }

    public function version(): string
    {
        return '1.0.0';
    }

    public function canHandle(AgentContext $context): bool
    {
        $body = mb_strtolower(trim($context->body ?? ''));
        foreach ($this->keywords() as $keyword) {
            if (str_contains($body, mb_strtolower($keyword))) {
                return true;
            }
        }
        return false;
    }

    public function handle(AgentContext $context): AgentResult
    {
        $body = trim($context->body ?? '');
        $lower = mb_strtolower($body);

        if (preg_match('/\b(stats?|statistiques?|utilisation|usage|dashboard|tableau\s+de\s+bord)\b/iu', $lower)) {
            return $this->showStats($context);
        }

        if (preg_match('/\b(suggest|recommande|quels?\s+agents?|which\s+agents?|que\s+(?:puis|peux)[\s-]je|what\s+can|agents?\s+suggestions?)\b/iu', $lower)) {
            return $this->handleSuggestAgents($context);
        }

        if (preg_match('/\b(tips?|astuce|conseil|trick|raccourci|shortcut|fonctionnalit[eé]s?|features?|guide|tutorial|tip\s+hebdo)\b/iu', $lower)) {
            return $this->showTips($context);
        }

        if (preg_match('/\b(progression|progress|score|adoption|level)\b/iu', $lower)) {
            return $this->showProgression($context);
        }

        if (preg_match('/\b(coaching|coach|assistant\s+ia)\b/iu', $lower)) {
            return $this->handleSuggestAgents($context);
        }

        // Default: show a general overview with suggestions
        return $this->showOverview($context);
    }

    public function handlePendingContext(AgentContext $context, array $pendingContext): ?AgentResult
    {
        return null;
    }

    /**
     * Show usage statistics for the user.
     */
    private function showStats(AgentContext $context): AgentResult
    {
        $stats = UserAgentAnalytic::getUserStats($context->from);

        if ($stats['total_interactions'] === 0) {
            $reply = "*Statistiques d'utilisation*\n\n"
                . "Pas encore de donnees disponibles.\n"
                . "Commence a utiliser les agents et reviens ici pour voir tes stats !";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply);
        }

        $agentLines = [];
        $rank = 1;
        foreach (array_slice($stats['agents_used'], 0, 10) as $agent => $count) {
            $bar = str_repeat('=', min(20, intval($count / max(1, $stats['total_interactions']) * 40)));
            $pct = round($count / $stats['total_interactions'] * 100, 1);
            $agentLines[] = "  {$rank}. *{$agent}* [{$bar}] {$count}x ({$pct}%)";
            $rank++;
        }

        $reply = "*Statistiques d'utilisation (30 derniers jours)*\n\n"
            . "Total interactions: *{$stats['total_interactions']}*\n"
            . "Agents utilises: *{$stats['unique_agents']}*\n"
            . "Taux de succes: *{$stats['success_rate']}%*\n"
            . "Score d'adoption: *{$stats['adoption_score']}%*\n\n"
            . "*Top agents:*\n" . implode("\n", $agentLines);

        $this->sendText($context->from, $reply);
        $this->log($context, 'Stats displayed', ['total' => $stats['total_interactions'], 'adoption' => $stats['adoption_score']]);
        return AgentResult::reply($reply);
    }

    /**
     * Analyze the last 10 interactions, detect missing patterns,
     * and return 2-3 relevant agent suggestions with reasons.
     * Standalone method callable from outside (e.g. AgentOrchestrator, SendProactiveTips).
     */
    public function suggestAgentsForUser(string $userId): array
    {
        $recentInteractions = UserAgentAnalytic::getRecentInteractions($userId, 10);
        $stats = UserAgentAnalytic::getUserStats($userId);
        $usedAgents = array_keys($stats['agents_used']);

        $suggestions = [];

        // Pattern: user writes code but doesn't use code_review
        if (in_array('dev', $usedAgents) && !in_array('code_review', $usedAgents)) {
            $suggestions[] = [
                'agent' => 'code_review',
                'label' => 'Code Review',
                'reason' => 'Tu codes souvent — fais reviewer ton code automatiquement pour detecter bugs et failles',
            ];
        }

        // Pattern: user uses todo but not pomodoro
        if (in_array('todo', $usedAgents) && !in_array('pomodoro', $usedAgents)) {
            $suggestions[] = [
                'agent' => 'pomodoro',
                'label' => 'Pomodoro',
                'reason' => 'Tu geres tes taches — combine avec des sessions focus pour etre plus productif',
            ];
        }

        // Pattern: user uses reminder but not habit
        if (in_array('reminder', $usedAgents) && !in_array('habit', $usedAgents)) {
            $suggestions[] = [
                'agent' => 'habit',
                'label' => 'HabitAgent',
                'reason' => 'Tu utilises les rappels — essaie le suivi d\'habitudes pour des routines durables',
            ];
        }

        // Pattern: user uses chat a lot but not content_summarizer
        $chatCount = $recentInteractions->where('agent_used', 'chat')->count();
        if ($chatCount >= 5 && !in_array('content_summarizer', $usedAgents)) {
            $suggestions[] = [
                'agent' => 'content_summarizer',
                'label' => 'ContentSummarizer',
                'reason' => 'Tu poses beaucoup de questions — resume des articles directement avec un lien',
            ];
        }

        // Pattern: no budget tracking
        if (!in_array('budget_tracker', $usedAgents) && !in_array('finance', $usedAgents)) {
            $suggestions[] = [
                'agent' => 'budget_tracker',
                'label' => 'BudgetTracker',
                'reason' => 'Suis tes depenses au quotidien en envoyant simplement tes achats',
            ];
        }

        // Pattern: no time management
        if (!in_array('time_blocker', $usedAgents) && !in_array('pomodoro', $usedAgents)) {
            $suggestions[] = [
                'agent' => 'time_blocker',
                'label' => 'TimeBlocker',
                'reason' => 'Optimise ta journee avec des blocs de temps intelligents',
            ];
        }

        // Pattern: dev without flashcard
        if (in_array('dev', $usedAgents) && !in_array('flashcard', $usedAgents)) {
            $suggestions[] = [
                'agent' => 'flashcard',
                'label' => 'FlashcardAgent',
                'reason' => 'Tu developpes — cree des flashcards pour memoriser des concepts techniques',
            ];
        }

        // Fallback: suggest popular agents if few matches
        if (count($suggestions) < 2) {
            $popularUnused = array_diff(['todo', 'reminder', 'pomodoro', 'habit', 'daily_brief'], $usedAgents);
            foreach (array_slice($popularUnused, 0, 3 - count($suggestions)) as $agent) {
                $descs = [
                    'todo' => 'Organise tes taches avec des checklists intelligentes',
                    'reminder' => 'Ne rate plus jamais une deadline',
                    'pomodoro' => 'Booste ta productivite avec des sessions focus de 25 min',
                    'habit' => 'Construis des routines durables avec le suivi d\'habitudes',
                    'daily_brief' => 'Recois chaque matin un resume personnalise de ta journee',
                ];
                $suggestions[] = [
                    'agent' => $agent,
                    'label' => ucfirst($agent),
                    'reason' => $descs[$agent] ?? 'Decouvre cet agent pour enrichir ton usage',
                ];
            }
        }

        return array_slice($suggestions, 0, 3);
    }

    /**
     * Handle suggestion request from user message.
     */
    public function handleSuggestAgents(AgentContext $context): AgentResult
    {
        $recentInteractions = UserAgentAnalytic::getRecentInteractions($context->from, 10);
        $stats = UserAgentAnalytic::getUserStats($context->from);

        $usedAgents = array_keys($stats['agents_used']);

        // All available agents with descriptions
        $allAgents = [
            'todo' => 'Gestion de taches et checklists',
            'reminder' => 'Rappels et notifications temporelles',
            'project' => 'Gestion de projets GitLab',
            'dev' => 'Developpement, code et GitLab',
            'finance' => 'Suivi financier et budgets',
            'habit' => 'Suivi des habitudes quotidiennes',
            'pomodoro' => 'Sessions de focus et productivite',
            'flashcard' => 'Apprentissage par repetition espacee',
            'music' => 'Musique et playlists',
            'mood_check' => 'Suivi emotionnel et bien-etre',
            'content_summarizer' => 'Resume d\'articles et pages web',
            'event_reminder' => 'Evenements avec date/lieu',
            'code_review' => 'Revue et analyse de code',
            'smart_meeting' => 'Synthese de reunions',
            'document' => 'Creation de fichiers Excel/PDF/Word',
            'analysis' => 'Analyse approfondie de donnees',
            'budget_tracker' => 'Suivi detaille des depenses',
            'daily_brief' => 'Resume quotidien personnalise',
            'recipe' => 'Suggestions de recettes de cuisine',
            'time_blocker' => 'Optimisation du planning par blocs',
            'web_search' => 'Recherche web en temps reel',
            'hangman' => 'Jeu du pendu interactif',
            'interactive_quiz' => 'Quiz et trivia',
            'game_master' => 'Jeux interactifs et enigmes',
            'content_curator' => 'Curation de contenu personnalise',
        ];

        // Find unused agents
        $unusedAgents = array_diff_key($allAgents, array_flip($usedAgents));

        // Build suggestions using Claude for personalization
        $recentAgentsList = $recentInteractions->pluck('agent_used')->implode(', ');
        $unusedList = implode(', ', array_keys(array_slice($unusedAgents, 0, 10)));

        $systemPrompt = <<<PROMPT
Tu es un assistant coaching IA. Analyse les patterns d'utilisation et suggere 3 agents pertinents que l'utilisateur n'a pas encore essaye.

AGENTS RECEMMENT UTILISES: {$recentAgentsList}
AGENTS NON UTILISES: {$unusedList}

Reponds en francais avec:
1. Un bref constat sur l'utilisation actuelle (1 phrase)
2. 3 suggestions d'agents avec raison et exemple d'utilisation
3. Un encouragement

Format: texte WhatsApp avec *gras* pour les noms d'agents. Pas de markdown complexe.
Sois concis (max 15 lignes).
PROMPT;

        $response = $this->claude->chat(
            "Utilisateur {$context->from} - Stats: " . json_encode($stats),
            $this->resolveModel($context),
            $systemPrompt
        );

        $reply = $response ?? $this->fallbackSuggestions($unusedAgents);
        $this->sendText($context->from, $reply);
        $this->log($context, 'Agent suggestions generated', ['used_agents' => count($usedAgents), 'unused_agents' => count($unusedAgents)]);
        return AgentResult::reply($reply);
    }

    /**
     * Show tips and shortcuts.
     */
    private function showTips(AgentContext $context): AgentResult
    {
        $tips = [
            "Envoie un message vocal pour que *VoiceCommand* le transcrive et execute la commande automatiquement.",
            "Tape *pomodoro* pour lancer une session de focus de 25 min avec rappels.",
            "Dis *resume [URL]* pour obtenir un resume intelligent de n'importe quel article.",
            "Utilise *daily brief* pour recevoir un resume de ta journee chaque matin.",
            "Ecris *depense 25 restaurant* pour tracker tes depenses automatiquement.",
            "Tape *recette poulet tomate* pour des suggestions de cuisine avec tes ingredients.",
            "Dis *habitude mediter* pour commencer a tracker une nouvelle habitude quotidienne.",
            "Envoie une image pour que *Screenshot* en extraie le texte (OCR).",
            "Tape *quiz science* pour un quiz ludique avec scoring.",
            "Utilise *rappelle-moi dans 2h de...* pour un rappel rapide.",
            "Tape *bloque ma journee* pour optimiser ton planning par blocs de temps.",
            "Dis *mes stats* pour voir tes statistiques d'utilisation.",
        ];

        // Pick 5 random tips
        $selectedTips = collect($tips)->shuffle()->take(5);

        $reply = "*Astuces & Raccourcis*\n\n";
        foreach ($selectedTips->values() as $i => $tip) {
            $reply .= ($i + 1) . ". {$tip}\n\n";
        }

        $reply .= "_Dis 'suggest agents' pour des recommandations personnalisees._";

        $this->sendText($context->from, $reply);
        $this->log($context, 'Tips displayed');
        return AgentResult::reply($reply);
    }

    /**
     * Show progression and adoption score.
     */
    private function showProgression(AgentContext $context): AgentResult
    {
        $stats = UserAgentAnalytic::getUserStats($context->from);
        $score = $stats['adoption_score'];

        // Visual progress bar
        $filled = intval($score / 5);
        $empty = 20 - $filled;
        $bar = str_repeat('|', $filled) . str_repeat('.', $empty);

        // Level determination
        $level = match (true) {
            $score >= 80 => 'Expert',
            $score >= 60 => 'Avance',
            $score >= 40 => 'Intermediaire',
            $score >= 20 => 'Debutant',
            default => 'Novice',
        };

        $levelEmoji = match ($level) {
            'Expert' => '*****',
            'Avance' => '****',
            'Intermediaire' => '***',
            'Debutant' => '**',
            default => '*',
        };

        $reply = "*Progression*\n\n"
            . "Niveau: *{$level}* {$levelEmoji}\n"
            . "Score d'adoption: *{$score}%*\n"
            . "[{$bar}]\n\n"
            . "Interactions totales: *{$stats['total_interactions']}*\n"
            . "Agents decouverts: *{$stats['unique_agents']}* / 35\n"
            . "Taux de succes: *{$stats['success_rate']}%*\n\n";

        if ($score < 40) {
            $reply .= "_Explore plus d'agents pour augmenter ton score ! Dis 'suggest agents' pour des idees._";
        } elseif ($score < 70) {
            $reply .= "_Bonne progression ! Continue a explorer de nouveaux agents._";
        } else {
            $reply .= "_Excellent ! Tu maitrises une grande partie des fonctionnalites._";
        }

        $this->sendText($context->from, $reply);
        $this->log($context, 'Progression displayed', ['score' => $score, 'level' => $level]);
        return AgentResult::reply($reply);
    }

    /**
     * General overview with quick stats and suggestions.
     */
    private function showOverview(AgentContext $context): AgentResult
    {
        $stats = UserAgentAnalytic::getUserStats($context->from);

        $reply = "*AI Assistant - Coaching Personnalise*\n\n"
            . "Voici ce que je peux faire pour toi:\n\n"
            . "1. *mes stats* - Voir tes statistiques d'utilisation\n"
            . "2. *suggest agents* - Recommandations personnalisees\n"
            . "3. *tips* - Astuces et raccourcis utiles\n"
            . "4. *progression* - Ton score d'adoption et niveau\n\n";

        if ($stats['total_interactions'] > 0) {
            $reply .= "Tes stats rapides:\n"
                . "- {$stats['total_interactions']} interactions ces 30 derniers jours\n"
                . "- {$stats['unique_agents']} agents utilises\n"
                . "- Score d'adoption: {$stats['adoption_score']}%\n";
        } else {
            $reply .= "_Commence a utiliser les agents pour debloquer tes statistiques !_";
        }

        $this->sendText($context->from, $reply);
        $this->log($context, 'Overview displayed');
        return AgentResult::reply($reply);
    }

    /**
     * Fallback suggestions when Claude is unavailable.
     */
    private function fallbackSuggestions(array $unusedAgents): string
    {
        $suggestions = array_slice($unusedAgents, 0, 3, true);

        $reply = "*Suggestions d'agents a decouvrir:*\n\n";
        $i = 1;
        foreach ($suggestions as $name => $desc) {
            $reply .= "{$i}. *{$name}* - {$desc}\n";
            $i++;
        }

        return $reply;
    }
}
