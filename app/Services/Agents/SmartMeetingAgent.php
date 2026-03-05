<?php

namespace App\Services\Agents;

use App\Models\MeetingSession;
use App\Services\AgentContext;
use App\Services\MeetingAnalyzer;
use Illuminate\Support\Facades\Log;

class SmartMeetingAgent extends BaseAgent
{
    public function name(): string
    {
        return 'smart_meeting';
    }

    public function canHandle(AgentContext $context): bool
    {
        return $context->routedAgent === 'smart_meeting';
    }

    public function handle(AgentContext $context): AgentResult
    {
        $body = mb_strtolower(trim($context->body ?? ''));

        // Detect action type
        if ($this->isStartCommand($body)) {
            return $this->startMeeting($context, $body);
        }

        if ($this->isEndCommand($body)) {
            return $this->endMeeting($context);
        }

        if ($this->isSummaryCommand($body)) {
            return $this->showSummary($context, $body);
        }

        // If there's an active meeting, capture the message
        $active = MeetingSession::getActive($context->from);
        if ($active) {
            return $this->captureMessage($context, $active);
        }

        $reply = "Commandes disponibles:\n"
            . "- *reunion start [nom]* — Demarrer une reunion\n"
            . "- *reunion end* — Terminer la reunion en cours\n"
            . "- *synthese reunion [nom]* — Voir la synthese d'une reunion";
        $this->sendText($context->from, $reply);
        return AgentResult::reply($reply, ['action' => 'meeting_help']);
    }

    private function isStartCommand(string $body): bool
    {
        return (bool) preg_match('/\br[ée]union\s+start\b/iu', $body);
    }

    private function isEndCommand(string $body): bool
    {
        return (bool) preg_match('/\br[ée]union\s+end\b/iu', $body);
    }

    private function isSummaryCommand(string $body): bool
    {
        return (bool) preg_match('/\bsynth[eè]se\s+r[ée]union\b/iu', $body);
    }

    private function startMeeting(AgentContext $context, string $body): AgentResult
    {
        // Check if there's already an active meeting
        $active = MeetingSession::getActive($context->from);
        if ($active) {
            $reply = "Une reunion est deja en cours: *{$active->group_name}*\n"
                . "Termine-la d'abord avec *reunion end*.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'meeting_already_active']);
        }

        // Extract group name
        $groupName = 'Reunion';
        if (preg_match('/r[ée]union\s+start\s+(.+)/iu', $body, $matches)) {
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
            . "Tous les messages seront captures automatiquement.\n"
            . "Quand c'est fini, envoie *reunion end* pour obtenir la synthese.";
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

        $active->update([
            'status' => 'completed',
            'ended_at' => now(),
        ]);
        $active->deactivate();

        $messages = $active->messages_captured ?? [];

        if (empty($messages)) {
            $reply = "Reunion *{$active->group_name}* terminee.\nAucun message capture — pas de synthese a generer.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'meeting_ended_empty']);
        }

        $this->sendText($context->from, "Reunion *{$active->group_name}* terminee. Analyse en cours...");

        // Analyze with MeetingAnalyzer
        $analyzer = new MeetingAnalyzer();
        $analysis = $analyzer->analyze($messages, $active->group_name);

        // Store summary
        $active->update(['summary' => json_encode($analysis, JSON_UNESCAPED_UNICODE)]);

        // Format the synthesis
        $reply = $this->formatAnalysis($active->group_name, $analysis, count($messages));

        $this->sendText($context->from, $reply);

        // Auto-create tasks and reminders from action items
        $this->createTasksAndReminders($context, $analysis);

        $this->log($context, "Meeting ended: {$active->group_name}", [
            'meeting_id' => $active->id,
            'messages_count' => count($messages),
        ]);

        return AgentResult::reply($reply, [
            'action' => 'meeting_ended',
            'meeting_id' => $active->id,
            'analysis' => $analysis,
        ]);
    }

    private function showSummary(AgentContext $context, string $body): AgentResult
    {
        // Extract meeting name
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
                ? "Aucune reunion trouvee avec le nom \"{$meetingName}\"."
                : "Aucune reunion terminee trouvee.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'meeting_not_found']);
        }

        $summary = $meeting->summary ? json_decode($meeting->summary, true) : null;

        if (!$summary) {
            // Re-analyze if no summary stored
            $analyzer = new MeetingAnalyzer();
            $summary = $analyzer->analyze($meeting->messages_captured ?? [], $meeting->group_name);
            $meeting->update(['summary' => json_encode($summary, JSON_UNESCAPED_UNICODE)]);
        }

        $messagesCount = count($meeting->messages_captured ?? []);
        $reply = $this->formatAnalysis($meeting->group_name, $summary, $messagesCount);

        $this->sendText($context->from, $reply);
        return AgentResult::reply($reply, ['action' => 'meeting_summary', 'meeting_id' => $meeting->id]);
    }

