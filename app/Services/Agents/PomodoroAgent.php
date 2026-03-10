<?php

namespace App\Services\Agents;

use App\Models\AppSetting;
use App\Models\PomodoroSession;
use App\Services\AgentContext;
use App\Services\PomodoroSessionManager;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class PomodoroAgent extends BaseAgent
{
    private PomodoroSessionManager $pomodoroManager;

    public function __construct()
    {
        parent::__construct();
        $this->pomodoroManager = new PomodoroSessionManager();
    }

    public function name(): string
    {
        return 'pomodoro';
    }

    public function description(): string
    {
        return 'Agent timer Pomodoro pour la productivite. Permet de lancer des sessions de focus minutees (1-120min), mettre en pause/reprendre, prolonger une session en cours (extend), noter sa qualite de concentration (1-5), lancer des pauses courtes/longues (break), voir ses statistiques et records personnels, consulter l\'historique des sessions, voir un rapport hebdomadaire detaille, definir ou reinitialiser un objectif journalier (goal) ou hebdomadaire (weekly) de sessions, obtenir une suggestion intelligente de duree (suggest), comparer la semaine courante a la semaine precedente (compare), et afficher une astuce de productivite (tip).';
    }

    public function keywords(): array
    {
        return [
            'pomodoro', 'pomo', 'timer', 'minuteur',
            'focus', 'concentration', 'concentrer', 'se concentrer',
            'session de travail', 'work session', 'focus session',
            'start pomodoro', 'lance pomodoro', 'lancer pomodoro',
            'start 25', 'start 45', 'start 30',
            'pause pomodoro', 'stop pomodoro', 'end pomodoro',
            'stats pomodoro', 'pomodoro stats', 'mes sessions',
            'productivite', 'productivity', 'productif',
            'deep work', 'travail profond',
            'session en cours', 'timer en cours', 'combien de temps',
            '25 minutes', '45 minutes', 'minutes de focus',
            'historique pomodoro', 'pomodoro history', 'dernieres sessions',
            'objectif pomodoro', 'goal pomodoro', 'pomodoro goal',
            'aide pomodoro', 'help pomodoro', 'commandes pomodoro',
            'reprendre', 'resume pomodoro',
            'rapport pomodoro', 'rapport semaine', 'weekly report', 'bilan semaine',
            'reset objectif', 'supprimer objectif', 'enlever objectif',
            'break', 'pause courte', 'longue pause', 'prendre une pause',
            'repos pomodoro', 'short break', 'long break',
            'records', 'meilleurs', 'meilleures perfs', 'mes records', 'best pomodoro',
            'bilan du jour', 'sessions aujourd hui', "aujourd'hui pomodoro", 'recap pomodoro',
            'label', 'tag', 'sujet session', 'label session', 'etiquette session',
            'extend', 'prolonger', 'prolonge session', 'ajouter du temps', 'plus de temps',
            'extend session', 'prolonger session', 'encore du temps', 'ajoute minutes',
            'suggest', 'suggestion', 'suggere', 'conseille', 'recommande', 'quelle duree', 'combien de minutes',
            'compare', 'comparer', 'cette semaine vs', 'semaine derniere pomodoro', 'progression semaine',
            'weekly', 'objectif semaine', 'semaine objectif', 'goal semaine', 'objectif hebdomadaire',
            'tip', 'astuce', 'astuce productivite', 'conseil focus', 'conseil productivite',
        ];
    }

    public function version(): string
    {
        return '1.7.0';
    }

    public function canHandle(AgentContext $context): bool
    {
        return $context->routedAgent === 'pomodoro';
    }

    public function handle(AgentContext $context): AgentResult
    {
        try {
            $active = $this->pomodoroManager->getActiveSession($context->from, $context->agent->id);
            $activeText = $active
                ? "Session active: {$active->duration}min, demarree a " . $active->started_at->setTimezone(AppSetting::timezone())->format('H:i') . ($active->paused_at ? ' (EN PAUSE)' : ' (EN COURS)')
                : "(aucune session active)";

            $now = now(AppSetting::timezone())->format('Y-m-d H:i (l)');

            $response = $this->claude->chat(
                "Date et heure actuelles (heure de Paris): {$now}\nMessage: \"{$context->body}\"\n\nEtat actuel:\n{$activeText}",
                $this->resolveModel($context),
                $this->buildPrompt()
            );

            $parsed = $this->parseJson($response);

            if (!$parsed || empty($parsed['action'])) {
                $reply = "Je n'ai pas compris. Essaie :\n"
                    . "\"Start 25\" — Lancer un pomodoro de 25min\n"
                    . "\"Pause\" — Mettre en pause / Reprendre\n"
                    . "\"Stop\" — Abandonner la session\n"
                    . "\"End 4\" — Terminer avec une note de focus (1-5)\n"
                    . "\"Break 5\" — Pause courte de 5min\n"
                    . "\"Stats\" — Voir tes statistiques\n"
                    . "\"Help\" — Toutes les commandes disponibles";
                $this->sendText($context->from, $reply);
                return AgentResult::reply($reply, ['action' => 'pomodoro_parse_failed']);
            }

            $action = $parsed['action'];

            return match ($action) {
                'start'   => $this->handleStart($context, $parsed),
                'pause'   => $this->handlePause($context),
                'stop'    => $this->handleStop($context),
                'end'     => $this->handleEnd($context, $parsed),
                'break'   => $this->handleBreak($context, $parsed),
                'extend'  => $this->handleExtend($context, $parsed),
                'stats'   => $this->handleStats($context),
                'status'  => $this->handleStatus($context),
                'history' => $this->handleHistory($context),
                'goal'    => $this->handleGoal($context, $parsed),
                'reset'   => $this->handleReset($context),
                'report'  => $this->handleReport($context),
                'best'    => $this->handleBest($context),
                'today'   => $this->handleToday($context),
                'label'   => $this->handleLabel($context, $parsed),
                'help'    => $this->handleHelp($context),
                'suggest' => $this->handleSuggest($context),
                'compare' => $this->handleCompare($context),
                'weekly'  => $this->handleWeekly($context, $parsed),
                'tip'     => $this->handleTip($context),
                default   => $this->handleUnknown($context),
            };
        } catch (\Exception $e) {
            Log::error("PomodoroAgent handle error: " . $e->getMessage(), ['from' => $context->from]);
            $reply = "Une erreur est survenue. Reessaie dans un instant.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'pomodoro_error']);
        }
    }

    private function buildPrompt(): string
    {
        return <<<'PROMPT'
Tu es un assistant de productivite (Pomodoro Timer).
L'utilisateur te donne un message et tu dois determiner l'action a effectuer.

Reponds UNIQUEMENT en JSON valide, sans markdown, sans explication.

ACTIONS POSSIBLES:

1. DEMARRER une session pomodoro:
{"action": "start", "duration": 25}

2. METTRE EN PAUSE / REPRENDRE (meme commande, bascule l'etat):
{"action": "pause"}

3. ARRETER (abandonner) une session:
{"action": "stop"}

4. TERMINER une session avec note de focus:
{"action": "end", "rating": 4}

5. DEMARRER une PAUSE (break) apres un pomodoro:
{"action": "break", "duration": 5}
Durees: 5 (courte) ou 15 (longue). Par defaut: 5 si non precise.

6. VOIR les statistiques globales:
{"action": "stats"}

7. VOIR le statut de la session en cours:
{"action": "status"}

8. VOIR l'historique des dernieres sessions:
{"action": "history"}

9. DEFINIR ou VOIR l'objectif journalier:
{"action": "goal", "value": 4}
Pour voir l'objectif sans le modifier: {"action": "goal"}

10. REINITIALISER / SUPPRIMER l'objectif journalier:
{"action": "reset"}

11. VOIR le rapport hebdomadaire detaille:
{"action": "report"}

12. VOIR les meilleures performances (records personnels):
{"action": "best"}

13. AIDE - voir toutes les commandes:
{"action": "help"}

14. BILAN DU JOUR - voir un recapitulatif de la journee en cours:
{"action": "today"}

15. LABEL - definir une etiquette/sujet pour la session en cours ou la prochaine:
{"action": "label", "value": "coding"}
Pour voir ou supprimer le label actif: {"action": "label"}

16. PROLONGER la session en cours (extend):
{"action": "extend", "value": 10}
Pour prolonger de 10min par defaut: {"action": "extend"}

17. SUGGESTION intelligente de duree de session (selon l'heure et les perfs passees):
{"action": "suggest"}

18. COMPARER cette semaine vs semaine precedente:
{"action": "compare"}

19. OBJECTIF HEBDOMADAIRE - definir ou voir l'objectif de sessions pour la semaine:
{"action": "weekly", "value": 20}
Pour voir sans modifier: {"action": "weekly"}

20. ASTUCE - afficher une astuce de productivite:
{"action": "tip"}

REGLES:
- 'duration' pour start = duree en minutes (integer, par defaut 25). Min: 1, Max: 120. Valeurs courantes: 15, 25, 30, 45, 50, 60, 90
- 'duration' pour break = 5 (courte) ou 15 (longue) uniquement
- 'rating' = note de qualite de focus de 1 a 5 (integer). 1=tres distrait, 2=peu concentre, 3=correct, 4=bien concentre, 5=ultra concentre
- 'value' = objectif journalier en nombre de sessions (integer, entre 1 et 20)
- Si l'utilisateur dit juste "start", "pomodoro" ou "focus" sans duree → duration = 25
- Si l'utilisateur dit "start 45" ou "45 minutes de focus" → duration = 45
- Si le message est ambigu entre stop et end, prefere "end" si une note est donnee, "stop" sinon
- Si l'utilisateur dit "reprendre" et qu'il y a une session en pause → action = "pause" (toggle)
- "history", "historique", "dernieres sessions" → action = "history"
- "objectif", "goal", "set goal" → action = "goal"
- "reset objectif", "supprimer objectif", "enlever objectif", "no goal", "remove goal" → action = "reset"
- "rapport", "report", "semaine", "bilan semaine", "weekly" → action = "report"
- "break", "pause courte", "repos", "short break" → action = "break", duration = 5
- "longue pause", "grande pause", "long break", "break 15" → action = "break", duration = 15
- "records", "meilleurs", "meilleures perfs", "best", "mes records" → action = "best"
- "aide", "help", "commandes", "comment ca marche" → action = "help"
- "today", "bilan du jour", "aujourd'hui", "recap", "sessions aujourd'hui" → action = "today"
- "label coding", "tag lecture", "sujet maths" → action = "label", value = le sujet (ex: "coding")
- "label" seul, "mon label", "supprimer label" → action = "label" (sans value)
- 'value' pour label = texte court decrivant le sujet (max 50 caracteres)
- 'value' pour extend = minutes a ajouter (integer, entre 1 et 60, par defaut 10)
- "extend [n]", "prolonge [n] min", "ajoute [n] min", "encore [n] min" → action = "extend", value = n
- "extend" seul, "prolonge", "plus de temps" → action = "extend" (sans value, defaut = 10)
- "suggest", "suggere", "conseille", "quelle duree", "recommande" → action = "suggest"
- "compare", "comparer", "cette semaine vs", "semaine derniere", "progression" → action = "compare"
- "objectif semaine [n]", "weekly [n]", "goal semaine [n]", "viser [n] cette semaine" → action = "weekly", value = n
- "objectif semaine", "weekly", "mon objectif semaine" → action = "weekly" (sans value)
- 'value' pour weekly = nombre de sessions pour la semaine (integer, entre 1 et 100)
- "tip", "astuce", "conseil", "astuce productivite", "donne-moi un conseil" → action = "tip"

EXEMPLES:
- "Start 25" → {"action": "start", "duration": 25}
- "Lance un pomodoro" → {"action": "start", "duration": 25}
- "Focus 45 min" → {"action": "start", "duration": 45}
- "Deep work 90 minutes" → {"action": "start", "duration": 90}
- "Pause" → {"action": "pause"}
- "Reprends" ou "Resume" → {"action": "pause"}
- "Stop" ou "Arrete" ou "Abandonne" → {"action": "stop"}
- "Fini, 4/5" ou "End 4" → {"action": "end", "rating": 4}
- "Termine, c'etait bien" → {"action": "end", "rating": 4}
- "Done, je me suis trop distrait" → {"action": "end", "rating": 2}
- "Break" ou "Pause courte" → {"action": "break", "duration": 5}
- "Break 15" ou "Longue pause" → {"action": "break", "duration": 15}
- "Stats" ou "Mes stats pomodoro" → {"action": "stats"}
- "Session en cours?" ou "Timer?" ou "Combien de temps?" → {"action": "status"}
- "Historique" ou "Mes dernieres sessions" → {"action": "history"}
- "Objectif 4 sessions" ou "Set goal 4" → {"action": "goal", "value": 4}
- "Mon objectif" ou "Goal?" → {"action": "goal"}
- "Reset objectif" ou "Supprimer objectif" ou "No goal" → {"action": "reset"}
- "Rapport semaine" ou "Mon bilan" ou "Weekly" → {"action": "report"}
- "Mes records" ou "Meilleures perfs" ou "Best" → {"action": "best"}
- "Aide" ou "Help" ou "Commandes" → {"action": "help"}
- "Bilan du jour" ou "Today" ou "Sessions aujourd'hui" → {"action": "today"}
- "Label coding" ou "Tag lecture" → {"action": "label", "value": "coding"}
- "Label" seul ou "Mon label" → {"action": "label"}
- "Extend 10" ou "Prolonge 10 min" → {"action": "extend", "value": 10}
- "Encore 5 minutes" ou "Ajoute 5 min" → {"action": "extend", "value": 5}
- "Extend" seul ou "Prolonge" ou "Plus de temps" → {"action": "extend"}
- "Suggere", "Conseille", "Quelle duree?" → {"action": "suggest"}
- "Compare" ou "Progression semaine" ou "Semaine derniere?" → {"action": "compare"}
- "Objectif semaine 20" ou "Weekly 15" → {"action": "weekly", "value": 20}
- "Objectif semaine" ou "Weekly?" ou "Mon objectif semaine" → {"action": "weekly"}
- "Tip" ou "Astuce" ou "Donne-moi un conseil" → {"action": "tip"}

Reponds UNIQUEMENT avec le JSON.
PROMPT;
    }

    private function handleStart(AgentContext $context, array $parsed): AgentResult
    {
        $duration = $parsed['duration'] ?? 25;
        $duration = max(1, min(120, (int) $duration));

        $existing = $this->pomodoroManager->getActiveSession($context->from, $context->agent->id);
        $warningPrefix = '';
        if ($existing) {
            $elapsed = $existing->started_at->diffInMinutes(now());
            $warningPrefix = "Session precedente ({$existing->duration}min, {$elapsed}min ecoulees) abandonnee.\n\n";
        }

        $session = $this->pomodoroManager->startSession($context->from, $context->agent->id, $duration);

        // Clear any active break when starting a new pomodoro
        Cache::forget("pomodoro:break:{$context->from}:{$context->agent->id}");

        $tz = AppSetting::timezone();
        $startTime = $session->started_at->setTimezone($tz)->format('H:i');
        $endTime = $session->started_at->copy()->addMinutes($duration)->setTimezone($tz)->format('H:i');

        $goalSuffix = $this->buildGoalProgressSuffix($context);

        $reply = $warningPrefix
            . "Pomodoro lance ! {$duration}min de focus.\n"
            . "Debut : {$startTime} — Fin prevue : {$endTime}\n"
            . $goalSuffix
            . "\nDis \"pause\", \"stop\" ou \"end [1-5]\" quand tu as fini.";

        $this->sendText($context->from, $reply);
        $this->log($context, 'Pomodoro started', ['session_id' => $session->id, 'duration' => $duration]);

        return AgentResult::reply($reply, ['action' => 'pomodoro_start', 'session_id' => $session->id]);
    }

    private function handlePause(AgentContext $context): AgentResult
    {
        $session = $this->pomodoroManager->pauseSession($context->from, $context->agent->id);

        if (!$session) {
            $reply = "Aucune session active. Dis \"start 25\" pour en lancer une !";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'pomodoro_pause_no_session']);
        }

        if ($session->paused_at) {
            $pausedSince = $session->paused_at->setTimezone(AppSetting::timezone())->format('H:i');
            $elapsed = $session->started_at->diffInMinutes($session->paused_at);
            $remaining = max(0, $session->duration - $elapsed);
            $reply = "Session mise en pause a {$pausedSince}. ({$elapsed}min ecoulees, {$remaining}min restantes)\n"
                . "Dis \"pause\" ou \"reprends\" pour continuer.";
            $logMessage = 'Pomodoro paused';
        } else {
            $elapsed = $session->started_at->diffInMinutes(now());
            $remaining = max(0, $session->duration - $elapsed);
            $reply = "Session reprise ! Encore environ {$remaining}min de focus.\n"
                . "Dis \"end [note 1-5]\" quand tu as fini ou \"pause\" pour mettre en pause.";
            $logMessage = 'Pomodoro resumed';
        }

        $this->sendText($context->from, $reply);
        $this->log($context, $logMessage, ['session_id' => $session->id]);

        return AgentResult::reply($reply, ['action' => 'pomodoro_pause', 'session_id' => $session->id]);
    }

    private function handleStop(AgentContext $context): AgentResult
    {
        $session = $this->pomodoroManager->stopSession($context->from, $context->agent->id);

        if (!$session) {
            $reply = "Aucune session active a abandonner. Dis \"start 25\" pour en lancer une.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'pomodoro_stop_no_session']);
        }

        $elapsed = $session->started_at->diffInMinutes($session->ended_at);
        $percent = $session->duration > 0 ? round(($elapsed / $session->duration) * 100) : 0;

        $encouragement = match (true) {
            $percent >= 80 => "Tu etais si proche ! Relance une session courte pour finir sur une victoire.",
            $percent >= 50 => "Plus de la moitie accomplie. La prochaine, tu iras jusqu'au bout !",
            $percent >= 20 => "Chaque minute compte. Relance quand tu es pret.",
            default        => "Pas de souci. Reprends quand tu te sens pret.",
        };

        $reply = "Session abandonnee apres {$elapsed}min/{$session->duration}min ({$percent}% complete).\n"
            . $encouragement . " Dis \"start\" pour relancer.";

        $this->sendText($context->from, $reply);
        $this->log($context, 'Pomodoro stopped', ['session_id' => $session->id, 'elapsed' => $elapsed]);

        return AgentResult::reply($reply, ['action' => 'pomodoro_stop', 'session_id' => $session->id]);
    }

    private function handleEnd(AgentContext $context, array $parsed): AgentResult
    {
        $rating = isset($parsed['rating']) ? max(1, min(5, (int) $parsed['rating'])) : null;

        $session = $this->pomodoroManager->endSession($context->from, $context->agent->id, $rating);

        if (!$session) {
            $reply = "Aucune session active a terminer. Dis \"start 25\" pour en lancer une.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'pomodoro_end_no_session']);
        }

        $elapsed = $session->started_at->diffInMinutes($session->ended_at);
        $stars = $rating
            ? str_repeat('*', $rating) . str_repeat('.', 5 - $rating) . " ({$rating}/5)"
            : 'non note';

        $motivational = $this->getMotivationalMessage($rating);
        if ($rating === null) {
            $motivational .= "\nEx: \"end 4\" pour noter 4/5.";
        }

        $labelKey = "pomodoro:label:{$context->from}:{$context->agent->id}";
        $label = Cache::get($labelKey);
        if ($label) {
            Cache::forget($labelKey);
        }

        $labelHeader = $label ? " [{$label}]" : '';
        $reply = "Pomodoro termine{$labelHeader} !\n"
            . "Duree : {$elapsed}min — Focus : {$stars}\n"
            . $motivational;

        $stats = $this->pomodoroManager->getPomodoroStats($context->from, $context->agent->id);
        if ($stats['streak_days'] >= 2) {
            $reply .= "\nStreak : {$stats['streak_days']} jours d'affilee !";
        }

        // Weekly goal progress on end
        $weeklyGoal = $this->getWeeklyGoal($context);
        if ($weeklyGoal > 0) {
            $thisWeekCount = $this->getThisWeekCompletedCount($context);
            if ($thisWeekCount >= $weeklyGoal) {
                $reply .= "\nObjectif semaine atteint : {$thisWeekCount}/{$weeklyGoal} !";
            } else {
                $weeklyRemaining = $weeklyGoal - $thisWeekCount;
                $reply .= "\nSemaine : {$thisWeekCount}/{$weeklyGoal} ({$weeklyRemaining} restante(s))";
            }
        }

        $todayCount = $this->getTodayCompletedCount($context);
        $dailyGoal = $this->getDailyGoal($context);
        if ($dailyGoal > 0) {
            if ($todayCount >= $dailyGoal) {
                $reply .= "\nObjectif du jour atteint : {$todayCount}/{$dailyGoal} sessions !";
            } else {
                $remaining = $dailyGoal - $todayCount;
                $reply .= "\nObjectif : {$todayCount}/{$dailyGoal} sessions ({$remaining} restante(s))";
            }
        }

        // Suggest break based on session count (long break every 4 sessions)
        if ($todayCount > 0 && $todayCount % 4 === 0) {
            $reply .= "\n\n4 pomodoros ! Prends une longue pause (15min) — dis \"break 15\".";
        } else {
            $reply .= "\nDis \"break 5\" pour une pause ou \"start\" pour continuer.";
        }

        $this->sendText($context->from, $reply);
        $this->log($context, 'Pomodoro completed', [
            'session_id' => $session->id,
            'elapsed' => $elapsed,
            'rating' => $rating,
        ]);

        return AgentResult::reply($reply, ['action' => 'pomodoro_end', 'session_id' => $session->id]);
    }

    private function handleBreak(AgentContext $context, array $parsed): AgentResult
    {
        $duration = (int) ($parsed['duration'] ?? 5);
        $duration = $duration >= 10 ? 15 : 5;

        $tz = AppSetting::timezone();
        $breakCacheKey = "pomodoro:break:{$context->from}:{$context->agent->id}";

        // Check if a break is already active
        $existingBreak = Cache::get($breakCacheKey);
        if ($existingBreak) {
            $breakStart = Carbon::parse($existingBreak['started_at']);
            $breakElapsed = $breakStart->diffInMinutes(now());
            $breakRemaining = max(0, $existingBreak['duration'] - $breakElapsed);
            if ($breakRemaining > 0) {
                $breakEnd = $breakStart->copy()->addMinutes($existingBreak['duration'])->setTimezone($tz)->format('H:i');
                $reply = "Une pause {$existingBreak['duration']}min est deja en cours ({$breakRemaining}min restantes, fin : {$breakEnd}).\n"
                    . "Dis \"status\" pour le detail ou \"start\" quand tu es pret a reprendre.";
                $this->sendText($context->from, $reply);
                return AgentResult::reply($reply, ['action' => 'pomodoro_break_already_active', 'remaining' => $breakRemaining]);
            }
            Cache::forget($breakCacheKey);
        }

        $endTime = now($tz)->addMinutes($duration);

        Cache::put($breakCacheKey, [
            'started_at' => now()->toDateTimeString(), // UTC
            'duration'   => $duration,
        ], $duration * 60 + 120);

        $label = $duration >= 10 ? 'Longue pause' : 'Pause courte';
        $reply = "{$label} de {$duration}min lancee !\n"
            . "Fin prevue : " . $endTime->format('H:i') . "\n"
            . "Repose-toi bien. Dis \"start\" quand tu es pret a reprendre.";

        $this->sendText($context->from, $reply);
        $this->log($context, 'Break started', ['duration' => $duration]);

        return AgentResult::reply($reply, ['action' => 'pomodoro_break_start', 'duration' => $duration]);
    }

    private function handleExtend(AgentContext $context, array $parsed): AgentResult
    {
        $session = $this->pomodoroManager->getActiveSession($context->from, $context->agent->id);

        if (!$session) {
            $reply = "Aucune session active à prolonger. Dis \"start 25\" pour en lancer une.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'pomodoro_extend_no_session']);
        }

        $minutes = isset($parsed['value']) ? max(1, min(60, (int) $parsed['value'])) : 10;
        $newDuration = $session->duration + $minutes;

        $session->update(['duration' => $newDuration]);
        $session->refresh();

        // Refresh cache TTL to match new duration
        Cache::put(
            "pomodoro:active:{$context->from}:{$context->agent->id}",
            $session->id,
            $newDuration * 60 + 300
        );

        $tz = AppSetting::timezone();
        $elapsed = $session->started_at->diffInMinutes(now());
        $remaining = max(0, $newDuration - $elapsed);
        $newEndTime = $session->started_at->copy()->addMinutes($newDuration)->setTimezone($tz)->format('H:i');

        $reply = "Session prolongée de {$minutes}min !\n"
            . "Durée totale : {$newDuration}min — Fin prévue : {$newEndTime}\n"
            . "Encore {$remaining}min de focus. Tu es dans le flow !";

        $this->sendText($context->from, $reply);
        $this->log($context, 'Pomodoro extended', [
            'session_id'    => $session->id,
            'added_minutes' => $minutes,
            'new_duration'  => $newDuration,
        ]);

        return AgentResult::reply($reply, [
            'action'     => 'pomodoro_extend',
            'session_id' => $session->id,
            'added'      => $minutes,
        ]);
    }

    private function handleStats(AgentContext $context): AgentResult
    {
        $stats = $this->pomodoroManager->getPomodoroStats($context->from, $context->agent->id);

        if ($stats['total_sessions'] === 0) {
            $reply = "Tu n'as pas encore de sessions Pomodoro.\nDis \"start 25\" pour commencer !";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'pomodoro_stats']);
        }

        $hours = intdiv($stats['total_duration_minutes'], 60);
        $mins = $stats['total_duration_minutes'] % 60;
        $durationText = $hours > 0 ? "{$hours}h{$mins}min" : "{$mins}min";
        $focusText = $stats['avg_focus_quality']
            ? number_format($stats['avg_focus_quality'], 1) . '/5'
            : 'non note';

        $todayCount = $this->getTodayCompletedCount($context);
        $dailyGoal = $this->getDailyGoal($context);
        $goalText = $dailyGoal > 0 ? " / objectif: {$dailyGoal}" : '';
        $streakText = $stats['streak_days'] > 0 ? "{$stats['streak_days']} jour(s)" : "0";

        // Weekly goal
        $weeklyGoal = $this->getWeeklyGoal($context);
        $weeklyGoalText = '';
        if ($weeklyGoal > 0) {
            $thisWeekCount = $stats['sessions_this_week'];
            $weeklyRemaining = max(0, $weeklyGoal - $thisWeekCount);
            $weeklyStatus = $thisWeekCount >= $weeklyGoal ? 'ATTEINT !' : "{$weeklyRemaining} restante(s)";
            $weeklyGoalText = " / objectif semaine: {$weeklyGoal} ({$weeklyStatus})";
        }

        // Best day record
        $bestDay = $this->getBestDayStats($context);
        $bestDayText = $bestDay ? " (record: {$bestDay['count']} en une journee)" : '';

        $reply = "Statistiques Pomodoro\n\n"
            . "Aujourd'hui : {$todayCount} session(s){$goalText}\n"
            . "Cette semaine : {$stats['sessions_this_week']} sessions{$weeklyGoalText}\n"
            . "Total : {$stats['total_sessions']} sessions{$bestDayText}\n"
            . "Temps de focus total : {$durationText}\n"
            . "Focus moyen : {$focusText}\n"
            . "Streak : {$streakText}\n\n"
            . "Dis \"best\" pour tes records, \"report\" pour le detail.";

        $this->sendText($context->from, $reply);
        $this->log($context, 'Pomodoro stats viewed', $stats);

        return AgentResult::reply($reply, ['action' => 'pomodoro_stats']);
    }

    private function handleStatus(AgentContext $context): AgentResult
    {
        $session = $this->pomodoroManager->getActiveSession($context->from, $context->agent->id);
        $tz = AppSetting::timezone();

        if (!$session) {
            // Check if a break is active
            $breakCacheKey = "pomodoro:break:{$context->from}:{$context->agent->id}";
            $breakData = Cache::get($breakCacheKey);

            if ($breakData) {
                $breakStart = Carbon::parse($breakData['started_at']);
                $breakElapsed = $breakStart->diffInMinutes(now());
                $breakRemaining = max(0, $breakData['duration'] - $breakElapsed);
                $breakEnd = $breakStart->copy()->addMinutes($breakData['duration'])->setTimezone($tz)->format('H:i');

                if ($breakRemaining > 0) {
                    $progressBar = $this->buildProgressBar($breakElapsed, $breakData['duration']);
                    $reply = "Pause en cours ({$breakData['duration']}min)\n"
                        . "{$progressBar} {$breakElapsed}min/{$breakData['duration']}min\n"
                        . "Fin prevue : {$breakEnd} — {$breakRemaining}min restantes\n"
                        . "Dis \"start\" quand tu es pret a reprendre.";
                    $this->sendText($context->from, $reply);
                    return AgentResult::reply($reply, ['action' => 'pomodoro_break_status']);
                }

                Cache::forget($breakCacheKey);
            }

            $reply = "Aucune session en cours. Dis \"start 25\" pour en lancer une !";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'pomodoro_status_none']);
        }

        $isPaused = (bool) $session->paused_at;
        $status = $isPaused ? 'EN PAUSE' : 'EN COURS';

        $elapsed = $isPaused
            ? $session->started_at->diffInMinutes($session->paused_at)
            : $session->started_at->diffInMinutes(now());

        // Detect expired session: time is up but session not yet ended
        if (!$isPaused && $elapsed >= $session->duration) {
            $overtime = $elapsed - $session->duration;
            $overtimeText = $overtime > 0 ? " (+{$overtime}min de depassement)" : '';
            $reply = "Session terminee{$overtimeText} ! Bravo !\n"
                . "Valide avec \"end [note 1-5]\" pour enregistrer ta session.\n"
                . "Ou dis \"stop\" pour abandonner. Tu peux aussi prolonger avec \"extend 10\".";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'pomodoro_status_expired', 'session_id' => $session->id]);
        }

        $remaining = max(0, $session->duration - $elapsed);
        $percent = $session->duration > 0 ? round(($elapsed / $session->duration) * 100) : 0;
        $startTime = $session->started_at->setTimezone($tz)->format('H:i');
        $endTime = $session->started_at->copy()->addMinutes($session->duration)->setTimezone($tz)->format('H:i');
        $progressBar = $this->buildProgressBar($elapsed, $session->duration);

        $label = Cache::get("pomodoro:label:{$context->from}:{$context->agent->id}");
        $labelText = $label ? "\nLabel : {$label}" : '';

        $reply = "Session {$status}\n"
            . "{$progressBar} {$percent}%\n"
            . "Debut : {$startTime} — Fin prevue : {$endTime}\n"
            . "Ecoulees : {$elapsed}min — Restantes : {$remaining}min"
            . $labelText;

        if ($isPaused) {
            $reply .= "\n\nDis \"pause\" ou \"reprends\" pour continuer.";
        } else {
            $reply .= "\n\nDis \"end [note 1-5]\" pour terminer ou \"pause\" pour mettre en pause.";
        }

        $this->sendText($context->from, $reply);
        return AgentResult::reply($reply, ['action' => 'pomodoro_status', 'session_id' => $session->id]);
    }

    private function handleHistory(AgentContext $context): AgentResult
    {
        $sessions = PomodoroSession::where('user_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->whereNotNull('ended_at')
            ->orderBy('started_at', 'desc')
            ->limit(7)
            ->get();

        if ($sessions->isEmpty()) {
            $reply = "Aucune session terminee pour le moment.\nDis \"start 25\" pour commencer !";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'pomodoro_history_empty']);
        }

        $tz = AppSetting::timezone();
        $lines = ["7 dernieres sessions :"];

        foreach ($sessions as $s) {
            $date = $s->started_at->setTimezone($tz)->format('d/m H:i');
            $elapsed = $s->started_at->diffInMinutes($s->ended_at);
            $icon = $s->is_completed ? 'OK' : 'X ';
            $ratingText = $s->focus_quality ? " [{$s->focus_quality}/5]" : '';
            $lines[] = "{$icon} {$date} — {$elapsed}min/{$s->duration}min{$ratingText}";
        }

        $reply = implode("\n", $lines);
        $this->sendText($context->from, $reply);
        $this->log($context, 'Pomodoro history viewed');

        return AgentResult::reply($reply, ['action' => 'pomodoro_history']);
    }

    private function handleGoal(AgentContext $context, array $parsed): AgentResult
    {
        $cacheKey = "pomodoro:goal:{$context->from}:{$context->agent->id}";

        if (isset($parsed['value'])) {
            $goal = max(1, min(20, (int) $parsed['value']));
            Cache::put($cacheKey, $goal, now()->addDays(365));

            $todayCount = $this->getTodayCompletedCount($context);
            $remaining = max(0, $goal - $todayCount);

            $reply = "Objectif defini : {$goal} sessions par jour.\n"
                . "Aujourd'hui : {$todayCount}/{$goal} ({$remaining} restante(s)).\n"
                . "Dis \"stats\" pour suivre ta progression.";

            $this->sendText($context->from, $reply);
            $this->log($context, 'Pomodoro goal set', ['goal' => $goal]);

            return AgentResult::reply($reply, ['action' => 'pomodoro_goal_set', 'goal' => $goal]);
        }

        $goal = Cache::get($cacheKey, 0);
        $todayCount = $this->getTodayCompletedCount($context);

        if ($goal === 0) {
            $reply = "Tu n'as pas d'objectif journalier defini.\n"
                . "Dis \"objectif 4\" pour viser 4 sessions par jour.";
        } else {
            $remaining = max(0, $goal - $todayCount);
            $status = $todayCount >= $goal ? 'ATTEINT !' : "{$remaining} restante(s)";
            $reply = "Objectif journalier : {$goal} sessions\n"
                . "Aujourd'hui : {$todayCount}/{$goal} — {$status}\n"
                . "Dis \"objectif [nombre]\" pour changer ou \"reset\" pour supprimer.";
        }

        $this->sendText($context->from, $reply);

        return AgentResult::reply($reply, ['action' => 'pomodoro_goal_view']);
    }

    private function handleReset(AgentContext $context): AgentResult
    {
        $cacheKey = "pomodoro:goal:{$context->from}:{$context->agent->id}";
        $hadGoal = Cache::has($cacheKey);
        Cache::forget($cacheKey);

        if ($hadGoal) {
            $reply = "Objectif journalier supprime.\nDis \"objectif [nombre]\" pour en definir un nouveau.";
        } else {
            $reply = "Tu n'avais pas d'objectif journalier defini.\nDis \"objectif [nombre]\" pour en definir un.";
        }

        $this->sendText($context->from, $reply);
        $this->log($context, 'Pomodoro goal reset');

        return AgentResult::reply($reply, ['action' => 'pomodoro_goal_reset']);
    }

    private function handleReport(AgentContext $context): AgentResult
    {
        $tz = AppSetting::timezone();
        $startOfWeek = now($tz)->copy()->startOfWeek();

        $sessions = PomodoroSession::where('user_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->where('is_completed', true)
            ->where('started_at', '>=', $startOfWeek)
            ->orderBy('started_at')
            ->get(['started_at', 'duration', 'focus_quality']);

        if ($sessions->isEmpty()) {
            $reply = "Aucune session completee cette semaine.\nDis \"start 25\" pour commencer !";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'pomodoro_report_empty']);
        }

        $byDay = [];
        foreach ($sessions as $s) {
            $day = $s->started_at->setTimezone($tz)->format('D d/m');
            if (!isset($byDay[$day])) {
                $byDay[$day] = ['count' => 0, 'minutes' => 0, 'ratings' => []];
            }
            $byDay[$day]['count']++;
            $byDay[$day]['minutes'] += $s->duration;
            if ($s->focus_quality) {
                $byDay[$day]['ratings'][] = $s->focus_quality;
            }
        }

        $totalMinutes = $sessions->sum('duration');
        $totalHours = intdiv((int) $totalMinutes, 60);
        $totalMins = (int) $totalMinutes % 60;
        $totalTime = $totalHours > 0 ? "{$totalHours}h{$totalMins}min" : "{$totalMins}min";

        $lines = ["Rapport semaine (lun — auj) :"];
        foreach ($byDay as $day => $data) {
            $avgRating = !empty($data['ratings'])
                ? round(array_sum($data['ratings']) / count($data['ratings']), 1)
                : null;
            $ratingText = $avgRating ? " [focus: {$avgRating}/5]" : '';
            $lines[] = "{$day} : {$data['count']} session(s), {$data['minutes']}min{$ratingText}";
        }

        // Summary line with avg focus across the week
        $allRatings = $sessions->filter(fn ($s) => $s->focus_quality !== null)->pluck('focus_quality')->toArray();
        $avgFocusSummary = !empty($allRatings)
            ? ' — focus moy: ' . number_format(array_sum($allRatings) / count($allRatings), 1) . '/5'
            : '';

        $lines[] = "\nTotal : {$sessions->count()} sessions — {$totalTime} de focus{$avgFocusSummary}";

        $reply = implode("\n", $lines);
        $this->sendText($context->from, $reply);
        $this->log($context, 'Pomodoro report viewed');

        return AgentResult::reply($reply, ['action' => 'pomodoro_report']);
    }

    private function handleBest(AgentContext $context): AgentResult
    {
        $tz = AppSetting::timezone();

        $allSessions = PomodoroSession::where('user_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->where('is_completed', true)
            ->orderBy('started_at')
            ->get(['started_at', 'duration', 'focus_quality']);

        if ($allSessions->isEmpty()) {
            $reply = "Pas encore assez de sessions pour afficher tes records.\nDis \"start 25\" pour commencer !";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'pomodoro_best_empty']);
        }

        // Group by local date to find best day
        $byDay = [];
        foreach ($allSessions as $s) {
            $day = $s->started_at->setTimezone($tz)->format('Y-m-d');
            if (!isset($byDay[$day])) {
                $byDay[$day] = ['count' => 0, 'minutes' => 0];
            }
            $byDay[$day]['count']++;
            $byDay[$day]['minutes'] += $s->duration;
        }

        // Find best day by session count
        $bestDayKey = null;
        $bestDayCount = 0;
        foreach ($byDay as $day => $data) {
            if ($data['count'] > $bestDayCount) {
                $bestDayCount = $data['count'];
                $bestDayKey = $day;
            }
        }

        $stats = $this->pomodoroManager->getPomodoroStats($context->from, $context->agent->id);

        // Best focus session
        $bestFocus = PomodoroSession::where('user_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->where('is_completed', true)
            ->whereNotNull('focus_quality')
            ->orderByDesc('focus_quality')
            ->orderByDesc('duration')
            ->first(['started_at', 'duration', 'focus_quality']);

        $bestDayFormatted = Carbon::parse($bestDayKey, $tz)->format('d/m/Y');
        $bestDayMinutes = $byDay[$bestDayKey]['minutes'];
        $bestDayHours = intdiv($bestDayMinutes, 60);
        $bestDayMins = $bestDayMinutes % 60;
        $bestDayTime = $bestDayHours > 0 ? "{$bestDayHours}h{$bestDayMins}min" : "{$bestDayMins}min";

        $reply = "Tes meilleurs records :\n\n"
            . "Meilleure journee : {$bestDayFormatted}\n"
            . "  → {$bestDayCount} sessions — {$bestDayTime} de focus\n"
            . "Meilleur streak : {$stats['streak_days']} jour(s) consecutifs\n";

        if ($bestFocus) {
            $focusDate = $bestFocus->started_at->setTimezone($tz)->format('d/m');
            $reply .= "Meilleure session : {$bestFocus->focus_quality}/5 ({$bestFocus->duration}min le {$focusDate})\n";
        }

        $totalHours = intdiv($stats['total_duration_minutes'], 60);
        $totalMins = $stats['total_duration_minutes'] % 60;
        $totalTime = $totalHours > 0 ? "{$totalHours}h{$totalMins}min" : "{$totalMins}min";
        $reply .= "\nTotal cumule : {$stats['total_sessions']} sessions — {$totalTime} de focus";

        $this->sendText($context->from, $reply);
        $this->log($context, 'Pomodoro best viewed');

        return AgentResult::reply($reply, ['action' => 'pomodoro_best']);
    }

    private function handleHelp(AgentContext $context): AgentResult
    {
        $reply = "Commandes Pomodoro :\n\n"
            . "LANCER\n"
            . "  start [min] — Ex: \"start 25\" ou \"focus 45\"\n"
            . "  suggest — Suggestion de duree selon l'heure et tes perfs\n"
            . "  break [5|15] — Pause courte (5min) ou longue (15min)\n"
            . "  label [sujet] — Etiqueter la session (ex: label coding)\n\n"
            . "EN SESSION\n"
            . "  pause — Pause / Reprendre\n"
            . "  extend [min] — Prolonger de X min (ex: extend 10)\n"
            . "  stop — Abandonner la session\n"
            . "  end [1-5] — Terminer avec note de focus\n"
            . "  status — Temps restant / etat\n\n"
            . "HISTORIQUE\n"
            . "  today — Bilan du jour\n"
            . "  stats — Statistiques globales\n"
            . "  best — Tes meilleurs records\n"
            . "  history — 7 dernieres sessions\n"
            . "  report — Rapport detaille de la semaine\n"
            . "  compare — Cette semaine vs semaine passee\n\n"
            . "OBJECTIF\n"
            . "  goal — Voir l'objectif du jour\n"
            . "  goal [n] — Definir un objectif (ex: goal 4)\n"
            . "  reset — Supprimer l'objectif";

        $this->sendText($context->from, $reply);

        return AgentResult::reply($reply, ['action' => 'pomodoro_help']);
    }

    private function handleToday(AgentContext $context): AgentResult
    {
        $tz = AppSetting::timezone();
        $today = now($tz)->toDateString();

        $sessions = PomodoroSession::where('user_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->whereDate('started_at', $today)
            ->whereNotNull('ended_at')
            ->orderBy('started_at', 'desc')
            ->get();

        $completed = $sessions->where('is_completed', true);
        $abandoned = $sessions->where('is_completed', false);
        $todayCount = $completed->count();

        if ($todayCount === 0 && $abandoned->count() === 0) {
            $reply = "Aujourd'hui : aucune session.\nDis \"start 25\" pour commencer !";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'pomodoro_today_empty']);
        }

        $todayMinutes = (int) $completed->sum('duration');
        $hours = intdiv($todayMinutes, 60);
        $mins = $todayMinutes % 60;
        $timeText = $hours > 0 ? "{$hours}h{$mins}min" : "{$mins}min";

        $avgFocus = $completed->whereNotNull('focus_quality')->avg('focus_quality');
        $focusLine = $avgFocus ? "\nFocus moyen : " . number_format($avgFocus, 1) . '/5' : '';

        $abandonedCount = $abandoned->count();
        $abandonedText = $abandonedCount > 0 ? " (+{$abandonedCount} abandonnee(s))" : '';

        $dailyGoal = $this->getDailyGoal($context);
        $goalLine = '';
        if ($dailyGoal > 0) {
            if ($todayCount >= $dailyGoal) {
                $goalLine = "\nObjectif atteint : {$todayCount}/{$dailyGoal} !";
            } else {
                $remaining = $dailyGoal - $todayCount;
                $goalLine = "\nObjectif : {$todayCount}/{$dailyGoal} ({$remaining} restante(s))";
            }
        }

        $reply = "Bilan du jour :\n\n"
            . "Sessions : {$todayCount} completee(s){$abandonedText}\n"
            . "Focus total : {$timeText}"
            . $focusLine
            . $goalLine;

        // Last 3 sessions today
        $last3 = $sessions->take(3);
        if ($last3->isNotEmpty()) {
            $reply .= "\n\nDernieres sessions :";
            foreach ($last3 as $s) {
                $time = $s->started_at->setTimezone($tz)->format('H:i');
                $icon = $s->is_completed ? 'OK' : 'X ';
                $ratingText = $s->focus_quality ? " [{$s->focus_quality}/5]" : '';
                $reply .= "\n{$icon} {$time} — {$s->duration}min{$ratingText}";
            }
        }

        $this->sendText($context->from, $reply);
        $this->log($context, 'Pomodoro today viewed', ['count' => $todayCount]);

        return AgentResult::reply($reply, ['action' => 'pomodoro_today']);
    }

    private function handleLabel(AgentContext $context, array $parsed): AgentResult
    {
        $cacheKey = "pomodoro:label:{$context->from}:{$context->agent->id}";

        if (isset($parsed['value']) && trim((string) $parsed['value']) !== '') {
            $label = mb_substr(trim((string) $parsed['value']), 0, 50);
            Cache::put($cacheKey, $label, now()->addHours(4));

            $reply = "Label defini : \"{$label}\"\n"
                . "Il sera associe a ta session en cours ou a la prochaine.\n"
                . "Dis \"status\" pour confirmer ou \"label\" pour supprimer.";

            $this->sendText($context->from, $reply);
            $this->log($context, 'Pomodoro label set', ['label' => $label]);

            return AgentResult::reply($reply, ['action' => 'pomodoro_label_set', 'label' => $label]);
        }

        $current = Cache::get($cacheKey);
        if ($current) {
            Cache::forget($cacheKey);
            $reply = "Label \"{$current}\" supprime.\nDis \"label [sujet]\" pour en definir un nouveau.";
        } else {
            $reply = "Aucun label actif.\nExemple : \"label coding\", \"label lecture\", \"label projet X\".";
        }

        $this->sendText($context->from, $reply);

        return AgentResult::reply($reply, ['action' => 'pomodoro_label_view']);
    }

    private function handleSuggest(AgentContext $context): AgentResult
    {
        $tz = AppSetting::timezone();
        $hour = now($tz)->hour;

        // Time-of-day base duration (in minutes)
        $baseDuration = match (true) {
            $hour >= 6 && $hour < 10  => 45,
            $hour >= 10 && $hour < 12 => 50,
            $hour >= 12 && $hour < 14 => 25,
            $hour >= 14 && $hour < 17 => 30,
            $hour >= 17 && $hour < 20 => 25,
            $hour >= 20 && $hour < 23 => 20,
            default                   => 25,
        };

        $timeLabel = match (true) {
            $hour >= 6 && $hour < 10  => 'matin (fenetre de focus optimal)',
            $hour >= 10 && $hour < 12 => 'fin de matinee (pic de productivite)',
            $hour >= 12 && $hour < 14 => 'post-dejeuner (concentration reduite)',
            $hour >= 14 && $hour < 17 => 'apres-midi',
            $hour >= 17 && $hour < 20 => 'soiree',
            $hour >= 20 && $hour < 23 => 'nuit',
            default                   => 'nuit tardive',
        };

        $recentSessions = PomodoroSession::where('user_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->where('is_completed', true)
            ->orderByDesc('started_at')
            ->limit(10)
            ->get(['duration', 'focus_quality']);

        if ($recentSessions->isEmpty()) {
            $reply = "Suggestion pour {$timeLabel} :\n\n"
                . "Duree recommandee : 25min (classique Pomodoro)\n"
                . "Parfait pour commencer ! Lance ta premiere session.\n\n"
                . "Dis \"start 25\" pour demarrer.";
            $this->sendText($context->from, $reply);
            $this->log($context, 'Pomodoro suggest viewed', ['suggested' => 25, 'reason' => 'no history']);
            return AgentResult::reply($reply, ['action' => 'pomodoro_suggest', 'suggested_duration' => 25]);
        }

        $avgFocus = $recentSessions->whereNotNull('focus_quality')->avg('focus_quality');
        $avgDuration = (int) round($recentSessions->avg('duration'));

        // Adjust duration based on recent focus quality
        $suggested = $baseDuration;
        if ($avgFocus !== null) {
            if ($avgFocus >= 4.5) {
                $suggested = min(60, $suggested + 10);
            } elseif ($avgFocus < 2.5) {
                $suggested = max(15, $suggested - 10);
            }
        }

        // Round to nearest 5 for clean numbers
        $suggested = (int) (round($suggested / 5) * 5);

        $focusText  = $avgFocus !== null ? number_format($avgFocus, 1) . '/5' : 'non note';
        $breakTip   = $suggested >= 45 ? 'break 10' : 'break 5';
        $focusNote  = match (true) {
            $avgFocus === null      => '',
            $avgFocus >= 4.5        => " Ton focus recent est excellent — tu peux viser plus long !",
            $avgFocus < 2.5         => " Ton focus recent est bas — des sessions plus courtes t'aideront.",
            default                 => '',
        };

        $reply = "Suggestion pour {$timeLabel} :\n\n"
            . "Duree recommandee : {$suggested}min{$focusNote}\n"
            . "Focus moyen recent : {$focusText} — duree moy.: {$avgDuration}min\n\n"
            . "Apres la session, prends une pause ({$breakTip}).\n"
            . "Dis \"start {$suggested}\" pour lancer !";

        $this->sendText($context->from, $reply);
        $this->log($context, 'Pomodoro suggest viewed', [
            'suggested'    => $suggested,
            'avg_focus'    => $avgFocus,
            'avg_duration' => $avgDuration,
            'hour'         => $hour,
        ]);

        return AgentResult::reply($reply, ['action' => 'pomodoro_suggest', 'suggested_duration' => $suggested]);
    }

    private function handleCompare(AgentContext $context): AgentResult
    {
        $tz           = AppSetting::timezone();
        $thisWeekStart = now($tz)->copy()->startOfWeek(\Carbon\Carbon::MONDAY);
        $lastWeekStart = $thisWeekStart->copy()->subWeek();

        $thisWeek = PomodoroSession::where('user_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->where('is_completed', true)
            ->where('started_at', '>=', $thisWeekStart)
            ->get(['duration', 'focus_quality']);

        $lastWeek = PomodoroSession::where('user_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->where('is_completed', true)
            ->where('started_at', '>=', $lastWeekStart)
            ->where('started_at', '<', $thisWeekStart)
            ->get(['duration', 'focus_quality']);

        if ($thisWeek->isEmpty() && $lastWeek->isEmpty()) {
            $reply = "Pas encore assez de donnees pour comparer les semaines.\nDis \"start 25\" pour commencer !";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'pomodoro_compare_empty']);
        }

        $thisSessions = $thisWeek->count();
        $lastSessions = $lastWeek->count();
        $sessionsDelta = $thisSessions - $lastSessions;
        $sessionsDeltaText = $sessionsDelta > 0 ? "+{$sessionsDelta}" : (string) $sessionsDelta;

        $thisMinutes = (int) $thisWeek->sum('duration');
        $lastMinutes = (int) $lastWeek->sum('duration');
        $minutesDelta = $thisMinutes - $lastMinutes;
        $minutesDeltaText = $minutesDelta > 0 ? "+{$minutesDelta}min" : "{$minutesDelta}min";

        $thisFocus = $thisWeek->whereNotNull('focus_quality')->avg('focus_quality');
        $lastFocus = $lastWeek->whereNotNull('focus_quality')->avg('focus_quality');

        $formatTime  = static fn(int $m): string => $m >= 60 ? intdiv($m, 60) . 'h' . ($m % 60) . 'min' : "{$m}min";
        $formatFocus = static fn($f): string => $f !== null ? number_format($f, 1) . '/5' : 'n/a';

        $focusDeltaText = '';
        if ($thisFocus !== null && $lastFocus !== null) {
            $fd = $thisFocus - $lastFocus;
            $focusDeltaText = ($fd >= 0 ? ' (+' : ' (') . number_format($fd, 1) . ')';
        }

        $trend = match (true) {
            $sessionsDelta > 0  => "En progression ! Continue sur cette lancee.",
            $sessionsDelta < 0  => "En baisse — relance-toi, tu peux faire mieux !",
            default             => "Stable. Fixe-toi un nouvel objectif pour progresser.",
        };

        $reply = "Comparaison semaines :\n\n"
            . "Sessions   : {$thisSessions} (sem.) vs {$lastSessions} (préc.) [{$sessionsDeltaText}]\n"
            . "Temps focus: {$formatTime($thisMinutes)} vs {$formatTime($lastMinutes)} [{$minutesDeltaText}]\n"
            . "Focus moy. : {$formatFocus($thisFocus)}{$focusDeltaText} vs {$formatFocus($lastFocus)}\n\n"
            . $trend;

        $this->sendText($context->from, $reply);
        $this->log($context, 'Pomodoro compare viewed', [
            'this_week'  => $thisSessions,
            'last_week'  => $lastSessions,
            'delta'      => $sessionsDelta,
        ]);

        return AgentResult::reply($reply, ['action' => 'pomodoro_compare']);
    }

    private function handleUnknown(AgentContext $context): AgentResult
    {
        $reply = "Action non reconnue. Dis \"help\" pour voir toutes les commandes.";
        $this->sendText($context->from, $reply);
        return AgentResult::reply($reply, ['action' => 'pomodoro_unknown_action']);
    }

    private function getTodayCompletedCount(AgentContext $context): int
    {
        $today = now(AppSetting::timezone())->toDateString();
        return PomodoroSession::where('user_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->where('is_completed', true)
            ->whereDate('started_at', $today)
            ->count();
    }

    private function getDailyGoal(AgentContext $context): int
    {
        return (int) Cache::get("pomodoro:goal:{$context->from}:{$context->agent->id}", 0);
    }

    private function buildGoalProgressSuffix(AgentContext $context): string
    {
        $goal = $this->getDailyGoal($context);
        if ($goal === 0) {
            return '';
        }

        $todayCount = $this->getTodayCompletedCount($context);
        $remaining = max(0, $goal - $todayCount);

        if ($remaining === 0) {
            return "Objectif du jour deja atteint ({$todayCount}/{$goal}) — bonus session !\n";
        }

        return "Objectif du jour : {$todayCount}/{$goal} sessions ({$remaining} restante(s))\n";
    }

    private function getBestDayStats(AgentContext $context): ?array
    {
        $tz = AppSetting::timezone();
        $sessions = PomodoroSession::where('user_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->where('is_completed', true)
            ->get(['started_at']);

        if ($sessions->isEmpty()) {
            return null;
        }

        $byDay = [];
        foreach ($sessions as $s) {
            $day = $s->started_at->setTimezone($tz)->format('Y-m-d');
            $byDay[$day] = ($byDay[$day] ?? 0) + 1;
        }

        $bestCount = max($byDay);
        $bestDay = array_search($bestCount, $byDay);

        return ['day' => $bestDay, 'count' => $bestCount];
    }

    private function buildProgressBar(int $elapsed, int $total): string
    {
        if ($total <= 0) {
            return '[----------]';
        }

        $percent = min(1.0, $elapsed / $total);
        $filled = (int) round($percent * 10);
        $empty = 10 - $filled;

        return '[' . str_repeat('#', $filled) . str_repeat('-', $empty) . ']';
    }

    private function getMotivationalMessage(?int $rating): string
    {
        if ($rating === null) {
            return "Pense a noter ton focus la prochaine fois (end 1-5).";
        }

        return match (true) {
            $rating >= 5 => "Etat de flow total ! Tu etais dans la zone.",
            $rating >= 4 => "Tres bonne session, continue comme ca !",
            $rating >= 3 => "Session correcte. Chaque pomodoro compte !",
            $rating >= 2 => "Ce n'est pas grave, la prochaine sera meilleure.",
            default      => "On a tous des jours difficiles. Lance-en une autre quand tu es pret.",
        };
    }

    private function handleWeekly(AgentContext $context, array $parsed): AgentResult
    {
        $cacheKey = "pomodoro:weekly_goal:{$context->from}:{$context->agent->id}";

        if (isset($parsed['value'])) {
            $goal = max(1, min(100, (int) $parsed['value']));
            Cache::put($cacheKey, $goal, now()->endOfWeek()->addDays(7));

            $thisWeekCount = $this->getThisWeekCompletedCount($context);
            $remaining = max(0, $goal - $thisWeekCount);

            $reply = "Objectif hebdomadaire defini : {$goal} sessions cette semaine.\n"
                . "Progression : {$thisWeekCount}/{$goal} ({$remaining} restante(s)).\n"
                . "Dis \"stats\" pour suivre ou \"compare\" pour voir la progression.";

            $this->sendText($context->from, $reply);
            $this->log($context, 'Pomodoro weekly goal set', ['goal' => $goal]);

            return AgentResult::reply($reply, ['action' => 'pomodoro_weekly_goal_set', 'goal' => $goal]);
        }

        $goal = Cache::get($cacheKey, 0);
        $thisWeekCount = $this->getThisWeekCompletedCount($context);

        if ($goal === 0) {
            $reply = "Tu n'as pas d'objectif hebdomadaire defini.\n"
                . "Dis \"objectif semaine 20\" pour viser 20 sessions cette semaine.";
        } else {
            $remaining = max(0, $goal - $thisWeekCount);
            $status = $thisWeekCount >= $goal ? 'ATTEINT !' : "{$remaining} restante(s)";
            $reply = "Objectif hebdomadaire : {$goal} sessions\n"
                . "Cette semaine : {$thisWeekCount}/{$goal} — {$status}\n"
                . "Dis \"objectif semaine [n]\" pour modifier ou \"stats\" pour le detail.";
        }

        $this->sendText($context->from, $reply);

        return AgentResult::reply($reply, ['action' => 'pomodoro_weekly_goal_view']);
    }

    private function handleTip(AgentContext $context): AgentResult
    {
        $tips = [
            "Commence par ta tache la plus difficile — ton cerveau est au sommet de sa forme le matin.",
            "Ferme tes onglets inutiles avant de commencer. Chaque onglet ouvert sollicite ta memoire de travail.",
            "La regle des 2 minutes : si c'est faisable en 2 min, fais-le maintenant. Sinon, planifie-le.",
            "Prends une vraie pause : leve-toi, hydrate-toi, eloigne-toi de l'ecran. Ton cerveau rechargera mieux.",
            "Definis UNE priorite principale avant de commencer ta session. Le focus commence par la clarte.",
            "Mets ton telephone en mode silence. Une notification peut couper 20 minutes de concentration.",
            "L'etat de flow demande en moyenne 23 minutes de travail ininterrompu pour s'installer.",
            "Ecris ce que tu veux accomplir AVANT de demarrer le timer. Ca ancre ton intention.",
            "La technique Pomodoro fonctionne mieux avec des taches bien decoupees. Divise avant de conqurir.",
            "Apres 4 pomodoros, prends une pause de 20-30 min. Ton cerveau consolide alors l'information.",
            "Ecoute de la musique sans paroles si tu as besoin de fond sonore — les paroles fragmentent l'attention.",
            "Le plus dur, c'est de commencer. Lance le timer, tu verras : le travail s'enclenche tout seul.",
            "Note les pensees parasites qui surgissent sur un papier — elles ne disparaitront pas d'elles-memes.",
            "Varie les durees de tes sessions selon l'energie : 15min quand tu es fatigue, 50min quand tu es frais.",
        ];

        $tz = AppSetting::timezone();
        $index = (now($tz)->dayOfWeek + now($tz)->hour) % count($tips);
        $tip = $tips[$index];

        $reply = "Astuce productivite :\n\n\"{$tip}\"\n\nDis \"start 25\" pour appliquer ca maintenant !";

        $this->sendText($context->from, $reply);
        $this->log($context, 'Pomodoro tip viewed', ['index' => $index]);

        return AgentResult::reply($reply, ['action' => 'pomodoro_tip']);
    }

    private function getThisWeekCompletedCount(AgentContext $context): int
    {
        $startOfWeek = now(AppSetting::timezone())->copy()->startOfWeek(\Carbon\Carbon::MONDAY);
        return PomodoroSession::where('user_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->where('is_completed', true)
            ->where('started_at', '>=', $startOfWeek)
            ->count();
    }

    private function getWeeklyGoal(AgentContext $context): int
    {
        return (int) Cache::get("pomodoro:weekly_goal:{$context->from}:{$context->agent->id}", 0);
    }

    private function parseJson(?string $response): ?array
    {
        if (!$response) {
            return null;
        }

        $clean = trim($response);

        if (preg_match('/```(?:json)?\s*(.*?)\s*```/s', $clean, $m)) {
            $clean = $m[1];
        }

        if (!str_starts_with($clean, '{') && preg_match('/(\{.*\})/s', $clean, $m)) {
            $clean = $m[1];
        }

        $decoded = json_decode($clean, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            Log::warning("PomodoroAgent parseJson error: " . json_last_error_msg() . " | raw: {$clean}");
            return null;
        }

        return $decoded;
    }
}
