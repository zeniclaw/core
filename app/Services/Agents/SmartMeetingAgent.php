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
        return 'Agent de reunion intelligent. Capture automatiquement les messages pendant une reunion, genere une synthese structuree avec decisions, actions a faire, risques, participants et prochaines etapes. Permet aussi de lister les reunions passees, verifier le statut en cours, et cree automatiquement des todos et rappels a partir des action items.';
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
        ];
    }

    public function version(): string
    {
        return '1.1.0';
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

        if ($this->isStatusCommand($body)) {
            return $this->showStatus($context);
        }

        if ($this->isListCommand($body)) {
            return $this->listMeetings($context);
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
            . "- *reunion status* — Statut de la reunion en cours\n"
            . "- *reunion list* — Historique des 5 dernieres reunions\n"
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

    private function isStatusCommand(string $body): bool
    {
        return (bool) preg_match('/\b(r[ée]union|meeting)\s+status\b/iu', $body)
            || (bool) preg_match('/\bstatut\s+r[ée]union\b/iu', $body);
    }

    private function isListCommand(string $body): bool
    {
        return (bool) preg_match('/\b(r[ée]union|meeting)s?\s+(list|liste)\b/iu', $body)
            || (bool) preg_match('/\b(liste|historique|mes)\s+r[ée]unions?\b/iu', $body);
    }

    private function isSummaryCommand(string $body): bool
    {
        return (bool) preg_match('/\bsynth[eè]se\s+r[ée]union\b/iu', $body);
    }

    // ── Actions ───────────────────────────────────────────────────────────

    private function startMeeting(AgentContext $context): AgentResult
    {
        $active = MeetingSession::getActive($context->from);
        if ($active) {
            $elapsed = $active->started_at->diffForHumans(now(), true);
            $msgCount = count($active->messages_captured ?? []);
            $reply = "Une reunion est deja en cours: *{$active->group_name}*\n"
                . "Demarree il y a {$elapsed} — {$msgCount} messages captures.\n"
                . "Termine-la d'abord avec *reunion end*.";
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
            . "Tous tes messages seront captures automatiquement.\n"
            . "Utilise *reunion status* pour voir l'avancement.\n"
            . "Envoie *reunion end* quand tu as fini pour obtenir la synthese.";
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

        $this->sendText(
            $context->from,
            "Reunion *{$active->group_name}* terminee ({$durationStr}, " . count($messages) . " messages). Analyse en cours..."
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

        $reply = $this->formatAnalysis($active->group_name, $analysis, count($messages), $durationStr);
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

        $participants = array_unique(array_column($messages, 'sender'));
        sort($participants);

        $reply = "*Reunion en cours: {$active->group_name}*\n\n"
            . "Duree: {$elapsed}\n"
            . "Messages captures: " . count($messages) . "\n";

        if (!empty($participants)) {
            $reply .= "Participants: " . implode(', ', $participants) . "\n";
        }

        $reply .= "\nEnvoie *reunion end* pour terminer et obtenir la synthese.";

        $this->sendText($context->from, $reply);
        return AgentResult::reply($reply, [
            'action' => 'meeting_status',
            'meeting_id' => $active->id,
            'messages_count' => count($messages),
            'participants' => $participants,
        ]);
    }

    private function listMeetings(AgentContext $context): AgentResult
    {
        $meetings = MeetingSession::forUser($context->from)
            ->completed()
            ->latest('ended_at')
            ->limit(5)
            ->get();

        if ($meetings->isEmpty()) {
            $reply = "Aucune reunion terminee trouvee.\nDemarre une nouvelle reunion avec *reunion start [nom]*.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'no_meetings_found']);
        }

        $lines = ["*Historique des reunions:*\n"];
        foreach ($meetings as $i => $m) {
            $date = $m->ended_at ? $m->ended_at->format('d/m/y H:i') : 'inconnu';
            $msgCount = count($m->messages_captured ?? []);
            $duration = ($m->started_at && $m->ended_at)
                ? $this->formatDuration($m->started_at->diff($m->ended_at))
                : '?';
            $lines[] = ($i + 1) . ". *{$m->group_name}*";
            $lines[] = "   {$date} — {$duration} — {$msgCount} msg";
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
            $this->sendText($context->from, "Regeneration de la synthese pour *{$meeting->group_name}*...");
            try {
                $analyzer = new MeetingAnalyzer();
                $summary = $analyzer->analyze($meeting->messages_captured ?? [], $meeting->group_name);
                $meeting->update(['summary' => json_encode($summary, JSON_UNESCAPED_UNICODE)]);
            } catch (\Throwable $e) {
                Log::error('SmartMeetingAgent: showSummary re-analyze failed: ' . $e->getMessage());
                $reply = "Erreur lors de la regeneration de la synthese. Reessaie.";
                $this->sendText($context->from, $reply);
                return AgentResult::reply($reply, ['action' => 'summary_error']);
            }
        }

        $messagesCount = count($meeting->messages_captured ?? []);
        $duration = ($meeting->started_at && $meeting->ended_at)
            ? $this->formatDuration($meeting->started_at->diff($meeting->ended_at))
            : null;

        $reply = $this->formatAnalysis($meeting->group_name, $summary, $messagesCount, $duration);
        $this->sendText($context->from, $reply);
        return AgentResult::reply($reply, ['action' => 'meeting_summary', 'meeting_id' => $meeting->id]);
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
                $lines[] = "  • {$d}";
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
                $line = "  • {$task}";
                if ($assignee) {
                    $line .= " _(→ {$assignee})_";
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
                $lines[] = "  ⚠ {$r}";
            }
            $lines[] = '';
        }

        // Next steps
        if (!empty($analysis['next_steps'])) {
            $lines[] = "*Prochaines etapes:*";
            foreach ($analysis['next_steps'] as $s) {
                $lines[] = "  → {$s}";
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
        if ($interval->h > 0) {
            return $interval->h . 'h' . ($interval->i > 0 ? $interval->i . 'min' : '');
        }
        if ($interval->i > 0) {
            return $interval->i . ' min';
        }
        return $interval->s . ' sec';
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
                    body: "ajoute {$prefix}{$task}",
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
