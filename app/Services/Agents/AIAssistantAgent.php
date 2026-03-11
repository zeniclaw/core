<?php

namespace App\Services\Agents;

use App\Models\UserAgentAnalytic;
use App\Services\AgentContext;

class AIAssistantAgent extends BaseAgent
{
    private const TOTAL_AGENTS = 35;

    /** Alias map for fuzzy agent name matching in showAgentHelp() */
    private const AGENT_ALIASES = [
        'budget'       => 'budget_tracker',
        'tracker'      => 'budget_tracker',
        'depense'      => 'budget_tracker',
        'depenses'     => 'budget_tracker',
        'tache'        => 'todo',
        'taches'       => 'todo',
        'task'         => 'todo',
        'focus'        => 'pomodoro',
        'timer'        => 'pomodoro',
        'habitude'     => 'habit',
        'habitudes'    => 'habit',
        'routine'      => 'habit',
        'rappel'       => 'reminder',
        'rappels'      => 'reminder',
        'resume'       => 'content_summarizer',
        'resumer'      => 'content_summarizer',
        'quiz'         => 'interactive_quiz',
        'jeu'          => 'game_master',
        'planifier'    => 'time_blocker',
        'planning'     => 'time_blocker',
        'humeur'       => 'mood_check',
        'emotion'      => 'mood_check',
        'recette'      => 'recipe',
        'cuisine'      => 'recipe',
        'recherche'    => 'web_search',
        'rechercher'   => 'web_search',
        'code'         => 'dev',
        'developpement'=> 'dev',
        'reunion'      => 'smart_meeting',
        'meeting'      => 'smart_meeting',
        'document'     => 'document',
        'fichier'      => 'document',
        'musique'      => 'music',
        'playlist'     => 'music',
        'projet'       => 'project',
        'briefe'       => 'daily_brief',
        'brief'        => 'daily_brief',
        'matin'        => 'daily_brief',
        'flashcard'    => 'flashcard',
        'fiche'        => 'flashcard',
        'analyse'      => 'analysis',
    ];

    public function name(): string
    {
        return 'assistant';
    }

