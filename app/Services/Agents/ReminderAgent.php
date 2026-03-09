<?php

namespace App\Services\Agents;

use App\Models\AppSetting;
use App\Models\Reminder;
use App\Services\AgentContext;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class ReminderAgent extends BaseAgent
{
    /** Max active reminders fetched to avoid giant LLM prompts */
    private const MAX_REMINDERS = 30;

    public function name(): string
    {
        return 'reminder';
    }

    public function description(): string
    {
        return 'Agent de gestion des rappels et alarmes. Permet de creer, lister, chercher, modifier, completer, supprimer et reporter des rappels ponctuels ou recurrents. Gere aussi l\'agenda du jour, les prochains rappels et les statistiques.';
    }

    public function keywords(): array
    {
        return [
            'rappel', 'rappels', 'rappelle-moi', 'rappelle moi', 'remind', 'reminder', 'reminders',
            'remind me', 'rappeler', 'rappele-moi', 'rappele moi',
            'alarme', 'alarm', 'alerte', 'alert',
            'dans 10 minutes', 'dans 1 heure', 'dans 30 min',
            'demain a', 'demain matin', 'a 10h', 'a 14h',
            'chaque jour', 'chaque lundi', 'tous les jours', 'every day', 'every week',
            'en semaine a', 'chaque semaine',
            'mes rappels', 'mon agenda', 'my reminders', 'list reminders',
            'rappels aujourd\'hui', 'rappels du jour', 'rappels de demain',
            'prochains rappels', 'prochain rappel',
            'supprimer rappel', 'supprime rappel', 'delete reminder', 'annuler rappel',
            'reporter rappel', 'reporte rappel', 'postpone', 'snooze', 'repousser',
            'recurrent', 'recurrence', 'periodique',
            'n\'oublie pas', 'noublie pas', 'pense a', 'faut que je',
            'marquer fait', 'marque fait', 'complete rappel', 'done reminder', 'c\'est fait',
            'aide rappel', 'help reminder', 'aide rappels',
            'cherche rappel', 'chercher rappel', 'trouve rappel', 'search reminder',
            'modifie rappel', 'modifier rappel', 'edit reminder', 'change rappel',
            'lundi prochain', 'semaine prochaine', 'mois prochain',
            'stats rappels', 'statistiques rappels', 'historique rappels', 'rappels completes',
            'bilan rappels',
        ];
    }

    public function version(): string
    {
        return '1.3.0';
    }

    public function canHandle(AgentContext $context): bool
    {
        return $context->routedAgent === 'reminder';
    }

    public function handle(AgentContext $context): AgentResult
    {
        // Fetch active reminders for this user (capped to avoid huge prompts)
        $reminders = Reminder::where('requester_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->where('status', 'pending')
            ->orderBy('scheduled_at')
            ->limit(self::MAX_REMINDERS)
            ->get();

        $listText = $this->formatReminderList($reminders);
        $tz = AppSetting::timezone();
        $now = now($tz)->format('Y-m-d H:i (l)');

        $response = $this->claude->chat(
            "Date et heure actuelles ({$tz}): {$now}\nMessage: \"{$context->body}\"\n\nRappels actifs:\n{$listText}",
            $this->resolveModel($context),
            $this->buildPrompt($now)
        );

        $parsed = $this->parseJson($response);

        if (!$parsed || empty($parsed['action'])) {
            $reply = "J'ai pas bien compris. Essaie:\n"
                . "\"Rappelle-moi d'appeler Jean demain a 10h\"\n"
                . "\"Mes rappels\" ou \"Rappels d'aujourd'hui\"\n"
                . "\"Supprime le rappel 2\"\n"
                . "\"Marque le rappel 1 comme fait\"\n"
                . "\"Stats rappels\"\n"
                . "\"aide\" pour voir toutes les commandes";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'reminder_parse_failed']);
        }

        $action = $parsed['action'];

        return match ($action) {
            'create'   => $this->handleCreate($context, $parsed),
            'list'     => $this->handleList($context, $reminders),
            'today'    => $this->handleToday($context),
            'delete'   => $this->handleDelete($context, $reminders, $parsed),
            'postpone' => $this->handlePostpone($context, $reminders, $parsed),
            'complete' => $this->handleComplete($context, $reminders, $parsed),
            'search'   => $this->handleSearch($context, $parsed),
            'edit'     => $this->handleEdit($context, $reminders, $parsed),
            'stats'    => $this->handleStats($context),
            'help'     => $this->handleHelp($context),
            default    => $this->handleUnknownAction($context, $action),
        };
    }

    private function buildPrompt(string $now): string
    {
        return <<<PROMPT
Tu es un assistant de gestion de rappels (reminders).
L'utilisateur te donne un message et tu dois determiner l'action a effectuer.
Date et heure actuelles (reference): {$now}

Reponds UNIQUEMENT en JSON valide, sans markdown, sans explication.

ACTIONS POSSIBLES:

1. CREER un rappel:
{"action": "create", "message": "texte du rappel", "scheduled_at": "YYYY-MM-DD HH:MM", "recurrence": "weekly:thursday:09:00" | null}

2. LISTER tous les rappels:
{"action": "list"}

3. RAPPELS DU JOUR (aujourd'hui + demain):
{"action": "today"}

4. SUPPRIMER des rappels:
{"action": "delete", "items": [1, 2]}

5. REPORTER un rappel:
{"action": "postpone", "item": 1, "new_time": "expression temporelle"}

6. MARQUER comme COMPLETE (fait) un rappel:
{"action": "complete", "items": [1]}

7. CHERCHER des rappels par mot-cle:
{"action": "search", "query": "mot-cle"}

8. MODIFIER le message d'un rappel:
{"action": "edit", "item": 1, "new_message": "nouveau texte du rappel"}

9. STATISTIQUES des rappels:
{"action": "stats"}

10. AIDE:
{"action": "help"}

REGLES POUR "create":
- 'message' = reformule le rappel de maniere claire et courte (ex: 'Appeler Jean')
- 'scheduled_at' = la date/heure de la PROCHAINE occurrence au format YYYY-MM-DD HH:MM
- 'recurrence' = regle de recurrence si le rappel est periodique, sinon null
  Formats: "daily:HH:MM", "weekly:DAYNAME:HH:MM" (monday,...,sunday), "monthly:DAY:HH:MM", "weekdays:HH:MM" (lundi-vendredi)
  Exemples: "chaque lundi a 8h" -> "weekly:monday:08:00", "tous les jours a 7h" -> "daily:07:00", "en semaine a 8h" -> "weekdays:08:00"
- Si l'heure est mentionnee sans date, utilise la date du jour (ou demain si l'heure est passee)
- Si 'demain' est mentionne, utilise la date de demain
- Si 'dans X minutes/heures', calcule a partir de la date actuelle
- Si 'lundi prochain', 'mardi prochain', etc., calcule la date du prochain jour de la semaine mentionne
- "ce soir" = aujourd'hui ~20:00, "ce matin" = aujourd'hui ~08:00, "ce midi" = aujourd'hui 12:00

REGLES POUR "today":
- Quand l'utilisateur demande ses rappels d'aujourd'hui, du jour, de demain, ou les prochains rappels
- Affiche aujourd'hui ET demain pour donner une vue courte

REGLES POUR "list":
- Quand l'utilisateur demande a voir TOUS ses rappels, son agenda complet

REGLES POUR "delete":
- items = liste des numeros de rappels a supprimer (integers, base 1, correspondant a la liste des rappels actifs)
- Fonctionne aussi pour "annule", "efface", "enleve" le rappel X

REGLES POUR "postpone":
- item = numero du rappel a reporter (integer, base 1)
- new_time = expression temporelle. Formats acceptes:
  - "YYYY-MM-DD HH:MM" (date absolue)
  - "demain HH:MM" ou "demain" (lendemain, meme heure si pas precisee)
  - "+Xmin", "+Xh", "+Xj", "+Xsem" (relatif: minutes, heures, jours, semaines)
  - "lundi prochain", "mardi prochain", etc. (prochain jour de la semaine, meme heure)
  - "semaine prochaine" (+7 jours, meme heure)

REGLES POUR "complete":
- items = liste des numeros de rappels a marquer comme faits (integers, base 1)
- Utilise pour: "c'est fait", "marque fait", "done", "j'ai fait le rappel X", "c'est regle"

REGLES POUR "search":
- query = mot-cle ou expression a chercher dans les messages des rappels
- Utilise pour: "cherche rappel X", "trouve mes rappels avec Y", "rappels jean", "search reminder"

REGLES POUR "edit":
- item = numero du rappel a modifier (integer, base 1)
- new_message = nouveau texte du rappel (reformule clairement et brievement)
- Utilise pour: "modifie le rappel 1", "change le rappel 2 en...", "renomme le rappel"

REGLES POUR "stats":
- Quand l'utilisateur demande des stats, statistiques, historique, bilan, rappels completes, rappels annules

REGLES POUR "help":
- Quand l'utilisateur demande de l'aide, les commandes disponibles, "aide", "help", "que peux-tu faire"

EXEMPLES (basees sur la date actuelle en reference):
- "Rappelle-moi d'appeler Jean demain a 10h" -> {"action": "create", "message": "Appeler Jean", "scheduled_at": "YYYY-MM-DD 10:00", "recurrence": null}
- "Mes rappels" -> {"action": "list"}
- "Mon agenda complet" -> {"action": "list"}
- "Mes rappels d'aujourd'hui" -> {"action": "today"}
- "Rappels du jour" -> {"action": "today"}
- "Prochains rappels" -> {"action": "today"}
- "Rappels de demain" -> {"action": "today"}
- "Supprime le rappel 2" -> {"action": "delete", "items": [2]}
- "Supprime les rappels 1 et 3" -> {"action": "delete", "items": [1, 3]}
- "Reporte le rappel 1 a demain 10h" -> {"action": "postpone", "item": 1, "new_time": "demain 10:00"}
- "Reporte le rappel 1 de 1h" -> {"action": "postpone", "item": 1, "new_time": "+1h"}
- "Reporte le rappel 2 d'une semaine" -> {"action": "postpone", "item": 2, "new_time": "+1sem"}
- "Reporte le rappel 1 a lundi prochain" -> {"action": "postpone", "item": 1, "new_time": "lundi prochain"}
- "Rappelle-moi en semaine a 8h de prendre mes vitamines" -> {"action": "create", "message": "Prendre mes vitamines", "scheduled_at": "YYYY-MM-DD 08:00", "recurrence": "weekdays:08:00"}
- "Marque le rappel 1 comme fait" -> {"action": "complete", "items": [1]}
- "C'est fait pour le rappel 2" -> {"action": "complete", "items": [2]}
- "Cherche rappels jean" -> {"action": "search", "query": "jean"}
- "Trouve mes rappels avec vitamines" -> {"action": "search", "query": "vitamines"}
- "Modifie le rappel 1 : Appeler Marie" -> {"action": "edit", "item": 1, "new_message": "Appeler Marie"}
- "Change le rappel 2 en 'Envoyer le rapport'" -> {"action": "edit", "item": 2, "new_message": "Envoyer le rapport"}
- "Stats rappels" -> {"action": "stats"}
- "Historique rappels" -> {"action": "stats"}
- "Rappels completes cette semaine" -> {"action": "stats"}
- "Aide" -> {"action": "help"}
- "Que peux-tu faire ?" -> {"action": "help"}

Reponds UNIQUEMENT avec le JSON.
PROMPT;
    }

    private function handleCreate(AgentContext $context, array $parsed): AgentResult
    {
        if (empty($parsed['message']) || empty($parsed['scheduled_at'])) {
            $reply = "J'ai pas bien compris ton rappel. Essaie:\n"
                . "\"Rappelle-moi d'appeler Jean demain a 10h\"";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'reminder_parse_failed']);
        }

        try {
            $scheduledAt = Carbon::parse($parsed['scheduled_at'], AppSetting::timezone());
        } catch (\Exception $e) {
            $reply = "J'ai pas reussi a comprendre la date/heure. Precise un peu plus ?\n"
                . "Exemple: \"demain a 10h\", \"dans 30 minutes\", \"lundi a 9h\"";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'reminder_date_failed']);
        }

        // Warn if date is in the past (only for non-recurring reminders)
        $tz = AppSetting::timezone();
        if (empty($parsed['recurrence']) && $scheduledAt->isPast()) {
            $reply = "La date que tu as donnee est dans le passe ("
                . $scheduledAt->setTimezone($tz)->format('d/m/Y a H:i')
                . ").\nCorrige la date et reessaie.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'reminder_past_date']);
        }

        $scheduledAtUtc = $scheduledAt->copy()->utc();

        // Duplicate detection: warn if identical message exists within 5 minutes of same slot
        $windowStart = $scheduledAtUtc->copy()->subMinutes(5);
        $windowEnd   = $scheduledAtUtc->copy()->addMinutes(5);
        $duplicate = Reminder::where('requester_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->where('status', 'pending')
            ->where('message', $parsed['message'])
            ->whereBetween('scheduled_at', [$windowStart, $windowEnd])
            ->first();

        if ($duplicate) {
            $dupTime = $duplicate->scheduled_at->copy()->setTimezone($tz)->format('d/m/Y a H:i');
            $reply = "Un rappel similaire existe deja :\n"
                . "*{$duplicate->message}* — le {$dupTime}\n\n"
                . "Tape \"mes rappels\" pour voir ta liste.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'reminder_duplicate', 'reminder_id' => $duplicate->id]);
        }

        $reminder = Reminder::create([
            'agent_id'        => $context->agent->id,
            'requester_phone' => $context->from,
            'requester_name'  => $context->senderName,
            'message'         => $parsed['message'],
            'channel'         => 'whatsapp',
            'scheduled_at'    => $scheduledAtUtc,
            'recurrence_rule' => $parsed['recurrence'] ?? null,
            'status'          => 'pending',
        ]);

        $parisTime = $scheduledAtUtc->copy()->setTimezone($tz);
        $recurrenceText = '';
        if (!empty($parsed['recurrence'])) {
            $recurrenceText = "\nRecurrence : " . $this->formatRecurrenceHuman($parsed['recurrence']);
        }
        $reply = "Rappel cree !\n"
            . "*{$parsed['message']}*\n"
            . "Le {$parisTime->format('d/m/Y')} a {$parisTime->format('H:i')}"
            . $recurrenceText;

        $this->sendText($context->from, $reply);

        $this->log($context, 'Reminder created', [
            'reminder_id'  => $reminder->id,
            'message'      => $parsed['message'],
            'scheduled_at' => $scheduledAtUtc->toISOString(),
        ]);

        return AgentResult::reply($reply, ['reminder_id' => $reminder->id]);
    }

    private function handleList(AgentContext $context, $reminders): AgentResult
    {
        if ($reminders->isEmpty()) {
            $reply = "Tu n'as aucun rappel actif pour le moment.\n\n"
                . "Cree un rappel avec:\n"
                . "\"Rappelle-moi de [chose] [quand]\"";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'reminder_list']);
        }

        $reply = $this->formatAgendaView($reminders);
        $this->sendText($context->from, $reply);

        $this->log($context, 'Reminder list viewed', ['count' => $reminders->count()]);

        return AgentResult::reply($reply, ['action' => 'reminder_list', 'count' => $reminders->count()]);
    }

    /**
     * NEW: Show only today's and tomorrow's reminders for a quick daily view.
     */
    private function handleToday(AgentContext $context): AgentResult
    {
        $tz         = AppSetting::timezone();
        $todayStart = now($tz)->startOfDay()->utc();
        $todayEnd   = now($tz)->endOfDay()->utc();

        $todayReminders = Reminder::where('requester_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->where('status', 'pending')
            ->whereBetween('scheduled_at', [$todayStart, $todayEnd])
            ->orderBy('scheduled_at')
            ->get();

        $tomorrowStart = now($tz)->addDay()->startOfDay()->utc();
        $tomorrowEnd   = now($tz)->addDay()->endOfDay()->utc();

        $tomorrowReminders = Reminder::where('requester_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->where('status', 'pending')
            ->whereBetween('scheduled_at', [$tomorrowStart, $tomorrowEnd])
            ->orderBy('scheduled_at')
            ->get();

        if ($todayReminders->isEmpty() && $tomorrowReminders->isEmpty()) {
            $reply = "Aucun rappel prevu aujourd'hui ni demain.\n\n"
                . "Tape \"mes rappels\" pour voir tous tes rappels.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'reminder_today', 'today_count' => 0, 'tomorrow_count' => 0]);
        }

        $lines = [];

        if ($todayReminders->isNotEmpty()) {
            $lines[] = "*Aujourd'hui ({$todayReminders->count()}) :*";
            foreach ($todayReminders as $reminder) {
                $time    = $reminder->scheduled_at->copy()->setTimezone($tz)->format('H:i');
                $recIcon = $reminder->recurrence_rule ? ' [rec]' : '';
                $lines[] = "  {$time} — {$reminder->message}{$recIcon}";
            }
        } else {
            $lines[] = "Aucun rappel aujourd'hui.";
        }

        if ($tomorrowReminders->isNotEmpty()) {
            $lines[] = '';
            $lines[] = "*Demain ({$tomorrowReminders->count()}) :*";
            foreach ($tomorrowReminders as $reminder) {
                $time    = $reminder->scheduled_at->copy()->setTimezone($tz)->format('H:i');
                $recIcon = $reminder->recurrence_rule ? ' [rec]' : '';
                $lines[] = "  {$time} — {$reminder->message}{$recIcon}";
            }
        }

        $reply = implode("\n", $lines);
        $this->sendText($context->from, $reply);

        $this->log($context, 'Reminder today viewed', [
            'today_count'    => $todayReminders->count(),
            'tomorrow_count' => $tomorrowReminders->count(),
        ]);

        return AgentResult::reply($reply, [
            'action'         => 'reminder_today',
            'today_count'    => $todayReminders->count(),
            'tomorrow_count' => $tomorrowReminders->count(),
        ]);
    }

    /**
     * NEW: Show completion statistics and recent history.
     */
    private function handleStats(AgentContext $context): AgentResult
    {
        $tz = AppSetting::timezone();

        $pendingCount = Reminder::where('requester_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->where('status', 'pending')
            ->count();

        $weekStart = now($tz)->startOfWeek(Carbon::MONDAY)->utc();

        $completedThisWeek = Reminder::where('requester_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->where('status', 'sent')
            ->where('sent_at', '>=', $weekStart)
            ->count();

        $completedTotal = Reminder::where('requester_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->where('status', 'sent')
            ->count();

        $cancelledTotal = Reminder::where('requester_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->where('status', 'cancelled')
            ->count();

        // Last 5 completed reminders
        $recentCompleted = Reminder::where('requester_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->where('status', 'sent')
            ->orderByDesc('sent_at')
            ->limit(5)
            ->get();

        $lines   = ["*Statistiques de tes rappels :*\n"];
        $lines[] = "En attente              : {$pendingCount}";
        $lines[] = "Completes cette semaine : {$completedThisWeek}";
        $lines[] = "Completes au total      : {$completedTotal}";
        $lines[] = "Annules au total        : {$cancelledTotal}";

        if ($recentCompleted->isNotEmpty()) {
            $lines[] = "\n*5 derniers completes :*";
            foreach ($recentCompleted as $reminder) {
                $sentAt  = $reminder->sent_at
                    ? $reminder->sent_at->copy()->setTimezone($tz)->format('d/m H:i')
                    : '—';
                $lines[] = "  {$sentAt} — {$reminder->message}";
            }
        }

        $reply = implode("\n", $lines);
        $this->sendText($context->from, $reply);

        $this->log($context, 'Reminder stats viewed', [
            'pending'             => $pendingCount,
            'completed_this_week' => $completedThisWeek,
            'completed_total'     => $completedTotal,
        ]);

        return AgentResult::reply($reply, [
            'action'              => 'reminder_stats',
            'pending'             => $pendingCount,
            'completed_this_week' => $completedThisWeek,
            'completed_total'     => $completedTotal,
        ]);
    }

    private function handleDelete(AgentContext $context, $reminders, array $parsed): AgentResult
    {
        $items = $parsed['items'] ?? [];

        if (empty($items)) {
            $reply = "Quel rappel veux-tu supprimer ? Donne-moi le numero.\nEx: \"Supprime le rappel 2\"";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'reminder_delete_no_items']);
        }

        $deleted  = [];
        $notFound = [];
        $values   = $reminders->values();

        foreach ($items as $num) {
            $index    = (int) $num - 1;
            $reminder = $values[$index] ?? null;
            if ($reminder) {
                $deleted[] = $reminder->message;
                $reminder->update(['status' => 'cancelled']);
            } else {
                $notFound[] = $num;
            }
        }

        if (empty($deleted)) {
            $reply = "Aucun rappel trouve avec ce(s) numero(s). Tape \"mes rappels\" pour voir la liste.";
        } else {
            $reply = count($deleted) === 1
                ? "Rappel supprime :\n- {$deleted[0]}"
                : "Rappels supprimes :\n" . implode("\n", array_map(fn($m) => "- {$m}", $deleted));

            if (!empty($notFound)) {
                $reply .= "\n\nNumero(s) introuvable(s) : " . implode(', ', $notFound);
            }
        }

        $this->sendText($context->from, $reply);
        $this->log($context, 'Reminder deleted', ['items' => $items, 'deleted' => $deleted]);

        return AgentResult::reply($reply, ['action' => 'reminder_delete', 'deleted_count' => count($deleted)]);
    }

    private function handlePostpone(AgentContext $context, $reminders, array $parsed): AgentResult
    {
        $item    = $parsed['item'] ?? null;
        $newTime = $parsed['new_time'] ?? null;

        if (!$item || !$newTime) {
            $reply = "Quel rappel veux-tu reporter et a quand ?\nEx: \"Reporte le rappel 1 a demain 10h\"";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'reminder_postpone_missing']);
        }

        $index    = (int) $item - 1;
        $reminder = $reminders->values()[$index] ?? null;

        if (!$reminder) {
            $reply = "Rappel #{$item} introuvable. Tape \"mes rappels\" pour voir la liste.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'reminder_postpone_not_found']);
        }

        $newScheduledAt = $this->parseNewTime($newTime, $reminder->scheduled_at);

        if (!$newScheduledAt) {
            $reply = "J'ai pas compris la nouvelle heure. Essaie:\n"
                . "- \"demain 10:00\"\n"
                . "- \"+1h\", \"+30min\", \"+2j\", \"+1sem\"\n"
                . "- \"lundi prochain\", \"vendredi prochain\"\n"
                . "- \"semaine prochaine\"\n"
                . "- \"2026-03-10 14:00\"";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'reminder_postpone_parse_failed']);
        }

        // Guard against reporting in the past
        if ($newScheduledAt->isPast()) {
            $tz = AppSetting::timezone();
            $reply = "La nouvelle date est dans le passe ("
                . $newScheduledAt->copy()->setTimezone($tz)->format('d/m/Y a H:i')
                . ").\nChoisis une date future.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'reminder_postpone_past_date']);
        }

        $tz      = AppSetting::timezone();
        $oldTime = $reminder->scheduled_at->copy()->setTimezone($tz)->format('d/m a H:i');
        $reminder->update(['scheduled_at' => $newScheduledAt->utc()]);

        $parisTime = $newScheduledAt->copy()->setTimezone($tz);
        $reply     = "Rappel reporte !\n"
            . "*{$reminder->message}*\n"
            . "Avant : {$oldTime}\n"
            . "Nouveau : le {$parisTime->format('d/m/Y')} a {$parisTime->format('H:i')}";

        $this->sendText($context->from, $reply);
        $this->log($context, 'Reminder postponed', [
            'reminder_id'      => $reminder->id,
            'new_scheduled_at' => $newScheduledAt->toISOString(),
        ]);

        return AgentResult::reply($reply, ['action' => 'reminder_postpone']);
    }

    private function handleComplete(AgentContext $context, $reminders, array $parsed): AgentResult
    {
        $items = $parsed['items'] ?? [];

        if (empty($items)) {
            $reply = "Quel rappel veux-tu marquer comme fait ? Donne-moi le numero.\nEx: \"Marque le rappel 1 comme fait\"";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'reminder_complete_no_items']);
        }

        $completed = [];
        $notFound  = [];
        $values    = $reminders->values();

        foreach ($items as $num) {
            $index    = (int) $num - 1;
            $reminder = $values[$index] ?? null;
            if ($reminder) {
                $completed[] = $reminder->message;
                $reminder->update(['status' => 'sent', 'sent_at' => now()]);
            } else {
                $notFound[] = $num;
            }
        }

        if (empty($completed)) {
            $reply = "Aucun rappel trouve avec ce(s) numero(s). Tape \"mes rappels\" pour voir la liste.";
        } else {
            $reply = count($completed) === 1
                ? "Rappel marque comme fait :\n- {$completed[0]}"
                : "Rappels marques comme faits :\n" . implode("\n", array_map(fn($m) => "- {$m}", $completed));

            if (!empty($notFound)) {
                $reply .= "\n\nNumero(s) introuvable(s) : " . implode(', ', $notFound);
            }
        }

        $this->sendText($context->from, $reply);
        $this->log($context, 'Reminder completed', ['items' => $items, 'completed' => $completed]);

        return AgentResult::reply($reply, ['action' => 'reminder_complete', 'completed_count' => count($completed)]);
    }

    private function handleSearch(AgentContext $context, array $parsed): AgentResult
    {
        $query = trim($parsed['query'] ?? '');

        if (empty($query)) {
            $reply = "Que veux-tu chercher dans tes rappels ?\nEx: \"Cherche rappels jean\"";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'reminder_search_no_query']);
        }

        $reminders = Reminder::where('requester_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->where('status', 'pending')
            ->where('message', 'like', '%' . $query . '%')
            ->orderBy('scheduled_at')
            ->limit(self::MAX_REMINDERS)
            ->get();

        if ($reminders->isEmpty()) {
            $reply = "Aucun rappel actif avec le mot-cle \"{$query}\".\n"
                . "Tape \"mes rappels\" pour voir tous tes rappels actifs.\n"
                . "Tape \"stats rappels\" pour voir l'historique.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'reminder_search', 'count' => 0]);
        }

        $tz    = AppSetting::timezone();
        $lines = ["*Rappels contenant \"{$query}\" ({$reminders->count()}) :*"];

        foreach ($reminders as $i => $reminder) {
            $parisTime = $reminder->scheduled_at->copy()->setTimezone($tz);
            $recText   = $reminder->recurrence_rule
                ? ' (' . $this->formatRecurrenceHuman($reminder->recurrence_rule) . ')'
                : '';
            $lines[] = ($i + 1) . ". {$parisTime->format('d/m H:i')} — {$reminder->message}{$recText}";
        }

        $reply = implode("\n", $lines);
        $this->sendText($context->from, $reply);

        $this->log($context, 'Reminder search', ['query' => $query, 'count' => $reminders->count()]);

        return AgentResult::reply($reply, ['action' => 'reminder_search', 'count' => $reminders->count()]);
    }

    private function handleEdit(AgentContext $context, $reminders, array $parsed): AgentResult
    {
        $item       = $parsed['item'] ?? null;
        $newMessage = trim($parsed['new_message'] ?? '');

        if (!$item || empty($newMessage)) {
            $reply = "Precise le numero du rappel et le nouveau texte.\n"
                . "Ex: \"Modifie le rappel 1 : Appeler Marie\"";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'reminder_edit_missing']);
        }

        $index    = (int) $item - 1;
        $reminder = $reminders->values()[$index] ?? null;

        if (!$reminder) {
            $reply = "Rappel #{$item} introuvable. Tape \"mes rappels\" pour voir la liste.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'reminder_edit_not_found']);
        }

        $oldMessage = $reminder->message;
        $reminder->update(['message' => $newMessage]);

        $tz        = AppSetting::timezone();
        $parisTime = $reminder->scheduled_at->copy()->setTimezone($tz);
        $reply     = "Rappel modifie !\n"
            . "Avant : {$oldMessage}\n"
            . "Apres : *{$newMessage}*\n"
            . "Prevu le {$parisTime->format('d/m/Y')} a {$parisTime->format('H:i')}";

        $this->sendText($context->from, $reply);
        $this->log($context, 'Reminder edited', [
            'reminder_id' => $reminder->id,
            'old_message' => $oldMessage,
            'new_message' => $newMessage,
        ]);

        return AgentResult::reply($reply, ['action' => 'reminder_edit', 'reminder_id' => $reminder->id]);
    }

    private function handleHelp(AgentContext $context): AgentResult
    {
        $reply = "*Gestion des rappels — Commandes disponibles :*\n\n"
            . "*Creer un rappel :*\n"
            . "\"Rappelle-moi d'[action] [quand]\"\n"
            . "\"Rappelle-moi en semaine a 8h de prendre mes vitamines\"\n\n"
            . "*Voir les rappels :*\n"
            . "\"Mes rappels\" ou \"Mon agenda\" (liste complete)\n"
            . "\"Rappels d'aujourd'hui\" ou \"Rappels du jour\"\n\n"
            . "*Statistiques & historique :*\n"
            . "\"Stats rappels\" ou \"Historique rappels\"\n\n"
            . "*Chercher des rappels :*\n"
            . "\"Cherche rappels jean\"\n"
            . "\"Trouve mes rappels avec vitamines\"\n\n"
            . "*Modifier un rappel :*\n"
            . "\"Modifie le rappel 1 : Appeler Marie\"\n\n"
            . "*Reporter un rappel :*\n"
            . "\"Reporte le rappel 1 a demain 10h\"\n"
            . "\"Reporte le rappel 2 de +1h\" ou \"+1sem\"\n"
            . "\"Reporte le rappel 1 a lundi prochain\"\n\n"
            . "*Marquer comme fait :*\n"
            . "\"Marque le rappel 1 comme fait\"\n"
            . "\"C'est fait pour le rappel 2\"\n\n"
            . "*Supprimer un rappel :*\n"
            . "\"Supprime le rappel 2\"\n"
            . "\"Supprime les rappels 1 et 3\"\n\n"
            . "*Recurrences supportees :*\n"
            . "- Chaque jour : \"tous les jours a 7h\"\n"
            . "- En semaine : \"en semaine a 8h\"\n"
            . "- Chaque semaine : \"chaque lundi a 9h\"\n"
            . "- Chaque mois : \"le 1er de chaque mois a 10h\"";

        $this->sendText($context->from, $reply);
        return AgentResult::reply($reply, ['action' => 'reminder_help']);
    }

    private function handleUnknownAction(AgentContext $context, string $action): AgentResult
    {
        $reply = "Action non reconnue. Tape \"aide\" pour voir les commandes disponibles.";
        $this->sendText($context->from, $reply);
        $this->log($context, 'Unknown action', ['action' => $action], 'warning');
        return AgentResult::reply($reply, ['action' => 'reminder_unknown_action']);
    }

    private function parseNewTime(string $expr, Carbon $currentScheduledAt): ?Carbon
    {
        $expr = trim($expr);
        $tz   = AppSetting::timezone();
        $now  = now($tz);

        // Relative: +Xmin, +Xh, +Xj, +Xsem/w (also handles spaces: "+ 2 j")
        if (preg_match('/^\+\s*(\d+)\s*(min|minutes?|h|heures?|j|jours?|sem|semaines?|w|weeks?)$/i', $expr, $m)) {
            $amount = (int) $m[1];
            $unit   = strtolower($m[2]);
            return match (true) {
                str_starts_with($unit, 'min')  => $now->copy()->addMinutes($amount),
                str_starts_with($unit, 'h')    => $now->copy()->addHours($amount),
                str_starts_with($unit, 'j')    => $now->copy()->addDays($amount),
                str_starts_with($unit, 'sem')  => $now->copy()->addWeeks($amount),
                str_starts_with($unit, 'w')    => $now->copy()->addWeeks($amount),
                default                        => null,
            };
        }

        // "demain" with optional time (handles "10h", "10:30", "10h30")
        if (preg_match('/^demain\s*(?:a\s*|@\s*)?(\d{1,2})[h:]\s*(\d{2})?$/i', $expr, $m)) {
            $h   = (int) $m[1];
            $min = isset($m[2]) && $m[2] !== '' ? (int) $m[2] : 0;
            return $now->copy()->addDay()->setTime($h, $min, 0);
        }

        // "demain" alone — keep same time
        if (strtolower($expr) === 'demain') {
            $currentLocal = $currentScheduledAt->copy()->setTimezone($tz);
            return $now->copy()->addDay()->setTime($currentLocal->hour, $currentLocal->minute, 0);
        }

        // "lundi prochain", "mardi prochain", etc. — keep same time as current reminder
        $dayNames = [
            'lundi'     => 'monday',
            'mardi'     => 'tuesday',
            'mercredi'  => 'wednesday',
            'jeudi'     => 'thursday',
            'vendredi'  => 'friday',
            'samedi'    => 'saturday',
            'dimanche'  => 'sunday',
            'monday'    => 'monday',
            'tuesday'   => 'tuesday',
            'wednesday' => 'wednesday',
            'thursday'  => 'thursday',
            'friday'    => 'friday',
            'saturday'  => 'saturday',
            'sunday'    => 'sunday',
        ];

        if (preg_match('/^(\w+)\s+prochain$/i', $expr, $m)) {
            $dayKey = strtolower($m[1]);
            if (isset($dayNames[$dayKey])) {
                $currentLocal = $currentScheduledAt->copy()->setTimezone($tz);
                $next         = $now->copy()->next($dayNames[$dayKey]);
                return $next->setTime($currentLocal->hour, $currentLocal->minute, 0);
            }
        }

        // "semaine prochaine" — add 7 days, keep same time
        if (preg_match('/^semaine\s+prochaine$/i', $expr)) {
            $currentLocal = $currentScheduledAt->copy()->setTimezone($tz);
            return $now->copy()->addWeek()->setTime($currentLocal->hour, $currentLocal->minute, 0);
        }

        // Absolute YYYY-MM-DD HH:MM
        if (preg_match('/^\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}$/', $expr)) {
            try {
                return Carbon::parse($expr, $tz);
            } catch (\Exception $e) {
                return null;
            }
        }

        return null;
    }

    /**
     * Format reminders as an agenda view grouped by day
     */
    private function formatAgendaView($reminders): string
    {
        $tz      = AppSetting::timezone();
        $today   = now($tz)->startOfDay();
        $grouped = [];

        foreach ($reminders as $i => $reminder) {
            $parisTime = $reminder->scheduled_at->copy()->setTimezone($tz);
            $dayKey    = $parisTime->format('Y-m-d');

            if (!isset($grouped[$dayKey])) {
                $grouped[$dayKey] = [
                    'label' => $this->getDayLabel($parisTime, $today),
                    'items' => [],
                ];
            }

            $num     = $i + 1;
            $recIcon = $reminder->recurrence_rule ? ' [rec]' : '';
            $recText = $reminder->recurrence_rule
                ? ' (' . $this->formatRecurrenceHuman($reminder->recurrence_rule) . ')'
                : '';

            $grouped[$dayKey]['items'][] = "  {$num}. {$parisTime->format('H:i')} — {$reminder->message}{$recIcon}{$recText}";
        }

        $total = $reminders->count();
        $lines = ["*Tes rappels ({$total}) :*"];

        foreach ($grouped as $day) {
            $lines[] = "\n{$day['label']} :";
            foreach ($day['items'] as $item) {
                $lines[] = $item;
            }
        }

        $lines[] = "\nTape \"aide\" pour voir les commandes.";

        return implode("\n", $lines);
    }

    private function getDayLabel(Carbon $date, Carbon $today): string
    {
        $diff = (int) $today->diffInDays($date->copy()->startOfDay(), false);

        if ($diff === 0) return "Aujourd'hui";
        if ($diff === 1) return 'Demain';

        $days = [
            'Monday'    => 'Lundi',
            'Tuesday'   => 'Mardi',
            'Wednesday' => 'Mercredi',
            'Thursday'  => 'Jeudi',
            'Friday'    => 'Vendredi',
            'Saturday'  => 'Samedi',
            'Sunday'    => 'Dimanche',
        ];

        $dayName = $days[$date->format('l')] ?? $date->format('l');

        // Within the same week: use day name only
        if ($diff >= 2 && $diff <= 6) {
            return $dayName;
        }

        // Further out: full date
        return "{$dayName} {$date->format('d/m')}";
    }

    private function formatRecurrenceHuman(string $rule): string
    {
        $parts = explode(':', $rule);
        $type  = $parts[0] ?? '';

        return match ($type) {
            'daily'    => 'chaque jour a ' . ($parts[1] ?? '08:00'),
            'weekdays' => 'en semaine a ' . ($parts[1] ?? '08:00'),
            'weekly'   => 'chaque ' . $this->translateDay($parts[1] ?? '') . ' a ' . ($parts[2] ?? '09:00'),
            'monthly'  => 'le ' . ($parts[1] ?? '1') . ' de chaque mois a ' . ($parts[2] ?? '09:00'),
            default    => $rule,
        };
    }

    private function translateDay(string $day): string
    {
        return match (strtolower($day)) {
            'monday'    => 'lundi',
            'tuesday'   => 'mardi',
            'wednesday' => 'mercredi',
            'thursday'  => 'jeudi',
            'friday'    => 'vendredi',
            'saturday'  => 'samedi',
            'sunday'    => 'dimanche',
            default     => $day,
        };
    }

    /**
     * Format the reminder list for injection into the LLM prompt (capped)
     */
    private function formatReminderList($reminders): string
    {
        if ($reminders->isEmpty()) {
            return "(aucun rappel actif)";
        }

        $tz    = AppSetting::timezone();
        $lines = [];

        foreach ($reminders->values() as $i => $reminder) {
            $num       = $i + 1;
            $parisTime = $reminder->scheduled_at->copy()->setTimezone($tz);
            $recText   = $reminder->recurrence_rule ? " [recurrent: {$reminder->recurrence_rule}]" : '';
            $lines[]   = "#{$num} {$parisTime->format('Y-m-d H:i')} — {$reminder->message}{$recText}";
        }

        return implode("\n", $lines);
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

        $decoded = json_decode($clean, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            Log::warning('[ReminderAgent] JSON parse failed', [
                'error' => json_last_error_msg(),
                'raw'   => mb_substr($clean, 0, 300),
            ]);
            return null;
        }

        return $decoded;
    }
}
