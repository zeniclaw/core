<?php

namespace App\Services\Agents;

use App\Models\MeetingSession;
use App\Services\AgentContext;
use App\Services\MeetingAnalyzer;
use Illuminate\Support\Facades\Log;

class SmartMeetingAgent extends BaseAgent
{
    /** Maximum messages captured per meeting to avoid DB bloat */
    private const MAX_MESSAGES = 500;

    public function name(): string
    {
        return 'smart_meeting';
    }

    public function description(): string
    {
        return 'Agent de reunion intelligent. Capture automatiquement les messages pendant une reunion, genere une synthese structuree avec decisions, actions a faire, risques, participants et prochaines etapes. Permet aussi d\'ajouter des notes manuelles importantes, obtenir un recap partiel en cours de reunion, lister les reunions passees, rechercher dans l\'historique, afficher des statistiques, verifier le statut en cours, annuler une reunion, et cree automatiquement des todos et rappels a partir des action items.';
    }

    public function keywords(): array
    {
        return [
            'reunion', 'réunion', 'meeting', 'meet',
            'reunion start', 'reunion end', 'start meeting', 'end meeting',
            'demarrer reunion', 'terminer reunion', 'fin reunion', 'finir reunion',
            'synthese reunion', 'synthèse réunion', 'meeting summary',
            'compte rendu', 'compte-rendu', 'CR reunion', 'minutes',
            'notes de reunion', 'meeting notes',
            'action items', 'actions a faire',
            'decisions reunion', 'meeting decisions',
            'prochaines etapes', 'next steps',
            'reunion status', 'statut reunion', 'en cours reunion',
            'reunion list', 'liste reunions', 'historique reunion', 'mes reunions',
            'reunion cancel', 'annuler reunion', 'supprimer reunion',
            'reunion stats', 'stats reunion', 'statistiques reunion',
            'reunion search', 'chercher reunion', 'recherche reunion',
            'reunion note', 'note reunion', 'ajouter note reunion',
            'reunion recap', 'recap reunion', 'bilan reunion',
        ];
    }

    public function version(): string
    {
        return '1.3.0';
    }

    public function canHandle(AgentContext $context): bool
    {
        return $context->routedAgent === 'smart_meeting';
    }

    public function handle(AgentContext $context): AgentResult
    {
        $body = mb_strtolower(trim($context->body ?? ''));

        if ($this->isStartCommand($body)) {
            return $this->startMeeting($context);
        }

        if ($this->isEndCommand($body)) {
            return $this->endMeeting($context);
        }

        if ($this->isCancelCommand($body)) {
            return $this->cancelMeeting($context);
        }

        if ($this->isStatusCommand($body)) {
            return $this->showStatus($context);
        }

        if ($this->isStatsCommand($body)) {
            return $this->showStats($context);
        }

        if ($this->isSearchCommand($body)) {
            return $this->searchMeetings($context);
        }

        if ($this->isNoteCommand($body)) {
            return $this->addNote($context);
        }

        if ($this->isRecapCommand($body)) {
            return $this->showRecap($context);
        }

        if ($this->isListCommand($body)) {
            return $this->listMeetings($context, $body);
        }

        if ($this->isSummaryCommand($body)) {
            return $this->showSummary($context);
        }

        // If there's an active meeting, capture the message
        $active = MeetingSession::getActive($context->from);
        if ($active) {
            return $this->captureMessage($context, $active);
        }

        $reply = "*Reunion Agent* — Commandes disponibles:\n\n"
            . "- *reunion start [nom]* — Demarrer une reunion\n"
            . "- *reunion end* — Terminer et obtenir la synthese\n"
            . "- *reunion note [texte]* — Ajouter une note importante\n"
            . "- *reunion recap* — Bilan partiel en cours de reunion\n"
            . "- *reunion cancel* — Annuler sans generer de synthese\n"
            . "- *reunion status* — Statut de la reunion en cours\n"
            . "- *reunion list* — Historique des 5 dernieres reunions\n"
            . "- *reunion search [terme]* — Rechercher dans les reunions\n"
            . "- *reunion stats* — Statistiques de tes reunions\n"
            . "- *synthese reunion [nom]* — Revoir la synthese d'une reunion";
        $this->sendText($context->from, $reply);
        return AgentResult::reply($reply, ['action' => 'meeting_help']);
    }

