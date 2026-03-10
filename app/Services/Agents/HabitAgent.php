<?php

namespace App\Services\Agents;

use App\Models\AppSetting;
use App\Models\Habit;
use App\Models\HabitLog;
use App\Services\AgentContext;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class HabitAgent extends BaseAgent
{
    private const MAX_HABITS = 20;
    private const MAX_NAME_LENGTH = 50;

    public function name(): string
    {
        return 'habit';
    }

    public function description(): string
    {
        return 'Agent de suivi d\'habitudes (habit tracker). Permet de creer des habitudes quotidiennes ou hebdomadaires, les cocher chaque jour (ou plusieurs a la fois), suivre les streaks (series consecutives en jours ou semaines), voir les statistiques et taux de completion sur 30 jours, voir ce qu\'il reste a faire aujourd\'hui (daily ET weekly), renommer une habitude, changer la frequence, voir l\'historique des 7 derniers jours, annuler un log accidentel, recevoir de la motivation, voir le classement des streaks, le rapport hebdomadaire, le rapport mensuel des 30 derniers jours, analyser son meilleur jour de la semaine, mettre en pause/reprendre une habitude temporairement, fixer un objectif quotidien de nombre d\'habitudes a realiser, voir sa serie de jours parfaits (tous les daily faits dans la journee), obtenir des suggestions d\'habitudes complementaires via IA, visualiser un calendrier heatmap des 28 derniers jours, rattraper un oubli en loggant pour hier (backdate), et fixer un defi de streak personnel pour une habitude (streak challenge avec suivi de progression).';
    }

    public function keywords(): array
    {
        return [
            'habitude', 'habitudes', 'habit', 'habits',
            'habit tracker', 'suivi habitude', 'tracker habitude',
            'nouvelle habitude', 'ajouter habitude', 'add habit', 'new habit',
            'creer habitude', 'create habit',
            'cocher habitude', 'check habit', 'log habit',
            'j\'ai fait', 'j\'ai medite', 'j\'ai couru', 'j\'ai lu',
            'mes habitudes', 'my habits', 'liste habitudes', 'list habits',
            'stats habitudes', 'habit stats', 'statistiques habitudes',
            'streak', 'streaks', 'serie', 'mon streak', 'my streak',
            'supprimer habitude', 'delete habit', 'enlever habitude',
            'reset habitude', 'reinitialiser habitude',
            'renommer habitude', 'rename habit', 'changer nom habitude',
            'changer frequence', 'changer la frequence', 'change frequency',
            'passer en hebdo', 'passer en quotidien', 'modifier frequence',
            'historique habitude', 'habit history', 'derniers jours habitude',
            'meditation', 'sport', 'lecture', 'exercice', 'marche',
            'routine', 'routines', 'routine quotidienne', 'daily routine',
            'discipline', 'regularity', 'regularite',
            'aujourd\'hui habitude', 'habitudes du jour', 'today habits',
            'reste a faire', 'pas encore fait', 'pending habits',
            'annuler log', 'decocher habitude', 'unlog habit', 'undo habit',
            'motivation habitude', 'motiver', 'encouragement habitude', 'motivate',
            'aide habitude', 'help habit',
            'classement streak', 'streak board', 'meilleur streak', 'top streak',
            'rapport semaine', 'bilan semaine', 'weekly report', 'rapport habitudes',
            'rapport mensuel', 'bilan mensuel', 'monthly report', 'bilan 30 jours', '30 jours habitudes', 'rapport 30j',
            'meilleur jour', 'best day', 'jour regulier', 'quel jour', 'analyse jour',
            'pause habitude', 'pauser habitude', 'mettre en pause', 'suspendre habitude',
            'reprendre habitude', 'reactiver habitude', 'resume habit',
            'cocher plusieurs', 'log multiple', 'j\'ai fait sport et', 'j\'ai fait meditation et',
            'objectif habitude', 'objectif habitudes', 'fixer objectif', 'but habitude', 'goal habitude',
            'mon objectif', 'objectif du jour', 'objectif quotidien', 'target habitude',
            'jours parfaits', 'jour parfait', 'perfect day', 'serie parfaite', 'jours 100%',
            'jours parfaitement', 'combien jours parfaits', 'serie jours parfaits',
            'comparer semaine', 'compare semaine', 'vs semaine', 'progression semaine', 'comparaison semaine', 'semaine precedente',
            'top habitudes', 'meilleures habitudes', 'habitudes regulieres', 'plus regulier', 'podium habitudes', 'habitudes top',
            'suggerer habitude', 'suggestions habitudes', 'idee habitude', 'nouvelles idees habitude', 'conseils habitude', 'propose habitude',
            'heatmap habitude', 'calendrier habitude', 'carte habitude', 'visual habitude', 'grille habitude', 'heatmap',
            'hier', 'j\'ai fait hier', 'j\'ai medite hier', 'j\'ai couru hier', 'rattrapage', 'backdate', 'logger hier', 'oublie hier', 'j\'ai oublie', 'log hier',
            'defi streak', 'objectif streak', 'challenge habitude', 'challenge streak', 'viser streak', 'mon defi', 'defi habitude', 'objectif nombre jours',
        ];
    }

    public function version(): string
    {
        return '1.10.0';
    }

    public function canHandle(AgentContext $context): bool
    {
        return $context->routedAgent === 'habit';
    }

    public function handle(AgentContext $context): AgentResult
    {
        $habits = Habit::where('user_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->orderBy('name')
            ->get();

        $listText = $this->formatHabitList($habits);
        $now      = now(AppSetting::timezone())->format('Y-m-d H:i (l)');

        $response = $this->claude->chat(
            "Date et heure actuelles (heure de Paris): {$now}\nMessage: \"{$context->body}\"\n\nHabitudes actives:\n{$listText}",
            $this->resolveModel($context),
            $this->buildPrompt()
        );

        $parsed = $this->parseJson($response);

        if (!$parsed || empty($parsed['action'])) {
            $reply = "Je n'ai pas bien compris. Essaie :\n"
                . "- \"Ajouter habitude: Meditation, daily\"\n"
                . "- \"J'ai medite\" / \"Cocher meditation\"\n"
                . "- \"Mes habitudes\" / \"Stats habitudes\"\n"
                . "- \"Aujourd'hui\" pour voir ce qu'il reste\n"
                . "- \"Historique habitude 1\" pour les 7 derniers jours\n"
                . "- \"Classement streaks\" pour le top streaks\n"
                . "- \"Rapport semaine\" pour le bilan hebdo\n"
                . "- \"Aide habitudes\" pour le guide complet";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'habit_parse_failed']);
        }

        $action = $parsed['action'];

        return match ($action) {
            'add'              => $this->handleAdd($context, $parsed),
            'log'              => $this->handleLog($context, $habits, $parsed),
            'unlog'            => $this->handleUnlog($context, $habits, $parsed),
            'list'             => $this->handleList($context, $habits),
            'today'            => $this->handleToday($context, $habits),
            'stats'            => $this->handleStats($context, $habits),
            'delete'           => $this->handleDelete($context, $habits, $parsed),
            'reset'            => $this->handleReset($context, $habits, $parsed),
            'rename'           => $this->handleRename($context, $habits, $parsed),
            'history'          => $this->handleHistory($context, $habits, $parsed),
            'change_frequency' => $this->handleChangeFrequency($context, $habits, $parsed),
            'motivate'         => $this->handleMotivate($context, $habits),
            'streak_board'     => $this->handleStreakBoard($context, $habits),
            'weekly_report'    => $this->handleWeeklyReport($context, $habits),
            'monthly_report'   => $this->handleMonthlyReport($context, $habits),
            'best_day'         => $this->handleBestDay($context, $habits),
            'log_multiple'     => $this->handleLogMultiple($context, $habits, $parsed),
            'pause'            => $this->handlePause($context, $habits, $parsed),
            'resume'           => $this->handleResume($context, $habits, $parsed),
            'set_goal'         => $this->handleSetGoal($context, $parsed),
            'perfect_streak'   => $this->handlePerfectStreak($context, $habits),
            'compare_week'     => $this->handleCompareWeek($context, $habits),
            'top_habits'       => $this->handleTopHabits($context, $habits),
            'suggest'           => $this->handleSuggest($context, $habits),
            'heatmap'           => $this->handleHeatmap($context, $habits, $parsed),
            'backdate'          => $this->handleBackdate($context, $habits, $parsed),
            'streak_challenge'  => $this->handleStreakChallenge($context, $habits, $parsed),
            'help'              => $this->handleHelp($context),
            default             => $this->handleUnknown($context),
        };
    }

    private function buildPrompt(): string
    {
        return <<<'PROMPT'
Tu es un assistant de suivi d'habitudes (habit tracker).
L'utilisateur te donne un message et tu dois determiner l'action a effectuer.

Reponds UNIQUEMENT en JSON valide, sans markdown, sans explication.

ACTIONS POSSIBLES:

1. AJOUTER une habitude:
{"action": "add", "name": "nom de l'habitude", "description": "description optionnelle ou null", "frequency": "daily|weekly"}

2. COCHER (log) une habitude comme faite aujourd'hui:
{"action": "log", "item": 1}

3. ANNULER un log d'aujourd'hui (decocher accidentellement):
{"action": "unlog", "item": 1}

4. LISTER toutes les habitudes:
{"action": "list"}

5. VOIR les habitudes d'aujourd'hui (ce qu'il reste a faire):
{"action": "today"}

6. VOIR les statistiques completes:
{"action": "stats"}

7. SUPPRIMER une habitude definitivement:
{"action": "delete", "item": 1}

8. REINITIALISER les streaks et logs d'une habitude:
{"action": "reset", "item": 1}

9. RENOMMER une habitude existante:
{"action": "rename", "item": 1, "name": "nouveau nom"}

10. HISTORIQUE des 7 derniers jours pour une habitude:
{"action": "history", "item": 1}
Si l'utilisateur veut voir toutes les habitudes, item = null.

11. CHANGER LA FREQUENCE d'une habitude (daily <-> weekly):
{"action": "change_frequency", "item": 1, "frequency": "daily|weekly"}

12. MOTIVATION / BILAN MOTIVATION (streaks en jeu, habitudes faites ou non):
{"action": "motivate"}

13. CLASSEMENT DES STREAKS (top streaks de toutes les habitudes, classement):
{"action": "streak_board"}

14. RAPPORT HEBDOMADAIRE (bilan de la semaine en cours, taux de completion par habitude):
{"action": "weekly_report"}

15. AIDE / GUIDE d'utilisation:
{"action": "help"}

16. RAPPORT MENSUEL (bilan des 30 derniers jours, progression semaine par semaine):
{"action": "monthly_report"}

17. MEILLEUR JOUR (analyse des jours de la semaine les plus productifs sur 90 jours):
{"action": "best_day"}

18. COCHER PLUSIEURS habitudes en une seule fois:
{"action": "log_multiple", "items": [1, 3]}
Utilise quand l'utilisateur mentionne plusieurs habitudes a la fois. 'items' = tableau de numeros.

19. METTRE EN PAUSE une habitude temporairement (vacances, conge, etc.):
{"action": "pause", "item": 1}

20. REPRENDRE (reactiver) une habitude mise en pause:
{"action": "resume", "item": 1}

21. FIXER UN OBJECTIF QUOTIDIEN (nombre minimum d'habitudes a faire par jour):
{"action": "set_goal", "count": 3}
count = nombre d'habitudes cible (entier >= 1). Pour supprimer l'objectif: count = 0.

22. VOIR LA SERIE DE JOURS PARFAITS (jours consecutifs ou toutes les habitudes daily actives ont ete faites):
{"action": "perfect_streak"}

REGLES:
- 'name' = nom court et clair de l'habitude (ex: "Meditation", "Sport", "Lecture")
- 'frequency' = "daily" (quotidienne) ou "weekly" (hebdomadaire). Par defaut "daily".
- 'item' = numero de l'habitude dans la liste fournie (integer, base 1). Deduis le numero depuis le NOM de l'habitude citee.
- 'description' = description optionnelle, null si non fournie

CORRESPONDANCE NOM -> NUMERO:
Si l'utilisateur mentionne une habitude par son nom (ex: "sport", "meditation"), cherche dans la liste quelle habitude correspond et utilise son numero.
Exemple: si #2 est "Sport" et l'utilisateur dit "j'ai fait du sport" -> {"action": "log", "item": 2}

EXEMPLES:
- "Ajouter habitude meditation" -> {"action": "add", "name": "Meditation", "frequency": "daily", "description": null}
- "Nouvelle habitude: sport 3x/semaine" -> {"action": "add", "name": "Sport", "frequency": "weekly", "description": "3 fois par semaine"}
- "J'ai medite" ou "Cocher meditation" -> {"action": "log", "item": X} (X = numero de "Meditation" dans la liste)
- "J'ai couru" -> {"action": "log", "item": X} (X = numero de "Course"/"Sport"/"Running" dans la liste)
- "Annuler mon log sport" ou "J'ai pas fait sport finalement" -> {"action": "unlog", "item": X}
- "Mes habitudes" ou "Liste" -> {"action": "list"}
- "Qu'est-ce que j'ai fait aujourd'hui" ou "Habitudes du jour" -> {"action": "today"}
- "Stats habitudes" ou "Mon streak" ou "Mes stats" -> {"action": "stats"}
- "Supprimer habitude 2" -> {"action": "delete", "item": 2}
- "Reset habitude 1" -> {"action": "reset", "item": 1}
- "Renommer habitude 2 en Course a pied" -> {"action": "rename", "item": 2, "name": "Course a pied"}
- "Historique meditation" ou "Derniers jours meditation" -> {"action": "history", "item": X}
- "Historique de toutes mes habitudes" -> {"action": "history", "item": null}
- "Passer habitude 2 en hebdo" ou "Changer frequence meditation en quotidien" -> {"action": "change_frequency", "item": X, "frequency": "weekly|daily"}
- "Motivation" ou "Mes streaks en jeu" ou "Encourage-moi" -> {"action": "motivate"}
- "Classement streaks" ou "Top streaks" ou "Meilleur streak" ou "Streak board" -> {"action": "streak_board"}
- "Rapport semaine" ou "Bilan semaine" ou "Comment j'ai fait cette semaine" -> {"action": "weekly_report"}
- "Aide" ou "Comment ca marche" -> {"action": "help"}
- "Rapport mensuel" ou "Bilan 30 jours" ou "Comment j'ai fait ce mois" -> {"action": "monthly_report"}
- "Mon meilleur jour" ou "Quel jour suis-je le plus regulier" ou "Analyse par jour" -> {"action": "best_day"}
- "J'ai fait sport et meditation" ou "Cocher 1 et 3" -> {"action": "log_multiple", "items": [X, Y]}
- "Mettre en pause habitude 2" ou "Pause sport" ou "Je pars en vacances" -> {"action": "pause", "item": X}
- "Reprendre habitude 2" ou "Reactiver sport" -> {"action": "resume", "item": X}
- "Objectif 3 habitudes" ou "Je veux faire 4 habitudes par jour" -> {"action": "set_goal", "count": 3}
- "Supprimer mon objectif" ou "Pas d'objectif" -> {"action": "set_goal", "count": 0}
- "Mes jours parfaits" ou "Serie jours parfaits" ou "Combien de jours parfaits" -> {"action": "perfect_streak"}

23. COMPARER LA PROGRESSION semaine courante vs semaine precedente:
{"action": "compare_week"}

24. TOP HABITUDES les plus regulieres (30 derniers jours), podium:
{"action": "top_habits"}

- "Comparer semaine" ou "Ma semaine vs semaine derniere" ou "Progression cette semaine" -> {"action": "compare_week"}
- "Top habitudes" ou "Mes meilleures habitudes" ou "Habitudes les plus regulieres" -> {"action": "top_habits"}

25. SUGGESTIONS d'habitudes complementaires (IA coach):
{"action": "suggest"}

26. HEATMAP / CALENDRIER VISUEL des 28 derniers jours pour une ou toutes les habitudes:
{"action": "heatmap", "item": 1}
Si l'utilisateur veut voir toutes les habitudes, item = null.

- "Suggere-moi des habitudes" ou "Nouvelles idees d'habitudes" ou "Conseils habitudes" -> {"action": "suggest"}
- "Heatmap" ou "Calendrier habitude" ou "Montre-moi le calendrier de mes habitudes" -> {"action": "heatmap", "item": null}
- "Heatmap habitude 2" ou "Calendrier meditation" -> {"action": "heatmap", "item": X}

27. RATTRAPAGE / BACKDATE — logger une habitude pour HIER (oubli de la veille):
{"action": "backdate", "item": 1}
Utilise UNIQUEMENT quand l'utilisateur dit explicitement "hier", "la veille", "j'ai oublie de logger", etc.
Le backdating est limite a 1 jour en arriere (hier seulement).

28. DEFI STREAK — fixer un objectif de streak personnel pour une habitude:
{"action": "streak_challenge", "item": 1, "target": 30}
target = nombre de jours/semaines cible (entier >= 1). Pour supprimer le defi: target = 0. Pour voir l'etat du defi sans le modifier: target = null.

- "J'ai fait sport hier" ou "J'ai oublie de logger meditation hier" -> {"action": "backdate", "item": X}
- "Je veux atteindre 30 jours de streak sur meditation" -> {"action": "streak_challenge", "item": X, "target": 30}
- "Quel est mon defi streak sport" ou "Mon defi pour habitude 2" -> {"action": "streak_challenge", "item": X, "target": null}
- "Supprimer mon defi sport" -> {"action": "streak_challenge", "item": X, "target": 0}

Reponds UNIQUEMENT avec le JSON.
PROMPT;
    }

    private function handleAdd(AgentContext $context, array $parsed): AgentResult
    {
        $name = trim(preg_replace('/\s+/', ' ', $parsed['name'] ?? ''));
        if (!$name) {
            $reply = "Donne-moi le nom de l'habitude a ajouter.\nEx: \"Ajouter habitude Meditation\"";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'habit_add_no_name']);
        }

        if (mb_strlen($name) > self::MAX_NAME_LENGTH) {
            $reply = "Le nom de l'habitude est trop long (max " . self::MAX_NAME_LENGTH . " caracteres). Essaie un nom plus court.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'habit_add_name_too_long']);
        }

        $count = Habit::where('user_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->count();

        if ($count >= self::MAX_HABITS) {
            $reply = "Tu as atteint la limite de " . self::MAX_HABITS . " habitudes. Supprime-en une avant d'en ajouter une nouvelle.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'habit_add_limit_reached']);
        }

        $exists = Habit::where('user_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->whereRaw('LOWER(name) = ?', [strtolower($name)])
            ->exists();

        if ($exists) {
            $reply = "Tu as deja une habitude \"$name\". Dis \"mes habitudes\" pour voir ta liste.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'habit_add_duplicate']);
        }

        $habit = Habit::create([
            'agent_id'       => $context->agent->id,
            'user_phone'     => $context->from,
            'requester_name' => $context->senderName,
            'name'           => $name,
            'description'    => $parsed['description'] ?? null,
            'frequency'      => in_array($parsed['frequency'] ?? '', ['daily', 'weekly']) ? $parsed['frequency'] : 'daily',
        ]);

        $freqLabel = $habit->frequency === 'daily' ? 'quotidienne' : 'hebdomadaire';
        $reply     = "Habitude ajoutee !\n"
            . "Nom : {$habit->name}\n"
            . "Frequence : {$freqLabel}";

        if ($habit->description) {
            $reply .= "\nDescription : {$habit->description}";
        }

        $reply .= "\n\nDis \"j'ai fait {$habit->name}\" pour la cocher !";

        $this->sendText($context->from, $reply);
        $this->log($context, 'Habit created', ['habit_id' => $habit->id, 'name' => $name]);

        return AgentResult::reply($reply, ['habit_id' => $habit->id]);
    }

    private function handleLog(AgentContext $context, $habits, array $parsed): AgentResult
    {
        $item = $parsed['item'] ?? null;
        if (!$item || $habits->isEmpty()) {
            $reply = "Quelle habitude veux-tu cocher ? Dis \"mes habitudes\" pour voir la liste.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'habit_log_no_item']);
        }

        $habit = $habits->values()[(int) $item - 1] ?? null;

        if (!$habit) {
            $reply = "Habitude #{$item} introuvable. Dis \"mes habitudes\" pour voir ta liste.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'habit_log_not_found']);
        }

        $tz    = AppSetting::timezone();
        $today = now($tz)->toDateString();

        // For weekly habits: prevent double-logging the same week
        if ($habit->frequency === 'weekly') {
            $weekStart    = now($tz)->startOfWeek()->toDateString();
            $doneThisWeek = HabitLog::where('habit_id', $habit->id)
                ->whereBetween('completed_date', [$weekStart, $today])
                ->first();

            if ($doneThisWeek) {
                $doneDate = Carbon::parse($doneThisWeek->completed_date)->format('d/m');
                $streak   = $this->getCachedStreak($habit->id) ?? $this->calculateStreak($habit->id, $habit->frequency);
                $unit     = $streak <= 1 ? 'semaine' : 'semaines';
                $reply    = "Tu as deja coche \"{$habit->name}\" cette semaine (le {$doneDate}) !\n"
                    . "Streak : {$streak} {$unit} consecutives\n"
                    . "Continue comme ca !";
                $this->sendText($context->from, $reply);
                return AgentResult::reply($reply, ['action' => 'habit_already_logged_week']);
            }
        } else {
            // Daily: check if already done today
            $existing = HabitLog::where('habit_id', $habit->id)
                ->where('completed_date', $today)
                ->first();

            if ($existing) {
                $streak = $this->getCachedStreak($habit->id) ?? $this->calculateStreak($habit->id, $habit->frequency);
                $unit   = $streak <= 1 ? 'jour' : 'jours';
                $reply  = "Tu as deja coche \"{$habit->name}\" aujourd'hui !\n"
                    . "Streak actuel : {$streak} {$unit}\n"
                    . "Continue comme ca !";
                $this->sendText($context->from, $reply);
                return AgentResult::reply($reply, ['action' => 'habit_already_logged']);
            }
        }

        // Auto-resume if habit was paused
        $wasResumed = false;
        if ($habit->paused_at !== null) {
            $habit->update(['paused_at' => null]);
            $wasResumed = true;
        }

        $oldBestStreak = $this->getBestStreak($habit->id);
        $streak        = $this->calculateStreak($habit->id, $habit->frequency);
        $newStreak     = $streak + 1;
        $bestStreak    = max($oldBestStreak, $newStreak);
        $isNewRecord   = $newStreak > $oldBestStreak && $newStreak > 1;

        HabitLog::create([
            'habit_id'       => $habit->id,
            'completed_date' => $today,
            'streak_count'   => $newStreak,
            'best_streak'    => $bestStreak,
        ]);

        $this->cacheStreak($habit->id, $newStreak, $bestStreak);

        $unit  = $habit->frequency === 'weekly' ? ($newStreak <= 1 ? 'semaine' : 'semaines') : ($newStreak <= 1 ? 'jour' : 'jours');
        $bUnit = $habit->frequency === 'weekly' ? ($bestStreak <= 1 ? 'semaine' : 'semaines') : ($bestStreak <= 1 ? 'jour' : 'jours');

        $reply = "Habitude \"{$habit->name}\" cochee !\n"
            . "Streak : {$newStreak} {$unit} | Record : {$bestStreak} {$bUnit}";

        if ($wasResumed) {
            $reply .= "\n(Habitude reactivee automatiquement)";
        }

        $milestone = $this->getMilestoneMessage($newStreak, $isNewRecord, $habit->frequency);
        if ($milestone) {
            $reply .= "\n\n{$milestone}";
        }

        // Show streak challenge progress if a challenge is set
        $challengeKey = 'habit_challenge_' . $habit->id;
        $challengeVal = AppSetting::get($challengeKey);
        if ($challengeVal !== null) {
            $challengeTarget = (int) $challengeVal;
            $unit2           = $habit->frequency === 'weekly' ? 'semaines' : 'jours';
            if ($newStreak >= $challengeTarget) {
                $reply .= "\n\nDEFI ACCOMPLI ({$challengeTarget} {$unit2}) !";
            } else {
                $remaining2 = $challengeTarget - $newStreak;
                $reply .= "\n\nDefi : {$newStreak}/{$challengeTarget} {$unit2} (encore {$remaining2})";
            }
        }

        // Show remaining daily habits count
        if ($habit->frequency === 'daily' && $habits->count() > 1) {
            $allDailyIds = $habits->where('frequency', 'daily')->where('paused_at', null)->pluck('id')->toArray();
            if (count($allDailyIds) > 1) {
                $doneTodayIds   = HabitLog::whereIn('habit_id', $allDailyIds)
                    ->where('completed_date', $today)
                    ->pluck('habit_id')
                    ->toArray();
                $doneTodayIds[] = $habit->id; // include the one we just logged
                $pendingDaily   = count(array_filter($allDailyIds, fn($id) => !in_array($id, $doneTodayIds)));
                if ($pendingDaily > 0) {
                    $reply .= "\n\nEncore {$pendingDaily} habitude(s) journaliere(s) a faire aujourd'hui.";
                } elseif (count($allDailyIds) > 1) {
                    $reply .= "\n\nToutes tes habitudes journalieres sont faites !";
                }
            }
        }

        $this->sendText($context->from, $reply);
        $this->log($context, 'Habit logged', [
            'habit_id'    => $habit->id,
            'streak'      => $newStreak,
            'best_streak' => $bestStreak,
            'auto_resumed' => $wasResumed,
        ]);

        return AgentResult::reply($reply, ['habit_id' => $habit->id, 'streak' => $newStreak]);
    }

    private function handleUnlog(AgentContext $context, $habits, array $parsed): AgentResult
    {
        $item = $parsed['item'] ?? null;
        if (!$item || $habits->isEmpty()) {
            $reply = "Quelle habitude veux-tu decocher ? Donne le numero.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'habit_unlog_no_item']);
        }

        $habit = $habits->values()[(int) $item - 1] ?? null;

        if (!$habit) {
            $reply = "Habitude #{$item} introuvable. Dis \"mes habitudes\" pour voir ta liste.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'habit_unlog_not_found']);
        }

        $tz    = AppSetting::timezone();
        $today = now($tz)->toDateString();

        if ($habit->frequency === 'weekly') {
            $weekStart = now($tz)->startOfWeek()->toDateString();
            $log       = HabitLog::where('habit_id', $habit->id)
                ->whereBetween('completed_date', [$weekStart, $today])
                ->first();
        } else {
            $log = HabitLog::where('habit_id', $habit->id)
                ->where('completed_date', $today)
                ->first();
        }

        if (!$log) {
            $scope = $habit->frequency === 'weekly' ? 'cette semaine' : 'aujourd\'hui';
            $reply = "Tu n'as pas encore coche \"{$habit->name}\" {$scope}.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'habit_unlog_not_logged']);
        }

        $log->delete();
        Cache::forget("habit_streak:{$habit->id}");

        $scope = $habit->frequency === 'weekly' ? 'cette semaine' : "aujourd'hui";
        $reply = "Log annule pour \"{$habit->name}\" ({$scope}). Le streak a ete mis a jour.";
        $this->sendText($context->from, $reply);
        $this->log($context, 'Habit unlogged', ['habit_id' => $habit->id]);

        return AgentResult::reply($reply, ['action' => 'habit_unlog']);
    }

    private function handleList(AgentContext $context, $habits): AgentResult
    {
        if ($habits->isEmpty()) {
            $reply = "Tu n'as aucune habitude enregistree.\nDis \"ajouter habitude Meditation\" pour commencer !";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'habit_list_empty']);
        }

        $tz       = AppSetting::timezone();
        $today    = now($tz)->toDateString();
        $habitIds = $habits->pluck('id')->toArray();

        $doneTodayIds = HabitLog::whereIn('habit_id', $habitIds)
            ->where('completed_date', $today)
            ->pluck('habit_id')
            ->toArray();

        $weekStart       = now($tz)->startOfWeek()->toDateString();
        $doneThisWeekIds = HabitLog::whereIn('habit_id', $habitIds)
            ->whereBetween('completed_date', [$weekStart, $today])
            ->pluck('habit_id')
            ->unique()
            ->toArray();

        $lines = ["Tes habitudes :"];

        foreach ($habits->values() as $i => $habit) {
            $num       = $i + 1;
            $freqLabel = $habit->frequency === 'daily' ? 'quotidien' : 'hebdo';
            $streak    = $this->getCachedStreak($habit->id) ?? $this->calculateStreak($habit->id, $habit->frequency);
            $unit      = $habit->frequency === 'weekly' ? 'sem' : 'j';

            $isDone = $habit->frequency === 'weekly'
                ? in_array($habit->id, $doneThisWeekIds)
                : in_array($habit->id, $doneTodayIds);

            if ($habit->paused_at !== null) {
                $status = '[PAUSE]';
            } else {
                $status = $isDone ? '[FAIT]' : '[A FAIRE]';
            }

            $lines[] = "\n{$num}. {$habit->name} {$status}";
            $lines[] = "   {$freqLabel} | Streak: {$streak}{$unit}";
            if ($habit->description) {
                $lines[] = "   {$habit->description}";
            }
        }

        $doneDaily  = collect($habits)->where('frequency', 'daily')->filter(fn($h) => in_array($h->id, $doneTodayIds))->count();
        $doneWeekly = collect($habits)->where('frequency', 'weekly')->filter(fn($h) => in_array($h->id, $doneThisWeekIds))->count();
        $doneCount  = $doneDaily + $doneWeekly;
        $total      = $habits->count();

        $lines[] = "\n---";
        $lines[] = "Aujourd'hui : {$doneCount}/{$total} completees";

        $reply = implode("\n", $lines);
        $this->sendText($context->from, $reply);
        $this->log($context, 'Habit list viewed', ['count' => $total]);

        return AgentResult::reply($reply, ['action' => 'habit_list']);
    }

    /**
     * Show today's status for all habits (daily AND weekly).
     */
    private function handleToday(AgentContext $context, $habits): AgentResult
    {
        if ($habits->isEmpty()) {
            $reply = "Tu n'as aucune habitude enregistree.\nDis \"ajouter habitude Meditation\" pour commencer !";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'habit_today_empty']);
        }

        $tz       = AppSetting::timezone();
        $now      = now($tz);
        $today    = $now->toDateString();
        $habitIds = $habits->pluck('id')->toArray();

        $doneTodayIds = HabitLog::whereIn('habit_id', $habitIds)
            ->where('completed_date', $today)
            ->pluck('habit_id')
            ->toArray();

        $weekStart       = $now->copy()->startOfWeek()->toDateString();
        $doneThisWeekIds = HabitLog::whereIn('habit_id', $habitIds)
            ->whereBetween('completed_date', [$weekStart, $today])
            ->pluck('habit_id')
            ->unique()
            ->toArray();

        $activeHabits  = $habits->filter(fn($h) => $h->paused_at === null);
        $pausedHabits  = $habits->filter(fn($h) => $h->paused_at !== null);

        $dailyHabits  = $activeHabits->where('frequency', 'daily');
        $weeklyHabits = $activeHabits->where('frequency', 'weekly');

        $doneDaily     = $dailyHabits->filter(fn($h) => in_array($h->id, $doneTodayIds));
        $pendingDaily  = $dailyHabits->filter(fn($h) => !in_array($h->id, $doneTodayIds));
        $doneWeekly    = $weeklyHabits->filter(fn($h) => in_array($h->id, $doneThisWeekIds));
        $pendingWeekly = $weeklyHabits->filter(fn($h) => !in_array($h->id, $doneThisWeekIds));

        $lines = ["Aujourd'hui :"];

        if ($doneDaily->isNotEmpty() || $doneWeekly->isNotEmpty()) {
            $lines[] = "\nFaites :";
            foreach ($doneDaily->values() as $habit) {
                $num    = $habits->values()->search(fn($h) => $h->id === $habit->id) + 1;
                $streak = $this->getCachedStreak($habit->id) ?? $this->calculateStreak($habit->id, $habit->frequency);
                $lines[] = "  {$num}. {$habit->name} (streak: {$streak}j)";
            }
            foreach ($doneWeekly->values() as $habit) {
                $num    = $habits->values()->search(fn($h) => $h->id === $habit->id) + 1;
                $streak = $this->getCachedStreak($habit->id) ?? $this->calculateStreak($habit->id, $habit->frequency);
                $lines[] = "  {$num}. {$habit->name} [hebdo, fait cette semaine] (streak: {$streak} sem)";
            }
        }

        if ($pendingDaily->isNotEmpty() || $pendingWeekly->isNotEmpty()) {
            $lines[] = "\nA faire :";
            foreach ($pendingDaily->values() as $habit) {
                $num    = $habits->values()->search(fn($h) => $h->id === $habit->id) + 1;
                $streak = $this->getCachedStreak($habit->id) ?? $this->calculateStreak($habit->id, $habit->frequency);
                $lines[] = "  {$num}. {$habit->name} (streak: {$streak}j)";
            }
            foreach ($pendingWeekly->values() as $habit) {
                $num    = $habits->values()->search(fn($h) => $h->id === $habit->id) + 1;
                $streak = $this->getCachedStreak($habit->id) ?? $this->calculateStreak($habit->id, $habit->frequency);
                $lines[] = "  {$num}. {$habit->name} [hebdo, pas encore fait cette semaine] (streak: {$streak} sem)";
            }
        }

        if ($pausedHabits->isNotEmpty()) {
            $lines[] = "\nEn pause :";
            foreach ($pausedHabits->values() as $habit) {
                $num     = $habits->values()->search(fn($h) => $h->id === $habit->id) + 1;
                $lines[] = "  {$num}. {$habit->name} [PAUSE]";
            }
        }

        $doneCount    = $doneDaily->count() + $doneWeekly->count();
        $pendingCount = $pendingDaily->count() + $pendingWeekly->count();
        $activeTotal  = $activeHabits->count();

        $lines[] = "\n{$doneCount}/{$activeTotal} habitudes actives completees.";

        // Show goal progress if set
        $goalKey = 'habit_goal_' . md5($context->from);
        $goalRaw = AppSetting::get($goalKey);
        if ($goalRaw !== null) {
            $goal = (int) $goalRaw;
            if ($goal > 0) {
                if ($doneCount >= $goal) {
                    $lines[] = "Objectif du jour atteint : {$doneCount}/{$goal} !";
                } else {
                    $remaining = $goal - $doneCount;
                    $lines[] = "Objectif du jour : {$doneCount}/{$goal} (encore {$remaining} a faire)";
                }
            }
        }

        if ($pendingCount === 0 && $activeTotal > 0) {
            $lines[] = "Bravo, toutes les habitudes actives sont a jour !";
        }

        $reply = implode("\n", $lines);
        $this->sendText($context->from, $reply);
        $this->log($context, 'Habit today viewed', ['done' => $doneCount, 'pending' => $pendingCount]);

        return AgentResult::reply($reply, ['action' => 'habit_today']);
    }

    private function handleStats(AgentContext $context, $habits): AgentResult
    {
        if ($habits->isEmpty()) {
            $reply = "Tu n'as aucune habitude enregistree. Ajoute-en une d'abord !";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'habit_stats_empty']);
        }

        $tz       = AppSetting::timezone();
        $today    = now($tz);
        $todayStr = $today->toDateString();
        $since30  = $today->copy()->subDays(30)->toDateString();
        $habitIds = $habits->pluck('id')->toArray();

        $doneTodayIds = HabitLog::whereIn('habit_id', $habitIds)
            ->where('completed_date', $todayStr)
            ->pluck('habit_id')
            ->toArray();

        $weekStart       = $today->copy()->startOfWeek()->toDateString();
        $doneThisWeekIds = HabitLog::whereIn('habit_id', $habitIds)
            ->whereBetween('completed_date', [$weekStart, $todayStr])
            ->pluck('habit_id')
            ->unique()
            ->toArray();

        $totalLogsMap = HabitLog::whereIn('habit_id', $habitIds)
            ->selectRaw('habit_id, COUNT(*) as cnt')
            ->groupBy('habit_id')
            ->pluck('cnt', 'habit_id')
            ->toArray();

        $last30Map = HabitLog::whereIn('habit_id', $habitIds)
            ->where('completed_date', '>=', $since30)
            ->selectRaw('habit_id, COUNT(*) as cnt')
            ->groupBy('habit_id')
            ->pluck('cnt', 'habit_id')
            ->toArray();

        $lines          = ["Stats de tes habitudes :"];
        $totalCompleted = 0;
        $totalDoneToday = 0;

        foreach ($habits->values() as $i => $habit) {
            $num        = $i + 1;
            $streak     = $this->getCachedStreak($habit->id) ?? $this->calculateStreak($habit->id, $habit->frequency);
            $bestStreak = $this->getBestStreak($habit->id);
            $totalLogs  = $totalLogsMap[$habit->id] ?? 0;
            $last30     = $last30Map[$habit->id] ?? 0;

            $isDone = $habit->frequency === 'weekly'
                ? in_array($habit->id, $doneThisWeekIds)
                : in_array($habit->id, $doneTodayIds);

            if ($isDone) $totalDoneToday++;
            $totalCompleted += $totalLogs;

            $denominator = $habit->frequency === 'daily' ? 30 : 4;
            $rate        = $denominator > 0 ? min(100, round(($last30 / $denominator) * 100)) : 0;

            $freqLabel  = $habit->frequency === 'daily' ? 'quotidien' : 'hebdo';
            $streakUnit = $habit->frequency === 'weekly' ? 'sem' : 'j';
            $status     = $isDone ? ' [FAIT]' : '';

            $bar30 = $this->buildMiniBar($rate, 100);
            $lines[] = "\n{$num}. {$habit->name}{$status} [{$freqLabel}]";
            $lines[] = "   Streak: {$streak}{$streakUnit} | Record: {$bestStreak}{$streakUnit} | Total: {$totalLogs}";
            $lines[] = "   Taux 30j: {$bar30} {$rate}%";
        }

        $lines[] = "\n---";
        $lines[] = "Aujourd'hui: {$totalDoneToday}/{$habits->count()} completees";
        $lines[] = "Total completions: {$totalCompleted}";

        $reply = implode("\n", $lines);
        $this->sendText($context->from, $reply);
        $this->log($context, 'Habit stats viewed', ['count' => $habits->count()]);

        return AgentResult::reply($reply, ['action' => 'habit_stats']);
    }

    private function handleDelete(AgentContext $context, $habits, array $parsed): AgentResult
    {
        $item = $parsed['item'] ?? null;
        if (!$item || $habits->isEmpty()) {
            $reply = "Quelle habitude veux-tu supprimer ? Donne le numero.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'habit_delete_no_item']);
        }

        $habit = $habits->values()[(int) $item - 1] ?? null;

        if (!$habit) {
            $reply = "Habitude #{$item} introuvable. Dis \"mes habitudes\" pour voir ta liste.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'habit_delete_not_found']);
        }

        $name = $habit->name;
        $id   = $habit->id;
        $habit->delete();

        Cache::forget("habit_streak:{$id}");
        Cache::forget("habit_best_streak:{$id}");

        $reply = "Habitude \"{$name}\" supprimee.";
        $this->sendText($context->from, $reply);
        $this->log($context, 'Habit deleted', ['habit_id' => $id, 'name' => $name]);

        return AgentResult::reply($reply, ['action' => 'habit_delete']);
    }

    private function handleReset(AgentContext $context, $habits, array $parsed): AgentResult
    {
        $item = $parsed['item'] ?? null;
        if (!$item || $habits->isEmpty()) {
            $reply = "Quelle habitude veux-tu reinitialiser ?";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'habit_reset_no_item']);
        }

        $habit = $habits->values()[(int) $item - 1] ?? null;

        if (!$habit) {
            $reply = "Habitude #{$item} introuvable. Dis \"mes habitudes\" pour voir ta liste.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'habit_reset_not_found']);
        }

        HabitLog::where('habit_id', $habit->id)->delete();

        Cache::forget("habit_streak:{$habit->id}");
        Cache::forget("habit_best_streak:{$habit->id}");

        $reply = "Habitude \"{$habit->name}\" reinitialisee. Tous les streaks et logs effaces. On recommence a zero !";
        $this->sendText($context->from, $reply);
        $this->log($context, 'Habit reset', ['habit_id' => $habit->id, 'name' => $habit->name]);

        return AgentResult::reply($reply, ['action' => 'habit_reset']);
    }

    /**
     * Rename an existing habit (checks for duplicates, preserves all logs and streaks).
     */
    private function handleRename(AgentContext $context, $habits, array $parsed): AgentResult
    {
        $item    = $parsed['item'] ?? null;
        $newName = trim($parsed['name'] ?? '');

        if (!$item || $habits->isEmpty()) {
            $reply = "Quelle habitude veux-tu renommer ? Ex: \"Renommer habitude 2 en Course a pied\"";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'habit_rename_no_item']);
        }

        if (!$newName) {
            $reply = "Donne-moi le nouveau nom. Ex: \"Renommer habitude 2 en Course a pied\"";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'habit_rename_no_name']);
        }

        $habit = $habits->values()[(int) $item - 1] ?? null;

        if (!$habit) {
            $reply = "Habitude #{$item} introuvable. Dis \"mes habitudes\" pour voir ta liste.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'habit_rename_not_found']);
        }

        $exists = Habit::where('user_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->where('id', '!=', $habit->id)
            ->whereRaw('LOWER(name) = ?', [strtolower($newName)])
            ->exists();

        if ($exists) {
            $reply = "Tu as deja une habitude nommee \"{$newName}\".";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'habit_rename_duplicate']);
        }

        $oldName = $habit->name;
        $habit->update(['name' => $newName]);

        $reply = "Habitude renommee : \"{$oldName}\" -> \"{$newName}\"";
        $this->sendText($context->from, $reply);
        $this->log($context, 'Habit renamed', [
            'habit_id' => $habit->id,
            'old_name' => $oldName,
            'new_name' => $newName,
        ]);

        return AgentResult::reply($reply, ['action' => 'habit_rename', 'habit_id' => $habit->id]);
    }

    /**
     * Change frequency of an existing habit (daily <-> weekly).
     * Clears streak cache since the calculation unit changes.
     */
    private function handleChangeFrequency(AgentContext $context, $habits, array $parsed): AgentResult
    {
        $item      = $parsed['item'] ?? null;
        $frequency = $parsed['frequency'] ?? null;

        if (!$item || $habits->isEmpty()) {
            $reply = "Quelle habitude veux-tu modifier ?\nEx: \"Passer habitude 2 en hebdo\"";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'habit_change_freq_no_item']);
        }

        if (!in_array($frequency, ['daily', 'weekly'])) {
            $reply = "Indique la frequence souhaitee :\n- \"daily\" (quotidienne)\n- \"weekly\" (hebdomadaire)";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'habit_change_freq_invalid']);
        }

        $habit = $habits->values()[(int) $item - 1] ?? null;

        if (!$habit) {
            $reply = "Habitude #{$item} introuvable. Dis \"mes habitudes\" pour voir ta liste.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'habit_change_freq_not_found']);
        }

        if ($habit->frequency === $frequency) {
            $freqLabel = $frequency === 'daily' ? 'quotidienne' : 'hebdomadaire';
            $reply     = "\"{$habit->name}\" est deja en mode {$freqLabel}.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'habit_change_freq_same']);
        }

        $oldLabel = $habit->frequency === 'daily' ? 'quotidienne' : 'hebdomadaire';
        $newLabel = $frequency === 'daily' ? 'quotidienne' : 'hebdomadaire';

        $habit->update(['frequency' => $frequency]);

        Cache::forget("habit_streak:{$habit->id}");

        $reply = "Frequence de \"{$habit->name}\" changee !\n"
            . "{$oldLabel} -> {$newLabel}\n"
            . "Le streak sera recalcule en " . ($frequency === 'weekly' ? 'semaines' : 'jours') . ".";
        $this->sendText($context->from, $reply);
        $this->log($context, 'Habit frequency changed', [
            'habit_id' => $habit->id,
            'old_freq'  => $habit->frequency,
            'new_freq'  => $frequency,
        ]);

        return AgentResult::reply($reply, ['action' => 'habit_change_frequency', 'habit_id' => $habit->id]);
    }

    /**
     * Show today's motivation: streaks at risk, habits on track, encouragements.
     */
    private function handleMotivate(AgentContext $context, $habits): AgentResult
    {
        if ($habits->isEmpty()) {
            $reply = "Tu n'as aucune habitude enregistree.\nAjoute-en une pour commencer : \"Ajouter habitude Meditation\"";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'habit_motivate_empty']);
        }

        $tz        = AppSetting::timezone();
        $today     = now($tz)->toDateString();
        $weekStart = now($tz)->startOfWeek()->toDateString();
        $habitIds  = $habits->pluck('id')->toArray();

        $doneTodayIds = HabitLog::whereIn('habit_id', $habitIds)
            ->where('completed_date', $today)
            ->pluck('habit_id')
            ->toArray();

        $doneThisWeekIds = HabitLog::whereIn('habit_id', $habitIds)
            ->whereBetween('completed_date', [$weekStart, $today])
            ->pluck('habit_id')
            ->unique()
            ->toArray();

        $atRisk     = [];
        $onTrack    = [];
        $notStarted = [];
        $paused     = [];

        foreach ($habits->values() as $i => $habit) {
            $num    = $i + 1;
            $streak = $this->getCachedStreak($habit->id) ?? $this->calculateStreak($habit->id, $habit->frequency);
            $unit   = $habit->frequency === 'weekly' ? 'sem' : 'j';

            if ($habit->paused_at !== null) {
                $paused[] = "  {$num}. {$habit->name} [PAUSE]";
                continue;
            }

            $isDone = $habit->frequency === 'weekly'
                ? in_array($habit->id, $doneThisWeekIds)
                : in_array($habit->id, $doneTodayIds);

            if ($isDone) {
                $onTrack[] = "  {$num}. {$habit->name} (streak: {$streak}{$unit})";
            } elseif ($streak > 0) {
                $atRisk[] = "  {$num}. {$habit->name} — {$streak}{$unit} de streak en jeu !";
            } else {
                $notStarted[] = "  {$num}. {$habit->name}";
            }
        }

        $lines = ["Bilan motivation :"];

        if (!empty($onTrack)) {
            $lines[] = "\nDeja faites :";
            foreach ($onTrack as $item) {
                $lines[] = $item;
            }
        }

        if (!empty($atRisk)) {
            $lines[] = "\nStreaks en jeu :";
            foreach ($atRisk as $item) {
                $lines[] = $item;
            }
            $lines[] = "Ne les oublie pas !";
        }

        if (!empty($notStarted)) {
            $lines[] = "\nPas encore commencees :";
            foreach ($notStarted as $item) {
                $lines[] = $item;
            }
            $lines[] = "Le premier pas est le plus dur — lance-toi !";
        }

        if (!empty($paused)) {
            $lines[] = "\nEn pause :";
            foreach ($paused as $item) {
                $lines[] = $item;
            }
        }

        $doneCount   = count($onTrack);
        $activeTotal = $habits->filter(fn($h) => $h->paused_at === null)->count();
        $total       = $habits->count();

        if ($activeTotal > 0 && $doneCount === $activeTotal) {
            $lines[] = "\nBravo ! Toutes tes habitudes actives sont a jour !";
        } elseif ($activeTotal > 0 && $doneCount >= (int) ceil($activeTotal / 2)) {
            $lines[] = "\nBonne progression ! Plus que " . ($activeTotal - $doneCount) . " habitude(s) a cocher.";
        } elseif (!empty($atRisk)) {
            $lines[] = "\nProtege tes streaks — chaque jour compte !";
        } else {
            $lines[] = "\nChaque habitude cochee est une victoire. Vas-y !";
        }

        $reply = implode("\n", $lines);
        $this->sendText($context->from, $reply);
        $this->log($context, 'Habit motivate viewed', ['at_risk' => count($atRisk), 'on_track' => $doneCount]);

        return AgentResult::reply($reply, ['action' => 'habit_motivate']);
    }

    /**
     * Show 7-day completion history for one or all habits.
     */
    private function handleHistory(AgentContext $context, $habits, array $parsed): AgentResult
    {
        if ($habits->isEmpty()) {
            $reply = "Tu n'as aucune habitude enregistree.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'habit_history_empty']);
        }

        $item = $parsed['item'] ?? null;
        $tz   = AppSetting::timezone();
        $now  = now($tz);

        $days = [];
        for ($i = 6; $i >= 0; $i--) {
            $days[] = $now->copy()->subDays($i)->toDateString();
        }

        if ($item !== null) {
            $targetHabit = $habits->values()[(int) $item - 1] ?? null;
            if (!$targetHabit) {
                $reply = "Habitude #{$item} introuvable. Dis \"mes habitudes\" pour voir ta liste.";
                $this->sendText($context->from, $reply);
                return AgentResult::reply($reply, ['action' => 'habit_history_not_found']);
            }
            $targetHabits = collect([$targetHabit]);
        } else {
            $targetHabits = $habits;
        }

        $habitIds = $targetHabits->pluck('id')->toArray();

        $logsByHabit = HabitLog::whereIn('habit_id', $habitIds)
            ->whereBetween('completed_date', [$days[0], $days[6]])
            ->get()
            ->groupBy('habit_id');

        $lines = ["Historique (7 derniers jours) :"];

        foreach ($targetHabits->values() as $habit) {
            $num       = $habits->values()->search(fn($h) => $h->id === $habit->id) + 1;
            $habitLogs = $logsByHabit->get($habit->id, collect());
            $logDates  = $habitLogs->pluck('completed_date')
                ->map(fn($d) => $d instanceof Carbon ? $d->toDateString() : Carbon::parse($d)->toDateString())
                ->toArray();

            $cells     = [];
            $doneCount = 0;
            foreach ($days as $day) {
                $dayLabel = Carbon::parse($day, $tz)->format('d/m');
                $done     = in_array($day, $logDates);
                if ($done) $doneCount++;
                $cells[] = "{$dayLabel}:" . ($done ? 'X' : '_');
            }

            $freqLabel = $habit->frequency === 'weekly' ? ' [hebdo]' : '';
            $lines[]   = "\n{$num}. {$habit->name}{$freqLabel}";
            $lines[]   = "   " . implode(' | ', $cells);
            $lines[]   = "   Total: {$doneCount}/7 jours";
        }

        $reply = implode("\n", $lines);
        $this->sendText($context->from, $reply);
        $this->log($context, 'Habit history viewed', ['count' => $targetHabits->count()]);

        return AgentResult::reply($reply, ['action' => 'habit_history']);
    }

    /**
     * Set (or remove) a daily habit count goal stored in AppSetting.
     * goal = 0 removes the goal.
     */
    private function handleSetGoal(AgentContext $context, array $parsed): AgentResult
    {
        $count = isset($parsed['count']) ? (int) $parsed['count'] : null;

        if ($count === null || $count < 0) {
            $reply = "Indique un nombre d'habitudes comme objectif.\nEx: \"Objectif 3 habitudes par jour\"";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'habit_set_goal_invalid']);
        }

        $key = 'habit_goal_' . md5($context->from);

        if ($count === 0) {
            AppSetting::where('key', $key)->delete();
            $reply = "Objectif quotidien supprime.";
            $this->sendText($context->from, $reply);
            $this->log($context, 'Habit goal removed');
            return AgentResult::reply($reply, ['action' => 'habit_goal_removed']);
        }

        AppSetting::set($key, (string) $count);

        $reply = "Objectif fixe : {$count} habitude(s) par jour.\n"
            . "Dis \"aujourd'hui\" pour voir ta progression !";
        $this->sendText($context->from, $reply);
        $this->log($context, 'Habit goal set', ['goal' => $count]);

        return AgentResult::reply($reply, ['action' => 'habit_set_goal', 'goal' => $count]);
    }

    /**
     * Show consecutive "perfect days" streak (all active daily habits done each day).
     */
    private function handlePerfectStreak(AgentContext $context, $habits): AgentResult
    {
        $dailyHabits = $habits->filter(fn($h) => $h->frequency === 'daily' && $h->paused_at === null);

        if ($dailyHabits->isEmpty()) {
            $reply = "Tu n'as aucune habitude quotidienne active.\n"
                . "Ajoute une habitude daily pour suivre les jours parfaits.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'habit_perfect_streak_no_daily']);
        }

        $tz          = AppSetting::timezone();
        $today       = now($tz)->toDateString();
        $since       = now($tz)->subDays(59)->toDateString();
        $totalHabits = $dailyHabits->count();
        $habitIds    = $dailyHabits->pluck('id')->toArray();

        // Get all logs for the last 60 days grouped by date
        $logsByDate = HabitLog::whereIn('habit_id', $habitIds)
            ->where('completed_date', '>=', $since)
            ->get()
            ->groupBy(fn($log) => $log->completed_date instanceof Carbon
                ? $log->completed_date->toDateString()
                : Carbon::parse($log->completed_date)->toDateString());

        $isPerfect = fn(string $date) => $logsByDate->get($date, collect())
            ->pluck('habit_id')->unique()->count() >= $totalHabits;

        // Calculate current consecutive perfect streak
        $streak  = 0;
        $current = Carbon::parse($today, $tz);

        // If today isn't perfect yet, start streak check from yesterday
        if (!$isPerfect($today)) {
            $current->subDay();
        }

        while ($current->toDateString() >= $since) {
            if ($isPerfect($current->toDateString())) {
                $streak++;
                $current->subDay();
            } else {
                break;
            }
        }

        // Count total perfect days in the last 60 days
        $totalPerfect = 0;
        $cursor       = Carbon::parse($since, $tz);
        $end          = Carbon::parse($today, $tz);
        while ($cursor->lte($end)) {
            if ($isPerfect($cursor->toDateString())) {
                $totalPerfect++;
            }
            $cursor->addDay();
        }

        $todayPerfect = $isPerfect($today);

        $habitsLabel = $totalHabits === 1 ? '1 habitude quotidienne' : "{$totalHabits} habitudes quotidiennes";
        $lines       = ["Serie de jours parfaits ({$habitsLabel} actives) :"];
        $lines[]     = '';

        if ($streak === 0) {
            $lines[] = "Pas de serie en cours.";
            if ($todayPerfect) {
                $lines[] = "Aujourd'hui est parfait, mais hier etait rate.";
            } else {
                $lines[] = "Fais TOUTES tes habitudes aujourd'hui pour lancer une serie !";
            }
        } else {
            $unit    = $streak <= 1 ? 'jour parfait' : 'jours parfaits';
            $lines[] = "Serie actuelle : {$streak} {$unit} consecutifs";
            if ($todayPerfect) {
                $lines[] = "Aujourd'hui compte dans la serie !";
            } else {
                $lines[] = "Fais toutes tes habitudes aujourd'hui pour continuer !";
            }
        }

        $lines[] = '';
        $lines[] = "Total jours parfaits (60 derniers jours) : {$totalPerfect}";

        if ($streak >= 7) {
            $lines[] = "Semaine parfaite consecutive — tu es en feu !";
        } elseif ($streak >= 3) {
            $lines[] = "Belle serie — continue comme ca !";
        } elseif ($totalPerfect > 0) {
            $lines[] = "Continue a viser les jours parfaits !";
        }

        $reply = implode("\n", $lines);
        $this->sendText($context->from, $reply);
        $this->log($context, 'Habit perfect streak viewed', [
            'streak'        => $streak,
            'total_perfect' => $totalPerfect,
        ]);

        return AgentResult::reply($reply, ['action' => 'habit_perfect_streak', 'streak' => $streak]);
    }

    private function handleHelp(AgentContext $context): AgentResult
    {
        $reply = "Guide Habit Tracker :\n\n"
            . "AJOUTER\n"
            . "  \"Ajouter habitude Meditation\"\n"
            . "  \"Nouvelle habitude: Sport, hebdo\"\n\n"
            . "COCHER (faire une habitude)\n"
            . "  \"J'ai medite\" / \"J'ai fait du sport\"\n"
            . "  \"Cocher habitude 2\"\n"
            . "  \"J'ai fait sport et meditation\" (plusieurs a la fois)\n\n"
            . "ANNULER un log\n"
            . "  \"Annuler mon log sport\"\n"
            . "  \"J'ai pas fait meditation finalement\"\n\n"
            . "PAUSE / REPRISE\n"
            . "  \"Mettre en pause habitude 2\" — suspend sans casser le streak\n"
            . "  \"Reprendre habitude 2\" — reactive l'habitude\n\n"
            . "OBJECTIF QUOTIDIEN\n"
            . "  \"Objectif 3 habitudes par jour\" — fixe une cible daily\n"
            . "  \"Supprimer mon objectif\" — enleve la cible\n\n"
            . "VOIR\n"
            . "  \"Mes habitudes\" — liste avec streaks\n"
            . "  \"Aujourd'hui\" — ce qu'il reste a faire + progression objectif\n"
            . "  \"Stats habitudes\" — statistiques completes\n"
            . "  \"Historique habitude 2\" — 7 derniers jours\n"
            . "  \"Motivation\" — bilan streaks en jeu\n"
            . "  \"Classement streaks\" — top streaks de tes habitudes\n"
            . "  \"Rapport semaine\" — bilan de la semaine en cours\n"
            . "  \"Rapport mensuel\" — bilan des 30 derniers jours\n"
            . "  \"Mon meilleur jour\" — analyse par jour de semaine\n"
            . "  \"Jours parfaits\" — serie de jours ou tout a ete fait\n"
            . "  \"Comparer semaine\" — semaine courante vs semaine precedente\n"
            . "  \"Top habitudes\" — podium des 3 habitudes les plus regulieres\n"
            . "  \"Heatmap\" — calendrier visuel 28 jours\n\n"
            . "IA / SUGGESTIONS\n"
            . "  \"Suggere-moi des habitudes\" — idees complementaires via IA\n\n"
            . "RATTRAPAGE (oubli de la veille)\n"
            . "  \"J'ai fait sport hier\" — log pour hier si non deja fait\n"
            . "  \"J'ai oublie de logger meditation hier\"\n\n"
            . "DEFI STREAK\n"
            . "  \"Defi 30 jours sur meditation\" — fixe un objectif de streak\n"
            . "  \"Mon defi streak sport\" — voir l'avancement du defi\n"
            . "  \"Supprimer mon defi sport\" — efface le defi\n\n"
            . "GERER\n"
            . "  \"Renommer habitude 2 en Course a pied\"\n"
            . "  \"Passer habitude 2 en hebdo\" (changer frequence)\n"
            . "  \"Supprimer habitude 2\"\n"
            . "  \"Reset habitude 1\" — remet a zero\n\n"
            . "Les habitudes sont numerotees dans ta liste.\n"
            . "Streaks daily = jours | Streaks weekly = semaines.";

        $this->sendText($context->from, $reply);
        return AgentResult::reply($reply, ['action' => 'habit_help']);
    }

    private function handleUnknown(AgentContext $context): AgentResult
    {
        $reply = "Action non reconnue. Dis \"aide habitudes\" pour voir toutes les commandes.";
        $this->sendText($context->from, $reply);
        return AgentResult::reply($reply, ['action' => 'habit_unknown_action']);
    }

    /**
     * Log multiple habits in one shot.
     * JSON: {"action": "log_multiple", "items": [1, 3]}
     */
    private function handleLogMultiple(AgentContext $context, $habits, array $parsed): AgentResult
    {
        $items = $parsed['items'] ?? [];

        if (empty($items) || !is_array($items) || $habits->isEmpty()) {
            $reply = "Quelles habitudes veux-tu cocher ? Ex: \"J'ai fait sport et meditation\"";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'habit_log_multiple_no_items']);
        }

        $tz    = AppSetting::timezone();
        $today = now($tz)->toDateString();

        $logged    = [];
        $skipped   = [];
        $notFound  = [];

        foreach ($items as $itemNum) {
            $habit = $habits->values()[(int) $itemNum - 1] ?? null;

            if (!$habit) {
                $notFound[] = "#{$itemNum}";
                continue;
            }

            // Check already logged
            if ($habit->frequency === 'weekly') {
                $weekStart    = now($tz)->startOfWeek()->toDateString();
                $alreadyDone  = HabitLog::where('habit_id', $habit->id)
                    ->whereBetween('completed_date', [$weekStart, $today])
                    ->exists();
            } else {
                $alreadyDone = HabitLog::where('habit_id', $habit->id)
                    ->where('completed_date', $today)
                    ->exists();
            }

            if ($alreadyDone) {
                $skipped[] = $habit->name;
                continue;
            }

            // Auto-resume if paused
            if ($habit->paused_at !== null) {
                $habit->update(['paused_at' => null]);
            }

            $oldBest    = $this->getBestStreak($habit->id);
            $streak     = $this->calculateStreak($habit->id, $habit->frequency);
            $newStreak  = $streak + 1;
            $bestStreak = max($oldBest, $newStreak);

            HabitLog::create([
                'habit_id'       => $habit->id,
                'completed_date' => $today,
                'streak_count'   => $newStreak,
                'best_streak'    => $bestStreak,
            ]);

            $this->cacheStreak($habit->id, $newStreak, $bestStreak);
            $logged[] = "{$habit->name} (streak: {$newStreak})";
        }

        $lines = [];

        if (!empty($logged)) {
            $lines[] = "Cochees : " . implode(', ', $logged);
        }
        if (!empty($skipped)) {
            $lines[] = "Deja cochees aujourd'hui : " . implode(', ', $skipped);
        }
        if (!empty($notFound)) {
            $lines[] = "Introuvables : " . implode(', ', $notFound);
        }
        if (empty($logged) && empty($skipped)) {
            $lines[] = "Aucune habitude cochee.";
        }

        $reply = implode("\n", $lines);
        $this->sendText($context->from, $reply);
        $this->log($context, 'Habit log multiple', ['logged' => count($logged), 'skipped' => count($skipped)]);

        return AgentResult::reply($reply, ['action' => 'habit_log_multiple', 'logged' => count($logged)]);
    }

    /**
     * Pause an active habit (streak preserved, excluded from pending/at-risk views).
     */
    private function handlePause(AgentContext $context, $habits, array $parsed): AgentResult
    {
        $item = $parsed['item'] ?? null;
        if (!$item || $habits->isEmpty()) {
            $reply = "Quelle habitude veux-tu mettre en pause ? Donne le numero.\nEx: \"Mettre en pause habitude 2\"";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'habit_pause_no_item']);
        }

        $habit = $habits->values()[(int) $item - 1] ?? null;

        if (!$habit) {
            $reply = "Habitude #{$item} introuvable. Dis \"mes habitudes\" pour voir ta liste.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'habit_pause_not_found']);
        }

        if ($habit->paused_at !== null) {
            $reply = "\"{$habit->name}\" est deja en pause.\nDis \"reprendre habitude {$item}\" pour la reactiver.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'habit_pause_already_paused']);
        }

        $streak = $this->getCachedStreak($habit->id) ?? $this->calculateStreak($habit->id, $habit->frequency);
        $habit->update(['paused_at' => now()]);

        $reply = "Habitude \"{$habit->name}\" mise en pause.\n"
            . "Streak preservee : {$streak}" . ($habit->frequency === 'weekly' ? ' sem' : 'j') . "\n"
            . "Dis \"reprendre habitude {$item}\" quand tu es pret(e) !";
        $this->sendText($context->from, $reply);
        $this->log($context, 'Habit paused', ['habit_id' => $habit->id, 'name' => $habit->name]);

        return AgentResult::reply($reply, ['action' => 'habit_pause', 'habit_id' => $habit->id]);
    }

    /**
     * Resume a paused habit.
     */
    private function handleResume(AgentContext $context, $habits, array $parsed): AgentResult
    {
        $item = $parsed['item'] ?? null;
        if (!$item || $habits->isEmpty()) {
            $reply = "Quelle habitude veux-tu reprendre ? Donne le numero.\nEx: \"Reprendre habitude 2\"";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'habit_resume_no_item']);
        }

        $habit = $habits->values()[(int) $item - 1] ?? null;

        if (!$habit) {
            $reply = "Habitude #{$item} introuvable. Dis \"mes habitudes\" pour voir ta liste.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'habit_resume_not_found']);
        }

        if ($habit->paused_at === null) {
            $reply = "\"{$habit->name}\" n'est pas en pause, elle est deja active !";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'habit_resume_not_paused']);
        }

        $habit->update(['paused_at' => null]);

        $reply = "Habitude \"{$habit->name}\" reactivee !\n"
            . "Bienvenue de retour — continue sur ta lancee !";
        $this->sendText($context->from, $reply);
        $this->log($context, 'Habit resumed', ['habit_id' => $habit->id, 'name' => $habit->name]);

        return AgentResult::reply($reply, ['action' => 'habit_resume', 'habit_id' => $habit->id]);
    }

    /**
     * NEW: Show habits ranked by current streak descending.
     */
    private function handleStreakBoard(AgentContext $context, $habits): AgentResult
    {
        if ($habits->isEmpty()) {
            $reply = "Tu n'as aucune habitude enregistree.\nDis \"ajouter habitude Meditation\" pour commencer !";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'habit_streak_board_empty']);
        }

        $ranked = [];
        foreach ($habits->values() as $i => $habit) {
            $streak     = $this->getCachedStreak($habit->id) ?? $this->calculateStreak($habit->id, $habit->frequency);
            $bestStreak = $this->getBestStreak($habit->id);
            $unit       = $habit->frequency === 'weekly' ? 'sem' : 'j';
            $ranked[]   = [
                'num'        => $i + 1,
                'name'       => $habit->name,
                'streak'     => $streak,
                'bestStreak' => $bestStreak,
                'unit'       => $unit,
            ];
        }

        usort($ranked, fn($a, $b) => $b['streak'] <=> $a['streak']);

        $medals = ['1' => '1er', '2' => '2eme', '3' => '3eme'];
        $lines  = ["Classement des streaks :"];

        foreach ($ranked as $rank => $data) {
            $pos     = $rank + 1;
            $prefix  = $medals[(string) $pos] ?? "{$pos}eme";
            $lines[] = "\n{$prefix}. {$data['name']}";
            $lines[] = "   Streak: {$data['streak']}{$data['unit']} | Record: {$data['bestStreak']}{$data['unit']}";
        }

        $totalStreak = array_sum(array_column($ranked, 'streak'));
        $lines[]     = "\n---";
        $lines[]     = "Total streaks cumules : {$totalStreak}";

        $reply = implode("\n", $lines);
        $this->sendText($context->from, $reply);
        $this->log($context, 'Habit streak board viewed', ['count' => count($ranked)]);

        return AgentResult::reply($reply, ['action' => 'habit_streak_board']);
    }

    /**
     * NEW: Weekly report — completion rate per habit for the current week.
     */
    private function handleWeeklyReport(AgentContext $context, $habits): AgentResult
    {
        if ($habits->isEmpty()) {
            $reply = "Tu n'as aucune habitude enregistree.\nDis \"ajouter habitude Meditation\" pour commencer !";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'habit_weekly_report_empty']);
        }

        $tz        = AppSetting::timezone();
        $now       = now($tz);
        $weekStart = $now->copy()->startOfWeek()->toDateString();
        $weekEnd   = $now->copy()->endOfWeek()->toDateString();
        $today     = $now->toDateString();
        $habitIds  = $habits->pluck('id')->toArray();

        // Build list of days from Monday to today (within the current week)
        $days   = [];
        $cursor = Carbon::parse($weekStart, $tz);
        $limit  = Carbon::parse(min($today, $weekEnd), $tz);
        while ($cursor->lte($limit)) {
            $days[] = $cursor->toDateString();
            $cursor->addDay();
        }
        $elapsedDays = count($days);

        $logsByHabit = HabitLog::whereIn('habit_id', $habitIds)
            ->whereBetween('completed_date', [$weekStart, $today])
            ->get()
            ->groupBy('habit_id');

        $totalDone     = 0;
        $totalPossible = 0;
        $weekLabel     = Carbon::parse($weekStart, $tz)->format('d/m') . ' - ' . Carbon::parse($weekEnd, $tz)->format('d/m');
        $lines         = ["Rapport semaine ({$weekLabel}) :"];

        foreach ($habits->values() as $i => $habit) {
            $num       = $i + 1;
            $habitLogs = $logsByHabit->get($habit->id, collect());

            if ($habit->frequency === 'daily') {
                $possible  = $elapsedDays;
                $doneCount = $habitLogs->count();
            } else {
                // Weekly: 1 expected per week, count as 0 or 1
                $possible  = 1;
                $doneCount = $habitLogs->isNotEmpty() ? 1 : 0;
            }

            $rate = $possible > 0 ? round(($doneCount / $possible) * 100) : 0;
            $totalDone     += $doneCount;
            $totalPossible += $possible;

            $bar     = $this->buildMiniBar($doneCount, $possible);
            $freq    = $habit->frequency === 'daily' ? 'daily' : 'hebdo';
            $lines[] = "\n{$num}. {$habit->name} [{$freq}]";
            $lines[] = "   {$bar} {$doneCount}/{$possible} ({$rate}%)";
        }

        $globalRate = $totalPossible > 0 ? (int) round(($totalDone / $totalPossible) * 100) : 0;
        $lines[]    = "\n---";
        $lines[]    = "Bilan semaine : {$totalDone}/{$totalPossible} ({$globalRate}%)";

        if ($globalRate === 100) {
            $lines[] = "Semaine parfaite ! Continue comme ca !";
        } elseif ($globalRate >= 75) {
            $lines[] = "Tres bonne semaine !";
        } elseif ($globalRate >= 50) {
            $lines[] = "Bonne progression, on peut faire mieux !";
        } else {
            $lines[] = "Il reste du chemin — la semaine n'est pas finie !";
        }

        $reply = implode("\n", $lines);
        $this->sendText($context->from, $reply);
        $this->log($context, 'Habit weekly report viewed', ['global_rate' => $globalRate]);

        return AgentResult::reply($reply, ['action' => 'habit_weekly_report']);
    }

    /**
     * Rapport des 30 derniers jours, breakdown par semaine (S1..S4).
     */
    private function handleMonthlyReport(AgentContext $context, $habits): AgentResult
    {
        if ($habits->isEmpty()) {
            $reply = "Tu n'as aucune habitude enregistree.\nDis \"ajouter habitude Meditation\" pour commencer !";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'habit_monthly_report_empty']);
        }

        $tz       = AppSetting::timezone();
        $now      = now($tz);
        $today    = $now->toDateString();
        $habitIds = $habits->pluck('id')->toArray();

        // 4 segments de ~7-8 jours couvrant les 30 derniers jours
        // S1 (plus ancien): j-29 -> j-22  (8 jours)
        // S2:               j-21 -> j-15  (7 jours)
        // S3:               j-14 -> j-8   (7 jours)
        // S4 (recent):      j-7  -> j-0   (8 jours)
        $segments = [
            ['start' => $now->copy()->subDays(29)->toDateString(), 'end' => $now->copy()->subDays(22)->toDateString(), 'label' => 'S1'],
            ['start' => $now->copy()->subDays(21)->toDateString(), 'end' => $now->copy()->subDays(15)->toDateString(), 'label' => 'S2'],
            ['start' => $now->copy()->subDays(14)->toDateString(), 'end' => $now->copy()->subDays(8)->toDateString(),  'label' => 'S3'],
            ['start' => $now->copy()->subDays(7)->toDateString(),  'end' => $today,                                   'label' => 'S4'],
        ];

        $since30 = $segments[0]['start'];

        $logsByHabit = HabitLog::whereIn('habit_id', $habitIds)
            ->whereBetween('completed_date', [$since30, $today])
            ->get()
            ->groupBy('habit_id');

        $totalDone     = 0;
        $totalPossible = 0;
        $dateRange     = Carbon::parse($since30, $tz)->format('d/m') . ' - ' . Carbon::parse($today, $tz)->format('d/m');
        $lines         = ["Rapport mensuel ({$dateRange}) :"];

        foreach ($habits->values() as $i => $habit) {
            $num       = $i + 1;
            $habitLogs = $logsByHabit->get($habit->id, collect());
            $logDates  = $habitLogs->pluck('completed_date')
                ->map(fn($d) => $d instanceof Carbon ? $d->toDateString() : Carbon::parse($d)->toDateString())
                ->toArray();

            $lines[] = "\n{$num}. {$habit->name}";

            if ($habit->frequency === 'daily') {
                $habitDone     = 0;
                $habitPossible = 0;

                foreach ($segments as $seg) {
                    $wDone   = 0;
                    $wDays   = 0;
                    $cursor  = Carbon::parse($seg['start'], $tz);
                    $wEnd    = Carbon::parse($seg['end'], $tz);

                    while ($cursor->lte($wEnd)) {
                        $wDays++;
                        if (in_array($cursor->toDateString(), $logDates)) {
                            $wDone++;
                        }
                        $cursor->addDay();
                    }

                    $wRate         = $wDays > 0 ? round(($wDone / $wDays) * 100) : 0;
                    $bar           = $this->buildMiniBar($wDone, $wDays);
                    $lines[]       = "   {$seg['label']}: {$bar} {$wDone}/{$wDays} ({$wRate}%)";
                    $habitDone     += $wDone;
                    $habitPossible += $wDays;
                }

                $rate30        = $habitPossible > 0 ? round(($habitDone / $habitPossible) * 100) : 0;
                $lines[]       = "   Total: {$habitDone}/30j ({$rate30}%)";
                $totalDone     += $habitDone;
                $totalPossible += $habitPossible;
            } else {
                // Habitude hebdomadaire: max 4 semaines
                $weeksDone = 0;
                foreach ($segments as $seg) {
                    $doneInSeg = collect($logDates)
                        ->filter(fn($d) => $d >= $seg['start'] && $d <= $seg['end'])
                        ->isNotEmpty();
                    if ($doneInSeg) {
                        $weeksDone++;
                    }
                }
                $rate30        = round(($weeksDone / 4) * 100);
                $bar           = $this->buildMiniBar($weeksDone, 4);
                $lines[]       = "   {$bar} {$weeksDone}/4 sem ({$rate30}%)";
                $totalDone     += $weeksDone;
                $totalPossible += 4;
            }
        }

        $globalRate = $totalPossible > 0 ? (int) round(($totalDone / $totalPossible) * 100) : 0;
        $lines[]    = "\n---";
        $lines[]    = "Taux global 30j : {$globalRate}%";

        if ($globalRate >= 90) {
            $lines[] = "Mois exceptionnel — continue comme ca !";
        } elseif ($globalRate >= 75) {
            $lines[] = "Tres bon mois !";
        } elseif ($globalRate >= 50) {
            $lines[] = "Bonne progression — on peut faire encore mieux !";
        } else {
            $lines[] = "Mois difficile — mais chaque jour est une nouvelle chance !";
        }

        $reply = implode("\n", $lines);
        $this->sendText($context->from, $reply);
        $this->log($context, 'Habit monthly report viewed', ['global_rate' => $globalRate]);

        return AgentResult::reply($reply, ['action' => 'habit_monthly_report']);
    }

    /**
     * Analyse la regularite par jour de la semaine sur les 90 derniers jours.
     * Uniquement pour les habitudes daily (les weekly n'ont pas de jour specifique).
     */
    private function handleBestDay(AgentContext $context, $habits): AgentResult
    {
        $dailyHabits = $habits->where('frequency', 'daily');

        if ($dailyHabits->isEmpty()) {
            $reply = "Tu n'as aucune habitude quotidienne.\nAjoute-en une pour voir ton analyse par jour de semaine.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'habit_best_day_no_daily']);
        }

        $tz         = AppSetting::timezone();
        $now        = now($tz);
        $today      = $now->toDateString();
        $since90    = $now->copy()->subDays(89)->toDateString();
        $habitIds   = $dailyHabits->pluck('id')->toArray();
        $dailyCount = $dailyHabits->count();

        $logs = HabitLog::whereIn('habit_id', $habitIds)
            ->where('completed_date', '>=', $since90)
            ->get();

        if ($logs->isEmpty()) {
            $reply = "Pas encore assez de donnees.\nCoche quelques habitudes d'abord pour voir ton analyse !";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'habit_best_day_no_data']);
        }

        // Compter les logs par jour de semaine (1=Lundi, 7=Dimanche)
        $dowCounts = array_fill(1, 7, 0);
        foreach ($logs as $log) {
            $dow = Carbon::parse($log->completed_date instanceof Carbon ? $log->completed_date->toDateString() : $log->completed_date, $tz)->dayOfWeekIso;
            $dowCounts[$dow]++;
        }

        // Compter le nombre d'occurrences de chaque jour dans la periode
        $dowOccurrences = array_fill(1, 7, 0);
        $cursor         = Carbon::parse($since90, $tz);
        $end            = Carbon::parse($today, $tz);
        while ($cursor->lte($end)) {
            $dowOccurrences[$cursor->dayOfWeekIso]++;
            $cursor->addDay();
        }

        // Calculer les taux de completion par jour
        $dayNames = [1 => 'Lundi', 2 => 'Mardi', 3 => 'Mercredi', 4 => 'Jeudi', 5 => 'Vendredi', 6 => 'Samedi', 7 => 'Dimanche'];
        $rates    = [];
        for ($dow = 1; $dow <= 7; $dow++) {
            $expected   = $dowOccurrences[$dow] * $dailyCount;
            $rates[$dow] = $expected > 0 ? min(100, (int) round(($dowCounts[$dow] / $expected) * 100)) : 0;
        }

        $bestDow  = (int) array_search(max($rates), $rates);
        $worstDow = (int) array_search(min($rates), $rates);

        $lines   = ["Analyse par jour de semaine (90 derniers jours) :"];
        $lines[] = "({$dailyCount} habitude(s) quotidienne(s))";
        $lines[] = "";

        for ($dow = 1; $dow <= 7; $dow++) {
            $bar     = $this->buildMiniBar($rates[$dow], 100, 7);
            $marker  = ($dow === $bestDow) ? ' <-' : '';
            $lines[] = sprintf("  %-10s %s %d%%%s", $dayNames[$dow] . ':', $bar, $rates[$dow], $marker);
        }

        $lines[] = "";
        $lines[] = "Meilleur jour : {$dayNames[$bestDow]} ({$rates[$bestDow]}%)";
        if ($bestDow !== $worstDow) {
            $lines[] = "Jour le plus difficile : {$dayNames[$worstDow]} ({$rates[$worstDow]}%)";
        }

        if ($rates[$bestDow] >= 80) {
            $lines[] = "Excellent le {$dayNames[$bestDow]} — continue !";
        } elseif ($rates[$bestDow] >= 60) {
            $lines[] = "Bon rythme le {$dayNames[$bestDow]} !";
        } else {
            $lines[] = "Tu peux encore progresser — essaie de viser 80% !";
        }

        $reply = implode("\n", $lines);
        $this->sendText($context->from, $reply);
        $this->log($context, 'Habit best day viewed', ['best_dow' => $bestDow, 'worst_dow' => $worstDow]);

        return AgentResult::reply($reply, ['action' => 'habit_best_day', 'best_dow' => $bestDow]);
    }

    /**
     * Build a simple mini progress bar (e.g. "###--" for 3/5).
     */
    private function buildMiniBar(int $done, int $total, int $width = 5): string
    {
        if ($total <= 0) {
            return str_repeat('-', $width);
        }
        $filled = (int) round(($done / $total) * $width);
        $filled = max(0, min($width, $filled));
        return str_repeat('#', $filled) . str_repeat('-', $width - $filled);
    }

    /**
     * Calculate the current streak for a habit.
     * For daily habits: consecutive days ending today or yesterday.
     * For weekly habits: consecutive weeks ending this week or last week.
     */
    public function calculateStreak(int $habitId, string $frequency = 'daily'): int
    {
        $cached = $this->getCachedStreak($habitId);
        if ($cached !== null) {
            return $cached;
        }

        $tz    = AppSetting::timezone();
        $dates = HabitLog::where('habit_id', $habitId)
            ->orderByDesc('completed_date')
            ->pluck('completed_date')
            ->map(fn($d) => $d instanceof Carbon ? $d->toDateString() : Carbon::parse($d)->toDateString())
            ->toArray();

        if (empty($dates)) {
            $this->cacheStreak($habitId, 0, null);
            return 0;
        }

        $streak = $frequency === 'weekly'
            ? $this->calculateConsecutiveWeeks($dates, $tz)
            : $this->calculateConsecutiveDays($dates, $tz);

        $this->cacheStreak($habitId, $streak, null);

        return $streak;
    }

    /**
     * Count consecutive days (for daily habits).
     */
    private function calculateConsecutiveDays(array $dates, string $tz): int
    {
        $today   = now($tz)->toDateString();
        $streak  = 0;
        $current = Carbon::parse($today, $tz);

        if ($dates[0] !== $today) {
            $current->subDay();
        }

        foreach ($dates as $date) {
            if ($date === $current->toDateString()) {
                $streak++;
                $current->subDay();
            } elseif (Carbon::parse($date) < $current) {
                break;
            }
        }

        return $streak;
    }

    /**
     * Count consecutive weeks (for weekly habits).
     * A week is "done" if at least one log exists in it.
     */
    private function calculateConsecutiveWeeks(array $dates, string $tz): int
    {
        $now           = now($tz);
        $thisWeekStart = $now->copy()->startOfWeek();

        // Map each date to its ISO week start
        $weeks = [];
        foreach ($dates as $date) {
            $weekStart          = Carbon::parse($date, $tz)->startOfWeek()->toDateString();
            $weeks[$weekStart]  = true;
        }

        $streak    = 0;
        $checkWeek = $thisWeekStart->copy();

        // If not logged this week, start from last week
        if (!isset($weeks[$checkWeek->toDateString()])) {
            $checkWeek->subWeek();
        }

        while (isset($weeks[$checkWeek->toDateString()])) {
            $streak++;
            $checkWeek->subWeek();
            if ($streak > 1000) break; // safety guard
        }

        return $streak;
    }

    private function getBestStreak(int $habitId): int
    {
        $cached = Cache::get("habit_best_streak:{$habitId}");
        if ($cached !== null) {
            return (int) $cached;
        }

        $best = (int) (HabitLog::where('habit_id', $habitId)->max('best_streak') ?? 0);
        Cache::put("habit_best_streak:{$habitId}", $best, 3600);

        return $best;
    }

    private function getCachedStreak(int $habitId): ?int
    {
        $val = Cache::get("habit_streak:{$habitId}");
        return $val !== null ? (int) $val : null;
    }

    private function cacheStreak(int $habitId, int $streak, ?int $bestStreak): void
    {
        Cache::put("habit_streak:{$habitId}", $streak, 3600);
        if ($bestStreak !== null) {
            Cache::put("habit_best_streak:{$habitId}", $bestStreak, 3600);
        }
    }

    private function formatHabitList($habits): string
    {
        if ($habits->isEmpty()) {
            return "(aucune habitude enregistree)";
        }

        $tz       = AppSetting::timezone();
        $today    = now($tz)->toDateString();
        $habitIds = $habits->pluck('id')->toArray();

        $doneTodayIds = HabitLog::whereIn('habit_id', $habitIds)
            ->where('completed_date', $today)
            ->pluck('habit_id')
            ->toArray();

        $weekStart       = now($tz)->startOfWeek()->toDateString();
        $doneThisWeekIds = HabitLog::whereIn('habit_id', $habitIds)
            ->whereBetween('completed_date', [$weekStart, $today])
            ->pluck('habit_id')
            ->unique()
            ->toArray();

        $lines = [];

        foreach ($habits->values() as $i => $habit) {
            $num    = $i + 1;
            $streak = $this->getCachedStreak($habit->id) ?? $this->calculateStreak($habit->id, $habit->frequency);
            $unit   = $habit->frequency === 'weekly' ? 'sem' : 'j';

            $isDone = $habit->frequency === 'weekly'
                ? in_array($habit->id, $doneThisWeekIds)
                : in_array($habit->id, $doneTodayIds);

            if ($habit->paused_at !== null) {
                $check = '[PAUSE]';
            } else {
                $check = $isDone ? '[FAIT]' : '[A FAIRE]';
            }
            $lines[] = "#{$num} {$habit->name} ({$habit->frequency}) — streak: {$streak}{$unit} {$check}";
        }

        return implode("\n", $lines);
    }

    private function getMilestoneMessage(int $streak, bool $isNewRecord, string $frequency = 'daily'): string
    {
        $unit       = $frequency === 'weekly' ? 'semaines' : 'jours';
        $milestones = $frequency === 'weekly' ? [52, 26, 12, 8, 4, 2] : [365, 100, 90, 60, 50, 30, 21, 14, 7, 3];

        foreach ($milestones as $m) {
            if ($streak === $m) {
                if ($frequency === 'weekly') {
                    return match ($m) {
                        2  => "2 semaines de suite, super debut !",
                        4  => "1 mois complet sans interruption !",
                        8  => "2 mois de regularite — impressionnant !",
                        12 => "3 mois, une vraie discipline !",
                        26 => "6 mois — mi-annee de streak !",
                        52 => "1 an complet ! Legendaire !",
                        default => "{$m} {$unit} d'affilee !",
                    };
                }

                return match ($m) {
                    3   => "3 jours de suite, beau debut !",
                    7   => "1 semaine complete, continue !",
                    14  => "2 semaines sans interruption !",
                    21  => "21 jours — une nouvelle habitude est nee !",
                    30  => "30 jours d'affilee, impressionnant !",
                    50  => "50 jours — tu es une machine !",
                    60  => "2 mois sans interruption — incroyable !",
                    90  => "3 mois de regularite — champion !",
                    100 => "100 jours ! Legendaire !",
                    365 => "1 an complet — tu es un exemple de discipline !",
                    default => "{$m} {$unit} d'affilee !",
                };
            }
        }

        if ($isNewRecord) {
            return "Nouveau record personnel !";
        }

        return '';
    }

    /**
     * Compare current week completion vs previous week, per habit and globally.
     */
    private function handleCompareWeek(AgentContext $context, $habits): AgentResult
    {
        if ($habits->isEmpty()) {
            $reply = "Tu n'as aucune habitude enregistree.\nDis \"ajouter habitude Meditation\" pour commencer !";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'habit_compare_week_empty']);
        }

        $tz       = AppSetting::timezone();
        $now      = now($tz);
        $today    = $now->toDateString();

        // Semaine courante : lundi -> aujourd'hui
        $curStart = $now->copy()->startOfWeek(Carbon::MONDAY)->toDateString();
        $curEnd   = $today;
        $curDays  = Carbon::parse($curStart, $tz)->diffInDays(Carbon::parse($curEnd, $tz)) + 1;

        // Semaine précédente : lundi-7 -> dimanche-7
        $prevStart = $now->copy()->subWeek()->startOfWeek(Carbon::MONDAY)->toDateString();
        $prevEnd   = $now->copy()->subWeek()->endOfWeek()->toDateString();
        $prevDays  = 7;

        $habitIds = $habits->pluck('id')->toArray();

        $curLogs  = HabitLog::whereIn('habit_id', $habitIds)
            ->whereBetween('completed_date', [$curStart, $curEnd])
            ->get()
            ->groupBy('habit_id');

        $prevLogs = HabitLog::whereIn('habit_id', $habitIds)
            ->whereBetween('completed_date', [$prevStart, $prevEnd])
            ->get()
            ->groupBy('habit_id');

        $curWeekLabel  = Carbon::parse($curStart, $tz)->format('d/m') . '-' . Carbon::parse($curEnd, $tz)->format('d/m');
        $prevWeekLabel = Carbon::parse($prevStart, $tz)->format('d/m') . '-' . Carbon::parse($prevEnd, $tz)->format('d/m');

        $lines = [
            "Comparaison des semaines :",
            "Prec ({$prevWeekLabel}) -> Courante ({$curWeekLabel})",
        ];

        $totalCurDone  = 0;
        $totalPrevDone = 0;
        $totalCurPoss  = 0;
        $totalPrevPoss = 0;

        foreach ($habits->values() as $i => $habit) {
            $num = $i + 1;

            if ($habit->frequency === 'daily') {
                $curDone   = $curLogs->get($habit->id, collect())->count();
                $prevDone  = $prevLogs->get($habit->id, collect())->count();
                $curPoss   = $curDays;
                $prevPoss  = $prevDays;
            } else {
                $curDone  = $curLogs->get($habit->id, collect())->isNotEmpty() ? 1 : 0;
                $prevDone = $prevLogs->get($habit->id, collect())->isNotEmpty() ? 1 : 0;
                $curPoss  = 1;
                $prevPoss = 1;
            }

            $curRate  = $curPoss  > 0 ? round(($curDone  / $curPoss)  * 100) : 0;
            $prevRate = $prevPoss > 0 ? round(($prevDone / $prevPoss) * 100) : 0;
            $diff     = $curRate - $prevRate;

            if ($diff > 0) {
                $trend = "+{$diff}% (progres)";
            } elseif ($diff < 0) {
                $trend = "{$diff}% (baisse)";
            } else {
                $trend = "stable";
            }

            $totalCurDone  += $curDone;
            $totalPrevDone += $prevDone;
            $totalCurPoss  += $curPoss;
            $totalPrevPoss += $prevPoss;

            $lines[] = "\n{$num}. {$habit->name}";
            $lines[] = "   Prec: {$prevDone}/{$prevPoss} ({$prevRate}%) -> Courante: {$curDone}/{$curPoss} ({$curRate}%) [{$trend}]";
        }

        $globalCurRate  = $totalCurPoss  > 0 ? (int) round(($totalCurDone  / $totalCurPoss)  * 100) : 0;
        $globalPrevRate = $totalPrevPoss > 0 ? (int) round(($totalPrevDone / $totalPrevPoss) * 100) : 0;
        $globalDiff     = $globalCurRate - $globalPrevRate;

        $lines[] = "\n---";
        $lines[] = "Global: {$globalPrevRate}% -> {$globalCurRate}%";

        if ($globalDiff > 0) {
            $lines[] = "Progression de +{$globalDiff}% — continue comme ca !";
        } elseif ($globalDiff < 0) {
            $lines[] = "Baisse de " . abs($globalDiff) . "% — rattrape le retard !";
        } else {
            $lines[] = "Meme niveau que la semaine derniere. Vise plus haut !";
        }

        $reply = implode("\n", $lines);
        $this->sendText($context->from, $reply);
        $this->log($context, 'Habit compare week viewed', [
            'cur_rate'  => $globalCurRate,
            'prev_rate' => $globalPrevRate,
        ]);

        return AgentResult::reply($reply, [
            'action'    => 'habit_compare_week',
            'cur_rate'  => $globalCurRate,
            'prev_rate' => $globalPrevRate,
        ]);
    }

    /**
     * Show the top 3 most consistent habits over the last 30 days (by completion rate).
     */
    private function handleTopHabits(AgentContext $context, $habits): AgentResult
    {
        if ($habits->isEmpty()) {
            $reply = "Tu n'as aucune habitude enregistree.\nDis \"ajouter habitude Meditation\" pour commencer !";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'habit_top_habits_empty']);
        }

        $tz      = AppSetting::timezone();
        $now     = now($tz);
        $since30 = $now->copy()->subDays(29)->toDateString();

        $habitIds  = $habits->pluck('id')->toArray();
        $last30Map = HabitLog::whereIn('habit_id', $habitIds)
            ->where('completed_date', '>=', $since30)
            ->selectRaw('habit_id, COUNT(*) as cnt')
            ->groupBy('habit_id')
            ->pluck('cnt', 'habit_id')
            ->toArray();

        $ranked = [];
        foreach ($habits->values() as $i => $habit) {
            $last30      = $last30Map[$habit->id] ?? 0;
            $denominator = $habit->frequency === 'daily' ? 30 : 4;
            $rate        = $denominator > 0 ? min(100, (int) round(($last30 / $denominator) * 100)) : 0;
            $streak      = $this->getCachedStreak($habit->id) ?? $this->calculateStreak($habit->id, $habit->frequency);
            $ranked[]    = [
                'num'    => $i + 1,
                'name'   => $habit->name,
                'rate'   => $rate,
                'streak' => $streak,
                'unit'   => $habit->frequency === 'weekly' ? 'sem' : 'j',
                'last30' => $last30,
                'denom'  => $denominator,
            ];
        }

        usort($ranked, fn($a, $b) => $b['rate'] <=> $a['rate'] ?: $b['streak'] <=> $a['streak']);

        $medals   = ['1er', '2eme', '3eme'];
        $topCount = min(3, count($ranked));
        $lines    = ["Top habitudes (30 derniers jours) :"];

        for ($i = 0; $i < $topCount; $i++) {
            $data    = $ranked[$i];
            $bar     = $this->buildMiniBar($data['rate'], 100);
            $lines[] = "\n{$medals[$i]}. {$data['name']}";
            $lines[] = "   {$bar} {$data['rate']}% ({$data['last30']}/{$data['denom']}) | Streak: {$data['streak']}{$data['unit']}";
        }

        if (count($ranked) > 3) {
            $lines[] = "\n--- Autres ---";
            for ($i = 3; $i < count($ranked); $i++) {
                $data    = $ranked[$i];
                $pos     = $i + 1;
                $lines[] = "   {$pos}. {$data['name']}: {$data['rate']}%";
            }
        }

        $topRate = $ranked[0]['rate'] ?? 0;
        $lines[] = "";
        if ($topRate >= 90) {
            $lines[] = "Tes meilleures habitudes sont vraiment solides !";
        } elseif ($topRate >= 70) {
            $lines[] = "Bonne regularite sur tes top habitudes — continue !";
        } else {
            $lines[] = "Il y a de la place pour progresser — vise 80% !";
        }

        $reply = implode("\n", $lines);
        $this->sendText($context->from, $reply);
        $this->log($context, 'Habit top habits viewed', ['top_rate' => $topRate]);

        return AgentResult::reply($reply, ['action' => 'habit_top_habits', 'top_rate' => $topRate]);
    }

    /**
     * Ask Claude to suggest complementary habits based on the user's current habits.
     */
    private function handleSuggest(AgentContext $context, $habits): AgentResult
    {
        $habitList = $habits->isEmpty()
            ? 'Aucune habitude pour le moment.'
            : $habits->pluck('name')->implode(', ');

        $prompt = "L'utilisateur suit actuellement ces habitudes : {$habitList}.\n"
            . "Suggere 3 nouvelles habitudes complementaires, courtes et actionables.\n"
            . "Reponds en JSON valide uniquement : {\"suggestions\": [{\"name\": \"...\", \"reason\": \"...\", \"frequency\": \"daily|weekly\"}, ...]}\n"
            . "Les suggestions doivent etre differentes de celles deja trackees et adaptees au profil.";

        $response = $this->claude->chat(
            $prompt,
            $this->resolveModel($context),
            "Tu es un coach de bien-etre et de productivite. Suggere des habitudes saines, realistes et complementaires aux habitudes existantes. Reponds UNIQUEMENT en JSON valide, sans markdown."
        );

        $parsed      = $this->parseJson($response);
        $suggestions = $parsed['suggestions'] ?? [];

        if (empty($suggestions) || !is_array($suggestions)) {
            $reply = "Je n'ai pas pu generer des suggestions pour le moment. Reessaie dans quelques instants !";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'habit_suggest_failed']);
        }

        $lines = ["Suggestions d'habitudes complementaires :"];

        foreach (array_slice($suggestions, 0, 3) as $i => $s) {
            $num      = $i + 1;
            $name     = trim($s['name'] ?? '');
            $reason   = trim($s['reason'] ?? '');
            $freq     = ($s['frequency'] ?? 'daily') === 'weekly' ? 'hebdomadaire' : 'quotidienne';

            if (!$name) continue;

            $lines[] = "\n{$num}. {$name} [{$freq}]";
            if ($reason) {
                $lines[] = "   {$reason}";
            }
        }

        $lines[] = "\nDis \"Ajouter habitude [nom]\" pour en ajouter une !";

        $reply = implode("\n", $lines);
        $this->sendText($context->from, $reply);
        $this->log($context, 'Habit suggest viewed', ['count' => count($suggestions)]);

        return AgentResult::reply($reply, ['action' => 'habit_suggest', 'count' => count($suggestions)]);
    }

    /**
     * Show a 28-day heatmap (4 rows of 7 days) for one or all habits.
     * Each cell: X = done, _ = not done. Compact text-based visual.
     */
    private function handleHeatmap(AgentContext $context, $habits, array $parsed): AgentResult
    {
        if ($habits->isEmpty()) {
            $reply = "Tu n'as aucune habitude enregistree.\nDis \"ajouter habitude Meditation\" pour commencer !";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'habit_heatmap_empty']);
        }

        $item = $parsed['item'] ?? null;

        if ($item !== null) {
            $targetHabit = $habits->values()[(int) $item - 1] ?? null;
            if (!$targetHabit) {
                $reply = "Habitude #{$item} introuvable. Dis \"mes habitudes\" pour voir ta liste.";
                $this->sendText($context->from, $reply);
                return AgentResult::reply($reply, ['action' => 'habit_heatmap_not_found']);
            }
            $targetHabits = collect([$targetHabit]);
        } else {
            // Limit to 5 to avoid too long a message on WhatsApp
            $targetHabits = $habits->take(5);
        }

        $tz      = AppSetting::timezone();
        $now     = now($tz);
        $endDate = $now->toDateString();

        // Build exactly 28 days ending today
        $days = [];
        for ($i = 27; $i >= 0; $i--) {
            $days[] = $now->copy()->subDays($i)->toDateString();
        }

        $habitIds    = $targetHabits->pluck('id')->toArray();
        $logsByHabit = HabitLog::whereIn('habit_id', $habitIds)
            ->whereBetween('completed_date', [$days[0], $endDate])
            ->get()
            ->groupBy('habit_id');

        $startLabel = Carbon::parse($days[0], $tz)->format('d/m');
        $endLabel   = Carbon::parse($endDate, $tz)->format('d/m');
        $lines      = ["Heatmap ({$startLabel} - {$endLabel}) :"];
        $lines[]    = "L  M  M  J  V  S  D";

        foreach ($targetHabits->values() as $habit) {
            $num       = $habits->values()->search(fn($h) => $h->id === $habit->id) + 1;
            $habitLogs = $logsByHabit->get($habit->id, collect());
            $logDates  = $habitLogs->pluck('completed_date')
                ->map(fn($d) => $d instanceof Carbon ? $d->toDateString() : Carbon::parse($d)->toDateString())
                ->toArray();

            $doneCount = count(array_intersect($days, $logDates));
            $rate      = round($doneCount / 28 * 100);

            $freqLabel = $habit->frequency === 'weekly' ? ' [hebdo]' : '';
            $lines[]   = "\n{$num}. {$habit->name}{$freqLabel} ({$doneCount}/28j, {$rate}%)";

            // 4 rows of 7 days
            $chunks = array_chunk($days, 7);
            foreach ($chunks as $week) {
                $cells = [];
                foreach ($week as $day) {
                    $cells[] = in_array($day, $logDates) ? 'X' : '_';
                }
                $lines[] = "   " . implode("  ", $cells);
            }
        }

        if ($targetHabits->count() < $habits->count()) {
            $lines[] = "\n(Affichage limite aux 5 premieres habitudes. Dis \"Heatmap habitude N\" pour une habitude specifique.)";
        }

        $reply = implode("\n", $lines);
        $this->sendText($context->from, $reply);
        $this->log($context, 'Habit heatmap viewed', ['count' => $targetHabits->count()]);

        return AgentResult::reply($reply, ['action' => 'habit_heatmap', 'count' => $targetHabits->count()]);
    }

    /**
     * Backdate: log a habit for yesterday (1-day backdating to recover a forgotten log).
     * Only allowed if the habit was NOT already logged for yesterday.
     */
    private function handleBackdate(AgentContext $context, $habits, array $parsed): AgentResult
    {
        $item = $parsed['item'] ?? null;
        if (!$item || $habits->isEmpty()) {
            $reply = "Quelle habitude veux-tu logger pour hier ? Donne le numero.\nEx: \"J'ai fait sport hier\"";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'habit_backdate_no_item']);
        }

        $habit = $habits->values()[(int) $item - 1] ?? null;

        if (!$habit) {
            $reply = "Habitude #{$item} introuvable. Dis \"mes habitudes\" pour voir ta liste.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'habit_backdate_not_found']);
        }

        $tz        = AppSetting::timezone();
        $yesterday = now($tz)->subDay()->toDateString();

        // For weekly habits: check the week containing yesterday
        if ($habit->frequency === 'weekly') {
            $weekStart = now($tz)->subDay()->startOfWeek()->toDateString();
            $weekEnd   = now($tz)->subDay()->endOfWeek()->toDateString();
            $existing  = HabitLog::where('habit_id', $habit->id)
                ->whereBetween('completed_date', [$weekStart, $weekEnd])
                ->first();
        } else {
            $existing = HabitLog::where('habit_id', $habit->id)
                ->where('completed_date', $yesterday)
                ->first();
        }

        if ($existing) {
            $scope = $habit->frequency === 'weekly' ? 'la semaine precedente' : 'hier';
            $reply = "\"{$habit->name}\" est deja loggee pour {$scope}. Pas de rattrapage necessaire !";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'habit_backdate_already_logged']);
        }

        // Auto-resume if paused
        if ($habit->paused_at !== null) {
            $habit->update(['paused_at' => null]);
        }

        // Clear cache so streak is recalculated fresh after inserting the backdated log
        Cache::forget("habit_streak:{$habit->id}");
        Cache::forget("habit_best_streak:{$habit->id}");

        $oldBest    = $this->getBestStreak($habit->id);
        $streak     = $this->calculateStreak($habit->id, $habit->frequency);
        $newStreak  = $streak + 1;
        $bestStreak = max($oldBest, $newStreak);

        HabitLog::create([
            'habit_id'       => $habit->id,
            'completed_date' => $yesterday,
            'streak_count'   => $newStreak,
            'best_streak'    => $bestStreak,
        ]);

        $this->cacheStreak($habit->id, $newStreak, $bestStreak);

        $unit  = $habit->frequency === 'weekly'
            ? ($newStreak <= 1 ? 'semaine' : 'semaines')
            : ($newStreak <= 1 ? 'jour' : 'jours');
        $reply = "Rattrapage effectue ! \"{$habit->name}\" loggee pour hier.\n"
            . "Streak : {$newStreak} {$unit}";

        $milestone = $this->getMilestoneMessage($newStreak, $newStreak > $oldBest && $newStreak > 1, $habit->frequency);
        if ($milestone) {
            $reply .= "\n\n{$milestone}";
        }

        $this->sendText($context->from, $reply);
        $this->log($context, 'Habit backdated', ['habit_id' => $habit->id, 'date' => $yesterday, 'streak' => $newStreak]);

        return AgentResult::reply($reply, ['action' => 'habit_backdate', 'habit_id' => $habit->id, 'streak' => $newStreak]);
    }

    /**
     * Streak challenge: set, view or clear a personal streak target for a specific habit.
     * Stored in AppSetting as "habit_challenge_{habit_id}".
     * target = null  → view current challenge status
     * target = 0     → remove the challenge
     * target >= 1    → set a new challenge
     */
    private function handleStreakChallenge(AgentContext $context, $habits, array $parsed): AgentResult
    {
        $item   = $parsed['item'] ?? null;
        $target = array_key_exists('target', $parsed) ? $parsed['target'] : 'view';

        if (!$item || $habits->isEmpty()) {
            $reply = "Quelle habitude veux-tu defier ? Donne le numero.\n"
                . "Ex: \"Defi 30 jours sur meditation\" ou \"Mon defi streak sport\"";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'habit_challenge_no_item']);
        }

        $habit = $habits->values()[(int) $item - 1] ?? null;

        if (!$habit) {
            $reply = "Habitude #{$item} introuvable. Dis \"mes habitudes\" pour voir ta liste.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'habit_challenge_not_found']);
        }

        $key      = 'habit_challenge_' . $habit->id;
        $existing = AppSetting::get($key);
        $streak   = $this->getCachedStreak($habit->id) ?? $this->calculateStreak($habit->id, $habit->frequency);
        $unit     = $habit->frequency === 'weekly' ? 'semaines' : 'jours';

        // VIEW mode (target = null or not provided)
        if ($target === 'view' || $target === null) {
            if ($existing === null) {
                $reply = "Aucun defi fixe pour \"{$habit->name}\".\n"
                    . "Fixe-toi un objectif ! Ex: \"Defi 30 jours sur {$habit->name}\"";
            } else {
                $challengeTarget = (int) $existing;
                $progress        = min($streak, $challengeTarget);
                $bar             = $this->buildMiniBar($progress, $challengeTarget, 8);
                $pct             = $challengeTarget > 0 ? min(100, (int) round(($streak / $challengeTarget) * 100)) : 0;

                if ($streak >= $challengeTarget) {
                    $reply = "DEFI ACCOMPLI ! \"{$habit->name}\"\n"
                        . "Objectif : {$challengeTarget} {$unit} — Streak actuel : {$streak} {$unit}\n"
                        . "{$bar} 100%\n"
                        . "Felicitations — tu as releve le defi !";
                } else {
                    $remaining = $challengeTarget - $streak;
                    $reply = "Defi \"{$habit->name}\" : {$challengeTarget} {$unit}\n"
                        . "Progression : {$streak}/{$challengeTarget} {$unit} ({$pct}%)\n"
                        . "{$bar}\n"
                        . "Encore {$remaining} {$unit} pour accomplir le defi !";
                }
            }
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'habit_challenge_view', 'habit_id' => $habit->id]);
        }

        $targetInt = (int) $target;

        // REMOVE mode (target = 0)
        if ($targetInt === 0) {
            AppSetting::where('key', $key)->delete();
            $reply = "Defi supprime pour \"{$habit->name}\".";
            $this->sendText($context->from, $reply);
            $this->log($context, 'Habit challenge removed', ['habit_id' => $habit->id]);
            return AgentResult::reply($reply, ['action' => 'habit_challenge_removed', 'habit_id' => $habit->id]);
        }

        // SET mode (target >= 1)
        if ($targetInt < 1) {
            $reply = "L'objectif de streak doit etre un nombre positif. Ex: \"Defi 30 jours sur {$habit->name}\"";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'habit_challenge_invalid']);
        }

        AppSetting::set($key, (string) $targetInt);

        $pct   = $targetInt > 0 ? min(100, (int) round(($streak / $targetInt) * 100)) : 0;
        $bar   = $this->buildMiniBar($streak, $targetInt, 8);
        $reply = "Defi fixe pour \"{$habit->name}\" !\n"
            . "Objectif : {$targetInt} {$unit}\n"
            . "Progression actuelle : {$streak}/{$targetInt} ({$pct}%)\n"
            . "{$bar}\n"
            . "Dis \"mon defi streak {$habit->name}\" pour suivre ta progression !";

        $this->sendText($context->from, $reply);
        $this->log($context, 'Habit challenge set', ['habit_id' => $habit->id, 'target' => $targetInt, 'current_streak' => $streak]);

        return AgentResult::reply($reply, ['action' => 'habit_challenge_set', 'habit_id' => $habit->id, 'target' => $targetInt]);
    }

    private function parseJson(?string $response): ?array
    {
        if (!$response) return null;

        $clean = trim($response);

        if (preg_match('/```(?:json)?\s*(.*?)\s*```/s', $clean, $m)) {
            $clean = $m[1];
        }

        if (!str_starts_with($clean, '{') && preg_match('/(\{.*\})/s', $clean, $m)) {
            $clean = $m[1];
        }

        Log::debug("HabitAgent parse - cleaned: {$clean}");

        $decoded = json_decode($clean, true);
        if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
            Log::warning("HabitAgent JSON parse error: " . json_last_error_msg() . " | raw: {$clean}");
        }

        return $decoded;
    }
}