    public function description(): string
    {
        return 'Coaching IA personnalise : stats d\'utilisation, suggestions d\'agents, tips personnalises, progression, badges, bilan hebdomadaire, reference de commandes et guide par agent.';
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
            'semaine', 'weekly', 'tendance', 'trend', 'bilan semaine', 'bilan hebdo',
            'comment fonctionne', 'expliquer agent', 'aide agent',
            // New v1.2.0
            'badges', 'badge', 'achievement', 'trophee', 'trophees', 'recompense',
            'recompenses', 'succes', 'accomplissement',
            'commandes', 'liste commandes', 'toutes les commandes',
            'liste agents', 'tous les agents', 'reference', 'aide rapide',
            'rapport mensuel', 'mensuel', 'monthly',
            // New v1.3.0
            'streak', 'serie', 'jours consecutifs', 'ma serie', 'combo jours',
            'cherche agent', 'trouver agent', 'search agent', 'quel agent pour',
        ];
    }

    public function version(): string
    {
        return '1.3.0';
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
        $body  = trim($context->body ?? '');
        $lower = mb_strtolower($body);

        if (preg_match('/\b(stats?|statistiques?|utilisation|usage|dashboard|tableau\s+de\s+bord)\b/iu', $lower)) {
            return $this->showStats($context);
        }

        if (preg_match('/\b(semaine|weekly|tendance|trend|bilan\s+(?:semaine|hebdo)|7\s*jours)\b/iu', $lower)) {
            return $this->showWeeklySummary($context);
        }

        if (preg_match('/\b(rapport\s+mensuel|mensuel|monthly|bilan\s+mois)\b/iu', $lower)) {
            return $this->showMonthlySummary($context);
        }

        if (preg_match('/\b(suggest|recommande|quels?\s+agents?|which\s+agents?|que\s+(?:puis|peux)[\s-]je|what\s+can|agents?\s+suggestions?)\b/iu', $lower)) {
            return $this->handleSuggestAgents($context);
        }

        if (preg_match('/\b(tips?|astuce|conseil|trick|raccourci|shortcut|fonctionnalit[eé]s?|features?|tip\s+hebdo)\b/iu', $lower)) {
            return $this->showTips($context);
        }

        if (preg_match('/\b(badges?|achievement|trophee|recompense|succes|accomplissement)\b/iu', $lower)) {
            return $this->showAchievements($context);
        }

        if (preg_match('/\b(commandes?|liste\s+(?:agents?|commandes?)|tous\s+les\s+agents?|reference|aide\s+rapide)\b/iu', $lower)) {
            return $this->showCommandsReference($context);
        }

        if (preg_match('/\b(comment\s+(?:utiliser|fonctionne?)|guide\s+\w+|aide\s+\w+|expliquer?|how\s+to\s+use)\b/iu', $lower)) {
            return $this->showAgentHelp($context);
        }

        if (preg_match('/\b(tutorial|guide)\b/iu', $lower)) {
            return $this->showTips($context);
        }

        if (preg_match('/\b(progression|progress|score|adoption|level)\b/iu', $lower)) {
            return $this->showProgression($context);
        }

        if (preg_match('/\b(streak|serie|jours?\s+consecutifs?|combo\s+jours?|ma\s+serie)\b/iu', $lower)) {
            return $this->showStreak($context);
        }

        if (preg_match('/\b(?:cherche(?:r)?\s+(?:un\s+)?agent|search\s+agent|trouver?\s+(?:un\s+)?agent|quel\s+agent\s+(?:pour|fait|permet)|quel\s+agent)\b/iu', $lower)) {
            return $this->searchAgents($context);
        }

        if (preg_match('/\b(coaching|coach|assistant\s+ia)\b/iu', $lower)) {
            return $this->handleSuggestAgents($context);
        }

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
        try {
            $stats = UserAgentAnalytic::getUserStats($context->from);
        } catch (\Exception $e) {
            $reply = "Impossible de charger les statistiques pour le moment. Reessaie dans quelques instants.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply);
        }

        if ($stats['total_interactions'] === 0) {
            $reply = "*Statistiques d'utilisation*\n\n"
                . "Pas encore de donnees disponibles.\n"
                . "Commence a utiliser les agents et reviens ici pour voir tes stats !";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply);
        }

        $agentLines = [];
        $rank       = 1;
        foreach (array_slice($stats['agents_used'], 0, 8) as $agent => $count) {
            $pct    = round($count / $stats['total_interactions'] * 100, 1);
            $barLen = max(1, intval($pct / 10));
            $bar    = str_repeat('█', $barLen) . str_repeat('░', 10 - $barLen);
            $agentLines[] = "  {$rank}. *{$agent}* [{$bar}] {$count}x ({$pct}%)";
            $rank++;
        }

        $avgDuration = ($stats['avg_duration_ms'] ?? 0) > 0
            ? round($stats['avg_duration_ms'] / 1000, 1) . 's'
            : 'N/A';

        $streak = $this->computeStreak($context->from);
        $streakLine = $streak > 0
            ? "Serie active: *{$streak} jour(s) consecutif(s)*\n"
            : "";

        $reply = "*Statistiques d'utilisation (30 derniers jours)*\n\n"
            . "Total interactions: *{$stats['total_interactions']}*\n"
            . "Agents utilises: *{$stats['unique_agents']}* / " . self::TOTAL_AGENTS . "\n"
            . "Taux de succes: *{$stats['success_rate']}%*\n"
            . "Score d'adoption: *{$stats['adoption_score']}%*\n"
            . "Duree moy. reponse: *{$avgDuration}*\n"
            . $streakLine . "\n"
            . "*Top agents:*\n" . implode("\n", $agentLines) . "\n\n"
            . "_Dis 'progression' pour ton niveau, 'badges' pour tes recompenses, 'serie' pour ta serie ou 'bilan semaine' pour la tendance._";

        $this->sendText($context->from, $reply);
        $this->log($context, 'Stats displayed', [
            'total'    => $stats['total_interactions'],
            'adoption' => $stats['adoption_score'],
        ]);
        return AgentResult::reply($reply);
    }

    /**
     * Show week-over-week comparison summary.
     */
    private function showWeeklySummary(AgentContext $context): AgentResult
    {
        try {
            $thisWeek = UserAgentAnalytic::byUser($context->from)
                ->where('created_at', '>=', now()->subDays(7))
                ->get();

            $lastWeek = UserAgentAnalytic::byUser($context->from)
                ->whereBetween('created_at', [now()->subDays(14), now()->subDays(7)])
                ->get();
        } catch (\Exception $e) {
            $reply = "Impossible de charger le bilan hebdomadaire pour le moment.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply);
        }

        $thisCount = $thisWeek->count();
        $lastCount = $lastWeek->count();

        if ($thisCount === 0 && $lastCount === 0) {
            $reply = "*Bilan Hebdomadaire*\n\n"
                . "Aucune donnee pour les 14 derniers jours.\n"
                . "Commence a utiliser les agents pour voir ta progression !";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply);
        }

        $trend    = '=';
        $trendMsg = "Meme niveau qu'a la semaine derniere.";
        if ($lastCount > 0) {
            $diff = $thisCount - $lastCount;
            if ($diff > 0) {
                $diffPct  = round($diff / $lastCount * 100);
                $trend    = '+';
                $trendMsg = "En hausse de {$diffPct}% par rapport a la semaine derniere.";
            } elseif ($diff < 0) {
                $diffPct  = round(abs($diff) / $lastCount * 100);
                $trend    = '-';
                $trendMsg = "En baisse de {$diffPct}% par rapport a la semaine derniere.";
            }
        } elseif ($thisCount > 0) {
            $trend    = '+';
            $trendMsg = "Premiere semaine d'activite enregistree !";
        }

        $thisAgents  = $thisWeek->countBy('agent_used')->sortDesc()->take(5);
        $thisSuccess = $thisCount > 0
            ? round($thisWeek->where('success', true)->count() / $thisCount * 100, 1)
            : 0;

        $topLines = [];
        foreach ($thisAgents as $agent => $count) {
            $topLines[] = "  - *{$agent}*: {$count}x";
        }

        $reply = "*Bilan Hebdomadaire*\n\n"
            . "Cette semaine: *{$thisCount}* interactions [{$trend}]\n"
            . "Semaine derniere: *{$lastCount}* interactions\n"
            . "Taux de succes: *{$thisSuccess}%*\n";

        if (!empty($topLines)) {
            $reply .= "\n*Top agents cette semaine:*\n" . implode("\n", $topLines) . "\n";
        }

        $reply .= "\n_{$trendMsg}_";

        $this->sendText($context->from, $reply);
        $this->log($context, 'Weekly summary displayed', [
            'this_week' => $thisCount,
            'last_week' => $lastCount,
        ]);
        return AgentResult::reply($reply);
    }

    /**
     * Show monthly usage summary (new in v1.2.0).
     * Compares current month vs previous month with per-agent breakdown.
     */
    private function showMonthlySummary(AgentContext $context): AgentResult
    {
        try {
            $thisMonth = UserAgentAnalytic::byUser($context->from)
                ->where('created_at', '>=', now()->startOfMonth())
                ->get();

            $lastMonth = UserAgentAnalytic::byUser($context->from)
                ->whereBetween('created_at', [
                    now()->subMonth()->startOfMonth(),
                    now()->subMonth()->endOfMonth(),
                ])
                ->get();
        } catch (\Exception $e) {
            $reply = "Impossible de charger le rapport mensuel pour le moment.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply);
        }

        $thisCount = $thisMonth->count();
        $lastCount = $lastMonth->count();

        if ($thisCount === 0 && $lastCount === 0) {
            $reply = "*Rapport Mensuel*\n\n"
                . "Aucune donnee ce mois-ci.\n"
                . "Commence a utiliser les agents pour voir ton rapport !";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply);
        }

        $monthName = now()->locale('fr')->monthName;
        $lastMonthName = now()->subMonth()->locale('fr')->monthName;

        $diff = $thisCount - $lastCount;
        $trendIcon = $diff > 0 ? '+' : ($diff < 0 ? '-' : '=');
        $diffPct = $lastCount > 0 ? round(abs($diff) / $lastCount * 100) : 0;
        $trendMsg = $lastCount > 0
            ? ($diff > 0 ? "Progression de {$diffPct}% vs {$lastMonthName}" : ($diff < 0 ? "Baisse de {$diffPct}% vs {$lastMonthName}" : "Meme niveau que {$lastMonthName}"))
            : ($thisCount > 0 ? "Premier mois d'activite !" : "Aucune donnee.");

        $thisAgents = $thisMonth->countBy('agent_used')->sortDesc()->take(5);
        $thisUnique = $thisMonth->pluck('agent_used')->unique()->count();
        $thisSuccess = $thisCount > 0
            ? round($thisMonth->where('success', true)->count() / $thisCount * 100, 1)
            : 0;

        $topLines = [];
        foreach ($thisAgents as $agent => $count) {
            $pct = $thisCount > 0 ? round($count / $thisCount * 100) : 0;
            $topLines[] = "  - *{$agent}*: {$count}x ({$pct}%)";
        }

        $reply = "*Rapport Mensuel — " . ucfirst($monthName) . "*\n\n"
            . "Ce mois: *{$thisCount}* interactions [{$trendIcon}]\n"
            . ucfirst($lastMonthName) . ": *{$lastCount}* interactions\n"
            . "Agents uniques: *{$thisUnique}*\n"
            . "Taux de succes: *{$thisSuccess}%*\n";

        if (!empty($topLines)) {
            $reply .= "\n*Top agents du mois:*\n" . implode("\n", $topLines) . "\n";
        }

        $reply .= "\n_{$trendMsg}_\n"
            . "_Dis 'bilan semaine' pour la vue hebdomadaire ou 'badges' pour tes recompenses._";

        $this->sendText($context->from, $reply);
        $this->log($context, 'Monthly summary displayed', [
            'this_month' => $thisCount,
            'last_month' => $lastCount,
        ]);
        return AgentResult::reply($reply);
    }

    /**
     * Show personalized usage guide for a specific agent.
     * Supports alias matching (e.g. "budget" → "budget_tracker").
     */
    private function showAgentHelp(AgentContext $context): AgentResult
    {
        $lower     = mb_strtolower($context->body ?? '');
        $allAgents = $this->getAllAgentsMap();

        // First: exact match on agent name
        $targetAgent = null;
        foreach (array_keys($allAgents) as $agentName) {
            if (str_contains($lower, $agentName)) {
                $targetAgent = $agentName;
                break;
            }
        }

        // Second: alias/fuzzy match
        if (!$targetAgent) {
            foreach (self::AGENT_ALIASES as $alias => $agentName) {
                if (str_contains($lower, $alias) && isset($allAgents[$agentName])) {
                    $targetAgent = $agentName;
                    break;
                }
            }
        }

        if (!$targetAgent) {
            $list  = implode(', ', array_map(fn ($k) => "*{$k}*", array_keys($allAgents)));
            $reply = "*Guide des agents disponibles*\n\n"
                . "Pour obtenir l'aide d'un agent specifique, ecris par exemple:\n"
                . "_guide budget_tracker_\n"
                . "_comment utiliser pomodoro_\n"
                . "_aide todo_\n\n"
                . "Agents disponibles:\n{$list}\n\n"
                . "_Dis 'commandes' pour une reference rapide de toutes les commandes._";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply);
        }

        $agentDesc    = $allAgents[$targetAgent];
        $systemPrompt = <<<PROMPT