    // ── Command detection ─────────────────────────────────────────────────

    private function isStartCommand(string $body): bool
    {
        return (bool) preg_match('/\b(r[ée]union|meeting)\s+start\b/iu', $body)
            || (bool) preg_match('/\bdemarrer\s+r[ée]union\b/iu', $body);
    }

    private function isEndCommand(string $body): bool
    {
        return (bool) preg_match('/\b(r[ée]union|meeting)\s+end\b/iu', $body)
            || (bool) preg_match('/\b(terminer|finir|fin)\s+r[ée]union\b/iu', $body);
    }

    private function isCancelCommand(string $body): bool
    {
        return (bool) preg_match('/\b(r[ée]union|meeting)\s+cancel\b/iu', $body)
            || (bool) preg_match('/\b(annuler|supprimer)\s+r[ée]union\b/iu', $body);
    }

    private function isStatusCommand(string $body): bool
    {
        return (bool) preg_match('/\b(r[ée]union|meeting)\s+status\b/iu', $body)
            || (bool) preg_match('/\bstatut\s+(r[ée]union|meeting)\b/iu', $body);
    }

    private function isStatsCommand(string $body): bool
    {
        return (bool) preg_match('/\b(r[ée]union|meeting)s?\s+stats?\b/iu', $body)
            || (bool) preg_match('/\b(stats?|statistiques?)\s+(r[ée]union|meeting)s?\b/iu', $body);
    }

    private function isSearchCommand(string $body): bool
    {
        return (bool) preg_match('/\b(r[ée]union|meeting)\s+search\b/iu', $body)
            || (bool) preg_match('/\b(chercher|recherche)\s+r[ée]union\b/iu', $body);
    }

    private function isListCommand(string $body): bool
    {
        return (bool) preg_match('/\b(r[ée]union|meeting)s?\s+(list|liste)\b/iu', $body)
            || (bool) preg_match('/\b(liste|historique|mes)\s+r[ée]unions?\b/iu', $body);
    }

    private function isSummaryCommand(string $body): bool
    {
        return (bool) preg_match('/\bsynth[eè]se\s+r[ée]union\b/iu', $body)
            || (bool) preg_match('/\bmeeting\s+summary\b/iu', $body)
            || (bool) preg_match('/\bcompte[- ]?rendu\s+r[ée]union\b/iu', $body);
    }

    private function isNoteCommand(string $body): bool
    {
        return (bool) preg_match('/\br[ée]union\s+note\b/iu', $body)
            || (bool) preg_match('/\bnote\s+r[ée]union\b/iu', $body);
    }

    private function isRecapCommand(string $body): bool
    {
        return (bool) preg_match('/\br[ée]union\s+recap\b/iu', $body)
            || (bool) preg_match('/\brecap\s+r[ée]union\b/iu', $body)
            || (bool) preg_match('/\bbilan\s+r[ée]union\b/iu', $body);
    }

    // ── Actions ───────────────────────────────────────────────────────────

    private function startMeeting(AgentContext $context): AgentResult
    {
        $active = MeetingSession::getActive($context->from);
        if ($active) {
            $elapsed = $active->started_at->diffForHumans(now(), true);
            $msgCount = count(array_filter($active->messages_captured ?? [], fn($m) => ($m['type'] ?? 'message') === 'message'));
            $reply = "Une reunion est deja en cours: *{$active->group_name}*\n"
                . "Demarree il y a {$elapsed} — {$msgCount} messages captures.\n"
                . "Termine-la avec *reunion end* ou annule-la avec *reunion cancel*.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'meeting_already_active']);
        }

        // Extract group name from ORIGINAL body to preserve casing
        $originalBody = $context->body ?? '';
        $groupName = 'Reunion';
        if (preg_match('/(?:r[ée]union|meeting)\s+start\s+(.+)/iu', $originalBody, $matches)) {
            $groupName = trim($matches[1]);
        } elseif (preg_match('/demarrer\s+r[ée]union\s+(.+)/iu', $originalBody, $matches)) {
            $groupName = trim($matches[1]);
        }

        $meeting = MeetingSession::create([
            'user_phone' => $context->from,
            'agent_id' => $context->agent->id,
            'group_name' => $groupName,
            'status' => 'active',
            'started_at' => now(),
            'messages_captured' => [],
        ]);

        $meeting->activate();

        $this->log($context, "Meeting started: {$groupName}", ['meeting_id' => $meeting->id]);

        $reply = "Reunion *{$groupName}* demarree!\n\n"
            . "Tous tes messages seront captures automatiquement.\n\n"
            . "Commandes utiles:\n"
            . "- *reunion note [texte]* — Ajouter une note importante\n"
            . "- *reunion recap* — Bilan partiel sans cloturer\n"
            . "- *reunion status* — Voir l'avancement\n"
            . "- *reunion end* — Terminer et obtenir la synthese\n"
            . "- *reunion cancel* — Annuler sans synthese";
        $this->sendText($context->from, $reply);
        return AgentResult::reply($reply, ['action' => 'meeting_started', 'meeting_id' => $meeting->id]);
    }