    private function captureMessage(AgentContext $context, MeetingSession $meeting): AgentResult
    {
        $meeting->addMessage($context->senderName, $context->body ?? '');

        return AgentResult::silent(['action' => 'message_captured', 'meeting_id' => $meeting->id]);
    }

    private function formatAnalysis(string $groupName, array $analysis, int $messagesCount): string
    {
        $lines = ["*Synthese — {$groupName}*"];
        $lines[] = "({$messagesCount} messages captures)\n";

        // Summary
        if (!empty($analysis['summary'])) {
            $lines[] = "*Resume:*\n{$analysis['summary']}\n";
        }

        // Decisions
        if (!empty($analysis['decisions'])) {
            $lines[] = "*Decisions:*";
            foreach ($analysis['decisions'] as $d) {
                $lines[] = "  - {$d}";
            }
            $lines[] = '';
        }

        // Action items
        if (!empty($analysis['action_items'])) {
            $lines[] = "*Actions a faire:*";
            foreach ($analysis['action_items'] as $item) {
                $task = $item['task'] ?? $item;
                $assignee = $item['assignee'] ?? null;
                $deadline = $item['deadline'] ?? null;
                $extra = '';
                if ($assignee) $extra .= " ({$assignee})";
                if ($deadline) $extra .= " — {$deadline}";
                $lines[] = "  - {$task}{$extra}";
            }
            $lines[] = '';
        }

        // Risks
        if (!empty($analysis['risks'])) {
            $lines[] = "*Risques/Blockers:*";
            foreach ($analysis['risks'] as $r) {
                $lines[] = "  - {$r}";
            }
            $lines[] = '';
        }

        // Next steps
        if (!empty($analysis['next_steps'])) {
            $lines[] = "*Prochaines etapes:*";
            foreach ($analysis['next_steps'] as $s) {
                $lines[] = "  - {$s}";
            }
        }

        return implode("\n", $lines);
    }

    private function createTasksAndReminders(AgentContext $context, array $analysis): void
    {
        $actionItems = $analysis['action_items'] ?? [];
        if (empty($actionItems)) return;

        try {
            // Create todos via TodoAgent
            $todoAgent = new TodoAgent();
            foreach ($actionItems as $item) {
                $task = is_array($item) ? ($item['task'] ?? '') : $item;
                if (empty($task)) continue;

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

            // Create a reminder for next steps if any
            $nextSteps = $analysis['next_steps'] ?? [];
            if (!empty($nextSteps)) {
                $reminderAgent = new ReminderAgent();
                $stepsText = implode(', ', array_slice($nextSteps, 0, 3));
                $reminderContext = new AgentContext(
                    agent: $context->agent,
                    session: $context->session,
                    from: $context->from,
                    senderName: $context->senderName,
                    body: "rappelle-moi demain a 9h: Suivi reunion {$context->body} — {$stepsText}",
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