Tu es un assistant coaching pour une app WhatsApp avec des agents IA. Explique comment utiliser l'agent "{$targetAgent}" ({$agentDesc}).

Structure ta reponse EXACTEMENT ainsi (sans titres supplementaires) :
1. Ce que fait l'agent : 1 phrase claire
2. Exemples de messages WhatsApp (4-5 exemples varies couvrant les actions principales) :
   - *message exact* — ce que ca fait
3. Astuce avancee : 1 seule astuce peu connue, prefixee par "💡 Astuce :"

Regles de format WhatsApp :
- *texte* pour les commandes/messages exemples
- Tirets simples pour les listes
- Maximum 16 lignes au total
- Pas de markdown complexe (pas de ##, pas de ```)
- En francais, ton direct et actionnable
PROMPT;

        $response = $this->claude->chat(
            "Guide pour l'agent: {$targetAgent}",
            $this->resolveModel($context),
            $systemPrompt
        );

        if ($response) {
            $reply = $response;
        } else {
            $reply = "*Guide: {$targetAgent}*\n\n"
                . "{$agentDesc}\n\n"
                . "Envoie un message contenant le mot *{$targetAgent}* pour l'activer.\n"
                . "_Dis 'tips' pour voir d'autres astuces ou 'suggest agents' pour des recommandations._";
        }

        $this->sendText($context->from, $reply);
        $this->log($context, 'Agent help displayed', ['target_agent' => $targetAgent]);
        return AgentResult::reply($reply);
    }

    /**
     * Analyze the last 10 interactions, detect missing patterns,
     * and return 2-3 relevant agent suggestions with reasons.
     * Standalone method callable from outside (e.g. AgentOrchestrator, SendProactiveTips).
     */
    public function suggestAgentsForUser(string $userId): array
    {
        try {
            $recentInteractions = UserAgentAnalytic::getRecentInteractions($userId, 10);
            $stats              = UserAgentAnalytic::getUserStats($userId);
        } catch (\Exception $e) {
            return [];
        }

        $usedAgents  = array_keys($stats['agents_used']);
        $suggestions = [];

        // Pattern: user writes code but doesn't use code_review
        if (in_array('dev', $usedAgents) && !in_array('code_review', $usedAgents)) {
            $suggestions[] = [
                'agent'  => 'code_review',
                'label'  => 'Code Review',
                'reason' => 'Tu codes souvent — fais reviewer ton code automatiquement pour detecter bugs et failles',
            ];
        }

        // Pattern: user uses todo but not pomodoro
        if (in_array('todo', $usedAgents) && !in_array('pomodoro', $usedAgents)) {
            $suggestions[] = [
                'agent'  => 'pomodoro',
                'label'  => 'Pomodoro',
                'reason' => 'Tu geres tes taches — combine avec des sessions focus pour etre plus productif',
            ];
        }

        // Pattern: user uses reminder but not habit
        if (in_array('reminder', $usedAgents) && !in_array('habit', $usedAgents)) {
            $suggestions[] = [
                'agent'  => 'habit',
                'label'  => 'HabitAgent',
                'reason' => 'Tu utilises les rappels — essaie le suivi d\'habitudes pour des routines durables',
            ];
        }

        // Pattern: user uses chat a lot but not content_summarizer
        $chatCount = $recentInteractions->where('agent_used', 'chat')->count();
        if ($chatCount >= 5 && !in_array('content_summarizer', $usedAgents)) {
            $suggestions[] = [
                'agent'  => 'content_summarizer',
                'label'  => 'ContentSummarizer',
                'reason' => 'Tu poses beaucoup de questions — resume des articles directement avec un lien',
            ];
        }

        // Pattern: no budget tracking
        if (!in_array('budget_tracker', $usedAgents) && !in_array('finance', $usedAgents)) {
            $suggestions[] = [
                'agent'  => 'budget_tracker',
                'label'  => 'BudgetTracker',
                'reason' => 'Suis tes depenses au quotidien en envoyant simplement tes achats',
            ];
        }

        // Pattern: no time management
        if (!in_array('time_blocker', $usedAgents) && !in_array('pomodoro', $usedAgents)) {
            $suggestions[] = [
                'agent'  => 'time_blocker',
                'label'  => 'TimeBlocker',
                'reason' => 'Optimise ta journee avec des blocs de temps intelligents',
            ];
        }

        // Pattern: dev without flashcard
        if (in_array('dev', $usedAgents) && !in_array('flashcard', $usedAgents)) {
            $suggestions[] = [
                'agent'  => 'flashcard',
                'label'  => 'FlashcardAgent',
                'reason' => 'Tu developpes — cree des flashcards pour memoriser des concepts techniques',
            ];
        }

        // Fallback: suggest popular agents if few matches
        if (count($suggestions) < 2) {
            $popularUnused = array_diff(['todo', 'reminder', 'pomodoro', 'habit', 'daily_brief'], $usedAgents);
            foreach (array_slice($popularUnused, 0, 3 - count($suggestions)) as $agent) {
                $descs = [
                    'todo'        => 'Organise tes taches avec des checklists intelligentes',
                    'reminder'    => 'Ne rate plus jamais une deadline',
                    'pomodoro'    => 'Booste ta productivite avec des sessions focus de 25 min',
                    'habit'       => 'Construis des routines durables avec le suivi d\'habitudes',
                    'daily_brief' => 'Recois chaque matin un resume personnalise de ta journee',
                ];
                $suggestions[] = [
                    'agent'  => $agent,
                    'label'  => ucfirst($agent),
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
        try {
            $recentInteractions = UserAgentAnalytic::getRecentInteractions($context->from, 10);
            $stats              = UserAgentAnalytic::getUserStats($context->from);
        } catch (\Exception $e) {
            $reply = "Impossible de charger tes donnees pour le moment. Reessaie dans quelques instants.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply);
        }

        $usedAgents  = array_keys($stats['agents_used']);
        $allAgents   = $this->getAllAgentsMap();
        $unusedAgents = array_diff_key($allAgents, array_flip($usedAgents));

        $recentAgentsList = $recentInteractions->pluck('agent_used')->unique()->implode(', ') ?: 'aucun';
        $unusedList       = implode(', ', array_keys(array_slice($unusedAgents, 0, 12)));
        $statsSum         = "Total: {$stats['total_interactions']} interactions, {$stats['unique_agents']} agents, adoption: {$stats['adoption_score']}%";
        $memoryContext    = $this->formatContextMemoryForPrompt($context->from, $context);

        $systemPrompt = <<<PROMPT
Tu es un assistant coaching IA sur WhatsApp. Analyse les patterns d'utilisation et suggere 3 agents pertinents que l'utilisateur n'a pas encore essaye.

STATISTIQUES UTILISATEUR: {$statsSum}
AGENTS RECEMMENT UTILISES: {$recentAgentsList}
AGENTS NON UTILISES (disponibles): {$unusedList}
{$memoryContext}

Reponds en francais avec:
1. Un bref constat sur l'utilisation actuelle (1 phrase max)
2. 3 suggestions d'agents : nom en *gras*, raison personnalisee (1 phrase), exemple de message concret entre guillemets
3. Une phrase d'encouragement courte

Format WhatsApp : *gras* pour les noms d'agents, pas de markdown complexe. Max 15 lignes. Sois concis et personnalise.
PROMPT;

        $response = $this->claude->chat(
            "Suggestions d'agents pour l'utilisateur {$context->from}",
            $this->resolveModel($context),
            $systemPrompt
        );

        $reply = $response ?? $this->fallbackSuggestions($unusedAgents);
        $this->sendText($context->from, $reply);
        $this->log($context, 'Agent suggestions generated', [
            'used_agents'   => count($usedAgents),
            'unused_agents' => count($unusedAgents),
        ]);
        return AgentResult::reply($reply);
    }

    /**
     * Show personalized tips based on user's usage history.
     */
    private function showTips(AgentContext $context): AgentResult
    {
        try {
            $stats      = UserAgentAnalytic::getUserStats($context->from);
            $usedAgents = array_keys($stats['agents_used']);
        } catch (\Exception $e) {
            $usedAgents = [];
        }

        $allTips = [
            'voice'              => "Envoie un message vocal pour que *VoiceCommand* le transcrive et execute la commande automatiquement.",
            'pomodoro'           => "Tape *pomodoro* pour lancer une session de focus de 25 min avec rappels automatiques.",
            'content_summarizer' => "Dis *resume [URL]* pour obtenir un resume intelligent de n'importe quel article web.",
            'daily_brief'        => "Utilise *daily brief* pour recevoir un resume personnalise de ta journee chaque matin.",
            'budget_tracker'     => "Ecris *depense 25 restaurant* pour tracker tes depenses en 2 secondes.",
            'recipe'             => "Tape *recette poulet tomate* pour des idees cuisine avec tes ingredients disponibles.",
            'habit'              => "Dis *habitude mediter 10 min* pour commencer a tracker une nouvelle routine quotidienne.",
            'screenshot'         => "Envoie une image pour que *Screenshot* en extraie le texte automatiquement (OCR).",
            'interactive_quiz'   => "Tape *quiz science* pour un quiz ludique avec scoring en temps reel.",
            'reminder'           => "Utilise *rappelle-moi dans 2h de...* pour un rappel ultra-rapide.",
            'time_blocker'       => "Tape *bloque ma journee* pour optimiser ton planning par blocs de temps intelligents.",
            'assistant'          => "Dis *mes stats* pour voir tes statistiques ou *badges* pour tes recompenses.",
            'flashcard'          => "Tape *flashcard JavaScript* pour creer une fiche d'apprentissage en 1 message.",
            'web_search'         => "Dis *cherche actualites IA* pour une recherche web en temps reel.",
            'code_review'        => "Envoie ton code avec *review* pour une analyse de qualite, securite et bonnes pratiques.",
            'mood_check'         => "Dis *humeur* ou *comment je me sens* pour faire le point sur ton bien-etre.",
            'smart_meeting'      => "Tape *resume reunion* suivi de tes notes pour une synthese structuree automatique.",
            'game_master'        => "Dis *enigme* ou *jouer* pour une session de jeu interactif avec le Game Master.",
            'daily_brief2'       => "Personalise ton *daily brief* avec des categories (meteo, agenda, news) selon tes preferences.",
            'budget_tracker2'    => "Tape *mes depenses restaurant* pour voir toutes tes depenses d'une categorie specifique.",
            'assistant_streak'   => "Dis *ma serie* pour voir ton nombre de jours consecutifs d'utilisation et debloquer des badges.",
            'assistant_search'   => "Dis *cherche agent [mot-cle]* pour trouver l'agent ideal pour une tache specifique.",
            'assistant_guide'    => "Dis *guide [nom_agent]* pour recevoir un tutoriel complet sur n'importe quel agent.",
        ];

        // Prioritize tips for agents not yet used (discovery), then fill with used ones (reinforcement)
        $unusedTips = array_values(array_filter($allTips, fn ($_, $k) => !in_array(rtrim($k, '0123456789'), $usedAgents), ARRAY_FILTER_USE_BOTH));
        $usedTips   = array_values(array_filter($allTips, fn ($_, $k) => in_array(rtrim($k, '0123456789'), $usedAgents), ARRAY_FILTER_USE_BOTH));

        // Build a pool: unused tips first (shuffled), then used tips as filler
        $pool     = collect($unusedTips)->shuffle()->merge(collect($usedTips)->shuffle());
        $selected = $pool->take(5);

        if ($selected->isEmpty()) {
            $selected = collect(array_values($allTips))->shuffle()->take(5);
        }

        $reply = "*Astuces & Raccourcis*\n\n";
        foreach ($selected->values() as $i => $tip) {
            $reply .= ($i + 1) . ". {$tip}\n\n";
        }

        $reply .= "_Dis 'guide [agent]' pour apprendre a utiliser un agent specifique._\n"
            . "_Dis 'commandes' pour une reference rapide de toutes les commandes._";

        $this->sendText($context->from, $reply);
        $this->log($context, 'Tips displayed', ['unused_tip_count' => count($unusedTips)]);
        return AgentResult::reply($reply);
    }

    /**
     * Show gamification badges and achievements (new in v1.2.0).
     * Unlocks based on usage patterns, milestones and combos.
     */
    private function showAchievements(AgentContext $context): AgentResult
    {
        try {
            $stats = UserAgentAnalytic::getUserStats($context->from);
        } catch (\Exception $e) {
            $reply = "Impossible de charger tes badges pour le moment. Reessaie dans quelques instants.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply);
        }

        $total       = $stats['total_interactions'];
        $unique      = $stats['unique_agents'];
        $successRate = $stats['success_rate'];
        $usedAgents  = array_keys($stats['agents_used']);

        $unlocked = [];
        $locked   = [];

        // --- Interaction milestones ---
        $interactionBadges = [
            10  => ['Curieux', 'Premieres 10 interactions franchies'],
            50  => ['Explorateur', '50 interactions - tu prends l\'habitude'],
            100 => ['Utilisateur Regulier', '100 interactions - un vrai habitue'],
            500 => ['Power User', '500 interactions - un expert confirme'],
        ];
        foreach ($interactionBadges as $threshold => [$name, $desc]) {
            if ($total >= $threshold) {
                $unlocked[] = "✅ *{$name}* — {$desc}";
            } else {
                $remaining = $threshold - $total;
                $locked[]  = "🔒 {$name} — encore {$remaining} interaction(s) necessaires";
            }
        }

        // --- Agent diversity milestones ---
        $diversityBadges = [
            5  => ['Multi-Talent', '5 agents differents explores'],
            10 => ['Explorateur IA', '10 agents decouverts'],
            20 => ['Maitre des Agents', '20 agents explores'],
        ];
        foreach ($diversityBadges as $threshold => [$name, $desc]) {
            if ($unique >= $threshold) {
                $unlocked[] = "✅ *{$name}* — {$desc}";
            } else {
                $remaining = $threshold - $unique;
                $locked[]  = "🔒 {$name} — encore {$remaining} agent(s) a decouvrir";
            }
        }

        // --- Combo badges ---
        $productivityCombo = ['todo', 'pomodoro', 'habit'];
        if (count(array_intersect($productivityCombo, $usedAgents)) === 3) {
            $unlocked[] = "✅ *Maitre de la Productivite* — todo + pomodoro + habit utilises ensemble";
        } else {
            $missing = array_diff($productivityCombo, $usedAgents);
            $locked[] = "🔒 Maitre de la Productivite — utilise aussi: " . implode(', ', $missing);
        }

        $devCombo = ['dev', 'code_review'];
        if (count(array_intersect($devCombo, $usedAgents)) === 2) {
            $unlocked[] = "✅ *DevOps Affirme* — dev + code_review utilises ensemble";
        } else {
            $locked[] = "🔒 DevOps Affirme — utilise dev ET code_review";
        }

        $wellbeingCombo = ['mood_check', 'habit'];
        if (count(array_intersect($wellbeingCombo, $usedAgents)) === 2) {
            $unlocked[] = "✅ *Bien-etre Champion* — mood_check + habit combines";
        } else {
            $locked[] = "🔒 Bien-etre Champion — utilise mood_check ET habit";
        }

        // --- Streak badge ---
        $streak = $this->computeStreak($context->from);
        if ($streak >= 7) {
            $unlocked[] = "✅ *Assidu* — 7 jours consecutifs d'utilisation";
        } elseif ($streak >= 3) {
            $unlocked[] = "✅ *Sur la Lance* — 3 jours consecutifs d'utilisation";
        } else {
            $remaining = 3 - $streak;
            $locked[] = "🔒 Sur la Lance — encore {$remaining} jour(s) consecutif(s) necessaires";
        }

        // --- Quality badges ---
        if ($total >= 10 && $successRate >= 95) {
            $unlocked[] = "✅ *Precision Parfaite* — taux de succes >= 95% sur 10+ interactions";
        } elseif ($total >= 10 && $successRate >= 90) {
            $unlocked[] = "✅ *Expert en Commandes* — taux de succes >= 90%";
        } elseif ($total < 10 || $successRate < 90) {
            $locked[] = "🔒 Expert en Commandes — atteins 90% de succes sur 10+ interactions";
        }

        // --- Build response ---
        $unlockedCount = count($unlocked);
        $totalBadges   = $unlockedCount + count($locked);

        $reply = "*Badges & Recompenses*\n\n";
        $reply .= "Debloques: *{$unlockedCount} / {$totalBadges}*\n\n";

        if (!empty($unlocked)) {
            $reply .= "*Tes badges:*\n";
            foreach ($unlocked as $badge) {
                $reply .= "{$badge}\n";
            }
            $reply .= "\n";
        } else {
            $reply .= "Aucun badge debloque pour l'instant.\n\n";
        }

        if (!empty($locked)) {
            $reply .= "*Prochains objectifs:*\n";
            foreach (array_slice($locked, 0, 3) as $badge) {
                $reply .= "{$badge}\n";
            }
        }

        $reply .= "\n_Dis 'progression' pour ton score global ou 'suggest agents' pour des conseils._";

        $this->sendText($context->from, $reply);
        $this->log($context, 'Achievements displayed', ['unlocked' => $unlockedCount]);
        return AgentResult::reply($reply);
    }

    /**
     * Show quick commands reference for all major agents (new in v1.2.0).
     */
    private function showCommandsReference(AgentContext $context): AgentResult
    {
        $reply = "*Reference Rapide des Commandes*\n\n"
            . "*Productivite:*\n"
            . "- *todo*: `ajoute acheter du lait`\n"
            . "- *pomodoro*: `pomodoro 25 min focus`\n"
            . "- *habit*: `habitude mediter 10 min`\n"
            . "- *reminder*: `rappelle-moi dans 2h de...`\n"
            . "- *time_blocker*: `bloque ma journee`\n\n"
            . "*Finance:*\n"
            . "- *budget_tracker*: `depense 25 restaurant`\n"
            . "- *finance*: `mon solde`, `rapport depenses`\n\n"
            . "*IA & Contenu:*\n"
            . "- *content_summarizer*: `resume https://...`\n"
            . "- *web_search*: `cherche actualites IA`\n"
            . "- *flashcard*: `flashcard Python lambda`\n"
            . "- *daily_brief*: `daily brief`\n\n"
            . "*Dev:*\n"
            . "- *dev*: `gitlab issues ouverts`\n"
            . "- *code_review*: `review [colle ton code]`\n\n"
            . "*Bien-etre & Jeux:*\n"
            . "- *mood_check*: `comment je me sens`\n"
            . "- *recipe*: `recette poulet tomate`\n"
            . "- *quiz*: `quiz geographie`\n\n"
            . "*Assistant:*\n"
            . "- `mes stats`, `progression`, `badges`\n"
            . "- `bilan semaine`, `rapport mensuel`\n"
            . "- `suggest agents`, `tips`\n"
            . "- `guide [nom_agent]`\n"
            . "- `ma serie` — serie de jours consecutifs\n"
            . "- `cherche agent [mot-cle]` — trouver l'agent ideal\n\n"
            . "_Dis 'guide [agent]' pour les details complets d'un agent._";

        $this->sendText($context->from, $reply);
        $this->log($context, 'Commands reference displayed');
        return AgentResult::reply($reply);
    }

    /**
     * Show progression and adoption score.
     */
    private function showProgression(AgentContext $context): AgentResult
    {
        try {
            $stats = UserAgentAnalytic::getUserStats($context->from);
        } catch (\Exception $e) {
            $reply = "Impossible de charger ta progression pour le moment.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply);
        }

        $score = $stats['adoption_score'];

        $filled = intval($score / 5);
        $empty  = 20 - $filled;
        $bar    = str_repeat('█', $filled) . str_repeat('░', $empty);

        $level = match (true) {
            $score >= 80 => 'Expert',
            $score >= 60 => 'Avance',
            $score >= 40 => 'Intermediaire',
            $score >= 20 => 'Debutant',
            default      => 'Novice',
        };

        $levelStars = match ($level) {
            'Expert'        => '[*****]',
            'Avance'        => '[****.]',
            'Intermediaire' => '[***..]',
            'Debutant'      => '[**...]',
            default         => '[*....] ',
        };

        $nextThreshold = match (true) {
            $score < 20 => 20,
            $score < 40 => 40,
            $score < 60 => 60,
            $score < 80 => 80,
            default     => 100,
        };

        $agentsNeeded  = $score < 100 ? (int) ceil(($nextThreshold - $score) * self::TOTAL_AGENTS / 100) : 0;
        $nextLevelHint = $agentsNeeded > 0
            ? "Il te manque ~{$agentsNeeded} agent(s) pour atteindre le niveau suivant."
            : "Tu as atteint le score maximum !";

        $reply = "*Progression*\n\n"
            . "Niveau: *{$level}* {$levelStars}\n"
            . "Score d'adoption: *{$score}%*\n"
            . "[{$bar}]\n\n"
            . "Interactions totales: *{$stats['total_interactions']}*\n"
            . "Agents decouverts: *{$stats['unique_agents']}* / " . self::TOTAL_AGENTS . "\n"
            . "Taux de succes: *{$stats['success_rate']}%*\n\n"
            . "_{$nextLevelHint}_\n\n";

        if ($score < 40) {
            $reply .= "_Explore plus d'agents pour augmenter ton score ! Dis 'suggest agents' pour des idees._";
        } elseif ($score < 70) {
            $reply .= "_Bonne progression ! Continue a explorer de nouveaux agents._\n"
                . "_Dis 'badges' pour voir tes recompenses debloques._";
        } else {
            $reply .= "_Excellent ! Tu maitrises une grande partie des fonctionnalites._\n"
                . "_Dis 'badges' pour voir tes achievements._";
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
        try {
            $stats = UserAgentAnalytic::getUserStats($context->from);
        } catch (\Exception $e) {
            $stats = ['total_interactions' => 0, 'unique_agents' => 0, 'adoption_score' => 0];
        }

        $reply = "*AI Assistant - Coaching Personnalise*\n\n"
            . "Voici ce que je peux faire pour toi:\n\n"
            . "1. *mes stats* — Statistiques d'utilisation (30j)\n"
            . "2. *suggest agents* — Recommandations personnalisees\n"
            . "3. *tips* — Astuces et raccourcis utiles\n"
            . "4. *progression* — Ton score d'adoption et niveau\n"
            . "5. *bilan semaine* — Comparaison semaine/semaine\n"
            . "6. *rapport mensuel* — Bilan mensuel detaille\n"
            . "7. *badges* — Tes recompenses et achievements\n"
            . "8. *commandes* — Reference rapide de toutes les commandes\n"
            . "9. *guide [agent]* — Apprendre a utiliser un agent\n"
            . "10. *ma serie* — Serie de jours consecutifs d'utilisation\n"
            . "11. *cherche agent [mot-cle]* — Trouver l'agent ideal\n\n";

        if ($stats['total_interactions'] > 0) {
            $reply .= "Tes stats rapides:\n"
                . "- {$stats['total_interactions']} interactions ces 30 derniers jours\n"
                . "- {$stats['unique_agents']} / " . self::TOTAL_AGENTS . " agents utilises\n"
                . "- Score d'adoption: {$stats['adoption_score']}%\n";
        } else {
            $reply .= "_Commence a utiliser les agents pour debloquer tes statistiques !_\n"
                . "_Dis 'commandes' pour voir toutes les commandes disponibles._";
        }

        $this->sendText($context->from, $reply);
        $this->log($context, 'Overview displayed');
        return AgentResult::reply($reply);
    }

    /**
     * Fallback suggestions when Claude is unavailable.
     * Prioritizes high-value agents not yet used.
     */
    private function fallbackSuggestions(array $unusedAgents): string
    {
        $priority    = ['todo', 'reminder', 'pomodoro', 'habit', 'daily_brief', 'budget_tracker'];
        $prioritized = [];
        foreach ($priority as $name) {
            if (isset($unusedAgents[$name])) {
                $prioritized[$name] = $unusedAgents[$name];
            }
        }
        foreach ($unusedAgents as $name => $desc) {
            if (!isset($prioritized[$name])) {
                $prioritized[$name] = $desc;
            }
        }

        $suggestions = array_slice($prioritized, 0, 3, true);
        $reply = "*Suggestions d'agents a decouvrir:*\n\n";
        $i     = 1;
        foreach ($suggestions as $name => $desc) {
            $reply .= "{$i}. *{$name}* — {$desc}\n";
            $i++;
        }

        $reply .= "\n_Dis 'guide [nom_agent]' pour apprendre a l'utiliser._";
        return $reply;
    }

    /**
     * Compute the current consecutive daily usage streak for a user.
     * Returns 0 if no activity today or yesterday.
     */
    private function computeStreak(string $userId): int
    {
        try {
            $days = UserAgentAnalytic::byUser($userId)
                ->where('created_at', '>=', now()->subDays(60))
                ->get(['created_at'])
                ->map(fn ($a) => $a->created_at->toDateString())
                ->unique()
                ->sort()
                ->reverse()
                ->values();
        } catch (\Exception $e) {
            return 0;
        }

        if ($days->isEmpty()) {
            return 0;
        }

        $today     = now()->toDateString();
        $yesterday = now()->subDay()->toDateString();

        // Streak is only active if the user was active today or yesterday
        if (!$days->contains($today) && !$days->contains($yesterday)) {
            return 0;
        }

        $streak   = 0;
        $startDay = $days->contains($today) ? now() : now()->subDay();

        for ($i = 0; $i <= 59; $i++) {
            $dateStr = $startDay->copy()->subDays($i)->toDateString();
            if ($days->contains($dateStr)) {
                $streak++;
            } else {
                break;
            }
        }

        return $streak;
    }

    /**
     * Show current consecutive daily usage streak (new in v1.3.0).
     */
    private function showStreak(AgentContext $context): AgentResult
    {
        $streak = $this->computeStreak($context->from);

        if ($streak === 0) {
            try {
                $lastActivity = UserAgentAnalytic::byUser($context->from)
                    ->orderByDesc('created_at')
                    ->value('created_at');
            } catch (\Exception $e) {
                $lastActivity = null;
            }

            if (!$lastActivity) {
                $reply = "*Serie d'utilisation*\n\n"
                    . "Aucune activite enregistree pour le moment.\n"
                    . "Commence a utiliser les agents pour demarrer une serie !";
            } else {
                $daysAgo = now()->diffInDays($lastActivity);
                $reply = "*Serie d'utilisation*\n\n"
                    . "Pas de serie active.\n"
                    . "Derniere activite : il y a *{$daysAgo} jour(s)*.\n\n"
                    . "_Utilise un agent aujourd'hui pour relancer ta serie !_";
            }

            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply);
        }

        // Build visual streak bar (max 14 days shown)
        $displayDays = min($streak, 14);
        $streakBar   = str_repeat('🔥', $displayDays);

        $badge = match (true) {
            $streak >= 30 => 'Legende (30j+)',
            $streak >= 14 => 'Inarretable (14j+)',
            $streak >= 7  => 'Assidu (7j+)',
            $streak >= 3  => 'Sur la Lance (3j+)',
            default       => 'Debutant',
        };

        $motivation = match (true) {
            $streak >= 30 => 'Incroyable ! 30 jours sans interruption, tu es une legende !',
            $streak >= 14 => 'Deux semaines d\'afilee, tu es inarretable !',
            $streak >= 7  => 'Une semaine entiere ! Le badge "Assidu" est debloques.',
            $streak >= 3  => 'Trois jours de suite, continue comme ca !',
            default       => 'Belle serie ! Reviens demain pour continuer.',
        };

        $nextMilestone = match (true) {
            $streak >= 30 => null,
            $streak >= 14 => 30,
            $streak >= 7  => 14,
            $streak >= 3  => 7,
            default       => 3,
        };

        $reply = "*Serie d'utilisation*\n\n"
            . "{$streakBar}\n"
            . "Serie actuelle : *{$streak} jour(s) consecutif(s)*\n"
            . "Badge : *{$badge}*\n\n"
            . "_{$motivation}_";

        if ($nextMilestone) {
            $remaining = $nextMilestone - $streak;
            $reply .= "\n\n_Encore {$remaining} jour(s) pour atteindre le palier {$nextMilestone} jours !_";
        }

        $reply .= "\n\n_Dis 'badges' pour voir tous tes achievements._";

        $this->sendText($context->from, $reply);
        $this->log($context, 'Streak displayed', ['streak' => $streak]);
        return AgentResult::reply($reply);
    }

    /**
     * Search agents by keyword to help user discover relevant agents (new in v1.3.0).
     */
    private function searchAgents(AgentContext $context): AgentResult
    {
        $lower = mb_strtolower($context->body ?? '');

        // Extract search term after known trigger phrases
        $searchTerm = '';
        if (preg_match('/(?:cherche(?:r)?\s+(?:un\s+)?agent|search\s+agent|trouver?\s+(?:un\s+)?agent|quel\s+agent\s+(?:pour|fait|permet)|quel\s+agent)\s+(.+)/iu', $lower, $m)) {
            $searchTerm = trim($m[1]);
        }

        if (empty($searchTerm) || mb_strlen($searchTerm) < 2) {
            $reply = "*Recherche d'agent*\n\n"
                . "Dis-moi ce que tu veux faire, par exemple:\n"
                . "- *cherche agent productivite*\n"
                . "- *quel agent pour les recettes*\n"
                . "- *quel agent pour le code*\n"
                . "- *trouver agent finance*\n\n"
                . "_Ou dis 'commandes' pour voir tous les agents._";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply);
        }

        $allAgents = $this->getAllAgentsMap();
        $keywords  = array_filter(explode(' ', $searchTerm), fn ($k) => mb_strlen($k) >= 2);
        $matches   = [];

        foreach ($allAgents as $name => $desc) {
            $score = 0;
            foreach ($keywords as $kw) {
                if (str_contains(mb_strtolower($name), $kw)) {
                    $score += 2; // name match scores higher
                }
                if (str_contains(mb_strtolower($desc), $kw)) {
                    $score += 1;
                }
            }
            if ($score > 0) {
                $matches[$name] = ['desc' => $desc, 'score' => $score];
            }
        }

        // Sort by score descending
        uasort($matches, fn ($a, $b) => $b['score'] <=> $a['score']);

        if (empty($matches)) {
            $reply = "*Recherche : '{$searchTerm}'*\n\n"
                . "Aucun agent trouve pour cette recherche.\n\n"
                . "Essaie avec d'autres mots-cles :\n"
                . "- productivite, finance, bien-etre, dev, jeux, contenu\n\n"
                . "_Dis 'commandes' pour voir tous les agents disponibles._";
        } else {
            $count = count($matches);
            $reply = "*Agents pour '{$searchTerm}'* ({$count} trouve(s)) :\n\n";
            foreach (array_slice($matches, 0, 5, true) as $name => $info) {
                $reply .= "- *{$name}* — {$info['desc']}\n";
            }
            if ($count > 5) {
                $reply .= "_(et " . ($count - 5) . " autre(s))_\n";
            }
            $reply .= "\n_Dis 'guide [agent]' pour apprendre a l'utiliser._";
        }

        $this->sendText($context->from, $reply);
        $this->log($context, 'Agent search', ['query' => $searchTerm, 'matches' => count($matches)]);
        return AgentResult::reply($reply);
    }

    /**
     * Centralized map of all available agents with descriptions.
     */
    private function getAllAgentsMap(): array
    {
        return [
            'todo'               => 'Gestion de taches et checklists',
            'reminder'           => 'Rappels et notifications temporelles',
            'project'            => 'Gestion de projets GitLab',
            'dev'                => 'Developpement, code et GitLab',
            'finance'            => 'Suivi financier et budgets',
            'habit'              => 'Suivi des habitudes quotidiennes',
            'pomodoro'           => 'Sessions de focus et productivite',
            'flashcard'          => 'Apprentissage par repetition espacee',
            'music'              => 'Musique et playlists',
            'mood_check'         => 'Suivi emotionnel et bien-etre',
            'content_summarizer' => 'Resume d\'articles et pages web',
            'event_reminder'     => 'Evenements avec date/lieu',
            'code_review'        => 'Revue et analyse de code',
            'smart_meeting'      => 'Synthese de reunions',
            'document'           => 'Creation de fichiers Excel/PDF/Word',
            'analysis'           => 'Analyse approfondie de donnees',
            'budget_tracker'     => 'Suivi detaille des depenses',
            'daily_brief'        => 'Resume quotidien personnalise',
            'recipe'             => 'Suggestions de recettes de cuisine',
            'time_blocker'       => 'Optimisation du planning par blocs',
            'web_search'         => 'Recherche web en temps reel',
            'hangman'            => 'Jeu du pendu interactif',
            'interactive_quiz'   => 'Quiz et trivia',
            'game_master'        => 'Jeux interactifs et enigmes',
            'content_curator'    => 'Curation de contenu personnalise',
        ];
    }
}