    private function endMeeting(AgentContext $context): AgentResult
    {
        $active = MeetingSession::getActive($context->from);
        if (!$active) {
            $reply = "Aucune reunion en cours. Demarre-en une avec *reunion start [nom]*.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'no_active_meeting']);
        }

        $duration = $active->started_at->diff(now());
        $durationStr = $this->formatDuration($duration);
        $messages = $active->messages_captured ?? [];

        $active->update([
            'status' => 'completed',
            'ended_at' => now(),
        ]);
        $active->deactivate();

        if (empty($messages)) {
            $reply = "Reunion *{$active->group_name}* terminee ({$durationStr}).\nAucun message capture — pas de synthese a generer.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'meeting_ended_empty']);
        }

        $noteCount = count(array_filter($messages, fn($m) => ($m['type'] ?? '') === 'note'));
        $regularCount = count($messages) - $noteCount;

        $noteInfo = $noteCount > 0 ? ", {$noteCount} note(s)" : '';
        $this->sendText(
            $context->from,
            "Reunion *{$active->group_name}* terminee ({$durationStr}, {$regularCount} messages{$noteInfo}). Analyse en cours..."
        );

        try {
            $analyzer = new MeetingAnalyzer();
            $analysis = $analyzer->analyze($messages, $active->group_name);
        } catch (\Throwable $e) {
            Log::error('SmartMeetingAgent: MeetingAnalyzer failed: ' . $e->getMessage());
            $analysis = [
                'participants' => [],
                'decisions' => [],
                'action_items' => [],
                'risks' => [],
                'next_steps' => [],
                'summary' => "Analyse impossible. Reessaie avec *synthese reunion {$active->group_name}*.",
            ];
        }

        $active->update(['summary' => json_encode($analysis, JSON_UNESCAPED_UNICODE)]);

        $reply = $this->formatAnalysis($active->group_name, $analysis, $regularCount, $durationStr);
        $this->sendText($context->from, $reply);

        $this->createTasksAndReminders($context, $analysis, $active->group_name);

        $this->log($context, "Meeting ended: {$active->group_name}", [
            'meeting_id' => $active->id,
            'messages_count' => count($messages),
            'duration' => $durationStr,
        ]);

        return AgentResult::reply($reply, [
            'action' => 'meeting_ended',
            'meeting_id' => $active->id,
            'analysis' => $analysis,
        ]);
    }

    private function cancelMeeting(AgentContext $context): AgentResult
    {
        $active = MeetingSession::getActive($context->from);
        if (!$active) {
            $reply = "Aucune reunion en cours a annuler.\nDemarre une reunion avec *reunion start [nom]*.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'no_active_meeting']);
        }

        $groupName = $active->group_name;
        $msgCount = count($active->messages_captured ?? []);
        $elapsed = $this->formatDuration($active->started_at->diff(now()));

        $active->update([
            'status' => 'cancelled',
            'ended_at' => now(),
        ]);
        $active->deactivate();

        $this->log($context, "Meeting cancelled: {$groupName}", [
            'meeting_id' => $active->id,
            'messages_captured' => $msgCount,
        ]);

        $reply = "Reunion *{$groupName}* annulee ({$elapsed}, {$msgCount} messages supprimes).\n"
            . "Aucune synthese generee.\n"
            . "Lance une nouvelle reunion avec *reunion start [nom]*.";
        $this->sendText($context->from, $reply);
        return AgentResult::reply($reply, [
            'action' => 'meeting_cancelled',
            'meeting_id' => $active->id,
        ]);
    }

