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
        return 'Agent de reunion intelligent. Capture automatiquement les messages pendant une reunion, genere une synthese structuree avec decisions, actions a faire, risques, participants et prochaines etapes. Permet aussi d\'ajouter des notes manuelles importantes, des decisions explicites, capturer des actions pendant la reunion, demarrer depuis un template (standup/retro/planning/review/1on1), definir un agenda, declarer des participants, noter la qualite d\'une reunion, obtenir un recap partiel, exporter la synthese en texte brut, lister les reunions passees (filtrage semaine/mois), comparer les N dernieres reunions, rechercher dans l\'historique, afficher des statistiques detaillees, renommer une reunion en cours, consulter les actions a faire, annuler une reunion, et creer automatiquement des todos et rappels a partir des action items.';
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
            'reunion agenda', 'agenda reunion', 'objectif reunion',
            'reunion export', 'exporter reunion', 'export meeting',
            'reunion participants', 'participants reunion', 'declarer participants',
            'reunion quality', 'qualite reunion', 'noter reunion', 'reunion note qualite',
            'reunion rename', 'renommer reunion', 'changer nom reunion',
            'reunion actions', 'actions reunions', 'suivi reunion', 'actions en attente',
            'reunion decision', 'decision reunion', 'ajouter decision',
            'reunion compare', 'comparer reunions', 'comparaison reunion',
            'reunion action', 'action reunion', 'ajouter action reunion',
            'reunion template', 'template reunion', 'modele reunion',
        ];
    }

    public function version(): string
    {
        return '1.8.0';
    }

    public function canHandle(AgentContext $context): bool
    {
        return $context->routedAgent === 'smart_meeting';
    }

    public function handle(AgentContext $context): AgentResult
    {
        $body = mb_strtolower(trim($context->body ?? ''));

        if ($this->isTemplateCommand($body)) {
            return $this->startFromTemplate($context);
        }

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

        if ($this->isAgendaCommand($body)) {
            return $this->setOrShowAgenda($context);
        }

        if ($this->isExportCommand($body)) {
            return $this->exportMeeting($context);
        }

        if ($this->isParticipantsCommand($body)) {
            return $this->handleParticipants($context);
        }

        if ($this->isQualityCommand($body)) {
            return $this->rateLastMeeting($context);
        }

        if ($this->isRenameCommand($body)) {
            return $this->renameMeeting($context);
        }

        if ($this->isActionItemCommand($body)) {
            return $this->addActionItem($context);
        }

        if ($this->isActionsCommand($body)) {
            return $this->showPendingActions($context);
        }

        if ($this->isDecisionCommand($body)) {
            return $this->addDecision($context);
        }

        if ($this->isCompareCommand($body)) {
            return $this->compareMeetings($context);
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
            . "*Demarrer:*\n"
            . "- *reunion start [nom]* — Demarrer une reunion\n"
            . "- *reunion template [type]* — Demarrer depuis un template (standup/retro/planning/review/1on1)\n\n"
            . "*Pendant la reunion:*\n"
            . "- *reunion end* — Terminer et obtenir la synthese\n"
            . "- *reunion note [texte]* — Ajouter une note importante\n"
            . "- *reunion decision [texte]* — Enregistrer une decision explicite\n"
            . "- *reunion action [tache] -> [personne?]* — Capturer une action a faire\n"
            . "- *reunion agenda [texte]* — Definir l'agenda/objectif\n"
            . "- *reunion participants [noms]* — Declarer les participants\n"
            . "- *reunion recap* — Bilan partiel en cours de reunion\n"
            . "- *reunion rename [nouveau nom]* — Renommer la reunion en cours\n"
            . "- *reunion status* — Statut de la reunion en cours\n"
            . "- *reunion cancel* — Annuler sans generer de synthese\n\n"
            . "*Historique et suivi:*\n"
            . "- *reunion list* — Historique des 5 dernieres reunions\n"
            . "- *reunion list semaine* / *reunion list mois* — Filtrer par periode\n"
            . "- *reunion actions* — Actions a faire des dernieres reunions\n"
            . "- *reunion compare [n]* — Comparer les N dernieres reunions\n"
            . "- *reunion search [terme]* — Rechercher dans les reunions\n"
            . "- *reunion stats* — Statistiques de tes reunions\n"
            . "- *reunion export [nom]* — Exporter la synthese en texte brut\n"
            . "- *reunion quality [1-5] [nom?]* — Noter la qualite d'une reunion\n"
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

    private function isAgendaCommand(string $body): bool
    {
        return (bool) preg_match('/\br[ée]union\s+agenda\b/iu', $body)
            || (bool) preg_match('/\bagenda\s+r[ée]union\b/iu', $body);
    }

    private function isExportCommand(string $body): bool
    {
        return (bool) preg_match('/\br[ée]union\s+export\b/iu', $body)
            || (bool) preg_match('/\bexport(?:er)?\s+r[ée]union\b/iu', $body);
    }

    private function isParticipantsCommand(string $body): bool
    {
        return (bool) preg_match('/\br[ée]union\s+participants?\b/iu', $body)
            || (bool) preg_match('/\bparticipants?\s+r[ée]union\b/iu', $body)
            || (bool) preg_match('/\bdeclarer\s+participants?\b/iu', $body);
    }

    private function isQualityCommand(string $body): bool
    {
        return (bool) preg_match('/\br[ée]union\s+qualit[eé]\b/iu', $body)
            || (bool) preg_match('/\br[ée]union\s+quality\b/iu', $body)
            || (bool) preg_match('/\bqualit[eé]\s+r[ée]union\b/iu', $body)
            || (bool) preg_match('/\bnote[r]?\s+r[ée]union\b/iu', $body);
    }

    private function isRenameCommand(string $body): bool
    {
        return (bool) preg_match('/\br[ée]union\s+rename\b/iu', $body)
            || (bool) preg_match('/\brenommer\s+r[ée]union\b/iu', $body)
            || (bool) preg_match('/\bchanger\s+nom\s+r[ée]union\b/iu', $body);
    }

    private function isActionsCommand(string $body): bool
    {
        return (bool) preg_match('/\br[ée]union\s+actions?\b/iu', $body)
            || (bool) preg_match('/\bactions?\s+r[ée]unions?\b/iu', $body)
            || (bool) preg_match('/\bsuivi\s+r[ée]union\b/iu', $body)
            || (bool) preg_match('/\bactions?\s+en\s+attente\b/iu', $body);
    }

    private function isDecisionCommand(string $body): bool
    {
        return (bool) preg_match('/\br[ée]union\s+d[eé]cision\b/iu', $body)
            || (bool) preg_match('/\bd[eé]cision\s+r[ée]union\b/iu', $body)
            || (bool) preg_match('/\bajouter\s+d[eé]cision\b/iu', $body);
    }

    private function isCompareCommand(string $body): bool
    {
        return (bool) preg_match('/\br[ée]union\s+compare\b/iu', $body)
            || (bool) preg_match('/\bcomparer\s+r[ée]unions?\b/iu', $body)
            || (bool) preg_match('/\bcomparaison\s+r[ée]union\b/iu', $body);
    }

    private function isActionItemCommand(string $body): bool
    {
        // Matches "reunion action" (singular) but NOT "reunion actions" (plural → showPendingActions)
        return (bool) preg_match('/\br[ée]union\s+action(?!s\b)/iu', $body)
            || (bool) preg_match('/\baction(?!s\b)\s+r[ée]union\b/iu', $body);
    }

    private function isTemplateCommand(string $body): bool
    {
        return (bool) preg_match('/\br[ée]union\s+template\b/iu', $body)
            || (bool) preg_match('/\btemplate\s+r[ée]union\b/iu', $body)
            || (bool) preg_match('/\bmodele\s+r[ée]union\b/iu', $body);
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

        // Extract group name and optional inline agenda from ORIGINAL body to preserve casing
        // Supports: "reunion start Nom | agenda: objectif" or "reunion start Nom"
        $originalBody = $context->body ?? '';
        $groupName = 'Reunion';
        $inlineAgenda = null;

        if (preg_match('/(?:r[ée]union|meeting)\s+start\s+([^|]+?)(?:\s*\|\s*(.+))?$/iu', $originalBody, $matches)) {
            $groupName = trim($matches[1]);
            $inlineAgenda = !empty($matches[2]) ? trim($matches[2]) : null;
        } elseif (preg_match('/demarrer\s+r[ée]union\s+([^|]+?)(?:\s*\|\s*(.+))?$/iu', $originalBody, $matches)) {
            $groupName = trim($matches[1]);
            $inlineAgenda = !empty($matches[2]) ? trim($matches[2]) : null;
        }

        $initialMessages = [];
        if ($inlineAgenda) {
            $initialMessages[] = [
                'sender' => $context->senderName,
                'content' => $inlineAgenda,
                'timestamp' => now()->toISOString(),
                'type' => 'agenda',
            ];
        }

        $meeting = MeetingSession::create([
            'user_phone' => $context->from,
            'agent_id' => $context->agent->id,
            'group_name' => $groupName,
            'status' => 'active',
            'started_at' => now(),
            'messages_captured' => $initialMessages,
        ]);

        $meeting->activate();

        $this->log($context, "Meeting started: {$groupName}", ['meeting_id' => $meeting->id]);

        $agendaLine = $inlineAgenda ? "\nAgenda: _{$inlineAgenda}_\n" : '';

        $reply = "Reunion *{$groupName}* demarree!\n{$agendaLine}\n"
            . "Tous tes messages seront captures automatiquement.\n\n"
            . "Commandes utiles:\n"
            . "- *reunion note [texte]* — Ajouter une note importante\n"
            . "- *reunion agenda [texte]* — Definir/modifier l'agenda\n"
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

        $regularMessages = array_filter($messages, fn($m) => ($m['type'] ?? 'message') === 'message');

        if (empty($regularMessages)) {
            $reply = "Reunion *{$active->group_name}* terminee ({$durationStr}).\nAucun message capture — pas de synthese a generer.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'meeting_ended_empty']);
        }

        $regularCount = count($regularMessages);
        $noteCount = count(array_filter($messages, fn($m) => ($m['type'] ?? '') === 'note'));

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

        // Merge declared participants into analysis
        $declaredParticipants = $this->getDeclaredParticipants($messages);
        if (!empty($declaredParticipants)) {
            $analysis['participants'] = array_values(array_unique(array_merge($declaredParticipants, $analysis['participants'] ?? [])));
            sort($analysis['participants']);
        }

        // Merge explicitly captured decisions (prepend so they're prominent)
        $manualDecisions = array_values(array_filter($messages, fn($m) => ($m['type'] ?? '') === 'decision'));
        if (!empty($manualDecisions)) {
            $manualTexts = array_map(fn($m) => $m['content'] ?? '', $manualDecisions);
            $existing = $analysis['decisions'] ?? [];
            // Avoid duplicates: only add if not already present (case-insensitive)
            $existingLower = array_map('mb_strtolower', $existing);
            foreach (array_reverse($manualTexts) as $dec) {
                if (!in_array(mb_strtolower($dec), $existingLower, true)) {
                    array_unshift($existing, $dec);
                    array_unshift($existingLower, mb_strtolower($dec));
                }
            }
            $analysis['decisions'] = $existing;
        }

        // Merge explicitly captured action items (prepend so they're prominent)
        $manualActions = array_values(array_filter($messages, fn($m) => ($m['type'] ?? '') === 'action_item'));
        if (!empty($manualActions)) {
            $existing = $analysis['action_items'] ?? [];
            $existingLower = array_map(fn($i) => mb_strtolower(is_array($i) ? ($i['task'] ?? '') : (string) $i), $existing);
            foreach (array_reverse($manualActions) as $m) {
                $task = $m['content'] ?? '';
                if (empty($task)) {
                    continue;
                }
                if (!in_array(mb_strtolower($task), $existingLower, true)) {
                    array_unshift($existing, [
                        'task' => $task,
                        'assignee' => $m['assignee'] ?? null,
                        'deadline' => null,
                    ]);
                    array_unshift($existingLower, mb_strtolower($task));
                }
            }
            $analysis['action_items'] = $existing;
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
        $msgCount = count(array_filter($active->messages_captured ?? [], fn($m) => ($m['type'] ?? 'message') === 'message'));
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

        // Extract agenda if set
        $agenda = null;
        foreach ($messages as $msg) {
            if (($msg['type'] ?? '') === 'agenda') {
                $agenda = $msg['content'];
                break;
            }
        }

        $participants = $this->getMeetingParticipants($messages);

        $reply = "*Reunion en cours: {$active->group_name}*\n\n"
            . "Duree: {$elapsed}\n"
            . "Messages captures: " . count($regularMessages) . "\n";

        if ($agenda) {
            $agendaShort = mb_substr($agenda, 0, 100);
            $suffix = mb_strlen($agenda) > 100 ? '...' : '';
            $reply .= "Agenda: {$agendaShort}{$suffix}\n";
        }

        $manualDecisions = array_values(array_filter($messages, fn($m) => ($m['type'] ?? '') === 'decision'));
        $manualActions = array_values(array_filter($messages, fn($m) => ($m['type'] ?? '') === 'action_item'));

        if (count($notes) > 0) {
            $reply .= "Notes ajoutees: " . count($notes) . "\n";
        }

        if (count($manualDecisions) > 0) {
            $reply .= "Decisions notees: " . count($manualDecisions) . "\n";
        }

        if (count($manualActions) > 0) {
            $reply .= "Actions capturees: " . count($manualActions) . "\n";
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

        $periodLabel = null;
        $query = MeetingSession::forUser($context->from)->completed();

        // Support "reunion list semaine" / "reunion list mois"
        if (preg_match('/\bsemaine\b/iu', $body)) {
            $query->where('ended_at', '>=', now()->startOfWeek(\Carbon\Carbon::MONDAY));
            $limit = 20;
            $periodLabel = 'cette semaine';
        } elseif (preg_match('/\bmois\b/iu', $body)) {
            $query->where('ended_at', '>=', now()->startOfMonth());
            $limit = 30;
            $periodLabel = 'ce mois';
        }

        $meetings = $query->latest('ended_at')->limit($limit)->get();

        if ($meetings->isEmpty()) {
            $suffix = $periodLabel ? " ({$periodLabel})" : '';
            $reply = "Aucune reunion terminee trouvee{$suffix}.\nDemarre une nouvelle reunion avec *reunion start [nom]*.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'no_meetings_found']);
        }

        $title = $periodLabel ? "*Reunions — {$periodLabel}:*\n" : "*Historique des reunions:*\n";
        $lines = [$title];
        foreach ($meetings as $i => $m) {
            $date = $m->ended_at ? $m->ended_at->format('d/m/y H:i') : 'inconnu';
            $msgCount = count(array_filter($m->messages_captured ?? [], fn($msg) => ($msg['type'] ?? 'message') === 'message'));
            $duration = ($m->started_at && $m->ended_at)
                ? $this->formatDuration($m->started_at->diff($m->ended_at))
                : '?';
            $hasSummary = !empty($m->summary) ? '' : ' _(pas de synthese)_';
            $qualityStr = '';
            if (!empty($m->summary)) {
                $s = json_decode($m->summary, true);
                $rating = $s['quality_rating'] ?? null;
                if ($rating !== null) {
                    $qualityStr = ' ' . str_repeat('⭐', (int) $rating);
                }
            }
            $lines[] = ($i + 1) . ". *{$m->group_name}*{$hasSummary}{$qualityStr}";
            $lines[] = "   {$date} — {$duration} — {$msgCount} msg";
        }

        if (!$periodLabel) {
            $total = MeetingSession::forUser($context->from)->completed()->count();
            if ($total > $limit) {
                $lines[] = "\n_({$total} reunions au total — utilise *reunion list {$total}* pour tout voir)_";
            }
        }

        $lines[] = "\nPour revoir une synthese: *synthese reunion [nom]*";
        $lines[] = "Pour exporter: *reunion export [nom]*";

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

        // Append quality rating if present
        $qualityRating = $summary['quality_rating'] ?? null;
        if ($qualityRating !== null) {
            $stars = str_repeat('⭐', (int) $qualityRating) . str_repeat('☆', 5 - (int) $qualityRating);
            $qualityComment = $summary['quality_comment'] ?? null;
            $reply .= "\n\n*Qualite:* {$stars} ({$qualityRating}/5)";
            if ($qualityComment) {
                $reply .= "\n_{$qualityComment}_";
            }
        }

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

        // Total decisions across all summaries
        $totalDecisions = $completed->sum(function ($m) {
            if (!$m->summary) return 0;
            $s = json_decode($m->summary, true);
            return count($s['decisions'] ?? []);
        });

        // Average quality rating
        $ratings = $completed->filter(fn($m) => !empty($m->summary))
            ->map(function ($m) {
                $s = json_decode($m->summary, true);
                return $s['quality_rating'] ?? null;
            })
            ->filter(fn($r) => $r !== null);
        $avgQuality = $ratings->isNotEmpty() ? round($ratings->avg(), 1) : null;

        // Average messages per meeting
        $avgMessages = $totalMeetings > 0 ? (int) round($totalMessages / $totalMeetings) : 0;

        // Meetings this week
        $thisWeekMeetings = $completed->filter(fn($m) => $m->ended_at && $m->ended_at->isCurrentWeek());
        $thisWeekCount = $thisWeekMeetings->count();
        $thisWeekDecisions = $thisWeekMeetings->sum(function ($m) {
            if (!$m->summary) return 0;
            $s = json_decode($m->summary, true);
            return count($s['decisions'] ?? []);
        });
        $thisWeekActions = $thisWeekMeetings->sum(function ($m) {
            if (!$m->summary) return 0;
            $s = json_decode($m->summary, true);
            return count($s['action_items'] ?? []);
        });

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

        // Most active day of week
        $dayStats = [];
        foreach ($completed as $m) {
            if ($m->ended_at) {
                $day = $m->ended_at->locale('fr')->isoFormat('dddd');
                $dayStats[$day] = ($dayStats[$day] ?? 0) + 1;
            }
        }
        arsort($dayStats);
        $topDay = !empty($dayStats) ? array_key_first($dayStats) : null;

        $lastMeeting = $completed->sortByDesc('ended_at')->first();

        $weekLine = $thisWeekCount > 0
            ? "*{$thisWeekCount}* _(+{$thisWeekDecisions} decisions, +{$thisWeekActions} actions)_"
            : '*0*';

        $reply = "*Statistiques de tes reunions*\n\n"
            . "Reunions terminees: *{$totalMeetings}*\n"
            . "Cette semaine: {$weekLine}\n"
            . "Messages captures (total): *{$totalMessages}*\n"
            . "Moyenne messages/reunion: *{$avgMessages}*\n"
            . "Decisions prises (total): *{$totalDecisions}*\n"
            . "Action items generes: *{$totalActionItems}*\n"
            . "Duree moyenne: *{$avgDuration}*\n";

        if ($longestMeeting) {
            $longestDur = $this->formatDuration($longestMeeting->started_at->diff($longestMeeting->ended_at));
            $reply .= "Reunion la plus longue: *{$longestMeeting->group_name}* ({$longestDur})\n";
        }

        if ($topDay) {
            $reply .= "Jour le plus frequent: *{$topDay}*\n";
        }

        if (!empty($topParticipants)) {
            $reply .= "Top participants: " . implode(', ', $topParticipants) . "\n";
        }

        if ($avgQuality !== null) {
            $stars = str_repeat('⭐', (int) round($avgQuality));
            $reply .= "Qualite moyenne: {$stars} ({$avgQuality}/5)\n";
        }

        if ($lastMeeting) {
            $reply .= "Derniere reunion: *{$lastMeeting->group_name}* le " . $lastMeeting->ended_at->format('d/m/y');
        }

        $this->sendText($context->from, $reply);
        return AgentResult::reply($reply, [
            'action' => 'meeting_stats',
            'total_meetings' => $totalMeetings,
            'total_messages' => $totalMessages,
            'total_decisions' => $totalDecisions,
            'total_action_items' => $totalActionItems,
            'avg_quality' => $avgQuality,
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
                  ->orWhereRaw('LOWER(summary) LIKE ?', ["%{$lowerTerm}%"])
                  ->orWhereRaw('LOWER(CAST(messages_captured AS text)) LIKE ?', ["%{$lowerTerm}%"]);
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

        $regularMessages = array_filter($messages, fn($m) => ($m['type'] ?? 'message') === 'message');

        if (empty($regularMessages)) {
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

        $regularCount = count($regularMessages);
        $reply = $this->formatAnalysis($active->group_name . ' (recap partiel)', $analysis, $regularCount, $elapsed);
        $reply .= "\n\n_La reunion est toujours en cours. Envoie *reunion end* pour terminer._";

        $this->sendText($context->from, $reply);
        return AgentResult::reply($reply, [
            'action' => 'meeting_recap',
            'meeting_id' => $active->id,
            'messages_count' => count($messages),
        ]);
    }

    private function setOrShowAgenda(AgentContext $context): AgentResult
    {
        $active = MeetingSession::getActive($context->from);
        if (!$active) {
            $reply = "Aucune reunion en cours.\nDemarre-en une avec *reunion start [nom]*.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'no_active_meeting']);
        }

        $body = $context->body ?? '';
        $agendaText = null;

        if (preg_match('/r[ée]union\s+agenda\s+(.+)/iu', $body, $matches)) {
            $agendaText = trim($matches[1]);
        } elseif (preg_match('/agenda\s+r[ée]union\s+(.+)/iu', $body, $matches)) {
            $agendaText = trim($matches[1]);
        }

        if (empty($agendaText)) {
            // Show current agenda
            $messages = $active->messages_captured ?? [];
            $currentAgenda = null;
            foreach ($messages as $msg) {
                if (($msg['type'] ?? '') === 'agenda') {
                    $currentAgenda = $msg['content'];
                    break;
                }
            }
            if ($currentAgenda) {
                $reply = "*Agenda de {$active->group_name}:*\n{$currentAgenda}";
            } else {
                $reply = "Aucun agenda defini pour *{$active->group_name}*.\nDefinis-en un avec: *reunion agenda [objectif]*";
            }
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'agenda_shown', 'meeting_id' => $active->id]);
        }

        // Set/replace agenda
        $messages = $active->messages_captured ?? [];

        // Remove any previous agenda entry
        $messages = array_values(array_filter($messages, fn($m) => ($m['type'] ?? '') !== 'agenda'));

        // Insert agenda at beginning so it's used as context
        array_unshift($messages, [
            'sender' => $context->senderName,
            'content' => $agendaText,
            'timestamp' => now()->toISOString(),
            'type' => 'agenda',
        ]);

        $active->update(['messages_captured' => $messages]);

        $reply = "Agenda defini pour *{$active->group_name}*:\n_{$agendaText}_";
        $this->sendText($context->from, $reply);
        return AgentResult::reply($reply, [
            'action' => 'agenda_set',
            'meeting_id' => $active->id,
            'agenda' => $agendaText,
        ]);
    }

    private function exportMeeting(AgentContext $context): AgentResult
    {
        $body = $context->body ?? '';
        $meetingName = null;

        if (preg_match('/r[ée]union\s+export\s+(.+)/iu', $body, $matches)) {
            $meetingName = trim($matches[1]);
        } elseif (preg_match('/export(?:er)?\s+r[ée]union\s+(.+)/iu', $body, $matches)) {
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
                : "Aucune reunion terminee a exporter.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'meeting_not_found']);
        }

        $summary = $meeting->summary ? json_decode($meeting->summary, true) : null;

        if (!$summary) {
            $reply = "La reunion *{$meeting->group_name}* n'a pas de synthese.\nTermine-la avec *reunion end* ou regenere-la avec *synthese reunion {$meeting->group_name}*.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'no_summary_to_export']);
        }

        $messagesCount = count(array_filter($meeting->messages_captured ?? [], fn($m) => ($m['type'] ?? 'message') === 'message'));
        $duration = ($meeting->started_at && $meeting->ended_at)
            ? $this->formatDuration($meeting->started_at->diff($meeting->ended_at))
            : null;

        $export = $this->formatExport(
            $meeting->group_name,
            $summary,
            $messagesCount,
            $duration,
            $meeting->ended_at?->format('d/m/Y H:i')
        );

        $this->sendText($context->from, $export);
        return AgentResult::reply($export, [
            'action' => 'meeting_exported',
            'meeting_id' => $meeting->id,
        ]);
    }

    private function handleParticipants(AgentContext $context): AgentResult
    {
        $body = $context->body ?? '';
        $names = null;

        if (preg_match('/r[ée]union\s+participants?\s+(.+)/iu', $body, $m)) {
            $names = trim($m[1]);
        } elseif (preg_match('/participants?\s+r[ée]union\s+(.+)/iu', $body, $m)) {
            $names = trim($m[1]);
        } elseif (preg_match('/declarer\s+participants?\s+(.+)/iu', $body, $m)) {
            $names = trim($m[1]);
        }

        $active = MeetingSession::getActive($context->from);

        if (!$names) {
            // Show participants of active meeting or last completed
            if ($active) {
                $participants = $this->getMeetingParticipants($active->messages_captured ?? []);
                if (empty($participants)) {
                    $reply = "Aucun participant declare ou ayant envoye un message dans *{$active->group_name}*.\n"
                        . "Declare-les avec: *reunion participants Alice, Bob, Charlie*";
                } else {
                    $count = count($participants);
                    $reply = "*Participants ({$count}) — {$active->group_name}:*\n" . implode(', ', $participants);
                }
            } else {
                $reply = "Aucune reunion en cours.\n"
                    . "Demarre une reunion avec *reunion start [nom]*, puis utilise:\n"
                    . "*reunion participants Alice, Bob, Charlie*";
            }
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'participants_shown']);
        }

        if (!$active) {
            $reply = "Aucune reunion en cours. Demarre-en une avec *reunion start [nom]* avant de declarer des participants.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'no_active_meeting']);
        }

        // Parse comma-separated names
        $participantList = array_values(array_filter(
            array_map('trim', explode(',', $names)),
            fn($n) => !empty($n)
        ));

        if (empty($participantList)) {
            $reply = "Utilise: *reunion participants Alice, Bob, Charlie*";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'participants_missing_names']);
        }

        // Store as a special message type (replace previous declaration)
        $messages = $active->messages_captured ?? [];
        $messages = array_values(array_filter($messages, fn($m) => ($m['type'] ?? '') !== 'participants'));
        $messages[] = [
            'sender' => $context->senderName,
            'content' => implode(', ', $participantList),
            'participants' => $participantList,
            'timestamp' => now()->toISOString(),
            'type' => 'participants',
        ];
        $active->update(['messages_captured' => $messages]);

        $count = count($participantList);
        $reply = "*{$count} participant(s) declares pour {$active->group_name}:*\n" . implode(', ', $participantList);
        $this->sendText($context->from, $reply);
        return AgentResult::reply($reply, [
            'action' => 'participants_set',
            'meeting_id' => $active->id,
            'participants' => $participantList,
        ]);
    }

    private function rateLastMeeting(AgentContext $context): AgentResult
    {
        $body = $context->body ?? '';
        $rating = null;
        $comment = null;
        $meetingName = null;

        // Support: reunion quality 4 [nom de reunion] [commentaire optionnel]
        // Or:      reunion quality 4 [commentaire] (if no meeting name matches)
        if (preg_match('/(?:r[ée]union\s+(?:qualit[eé]|quality)|qualit[eé]\s+r[ée]union|noter?\s+r[ée]union)\s+([1-5])(?:\s+(.+))?/iu', $body, $m)) {
            $rating = (int) $m[1];
            $rest = !empty($m[2]) ? trim($m[2]) : null;

            if ($rest) {
                // Try to find a meeting with a name starting with the rest text
                $candidate = MeetingSession::forUser($context->from)
                    ->completed()
                    ->whereRaw('LOWER(group_name) LIKE ?', [strtolower($rest) . '%'])
                    ->latest('ended_at')
                    ->first();
                if ($candidate) {
                    $meetingName = $rest;
                } else {
                    // No meeting matched — treat rest as comment
                    $comment = $rest;
                }
            }
        }

        if ($rating === null) {
            $reply = "Note une reunion de 1 a 5:\n"
                . "*reunion quality [1-5] [nom reunion?] [commentaire?]*\n"
                . "Ex: *reunion quality 4 bonne productivite*\n"
                . "Ex: *reunion quality 4 Sprint Review tres productive*\n"
                . "Ex: *reunion quality 2 trop longue, peu de decisions*";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'quality_missing_rating']);
        }

        $query = MeetingSession::forUser($context->from)->completed();
        if ($meetingName) {
            $query->whereRaw('LOWER(group_name) LIKE ?', [strtolower($meetingName) . '%']);
        }
        $meeting = $query->latest('ended_at')->first();

        if (!$meeting) {
            $notFoundMsg = $meetingName ? "Aucune reunion \"{$meetingName}\" trouvee." : "Aucune reunion terminee a noter.";
            $reply = "{$notFoundMsg}\nDemarre une reunion avec *reunion start [nom]*.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'no_meeting_to_rate']);
        }

        $summary = $meeting->summary ? json_decode($meeting->summary, true) : [];
        if (!is_array($summary)) {
            $summary = [];
        }
        $summary['quality_rating'] = $rating;
        $summary['quality_comment'] = $comment;
        $meeting->update(['summary' => json_encode($summary, JSON_UNESCAPED_UNICODE)]);

        $stars = str_repeat('⭐', $rating) . str_repeat('☆', 5 - $rating);
        $reply = "Reunion *{$meeting->group_name}* notee: {$stars} ({$rating}/5)";
        if ($comment) {
            $reply .= "\n_{$comment}_";
        }

        $this->sendText($context->from, $reply);
        return AgentResult::reply($reply, [
            'action' => 'meeting_rated',
            'meeting_id' => $meeting->id,
            'rating' => $rating,
        ]);
    }

    private function renameMeeting(AgentContext $context): AgentResult
    {
        $active = MeetingSession::getActive($context->from);
        if (!$active) {
            $reply = "Aucune reunion en cours a renommer.\nDemarre une reunion avec *reunion start [nom]*.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'no_active_meeting']);
        }

        $body = $context->body ?? '';
        $newName = null;

        if (preg_match('/r[ée]union\s+rename\s+(.+)/iu', $body, $m)) {
            $newName = trim($m[1]);
        } elseif (preg_match('/renommer\s+r[ée]union\s+(.+)/iu', $body, $m)) {
            $newName = trim($m[1]);
        } elseif (preg_match('/changer\s+nom\s+r[ée]union\s+(.+)/iu', $body, $m)) {
            $newName = trim($m[1]);
        }

        if (empty($newName)) {
            $reply = "Utilise: *reunion rename [nouveau nom]*\nEx: *reunion rename Sprint Review Q2*";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'rename_missing_name']);
        }

        $oldName = $active->group_name;
        $active->update(['group_name' => $newName]);

        $this->log($context, "Meeting renamed: {$oldName} → {$newName}", ['meeting_id' => $active->id]);

        $reply = "Reunion renommee: *{$oldName}* → *{$newName}*";
        $this->sendText($context->from, $reply);
        return AgentResult::reply($reply, [
            'action' => 'meeting_renamed',
            'meeting_id' => $active->id,
            'old_name' => $oldName,
            'new_name' => $newName,
        ]);
    }

    private function showPendingActions(AgentContext $context): AgentResult
    {
        $meetings = MeetingSession::forUser($context->from)
            ->completed()
            ->whereNotNull('summary')
            ->latest('ended_at')
            ->limit(5)
            ->get();

        if ($meetings->isEmpty()) {
            $reply = "Aucune reunion terminee avec des actions a faire.\nDemarre une reunion avec *reunion start [nom]*.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'no_actions_found']);
        }

        $allItems = [];
        foreach ($meetings as $meeting) {
            $summary = json_decode($meeting->summary ?? '{}', true);
            $items = $summary['action_items'] ?? [];
            foreach ($items as $item) {
                $task = is_array($item) ? ($item['task'] ?? (string) $item) : (string) $item;
                if (empty($task)) {
                    continue;
                }
                $assignee = is_array($item) ? ($item['assignee'] ?? null) : null;
                $deadline = is_array($item) ? ($item['deadline'] ?? null) : null;
                $allItems[] = [
                    'task' => $task,
                    'assignee' => $assignee,
                    'deadline' => $deadline,
                    'meeting' => $meeting->group_name,
                    'date' => $meeting->ended_at?->format('d/m'),
                ];
            }
        }

        if (empty($allItems)) {
            $reply = "Aucune action a faire trouvee dans les dernieres reunions.\nBien joue!";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'no_actions_found']);
        }

        $totalCount = count($allItems);
        $lines = ["*Actions a faire — {$totalCount} au total*\n"];

        $currentMeeting = null;
        foreach ($allItems as $item) {
            if ($item['meeting'] !== $currentMeeting) {
                $currentMeeting = $item['meeting'];
                $lines[] = "\n_{$currentMeeting} ({$item['date']}):_";
            }
            $line = "  > {$item['task']}";
            if ($item['assignee']) {
                $line .= " _(-> {$item['assignee']})_";
            }
            if ($item['deadline']) {
                $line .= " — {$item['deadline']}";
            }
            $lines[] = $line;
        }

        $lines[] = "\nPour marquer comme fait: utilise *todo* pour gerer tes taches.";

        $reply = implode("\n", $lines);
        $this->sendText($context->from, $reply);
        return AgentResult::reply($reply, [
            'action' => 'pending_actions',
            'total_actions' => $totalCount,
        ]);
    }

    private function addDecision(AgentContext $context): AgentResult
    {
        $active = MeetingSession::getActive($context->from);
        if (!$active) {
            $reply = "Aucune reunion en cours. Demarre-en une avec *reunion start [nom]* avant d'enregistrer une decision.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'no_active_meeting']);
        }

        $body = $context->body ?? '';
        $decisionText = null;

        if (preg_match('/r[ée]union\s+d[eé]cision\s+(.+)/iu', $body, $matches)) {
            $decisionText = trim($matches[1]);
        } elseif (preg_match('/d[eé]cision\s+r[ée]union\s+(.+)/iu', $body, $matches)) {
            $decisionText = trim($matches[1]);
        } elseif (preg_match('/ajouter\s+d[eé]cision\s+(.+)/iu', $body, $matches)) {
            $decisionText = trim($matches[1]);
        }

        if (empty($decisionText)) {
            $reply = "Utilise: *reunion decision [texte]*\nEx: *reunion decision on migre vers PostgreSQL en Q3*";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'decision_missing_text']);
        }

        $messages = $active->messages_captured ?? [];
        $decisionCount = count(array_filter($messages, fn($m) => ($m['type'] ?? '') === 'decision'));

        $messages[] = [
            'sender' => $context->senderName,
            'content' => $decisionText,
            'timestamp' => now()->toISOString(),
            'type' => 'decision',
        ];
        $active->update(['messages_captured' => $messages]);

        $newCount = $decisionCount + 1;
        $reply = "Decision #{$newCount} enregistree dans *{$active->group_name}*:\n_{$decisionText}_";
        $this->sendText($context->from, $reply);
        return AgentResult::reply($reply, [
            'action' => 'decision_added',
            'meeting_id' => $active->id,
            'decision' => $decisionText,
            'decision_count' => $newCount,
        ]);
    }

    private function compareMeetings(AgentContext $context): AgentResult
    {
        $body = $context->body ?? '';
        $n = 3;
        if (preg_match('/\b(\d+)\s*$/u', $body, $m)) {
            $n = min((int) $m[1], 5);
        }
        $n = max($n, 2);

        $meetings = MeetingSession::forUser($context->from)
            ->completed()
            ->latest('ended_at')
            ->limit($n)
            ->get();

        if ($meetings->count() < 2) {
            $reply = "Il faut au moins 2 reunions terminees pour comparer.\nDemarre des reunions avec *reunion start [nom]*.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'not_enough_meetings']);
        }

        $lines = ["*Comparaison des {$meetings->count()} dernieres reunions*\n"];
        foreach ($meetings as $i => $m) {
            $date = $m->ended_at?->format('d/m/y') ?? '?';
            $msgCount = count(array_filter(
                $m->messages_captured ?? [],
                fn($msg) => ($msg['type'] ?? 'message') === 'message'
            ));
            $duration = ($m->started_at && $m->ended_at)
                ? $this->formatDuration($m->started_at->diff($m->ended_at))
                : '?';

            $summary = $m->summary ? json_decode($m->summary, true) : [];
            $decisionsCount = count($summary['decisions'] ?? []);
            $actionsCount = count($summary['action_items'] ?? []);
            $risksCount = count($summary['risks'] ?? []);
            $rating = $summary['quality_rating'] ?? null;
            $ratingStr = $rating !== null ? ' ' . str_repeat('⭐', (int) $rating) : '';

            $lines[] = ($i + 1) . ". *{$m->group_name}* ({$date}){$ratingStr}";
            $lines[] = "   Duree: {$duration} | {$msgCount} msg";
            $riskPart = $risksCount > 0 ? " | Risques: {$risksCount}" : '';
            $lines[] = "   Decisions: {$decisionsCount} | Actions: {$actionsCount}{$riskPart}";
        }

        // Trend insight: compare oldest vs newest duration
        $durationsSeconds = $meetings
            ->filter(fn($m) => $m->started_at && $m->ended_at)
            ->map(fn($m) => $m->started_at->diffInSeconds($m->ended_at));

        if ($durationsSeconds->count() >= 2) {
            $newest = $durationsSeconds->first();
            $oldest = $durationsSeconds->last();
            if ($newest > $oldest * 1.2) {
                $lines[] = "\n_Tendance: reunions recentes plus longues qu'avant_";
            } elseif ($newest < $oldest * 0.8) {
                $lines[] = "\n_Tendance: reunions recentes plus courtes — bonne gestion du temps!_";
            }
        }

        $lines[] = "\nPour la synthese: *synthese reunion [nom]*";
        $reply = implode("\n", $lines);
        $this->sendText($context->from, $reply);
        return AgentResult::reply($reply, [
            'action' => 'meeting_compare',
            'count' => $meetings->count(),
        ]);
    }

    private function addActionItem(AgentContext $context): AgentResult
    {
        $active = MeetingSession::getActive($context->from);
        if (!$active) {
            $reply = "Aucune reunion en cours. Demarre-en une avec *reunion start [nom]* avant de capturer une action.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'no_active_meeting']);
        }

        $body = $context->body ?? '';
        $taskText = null;
        $assignee = null;

        $raw = null;
        if (preg_match('/r[ée]union\s+action\s+(.+)/iu', $body, $matches)) {
            $raw = trim($matches[1]);
        } elseif (preg_match('/action\s+r[ée]union\s+(.+)/iu', $body, $matches)) {
            $raw = trim($matches[1]);
        }

        if ($raw !== null) {
            if (preg_match('/^(.+?)\s*->\s*(.+)$/u', $raw, $parts)) {
                $taskText = trim($parts[1]);
                $assignee = trim($parts[2]);
            } else {
                $taskText = $raw;
            }
        }

        if (empty($taskText)) {
            $reply = "Utilise: *reunion action [tache] -> [personne?]*\n"
                . "Ex: *reunion action preparer les slides -> Alice*\n"
                . "Ex: *reunion action revoir le budget*";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'action_missing_text']);
        }

        $messages = $active->messages_captured ?? [];
        $actionCount = count(array_filter($messages, fn($m) => ($m['type'] ?? '') === 'action_item'));

        $entry = [
            'sender' => $context->senderName,
            'content' => $taskText,
            'timestamp' => now()->toISOString(),
            'type' => 'action_item',
        ];
        if ($assignee !== null) {
            $entry['assignee'] = $assignee;
        }

        $messages[] = $entry;
        $active->update(['messages_captured' => $messages]);

        $newCount = $actionCount + 1;
        $assigneeLine = $assignee ? " _(-> {$assignee})_" : '';
        $reply = "Action #{$newCount} capturee dans *{$active->group_name}*:\n_{$taskText}_{$assigneeLine}";
        $this->sendText($context->from, $reply);
        return AgentResult::reply($reply, [
            'action' => 'action_item_added',
            'meeting_id' => $active->id,
            'task' => $taskText,
            'assignee' => $assignee,
            'action_count' => $newCount,
        ]);
    }

    private function startFromTemplate(AgentContext $context): AgentResult
    {
        $active = MeetingSession::getActive($context->from);
        if ($active) {
            $elapsed = $active->started_at->diffForHumans(now(), true);
            $reply = "Une reunion est deja en cours: *{$active->group_name}*\n"
                . "Demarree il y a {$elapsed}.\n"
                . "Termine-la avec *reunion end* ou annule-la avec *reunion cancel*.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'meeting_already_active']);
        }

        $body = $context->body ?? '';
        $templateType = null;

        if (preg_match('/(?:r[ée]union\s+template|template\s+r[ée]union|modele\s+r[ée]union)\s+(\S+)/iu', $body, $m)) {
            $templateType = mb_strtolower(trim($m[1]));
        }

        $templates = [
            'standup' => [
                'name' => 'Standup',
                'agenda' => 'Blockers / Avancement / Objectifs du jour',
                'description' => 'Daily standup (15 min recommandees)',
            ],
            'retro' => [
                'name' => 'Retrospective',
                'agenda' => 'Ce qui a bien marche / A ameliorer / Actions correctives',
                'description' => 'Sprint retrospective (60 min recommandees)',
            ],
            'retrospective' => [
                'name' => 'Retrospective',
                'agenda' => 'Ce qui a bien marche / A ameliorer / Actions correctives',
                'description' => 'Sprint retrospective (60 min recommandees)',
            ],
            'planning' => [
                'name' => 'Sprint Planning',
                'agenda' => 'Revue du backlog / Estimation / Engagement de sprint',
                'description' => 'Sprint planning (90 min recommandees)',
            ],
            'review' => [
                'name' => 'Sprint Review',
                'agenda' => 'Demo des features / Feedback / Validation des objectifs',
                'description' => 'Sprint review (30 min recommandees)',
            ],
            '1on1' => [
                'name' => '1-on-1',
                'agenda' => 'Progres & blockers / Feedback / Developpement personnel',
                'description' => '1-on-1 (30 min recommandees)',
            ],
        ];

        if (!$templateType || !isset($templates[$templateType])) {
            $available = "*Templates disponibles:*\n"
                . "- *standup* — Daily standup (15 min)\n"
                . "- *planning* — Sprint planning (90 min)\n"
                . "- *review* — Sprint review (30 min)\n"
                . "- *retro* — Retrospective (60 min)\n"
                . "- *1on1* — Entretien individuel (30 min)\n\n"
                . "Usage: *reunion template [type]*\nEx: *reunion template standup*";
            $this->sendText($context->from, $available);
            return AgentResult::reply($available, ['action' => 'template_list_shown']);
        }

        $template = $templates[$templateType];
        $groupName = $template['name'];
        $agendaText = $template['agenda'];

        $initialMessages = [[
            'sender' => $context->senderName,
            'content' => $agendaText,
            'timestamp' => now()->toISOString(),
            'type' => 'agenda',
        ]];

        $meeting = MeetingSession::create([
            'user_phone' => $context->from,
            'agent_id' => $context->agent->id,
            'group_name' => $groupName,
            'status' => 'active',
            'started_at' => now(),
            'messages_captured' => $initialMessages,
        ]);

        $meeting->activate();

        $this->log($context, "Meeting started from template: {$groupName} ({$templateType})", ['meeting_id' => $meeting->id]);

        $reply = "Reunion *{$groupName}* demarree! ({$template['description']})\n"
            . "Agenda: _{$agendaText}_\n\n"
            . "Tous tes messages seront captures automatiquement.\n\n"
            . "Commandes utiles:\n"
            . "- *reunion action [tache] -> [personne?]* — Capturer une action\n"
            . "- *reunion decision [texte]* — Enregistrer une decision\n"
            . "- *reunion recap* — Bilan partiel\n"
            . "- *reunion end* — Terminer et obtenir la synthese";
        $this->sendText($context->from, $reply);
        return AgentResult::reply($reply, [
            'action' => 'meeting_started_from_template',
            'meeting_id' => $meeting->id,
            'template' => $templateType,
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

        // Warn when approaching the limit
        if ($currentCount === self::MAX_MESSAGES - 20) {
            $this->sendText(
                $context->from,
                "Attention: plus que 20 messages avant la limite ({$currentCount}/" . self::MAX_MESSAGES . "). Pense a terminer la reunion bientot."
            );
        }

        $meeting->addMessage($context->senderName, $context->body ?? '');

        // Milestone notifications every 50 messages
        $newCount = $currentCount + 1;
        if ($newCount > 0 && $newCount % 50 === 0 && $newCount < self::MAX_MESSAGES) {
            $elapsed = $this->formatDuration($meeting->started_at->diff(now()));
            $this->sendText(
                $context->from,
                "{$newCount} messages captures dans *{$meeting->group_name}* ({$elapsed}). Envoie *reunion recap* pour un bilan partiel."
            );
        }

        return AgentResult::silent(['action' => 'message_captured', 'meeting_id' => $meeting->id]);
    }

    // ── Participant helpers ───────────────────────────────────────────────

    /**
     * Merge explicitly declared participants with participants derived from message senders.
     */
    private function getMeetingParticipants(array $messages): array
    {
        $declared = $this->getDeclaredParticipants($messages);
        $fromMessages = [];
        foreach ($messages as $msg) {
            if (($msg['type'] ?? 'message') === 'message') {
                $sender = $msg['sender'] ?? null;
                if ($sender) {
                    $fromMessages[] = $sender;
                }
            }
        }
        $all = array_unique(array_merge($declared, $fromMessages));
        sort($all);
        return array_values($all);
    }

    /**
     * Return participants from an explicit 'participants' type message, or [].
     */
    private function getDeclaredParticipants(array $messages): array
    {
        foreach ($messages as $msg) {
            if (($msg['type'] ?? '') === 'participants') {
                return $msg['participants'] ?? array_map('trim', explode(',', $msg['content'] ?? ''));
            }
        }
        return [];
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

    private function formatExport(string $groupName, array $analysis, int $messagesCount, ?string $duration, ?string $date): string
    {
        $separator = str_repeat('-', 32);
        $lines = [
            "COMPTE RENDU DE REUNION",
            str_repeat('=', 32),
            "Reunion: {$groupName}",
        ];

        if ($date) {
            $lines[] = "Date: {$date}";
        }

        $meta = "{$messagesCount} message(s)";
        if ($duration) {
            $meta .= " — duree: {$duration}";
        }
        $lines[] = $meta;
        $lines[] = $separator;

        if (!empty($analysis['participants'])) {
            $lines[] = '';
            $lines[] = "PARTICIPANTS";
            $lines[] = implode(', ', $analysis['participants']);
        }

        if (!empty($analysis['summary'])) {
            $lines[] = '';
            $lines[] = "RESUME";
            $lines[] = $analysis['summary'];
        }

        if (!empty($analysis['decisions'])) {
            $lines[] = '';
            $lines[] = "DECISIONS";
            foreach ($analysis['decisions'] as $d) {
                $lines[] = "  - {$d}";
            }
        }

        if (!empty($analysis['action_items'])) {
            $lines[] = '';
            $lines[] = "ACTIONS A FAIRE";
            foreach ($analysis['action_items'] as $item) {
                $task = is_array($item) ? ($item['task'] ?? (string) $item) : (string) $item;
                $assignee = is_array($item) ? ($item['assignee'] ?? null) : null;
                $deadline = is_array($item) ? ($item['deadline'] ?? null) : null;
                $line = "  - {$task}";
                if ($assignee) {
                    $line .= " (-> {$assignee})";
                }
                if ($deadline) {
                    $line .= " [{$deadline}]";
                }
                $lines[] = $line;
            }
        }

        if (!empty($analysis['risks'])) {
            $lines[] = '';
            $lines[] = "RISQUES / BLOCKERS";
            foreach ($analysis['risks'] as $r) {
                $lines[] = "  ! {$r}";
            }
        }

        if (!empty($analysis['next_steps'])) {
            $lines[] = '';
            $lines[] = "PROCHAINES ETAPES";
            foreach ($analysis['next_steps'] as $s) {
                $lines[] = "  -> {$s}";
            }
        }

        $lines[] = '';
        $lines[] = str_repeat('=', 32);
        $lines[] = "Genere par ZeniClaw Smart Meeting";

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
