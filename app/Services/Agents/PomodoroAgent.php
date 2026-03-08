<?php

namespace App\Services\Agents;

use App\Models\AppSetting;
use App\Models\PomodoroSession;
use App\Services\AgentContext;
use App\Services\PomodoroSessionManager;
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
        return 'Agent timer Pomodoro pour la productivite. Permet de lancer des sessions de focus minutees (1-120min), mettre en pause/reprendre, noter sa qualite de concentration (1-5), voir ses statistiques et streaks, consulter l\'historique des sessions, et definir un objectif journalier de sessions.';
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
        ];
    }

    public function version(): string
    {
        return '1.1.0';
    }

    public function canHandle(AgentContext $context): bool
    {
        return $context->routedAgent === 'pomodoro';
    }

    public function handle(AgentContext $context): AgentResult
    {
        $active = $this->pomodoroManager->getActiveSession($context->from, $context->agent->id);
        $activeText = $active
            ? "Session active: {$active->duration}min, demarree a " . $active->started_at->setTimezone(AppSetting::timezone())->format('H:i') . ($active->paused_at ? ' (EN PAUSE)' : '')
            : "(aucune session active)";

        $now = now(AppSetting::timezone())->format('Y-m-d H:i (l)');

        $response = $this->claude->chat(
            "Date et heure actuelles (heure de Paris): {$now}\nMessage: \"{$context->body}\"\n\nEtat actuel:\n{$activeText}",
            'claude-haiku-4-5-20251001',
            $this->buildPrompt()
        );

        $parsed = $this->parseJson($response);

        if (!$parsed || empty($parsed['action'])) {
            $reply = "Je n'ai pas compris. Essaie :\n"
                . "\"Start 25\" — Lancer un pomodoro de 25min\n"
                . "\"Pause\" — Mettre en pause / Reprendre\n"
                . "\"Stop\" — Abandonner la session\n"
                . "\"End 4\" — Terminer avec une note de focus (1-5)\n"
                . "\"Stats\" — Voir tes statistiques\n"
                . "\"Help\" — Toutes les commandes disponibles";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'pomodoro_parse_failed']);
        }

        $action = $parsed['action'];

        switch ($action) {
            case 'start':
                return $this->handleStart($context, $parsed);
            case 'pause':
                return $this->handlePause($context);
            case 'stop':
                return $this->handleStop($context);
            case 'end':
                return $this->handleEnd($context, $parsed);
            case 'stats':
                return $this->handleStats($context);
            case 'status':
                return $this->handleStatus($context);
            case 'history':
                return $this->handleHistory($context);
            case 'goal':
                return $this->handleGoal($context, $parsed);
            case 'help':
                return $this->handleHelp($context);
            default:
                $reply = "Action non reconnue. Dis \"help\" pour voir toutes les commandes.";
                $this->sendText($context->from, $reply);
                return AgentResult::reply($reply, ['action' => 'pomodoro_unknown_action']);
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

5. VOIR les statistiques globales:
{"action": "stats"}

6. VOIR le statut de la session en cours:
{"action": "status"}

7. VOIR l'historique des dernieres sessions:
{"action": "history"}

8. DEFINIR ou VOIR l'objectif journalier:
{"action": "goal", "value": 4}
Pour voir l'objectif sans le modifier: {"action": "goal"}

9. AIDE - voir toutes les commandes:
{"action": "help"}

REGLES:
- 'duration' = duree en minutes (integer, par defaut 25). Min: 1, Max: 120. Valeurs courantes: 15, 25, 30, 45, 50, 60, 90
- 'rating' = note de qualite de focus de 1 a 5 (integer). 1=tres distrait, 3=correct, 5=ultra concentre
- 'value' = objectif journalier en nombre de sessions (integer, entre 1 et 20)
- Si l'utilisateur dit juste "start", "pomodoro" ou "focus" sans duree → duration = 25
- Si l'utilisateur dit "start 45" ou "45 minutes de focus" → duration = 45
- Si le message est ambigu entre stop et end, prefere "end" si une note est donnee, "stop" sinon
- Si l'utilisateur dit "reprendre" et qu'il y a une session en pause → action = "pause" (toggle)
- "history", "historique", "dernieres sessions" → action = "history"
- "objectif", "goal", "set goal" → action = "goal"
- "aide", "help", "commandes", "comment ca marche" → action = "help"

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
- "Stats" ou "Mes stats pomodoro" → {"action": "stats"}
- "Session en cours?" ou "Timer?" ou "Combien de temps?" → {"action": "status"}
- "Historique" ou "Mes dernieres sessions" → {"action": "history"}
- "Objectif 4 sessions" ou "Set goal 4" → {"action": "goal", "value": 4}
- "Mon objectif" ou "Goal?" → {"action": "goal"}
- "Aide" ou "Help" ou "Commandes" → {"action": "help"}

Reponds UNIQUEMENT avec le JSON.
PROMPT;
    }

    private function handleStart(AgentContext $context, array $parsed): AgentResult
    {
        $duration = $parsed['duration'] ?? 25;
        $duration = max(1, min(120, (int) $duration));

        // Warn if replacing an active session
        $existing = $this->pomodoroManager->getActiveSession($context->from, $context->agent->id);
        $warningPrefix = '';
        if ($existing) {
            $elapsed = $existing->started_at->diffInMinutes(now());
            $warningPrefix = "Session precedente ({$existing->duration}min, {$elapsed}min ecoulees) abandonnee.\n\n";
        }

        $session = $this->pomodoroManager->startSession($context->from, $context->agent->id, $duration);

        $tz = AppSetting::timezone();
        $startTime = $session->started_at->setTimezone($tz)->format('H:i');
        $endTime = $session->started_at->copy()->addMinutes($duration)->setTimezone($tz)->format('H:i');

        // Check daily goal progress
        $goalSuffix = $this->buildGoalProgressSuffix($context, $duration);

        $reply = $warningPrefix
            . "Pomodoro lance ! {$duration} minutes de focus.\n"
            . "Debut : {$startTime}\n"
            . "Fin prevue : {$endTime}\n"
            . $goalSuffix
            . "\nDis \"pause\" pour mettre en pause, \"stop\" pour abandonner, ou \"end [note 1-5]\" quand tu as fini.";

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
            $reply = "Session mise en pause a {$pausedSince}.\nDis \"pause\" ou \"reprends\" pour continuer, \"stop\" pour abandonner.";
            $logMessage = 'Pomodoro paused';
        } else {
            $elapsed = $session->started_at->diffInMinutes(now());
            $remaining = max(0, $session->duration - $elapsed);
            $reply = "Session reprise ! Encore environ {$remaining}min de focus.\nDis \"pause\" pour mettre en pause ou \"end [note]\" quand tu as fini.";
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
            $reply = "Aucune session active a abandonner.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'pomodoro_stop_no_session']);
        }

        $elapsed = $session->started_at->diffInMinutes($session->ended_at);
        $reply = "Session abandonnee apres {$elapsed}min.\n"
            . "Prochaine fois tu iras jusqu'au bout ! Dis \"start\" pour relancer.";

        $this->sendText($context->from, $reply);
        $this->log($context, 'Pomodoro stopped', ['session_id' => $session->id, 'elapsed' => $elapsed]);

        return AgentResult::reply($reply, ['action' => 'pomodoro_stop', 'session_id' => $session->id]);
    }

    private function handleEnd(AgentContext $context, array $parsed): AgentResult
    {
        $rating = isset($parsed['rating']) ? max(1, min(5, (int) $parsed['rating'])) : null;

        $session = $this->pomodoroManager->endSession($context->from, $context->agent->id, $rating);

        if (!$session) {
            $reply = "Aucune session active a terminer.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'pomodoro_end_no_session']);
        }

        $elapsed = $session->started_at->diffInMinutes($session->ended_at);
        $stars = $rating
            ? str_repeat('*', $rating) . str_repeat('.', 5 - $rating) . " ({$rating}/5)"
            : 'non note';

        $reply = "Pomodoro termine !\n"
            . "Duree : {$elapsed}min\n"
            . "Focus : {$stars}";

        // Streak info
        $stats = $this->pomodoroManager->getPomodoroStats($context->from, $context->agent->id);
        if ($stats['streak_days'] > 1) {
            $reply .= "\nStreak : {$stats['streak_days']} jours d'affilee !";
        }

        // Daily goal progress
        $todayCount = $this->getTodayCompletedCount($context);
        $dailyGoal = $this->getDailyGoal($context);
        if ($dailyGoal > 0) {
            if ($todayCount >= $dailyGoal) {
                $reply .= "\nObjectif du jour atteint : {$todayCount}/{$dailyGoal} sessions !";
            } else {
                $remaining = $dailyGoal - $todayCount;
                $reply .= "\nObjectif : {$todayCount}/{$dailyGoal} sessions aujourd'hui ({$remaining} restante(s))";
            }
        }

        $this->sendText($context->from, $reply);
        $this->log($context, 'Pomodoro completed', [
            'session_id' => $session->id,
            'elapsed' => $elapsed,
            'rating' => $rating,
        ]);

        return AgentResult::reply($reply, ['action' => 'pomodoro_end', 'session_id' => $session->id]);
    }

    private function handleStats(AgentContext $context): AgentResult
    {
        $stats = $this->pomodoroManager->getPomodoroStats($context->from, $context->agent->id);

        if ($stats['total_sessions'] === 0) {
            $reply = "Tu n'as pas encore de sessions Pomodoro.\nDis \"start 25\" pour commencer !";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'pomodoro_stats']);
        }

        $hours = floor($stats['total_duration_minutes'] / 60);
        $mins = $stats['total_duration_minutes'] % 60;
        $durationText = $hours > 0 ? "{$hours}h{$mins}min" : "{$mins}min";
        $focusText = $stats['avg_focus_quality']
            ? number_format($stats['avg_focus_quality'], 1) . '/5'
            : 'pas encore de note';

        $todayCount = $this->getTodayCompletedCount($context);
        $dailyGoal = $this->getDailyGoal($context);
        $goalText = $dailyGoal > 0 ? " / objectif: {$dailyGoal}" : '';

        $reply = "Statistiques Pomodoro\n\n"
            . "Aujourd'hui : {$todayCount} session(s){$goalText}\n"
            . "Cette semaine : {$stats['sessions_this_week']} sessions\n"
            . "Total : {$stats['total_sessions']} sessions\n"
            . "Duree totale : {$durationText}\n"
            . "Focus moyen : {$focusText}\n"
            . "Streak : {$stats['streak_days']} jour(s)";

        $this->sendText($context->from, $reply);
        $this->log($context, 'Pomodoro stats viewed', $stats);

        return AgentResult::reply($reply, ['action' => 'pomodoro_stats']);
    }

    private function handleStatus(AgentContext $context): AgentResult
    {
        $session = $this->pomodoroManager->getActiveSession($context->from, $context->agent->id);

        if (!$session) {
            $reply = "Aucune session en cours. Dis \"start 25\" pour en lancer une !";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'pomodoro_status_none']);
        }

        $tz = AppSetting::timezone();
        $status = $session->paused_at ? 'EN PAUSE' : 'EN COURS';

        // Calculate actual elapsed time (excluding paused duration)
        if ($session->paused_at) {
            $elapsed = $session->started_at->diffInMinutes($session->paused_at);
        } else {
            $elapsed = $session->started_at->diffInMinutes(now());
        }

        $remaining = max(0, $session->duration - $elapsed);
        $startTime = $session->started_at->setTimezone($tz)->format('H:i');
        $endTime = $session->started_at->copy()->addMinutes($session->duration)->setTimezone($tz)->format('H:i');

        $reply = "Session {$status}\n"
            . "Duree : {$session->duration}min\n"
            . "Ecoulees : {$elapsed}min\n"
            . "Restantes : {$remaining}min\n"
            . "Debut : {$startTime} | Fin prevue : {$endTime}";

        if ($session->paused_at) {
            $reply .= "\nDis \"pause\" ou \"reprends\" pour continuer.";
        } else {
            $reply .= "\nDis \"end [note 1-5]\" pour terminer ou \"pause\" pour mettre en pause.";
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
            $statusIcon = $s->is_completed ? 'OK' : 'X';
            $ratingText = $s->focus_quality ? " [{$s->focus_quality}/5]" : '';
            $lines[] = "{$statusIcon} {$date} — {$elapsed}min/{$s->duration}min{$ratingText}";
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

        // View current goal
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
                . "Dis \"objectif [nombre]\" pour changer l'objectif.";
        }

        $this->sendText($context->from, $reply);

        return AgentResult::reply($reply, ['action' => 'pomodoro_goal_view']);
    }

    private function handleHelp(AgentContext $context): AgentResult
    {
        $reply = "Commandes Pomodoro :\n\n"
            . "LANCER\n"
            . "  start [min] — Ex: \"start 25\" ou \"focus 45\"\n\n"
            . "EN SESSION\n"
            . "  pause — Pause / Reprendre\n"
            . "  stop — Abandonner la session\n"
            . "  end [1-5] — Terminer avec note de focus\n"
            . "  status — Temps restant / etat\n\n"
            . "HISTORIQUE\n"
            . "  stats — Statistiques globales\n"
            . "  history — 7 dernieres sessions\n\n"
            . "OBJECTIF\n"
            . "  goal — Voir l'objectif du jour\n"
            . "  goal [n] — Definir un objectif (ex: goal 4)";

        $this->sendText($context->from, $reply);

        return AgentResult::reply($reply, ['action' => 'pomodoro_help']);
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

    private function buildGoalProgressSuffix(AgentContext $context, int $duration): string
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