    private function showStatus(AgentContext $context): AgentResult
    {
        $active = MeetingSession::getActive($context->from);
        if (!$active) {
            $reply = "Aucune reunion en cours.\nDemarre-en une avec *reunion start [nom]*.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'no_active_meeting']);
        }

        $messages = $active->messages_captured ?? [];
        $elapsed = $this->formatDuration($active->started_at->diff(now()));

        $regularMessages = array_values(array_filter($messages, fn($m) => ($m['type'] ?? 'message') === 'message'));
        $notes = array_values(array_filter($messages, fn($m) => ($m['type'] ?? '') === 'note'));

        $participants = array_unique(array_column($regularMessages, 'sender'));
        sort($participants);

        $reply = "*Reunion en cours: {$active->group_name}*\n\n"
            . "Duree: {$elapsed}\n"
            . "Messages captures: " . count($regularMessages) . "\n";

        if (count($notes) > 0) {
            $reply .= "Notes ajoutees: " . count($notes) . "\n";
        }

        if (!empty($participants)) {
            $reply .= "Participants: " . implode(', ', $participants) . "\n";
        }

        // Show last 3 regular messages for quick context
        $lastMessages = array_slice($regularMessages, -3);
        if (!empty($lastMessages)) {
            $reply .= "\n*Derniers messages:*\n";
            foreach ($lastMessages as $msg) {
                $sender = $msg['sender'] ?? 'Inconnu';
                $content = mb_substr($msg['content'] ?? '', 0, 60);
                $suffix = mb_strlen($msg['content'] ?? '') > 60 ? '...' : '';
                $reply .= "  {$sender}: {$content}{$suffix}\n";
            }
        }

        // Show last 2 notes if any
        if (!empty($notes)) {
            $lastNotes = array_slice($notes, -2);
            $reply .= "\n*Dernieres notes:*\n";
            foreach ($lastNotes as $note) {
                $content = mb_substr($note['content'] ?? '', 0, 80);
                $suffix = mb_strlen($note['content'] ?? '') > 80 ? '...' : '';
                $reply .= "  [NOTE] {$content}{$suffix}\n";
            }
        }

        $reply .= "\nEnvoie *reunion end* pour terminer et obtenir la synthese.";

        $this->sendText($context->from, $reply);
        return AgentResult::reply($reply, [
            'action' => 'meeting_status',
            'meeting_id' => $active->id,
            'messages_count' => count($regularMessages),
            'participants' => $participants,
        ]);
    }

    private function listMeetings(AgentContext $context, string $body = ''): AgentResult
    {
        // Support "reunion list 10" to show more results
        $limit = 5;
        if (preg_match('/\b(\d+)\s*$/', $body, $m)) {
            $limit = min((int) $m[1], 20);
        }

        $meetings = MeetingSession::forUser($context->from)
            ->completed()
            ->latest('ended_at')
            ->limit($limit)
            ->get();

        if ($meetings->isEmpty()) {
            $reply = "Aucune reunion terminee trouvee.\nDemarre une nouvelle reunion avec *reunion start [nom]*.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'no_meetings_found']);
        }

        $lines = ["*Historique des reunions:*\n"];
        foreach ($meetings as $i => $m) {
            $date = $m->ended_at ? $m->ended_at->format('d/m/y H:i') : 'inconnu';
            $msgCount = count(array_filter($m->messages_captured ?? [], fn($msg) => ($msg['type'] ?? 'message') === 'message'));
            $duration = ($m->started_at && $m->ended_at)
                ? $this->formatDuration($m->started_at->diff($m->ended_at))
                : '?';
            $hasSummary = !empty($m->summary) ? '' : ' _(pas de synthese)_';
            $lines[] = ($i + 1) . ". *{$m->group_name}*{$hasSummary}";
            $lines[] = "   {$date} — {$duration} — {$msgCount} msg";
        }

        $total = MeetingSession::forUser($context->from)->completed()->count();
        if ($total > $limit) {
            $lines[] = "\n_({$total} reunions au total — utilise *reunion list {$total}* pour tout voir)_";
        }

        $lines[] = "\nPour revoir une synthese: *synthese reunion [nom]*";

        $reply = implode("\n", $lines);
        $this->sendText($context->from, $reply);
        return AgentResult::reply($reply, ['action' => 'meeting_list', 'count' => $meetings->count()]);
    }

