<?php

namespace App\Services\Agents;

use App\Services\AgentContext;
use App\Services\PomodoroSessionManager;
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

    public function canHandle(AgentContext $context): bool
    {
        return $context->routedAgent === 'pomodoro';
    }

    public function handle(AgentContext $context): AgentResult
    {
        $active = $this->pomodoroManager->getActiveSession($context->from, $context->agent->id);
        $activeText = $active
            ? "Session active: {$active->duration}min, demarree a " . $active->started_at->setTimezone('Europe/Paris')->format('H:i') . ($active->paused_at ? ' (EN PAUSE)' : '')
            : "(aucune session active)";

        $now = now('Europe/Paris')->format('Y-m-d H:i (l)');

        $response = $this->claude->chat(
            "Date et heure actuelles (heure de Paris): {$now}\nMessage: \"{$context->body}\"\n\nEtat actuel:\n{$activeText}",
            'claude-haiku-4-5-20251001',
            $this->buildPrompt()
        );

        $parsed = $this->parseJson($response);

        if (!$parsed || empty($parsed['action'])) {
            $reply = "Je n'ai pas compris. Essaie :\n"
                . "\"Start 25\" — Lancer un pomodoro de 25min\n"
                . "\"Pause\" — Mettre en pause\n"
                . "\"Stop\" — Arreter la session\n"
                . "\"End 4\" — Terminer avec une note de focus (1-5)\n"
                . "\"Stats\" — Voir tes statistiques";
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
            default:
                $reply = "Action non reconnue. Essaie : start, pause, stop, end ou stats.";
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

2. METTRE EN PAUSE / REPRENDRE:
{"action": "pause"}

3. ARRETER (abandonner) une session:
{"action": "stop"}

4. TERMINER une session avec note de focus:
{"action": "end", "rating": 4}

5. VOIR les statistiques:
{"action": "stats"}

6. VOIR le statut de la session en cours:
{"action": "status"}

REGLES:
- 'duration' = duree en minutes (integer, par defaut 25). Valeurs courantes: 15, 25, 30, 45, 50
- 'rating' = note de qualite de focus de 1 a 5 (integer). 1=distrait, 5=ultra concentre
- Si l'utilisateur dit juste "start" ou "pomodoro" sans duree → duration = 25
- Si l'utilisateur dit "start 45" ou "45 minutes" → duration = 45
- Si le message est ambigu entre stop et end, prefere "end" si une note est donnee, "stop" sinon

EXEMPLES:
- "Start 25" → {"action": "start", "duration": 25}
- "Lance un pomodoro" → {"action": "start", "duration": 25}
- "Focus 45 min" → {"action": "start", "duration": 45}
- "Pause" → {"action": "pause"}
- "Stop" ou "Arrete" → {"action": "stop"}
- "Fini, 4/5" ou "End 4" → {"action": "end", "rating": 4}
- "Termine, c'etait bien" → {"action": "end", "rating": 4}
- "Stats" ou "Mes stats pomodoro" → {"action": "stats"}
- "Session en cours?" ou "Timer?" → {"action": "status"}
- "Session de travail de 30 minutes" → {"action": "start", "duration": 30}

Reponds UNIQUEMENT avec le JSON.
PROMPT;
    }

    private function handleStart(AgentContext $context, array $parsed): AgentResult
    {
        $duration = $parsed['duration'] ?? 25;
        $duration = max(1, min(120, (int) $duration));

        $session = $this->pomodoroManager->startSession($context->from, $context->agent->id, $duration);

        $reply = "Pomodoro lance ! {$duration} minutes de focus.\n"
            . "Debut : " . $session->started_at->setTimezone('Europe/Paris')->format('H:i') . "\n"
            . "Fin prevue : " . $session->started_at->copy()->addMinutes($duration)->setTimezone('Europe/Paris')->format('H:i') . "\n\n"
            . "Dis \"pause\" pour mettre en pause, \"stop\" pour arreter, ou \"end [note 1-5]\" quand tu as fini.";

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
            $reply = "Session mise en pause. Dis \"pause\" pour reprendre ou \"stop\" pour arreter.";
        } else {
            $reply = "Session reprise ! Continue ton focus.";
        }

        $this->sendText($context->from, $reply);
        $this->log($context, $session->paused_at ? 'Pomodoro paused' : 'Pomodoro resumed', ['session_id' => $session->id]);

        return AgentResult::reply($reply, ['action' => 'pomodoro_pause', 'session_id' => $session->id]);
    }

    private function handleStop(AgentContext $context): AgentResult
    {
        $session = $this->pomodoroManager->stopSession($context->from, $context->agent->id);

        if (!$session) {
            $reply = "Aucune session active a arreter.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'pomodoro_stop_no_session']);
        }

        $elapsed = $session->started_at->diffInMinutes($session->ended_at);
        $reply = "Session arretee apres {$elapsed} minutes.\n"
            . "Dis \"start\" pour en relancer une !";

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
        $stars = $rating ? str_repeat('*', $rating) . str_repeat('.', 5 - $rating) : 'non note';

        $reply = "Pomodoro termine !\n"
            . "Duree : {$elapsed} minutes\n"
            . "Focus : {$stars}";

        $stats = $this->pomodoroManager->getPomodoroStats($context->from, $context->agent->id);
        if ($stats['streak_days'] > 1) {
            $reply .= "\n\nStreak : {$stats['streak_days']} jours d'affilee !";
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

        $hours = floor($stats['total_duration_minutes'] / 60);
        $mins = $stats['total_duration_minutes'] % 60;
        $durationText = $hours > 0 ? "{$hours}h{$mins}min" : "{$mins}min";
        $focusText = $stats['avg_focus_quality'] ? "{$stats['avg_focus_quality']}/5" : 'pas encore de note';

        $reply = "Statistiques Pomodoro :\n\n"
            . "Cette semaine : {$stats['sessions_this_week']} sessions\n"
            . "Total : {$stats['total_sessions']} sessions\n"
            . "Duree totale : {$durationText}\n"
            . "Focus moyen : {$focusText}\n"
            . "Streak : {$stats['streak_days']} jour(s)";

        if ($stats['total_sessions'] === 0) {
            $reply = "Tu n'as pas encore de sessions Pomodoro.\nDis \"start 25\" pour commencer !";
        }

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

        $elapsed = $session->started_at->diffInMinutes(now());
        $remaining = max(0, $session->duration - $elapsed);
        $status = $session->paused_at ? 'EN PAUSE' : 'EN COURS';

        $reply = "Session {$status}\n"
            . "Duree : {$session->duration}min\n"
            . "Ecoulees : {$elapsed}min\n"
            . "Restantes : {$remaining}min\n"
            . "Debut : " . $session->started_at->setTimezone('Europe/Paris')->format('H:i');

        $this->sendText($context->from, $reply);
        return AgentResult::reply($reply, ['action' => 'pomodoro_status', 'session_id' => $session->id]);
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

        Log::info("PomodoroAgent parse - cleaned: {$clean}");

        return json_decode($clean, true);
    }
}