    private function showSummary(AgentContext $context): AgentResult
    {
        $body = $context->body ?? '';
        $meetingName = null;

        if (preg_match('/synth[eè]se\s+r[ée]union\s+(.+)/iu', $body, $matches)) {
            $meetingName = trim($matches[1]);
        } elseif (preg_match('/meeting\s+summary\s+(.+)/iu', $body, $matches)) {
            $meetingName = trim($matches[1]);
        } elseif (preg_match('/compte[- ]?rendu\s+r[ée]union\s+(.+)/iu', $body, $matches)) {
            $meetingName = trim($matches[1]);
        }

        $query = MeetingSession::forUser($context->from)->completed();

        if ($meetingName) {
            $query->where('group_name', 'like', "%{$meetingName}%");
        }

        $meeting = $query->latest('ended_at')->first();

        if (!$meeting) {
            $reply = $meetingName
                ? "Aucune reunion trouvee avec le nom \"{$meetingName}\".\nUtilise *reunion list* pour voir tes reunions."
                : "Aucune reunion terminee trouvee.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'meeting_not_found']);
        }

        $summary = $meeting->summary ? json_decode($meeting->summary, true) : null;

        if (!$summary) {
            $messages = $meeting->messages_captured ?? [];
            if (empty($messages)) {
                $reply = "La reunion *{$meeting->group_name}* n'a pas de messages captures — impossible de generer une synthese.";
                $this->sendText($context->from, $reply);
                return AgentResult::reply($reply, ['action' => 'meeting_no_messages']);
            }

            $this->sendText($context->from, "Regeneration de la synthese pour *{$meeting->group_name}*...");
            try {
                $analyzer = new MeetingAnalyzer();
                $summary = $analyzer->analyze($messages, $meeting->group_name);
                $meeting->update(['summary' => json_encode($summary, JSON_UNESCAPED_UNICODE)]);
            } catch (\Throwable $e) {
                Log::error('SmartMeetingAgent: showSummary re-analyze failed: ' . $e->getMessage());
                $reply = "Erreur lors de la regeneration de la synthese. Reessaie.";
                $this->sendText($context->from, $reply);
                return AgentResult::reply($reply, ['action' => 'summary_error']);
            }
        }

        $messagesCount = count(array_filter($meeting->messages_captured ?? [], fn($m) => ($m['type'] ?? 'message') === 'message'));
        $duration = ($meeting->started_at && $meeting->ended_at)
            ? $this->formatDuration($meeting->started_at->diff($meeting->ended_at))
            : null;

        $reply = $this->formatAnalysis($meeting->group_name, $summary, $messagesCount, $duration);
        $this->sendText($context->from, $reply);
        return AgentResult::reply($reply, ['action' => 'meeting_summary', 'meeting_id' => $meeting->id]);
    }

    private function showStats(AgentContext $context): AgentResult
    {
        $completed = MeetingSession::forUser($context->from)->completed()->get();

        if ($completed->isEmpty()) {
            $reply = "Aucune reunion terminee pour afficher des statistiques.\nDemarre une reunion avec *reunion start [nom]*.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'stats_no_data']);
        }

        $totalMeetings = $completed->count();
        $totalMessages = $completed->sum(fn($m) => count(array_filter($m->messages_captured ?? [], fn($msg) => ($msg['type'] ?? 'message') === 'message')));

        // Compute average duration in seconds
        $durationsSeconds = $completed
            ->filter(fn($m) => $m->started_at && $m->ended_at)
            ->map(fn($m) => $m->started_at->diffInSeconds($m->ended_at));

        $avgDuration = $durationsSeconds->isNotEmpty()
            ? $this->formatDuration($this->secondsToInterval((int) $durationsSeconds->avg()))
            : 'N/A';

        $longestMeeting = $completed
            ->filter(fn($m) => $m->started_at && $m->ended_at)
            ->sortByDesc(fn($m) => $m->started_at->diffInSeconds($m->ended_at))
            ->first();

        // Total action items across all summaries
        $totalActionItems = $completed->sum(function ($m) {
            if (!$m->summary) return 0;
            $s = json_decode($m->summary, true);
            return count($s['action_items'] ?? []);
        });

        // Average messages per meeting
        $avgMessages = $totalMeetings > 0 ? (int) round($totalMessages / $totalMeetings) : 0;

        // Meetings this week
        $thisWeekCount = $completed
            ->filter(fn($m) => $m->ended_at && $m->ended_at->isCurrentWeek())
            ->count();

        // Collect all participants
        $allParticipants = [];
        foreach ($completed as $m) {
            foreach ($m->messages_captured ?? [] as $msg) {
                if (($msg['type'] ?? 'message') !== 'message') {
                    continue;
                }
                $sender = $msg['sender'] ?? null;
                if ($sender) {
                    $allParticipants[$sender] = ($allParticipants[$sender] ?? 0) + 1;
                }
            }
        }
        arsort($allParticipants);
        $topParticipants = array_slice(array_keys($allParticipants), 0, 3);

        $lastMeeting = $completed->sortByDesc('ended_at')->first();

        $reply = "*Statistiques de tes reunions*\n\n"
            . "Reunions terminees: *{$totalMeetings}*\n"
            . "Cette semaine: *{$thisWeekCount}*\n"
            . "Messages captures (total): *{$totalMessages}*\n"
            . "Moyenne messages/reunion: *{$avgMessages}*\n"
            . "Action items generes: *{$totalActionItems}*\n"
            . "Duree moyenne: *{$avgDuration}*\n";

        if ($longestMeeting) {
            $longestDur = $this->formatDuration($longestMeeting->started_at->diff($longestMeeting->ended_at));
            $reply .= "Reunion la plus longue: *{$longestMeeting->group_name}* ({$longestDur})\n";
        }

        if (!empty($topParticipants)) {
            $reply .= "Top participants: " . implode(', ', $topParticipants) . "\n";
        }

        if ($lastMeeting) {
            $reply .= "Derniere reunion: *{$lastMeeting->group_name}* le " . $lastMeeting->ended_at->format('d/m/y');
        }

        $this->sendText($context->from, $reply);
        return AgentResult::reply($reply, [
            'action' => 'meeting_stats',
            'total_meetings' => $totalMeetings,
            'total_messages' => $totalMessages,
            'total_action_items' => $totalActionItems,
        ]);
    }

    private function searchMeetings(AgentContext $context): AgentResult
    {
        $body = $context->body ?? '';
        $term = null;

        if (preg_match('/(?:r[ée]union|meeting)\s+search\s+(.+)/iu', $body, $matches)) {
            $term = trim($matches[1]);
        } elseif (preg_match('/(?:chercher|recherche)\s+r[ée]union\s+(.+)/iu', $body, $matches)) {
            $term = trim($matches[1]);
        }

        if (empty($term)) {
            $reply = "Utilise: *reunion search [terme]*\nEx: *reunion search sprint* pour trouver toutes les reunions sprint.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'search_missing_term']);
        }

        $lowerTerm = strtolower($term);
        $meetings = MeetingSession::forUser($context->from)
            ->where(function ($q) use ($lowerTerm) {
                $q->whereRaw('LOWER(group_name) LIKE ?', ["%{$lowerTerm}%"])
                  ->orWhereRaw('LOWER(summary) LIKE ?', ["%{$lowerTerm}%"]);
            })
            ->latest('ended_at')
            ->limit(10)
            ->get();

        if ($meetings->isEmpty()) {
            $reply = "Aucune reunion trouvee pour \"{$term}\".\nUtilise *reunion list* pour voir toutes tes reunions.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'search_no_results', 'term' => $term]);
        }

        $lines = ["*Resultats pour \"{$term}\":*\n"];
        foreach ($meetings as $i => $m) {
            $date = $m->ended_at ? $m->ended_at->format('d/m/y') : ($m->started_at ? $m->started_at->format('d/m/y') : '?');
            $status = $m->status === 'active' ? ' _(en cours)_' : ($m->status === 'cancelled' ? ' _(annulee)_' : '');
            $lines[] = ($i + 1) . ". *{$m->group_name}*{$status} — {$date}";
        }

        $lines[] = "\nPour la synthese: *synthese reunion [nom]*";

        $reply = implode("\n", $lines);
        $this->sendText($context->from, $reply);
        return AgentResult::reply($reply, [
            'action' => 'search_results',
            'term' => $term,
            'count' => $meetings->count(),
        ]);
    }

    private function addNote(AgentContext $context): AgentResult
    {
        $active = MeetingSession::getActive($context->from);
        if (!$active) {
            $reply = "Aucune reunion en cours. Demarre-en une avec *reunion start [nom]* avant d'ajouter une note.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'no_active_meeting']);
        }

        $body = $context->body ?? '';
        $noteText = null;

        if (preg_match('/r[ée]union\s+note\s+(.+)/iu', $body, $matches)) {
            $noteText = trim($matches[1]);
        } elseif (preg_match('/note\s+r[ée]union\s+(.+)/iu', $body, $matches)) {
            $noteText = trim($matches[1]);
        }

        if (empty($noteText)) {
            $reply = "Utilise: *reunion note [texte]*\nEx: *reunion note decision importante: on livre vendredi*";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'note_missing_text']);
        }

        $messages = $active->messages_captured ?? [];
        $messages[] = [
            'sender' => $context->senderName,
            'content' => $noteText,
            'timestamp' => now()->toISOString(),
            'type' => 'note',
        ];
        $active->update(['messages_captured' => $messages]);

        $reply = "Note ajoutee a *{$active->group_name}*:\n_{$noteText}_";
        $this->sendText($context->from, $reply);
        return AgentResult::reply($reply, [
            'action' => 'note_added',
            'meeting_id' => $active->id,
            'note' => $noteText,
        ]);
    }

    private function showRecap(AgentContext $context): AgentResult
    {
        $active = MeetingSession::getActive($context->from);
        if (!$active) {
            $reply = "Aucune reunion en cours.\nDemarre-en une avec *reunion start [nom]*.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'no_active_meeting']);
        }

        $messages = $active->messages_captured ?? [];
        $elapsed = $this->formatDuration($active->started_at->diff(now()));

        if (empty($messages)) {
            $reply = "Aucun message capture pour le moment dans *{$active->group_name}* ({$elapsed}).\nContinue la reunion, les messages seront captures automatiquement.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'recap_empty']);
        }

        $this->sendText($context->from, "Bilan partiel de *{$active->group_name}* ({$elapsed})...");

        try {
            $analyzer = new MeetingAnalyzer();
            $analysis = $analyzer->analyze($messages, $active->group_name);
        } catch (\Throwable $e) {
            Log::error('SmartMeetingAgent: showRecap analyze failed: ' . $e->getMessage());
            $reply = "Impossible de generer le recap pour le moment. Reessaie dans quelques instants.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'recap_error']);
        }

        $regularCount = count(array_filter($messages, fn($m) => ($m['type'] ?? 'message') === 'message'));
        $reply = $this->formatAnalysis($active->group_name . ' (recap partiel)', $analysis, $regularCount, $elapsed);
        $reply .= "\n\n_La reunion est toujours en cours. Envoie *reunion end* pour terminer._";

        $this->sendText($context->from, $reply);
        return AgentResult::reply($reply, [
            'action' => 'meeting_recap',
            'meeting_id' => $active->id,
            'messages_count' => count($messages),
        ]);
    }

    private function captureMessage(AgentContext $context, MeetingSession $meeting): AgentResult
    {
        $currentCount = count($meeting->messages_captured ?? []);

        if ($currentCount >= self::MAX_MESSAGES) {
            $this->sendText(
                $context->from,
                "Limite de " . self::MAX_MESSAGES . " messages atteinte. Termine la reunion avec *reunion end*."
            );
            return AgentResult::silent(['action' => 'message_cap_reached', 'meeting_id' => $meeting->id]);
        }

        $meeting->addMessage($context->senderName, $context->body ?? '');
        return AgentResult::silent(['action' => 'message_captured', 'meeting_id' => $meeting->id]);
    }

    // ── Formatting ────────────────────────────────────────────────────────

    private function formatAnalysis(string $groupName, array $analysis, int $messagesCount, ?string $duration = null): string
    {
        $meta = "({$messagesCount} messages";
        if ($duration) {
            $meta .= ", duree: {$duration}";
        }
        $meta .= ")";

        $lines = ["*Synthese — {$groupName}*", $meta, ''];

        // Participants
        if (!empty($analysis['participants'])) {
            $lines[] = "*Participants:* " . implode(', ', $analysis['participants']);
            $lines[] = '';
        }

        // Summary
        if (!empty($analysis['summary'])) {
            $lines[] = "*Resume:*";
            $lines[] = $analysis['summary'];
            $lines[] = '';
        }

        // Decisions
        if (!empty($analysis['decisions'])) {
            $lines[] = "*Decisions:*";
            foreach ($analysis['decisions'] as $d) {
                $lines[] = "  + {$d}";
            }
            $lines[] = '';
        }

        // Action items
        if (!empty($analysis['action_items'])) {
            $lines[] = "*Actions a faire:*";
            foreach ($analysis['action_items'] as $item) {
                $task = is_array($item) ? ($item['task'] ?? (string) $item) : (string) $item;
                $assignee = is_array($item) ? ($item['assignee'] ?? null) : null;
                $deadline = is_array($item) ? ($item['deadline'] ?? null) : null;
                $line = "  > {$task}";
                if ($assignee) {
                    $line .= " _(-> {$assignee})_";
                }
                if ($deadline) {
                    $line .= " — {$deadline}";
                }
                $lines[] = $line;
            }
            $lines[] = '';
        }

        // Risks
        if (!empty($analysis['risks'])) {
            $lines[] = "*Risques/Blockers:*";
            foreach ($analysis['risks'] as $r) {
                $lines[] = "  ! {$r}";
            }
            $lines[] = '';
        }

        // Next steps
        if (!empty($analysis['next_steps'])) {
            $lines[] = "*Prochaines etapes:*";
            foreach ($analysis['next_steps'] as $s) {
                $lines[] = "  -> {$s}";
            }
            $lines[] = '';
        }

        // Remove trailing empty lines
        while (!empty($lines) && $lines[array_key_last($lines)] === '') {
            array_pop($lines);
        }

        return implode("\n", $lines);
    }

    private function formatDuration(\DateInterval $interval): string
    {
        if ($interval->days >= 1) {
            $extra = $interval->h > 0 ? " {$interval->h}h" : '';
            return $interval->days . 'j' . $extra;
        }
        if ($interval->h > 0) {
            return $interval->h . 'h' . ($interval->i > 0 ? $interval->i . 'min' : '');
        }
        if ($interval->i > 0) {
            return $interval->i . ' min';
        }
        return $interval->s . ' sec';
    }

    private function secondsToInterval(int $seconds): \DateInterval
    {
        $dt1 = new \DateTime('@0');
        $dt2 = new \DateTime("@{$seconds}");
        return $dt1->diff($dt2);
    }

    // ── Post-meeting automation ───────────────────────────────────────────

    private function createTasksAndReminders(AgentContext $context, array $analysis, string $meetingName): void
    {
        $actionItems = $analysis['action_items'] ?? [];
        if (empty($actionItems)) {
            return;
        }

        try {
            $todoAgent = new TodoAgent();
            foreach ($actionItems as $item) {
                $task = is_array($item) ? ($item['task'] ?? '') : (string) $item;
                if (empty($task)) {
                    continue;
                }

                $assignee = is_array($item) ? ($item['assignee'] ?? null) : null;
                $prefix = $assignee ? "[{$assignee}] " : '';

                $todoContext = new AgentContext(
                    agent: $context->agent,
                    session: $context->session,
                    from: $context->from,
                    senderName: $context->senderName,
                    body: "ajoute {$prefix}{$task} #reunion",
                    hasMedia: false,
                    mediaUrl: null,
                    mimetype: null,
                    media: null,
                    routedAgent: 'todo',
                    routedModel: 'claude-haiku-4-5-20251001',
                );
                $todoAgent->handle($todoContext);
            }

            $nextSteps = $analysis['next_steps'] ?? [];
            if (!empty($nextSteps)) {
                $reminderAgent = new ReminderAgent();
                $stepsText = implode(', ', array_slice($nextSteps, 0, 3));
                $reminderContext = new AgentContext(
                    agent: $context->agent,
                    session: $context->session,
                    from: $context->from,
                    senderName: $context->senderName,
                    body: "rappelle-moi demain a 9h: Suivi reunion \"{$meetingName}\" — {$stepsText}",
                    hasMedia: false,
                    mediaUrl: null,
                    mimetype: null,
                    media: null,
                    routedAgent: 'reminder',
                    routedModel: 'claude-haiku-4-5-20251001',
                );
                $reminderAgent->handle($reminderContext);
            }
        } catch (\Throwable $e) {
            Log::warning('SmartMeetingAgent: failed to create tasks/reminders: ' . $e->getMessage());
        }
    }
}
