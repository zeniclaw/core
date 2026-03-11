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
        return 'Agent de gestion des rappels et alarmes. Permet de creer, lister, chercher, modifier (message et/ou heure), dupliquer, completer, supprimer et reporter des rappels ponctuels ou recurrents. Gere aussi le prochain rappel, l\'agenda du jour, la vue semaine (7 jours), les rappels en retard (vue dediee), les statistiques et l\'archivage.';
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
            'prochains rappels', 'prochain rappel', 'prochain', 'next reminder',
            'supprimer rappel', 'supprime rappel', 'delete reminder', 'annuler rappel',
            'reporter rappel', 'reporte rappel', 'postpone', 'snooze', 'repousser',
            'recurrent', 'recurrence', 'periodique',
            'n\'oublie pas', 'noublie pas', 'pense a', 'faut que je',
            'marquer fait', 'marque fait', 'complete rappel', 'done reminder', 'c\'est fait',
            'aide rappel', 'help reminder', 'aide rappels',
            'cherche rappel', 'chercher rappel', 'trouve rappel', 'search reminder',
            'modifie rappel', 'modifier rappel', 'edit reminder', 'change rappel',
            'change heure rappel', 'change la date rappel', 'modifier heure rappel',
            'lundi prochain', 'semaine prochaine', 'mois prochain',
            'stats rappels', 'statistiques rappels', 'historique rappels', 'rappels completes',
            'bilan rappels',
            'semaine', 'cette semaine', 'rappels semaine', 'rappels de la semaine', 'vue semaine',
            'rappels 7 jours', 'planning semaine', 'week reminder', 'week view',
            'rappels en retard', 'rappels passes', 'retard',
            'nettoyer rappels', 'vider historique', 'supprimer historique', 'clear reminders', 'archive',
            'duplique rappel', 'dupliquer rappel', 'copie rappel', 'copier rappel', 'clone rappel', 'duplicate reminder',
            'mes retards', 'voir retards', 'rappels retard', 'liste retards',
            'tous les retards', 'bulk', 'tout marquer fait', 'effacer tous les retards',
            'planning mois', 'rappels mois', 'ce mois', 'mois prochain', '30 jours',
            'planning mensuel', 'vue mensuelle', 'month view',
        ];
    }

    public function version(): string
    {
        return '1.7.0';
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
                . "\"Prochain rappel\"\n"
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
            'week'     => $this->handleWeek($context),
            'next'     => $this->handleNext($context),
            'overdue'  => $this->handleOverdue($context),
            'copy'     => $this->handleCopy($context, $reminders, $parsed),
            'delete'   => $this->handleDelete($context, $reminders, $parsed),
            'postpone' => $this->handlePostpone($context, $reminders, $parsed),
            'complete' => $this->handleComplete($context, $reminders, $parsed),
            'search'   => $this->handleSearch($context, $parsed),
            'edit'     => $this->handleEdit($context, $reminders, $parsed),
            'stats'    => $this->handleStats($context),
            'clear'    => $this->handleClear($context),
            'help'     => $this->handleHelp($context),
            'bulk'     => $this->handleBulk($context, $parsed),
            'month'    => $this->handleMonth($context),
            default    => $this->handleUnknownAction($context, $action),
        };
    }

    // ── ToolProviderInterface ──────────────────────────────────────────

    public function tools(): array
    {
        $tz = AppSetting::timezone();
        return array_merge(parent::tools(), [
            [
                'name' => 'create_reminder',
                'description' => 'Create a reminder/alarm for the user. Use this when the user asks to be reminded of something at a specific time.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'message' => ['type' => 'string', 'description' => 'Short description of what to remind (e.g. "Appeler Jean")'],
                        'scheduled_at' => ['type' => 'string', 'description' => "When to trigger, format YYYY-MM-DD HH:MM ({$tz} timezone)"],
                        'recurrence' => ['type' => 'string', 'description' => 'Recurrence rule or null. Formats: "daily:HH:MM", "weekly:DAYNAME:HH:MM", "monthly:DAY:HH:MM", "weekdays:HH:MM"'],
                    ],
                    'required' => ['message', 'scheduled_at'],
                ],
            ],
            [
                'name' => 'list_reminders',
                'description' => 'List all active/pending reminders for the user.',
                'input_schema' => ['type' => 'object', 'properties' => (object) []],
            ],
            [
                'name' => 'delete_reminder',
                'description' => 'Delete/cancel one or more reminders by their position numbers (1-based).',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'items' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'List of reminder position numbers to delete (1-based)'],
                    ],
                    'required' => ['items'],
                ],
            ],
            [
                'name' => 'postpone_reminder',
                'description' => 'Postpone/reschedule a reminder to a new time.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'item' => ['type' => 'integer', 'description' => 'Position number of the reminder to postpone (1-based)'],
                        'new_time' => ['type' => 'string', 'description' => 'New time. Formats: "YYYY-MM-DD HH:MM", "demain HH:MM", "+Xmin", "+Xh", "+Xj"'],
                    ],
                    'required' => ['item', 'new_time'],
                ],
            ],
        ]);
    }

    public function executeTool(string $name, array $input, AgentContext $context): ?string
    {
        return match ($name) {
            'create_reminder' => $this->toolCreateReminder($input, $context),
            'list_reminders' => $this->toolListReminders($context),
            'delete_reminder' => $this->toolDeleteReminder($input, $context),
            'postpone_reminder' => $this->toolPostponeReminder($input, $context),
            default => parent::executeTool($name, $input, $context),
        };
    }

    private function toolCreateReminder(array $input, AgentContext $context): string
    {
        $message = $input['message'];
        $scheduledAt = Carbon::parse($input['scheduled_at'], AppSetting::timezone())->utc();
        $recurrence = $input['recurrence'] ?? null;

        $reminder = Reminder::create([
            'agent_id' => $context->agent->id,
            'requester_phone' => $context->from,
            'requester_name' => $context->senderName,
            'message' => $message,
            'channel' => 'whatsapp',
            'scheduled_at' => $scheduledAt,
            'recurrence_rule' => $recurrence,
            'status' => 'pending',
        ]);

        $parisTime = $scheduledAt->copy()->setTimezone(AppSetting::timezone());

        return json_encode([
            'success' => true,
            'reminder_id' => $reminder->id,
            'message' => $message,
            'scheduled_at_paris' => $parisTime->format('d/m/Y H:i'),
            'recurrence' => $recurrence,
        ]);
    }

    private function toolListReminders(AgentContext $context): string
    {
        $reminders = Reminder::where('requester_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->where('status', 'pending')
            ->orderBy('scheduled_at')
            ->get();

        if ($reminders->isEmpty()) {
            return json_encode(['reminders' => [], 'message' => 'Aucun rappel actif.']);
        }

        $list = [];
        foreach ($reminders->values() as $i => $r) {
            $parisTime = $r->scheduled_at->copy()->setTimezone(AppSetting::timezone());
            $list[] = [
                'number' => $i + 1,
                'message' => $r->message,
                'scheduled_at' => $parisTime->format('d/m/Y H:i'),
                'recurrence' => $r->recurrence_rule,
            ];
        }

        return json_encode(['reminders' => $list]);
    }

    private function toolDeleteReminder(array $input, AgentContext $context): string
    {
        $reminders = Reminder::where('requester_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->where('status', 'pending')
            ->orderBy('scheduled_at')
            ->get()
            ->values();

        $deleted = [];
        foreach ($input['items'] as $num) {
            $index = (int) $num - 1;
            $reminder = $reminders[$index] ?? null;
            if ($reminder) {
                $deleted[] = $reminder->message;
                $reminder->update(['status' => 'cancelled']);
            }
        }

        return json_encode(['deleted' => $deleted, 'count' => count($deleted)]);
    }

    private function toolPostponeReminder(array $input, AgentContext $context): string
    {
        $reminders = Reminder::where('requester_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->where('status', 'pending')
            ->orderBy('scheduled_at')
            ->get()
            ->values();

        $index = (int) $input['item'] - 1;
        $reminder = $reminders[$index] ?? null;

        if (!$reminder) {
            return json_encode(['error' => "Reminder #{$input['item']} not found."]);
        }

        $newScheduledAt = $this->parseNewTime($input['new_time'], $reminder->scheduled_at);
        if (!$newScheduledAt) {
            return json_encode(['error' => 'Could not parse the new time.']);
        }

        $reminder->update(['scheduled_at' => $newScheduledAt->utc()]);
        $parisTime = $newScheduledAt->copy()->setTimezone(AppSetting::timezone());

        return json_encode([
            'success' => true,
            'message' => $reminder->message,
            'new_scheduled_at' => $parisTime->format('d/m/Y H:i'),
        ]);
    }

    // ── End ToolProviderInterface ────────────────────────────────────────

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

4. PROCHAIN RAPPEL (le tout prochain, vue rapide):
{"action": "next"}

5. SUPPRIMER des rappels:
{"action": "delete", "items": [1, 2]}

6. REPORTER un rappel:
{"action": "postpone", "item": 1, "new_time": "expression temporelle"}

7. MARQUER comme COMPLETE (fait) un rappel:
{"action": "complete", "items": [1]}

8. CHERCHER des rappels par mot-cle:
{"action": "search", "query": "mot-cle"}

9. MODIFIER un rappel (message et/ou heure):
{"action": "edit", "item": 1, "new_message": "nouveau texte" | null, "new_time": "expression temporelle" | null}

10. STATISTIQUES des rappels:
{"action": "stats"}

11. VUE SEMAINE (les 7 prochains jours):
{"action": "week"}

12. VIDER l'historique des rappels termines/annules:
{"action": "clear"}

13. AIDE:
{"action": "help"}

14. RAPPELS EN RETARD (vue dediee de tous les rappels passes non traites):
{"action": "overdue"}

15. DUPLIQUER un rappel (creer une copie a un nouvel horaire):
{"action": "copy", "item": 1, "new_time": "expression temporelle ou null"}

16. ACTION EN MASSE sur les retards (marquer, supprimer ou reporter TOUS les retards d'un coup):
{"action": "bulk", "operation": "complete|delete|postpone", "target": "overdue", "new_time": "expression temporelle ou null"}

17. VUE MENSUELLE (les 30 prochains jours, groupes par semaine):
{"action": "month"}

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

REGLES POUR "next":
- Quand l'utilisateur demande son PROCHAIN rappel, le rappel suivant, "c'est quoi mon prochain rappel"
- Vue ultra-rapide : affiche un seul rappel

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
  - "HH:MM" (meme jour a cette heure, ou demain si l'heure est passee)

REGLES POUR "complete":
- items = liste des numeros de rappels a marquer comme faits (integers, base 1)
- Utilise pour: "c'est fait", "marque fait", "done", "j'ai fait le rappel X", "c'est regle"

REGLES POUR "search":
- query = mot-cle ou expression a chercher dans les messages des rappels
- Utilise pour: "cherche rappel X", "trouve mes rappels avec Y", "rappels jean", "search reminder"

REGLES POUR "edit":
- item = numero du rappel a modifier (integer, base 1)
- new_message = nouveau texte du rappel (si l'utilisateur change le texte), sinon null
- new_time = nouvelle date/heure (si l'utilisateur change quand), sinon null. Memes formats que "postpone"
- Au moins un des deux (new_message ou new_time) doit etre fourni
- Utilise pour: "modifie le rappel 1", "change le rappel 2 en...", "renomme le rappel", "change l'heure du rappel", "deplace le rappel a"

REGLES POUR "stats":
- Quand l'utilisateur demande des stats, statistiques, historique, bilan, rappels completes, rappels annules

REGLES POUR "week":
- Quand l'utilisateur demande ses rappels de la semaine, les 7 prochains jours, "cette semaine", "planning semaine", "vue hebdomadaire"

REGLES POUR "clear":
- Quand l'utilisateur veut nettoyer/vider son historique de rappels termines ou annules
- Utilise pour: "nettoie mes rappels", "vide l'historique", "supprime l'historique", "archive", "clear"

REGLES POUR "help":
- Quand l'utilisateur demande de l'aide, les commandes disponibles, "aide", "help", "que peux-tu faire"

REGLES POUR "overdue":
- Quand l'utilisateur veut voir SPECIFIQUEMENT ses rappels en retard / passes / non traites
- Utilise pour: "mes retards", "rappels en retard", "rappels passes", "voir mes retards", "liste retards"
- DIFFERENT de "today" qui montre aujourd'hui + demain + retards en meme temps

REGLES POUR "copy":
- item = numero du rappel a dupliquer (integer, base 1)
- new_time = nouvel horaire pour la copie (memes formats que "postpone"). null = demain meme heure
- Utilise pour: "duplique le rappel 1", "copie le rappel 2 pour vendredi", "clone le rappel 3 a 15h"
- La copie n'herite PAS de la recurrence, c'est un rappel unique

REGLES POUR "bulk":
- operation = "complete" (marquer faits), "delete" (supprimer), "postpone" (reporter)
- target = "overdue" (tous les rappels en retard) — seule valeur supportee pour l'instant
- new_time = expression temporelle (obligatoire si operation="postpone"). Memes formats que "postpone"
- Utilise pour: "marque tous les retards comme faits", "efface tous mes retards", "reporter tous les retards de +1j", "vider tous les retards"

REGLES POUR "month":
- Quand l'utilisateur demande son planning du mois, les rappels des 30 prochains jours, la vue mensuelle
- Utilise pour: "planning du mois", "rappels de ce mois", "mes 30 prochains jours", "vue mensuelle"

EXEMPLES (basees sur la date actuelle en reference):
- "Rappelle-moi d'appeler Jean demain a 10h" -> {"action": "create", "message": "Appeler Jean", "scheduled_at": "YYYY-MM-DD 10:00", "recurrence": null}
- "Mes rappels" -> {"action": "list"}
- "Mon agenda complet" -> {"action": "list"}
- "Mes rappels d'aujourd'hui" -> {"action": "today"}
- "Rappels du jour" -> {"action": "today"}
- "Prochains rappels" -> {"action": "today"}
- "Rappels de demain" -> {"action": "today"}
- "Quel est mon prochain rappel ?" -> {"action": "next"}
- "C'est quoi le prochain ?" -> {"action": "next"}
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
- "Modifie le rappel 1 : Appeler Marie" -> {"action": "edit", "item": 1, "new_message": "Appeler Marie", "new_time": null}
- "Change le rappel 2 en 'Envoyer le rapport'" -> {"action": "edit", "item": 2, "new_message": "Envoyer le rapport", "new_time": null}
- "Change l'heure du rappel 1 a 15h" -> {"action": "edit", "item": 1, "new_message": null, "new_time": "15:00"}
- "Deplace le rappel 3 a demain 9h" -> {"action": "edit", "item": 3, "new_message": null, "new_time": "demain 09:00"}
- "Modifie le rappel 2 texte Appeler Marc et heure a 16h" -> {"action": "edit", "item": 2, "new_message": "Appeler Marc", "new_time": "16:00"}
- "Stats rappels" -> {"action": "stats"}
- "Historique rappels" -> {"action": "stats"}
- "Rappels completes cette semaine" -> {"action": "stats"}
- "Rappels de la semaine" -> {"action": "week"}
- "Planning semaine" -> {"action": "week"}
- "Cette semaine" -> {"action": "week"}
- "Mes 7 prochains jours" -> {"action": "week"}
- "Nettoie mes rappels" -> {"action": "clear"}
- "Vide l'historique de mes rappels" -> {"action": "clear"}
- "Aide" -> {"action": "help"}
- "Que peux-tu faire ?" -> {"action": "help"}
- "Rappels en retard" -> {"action": "overdue"}
- "Mes retards" -> {"action": "overdue"}
- "Voir mes rappels passes" -> {"action": "overdue"}
- "Duplique le rappel 1" -> {"action": "copy", "item": 1, "new_time": null}
- "Copie le rappel 2 pour demain 15h" -> {"action": "copy", "item": 2, "new_time": "demain 15:00"}
- "Clone le rappel 1 a vendredi" -> {"action": "copy", "item": 1, "new_time": "vendredi prochain"}
- "Marque tous les retards comme faits" -> {"action": "bulk", "operation": "complete", "target": "overdue", "new_time": null}
- "Supprime tous mes retards" -> {"action": "bulk", "operation": "delete", "target": "overdue", "new_time": null}
- "Reporte tous les retards de 1 jour" -> {"action": "bulk", "operation": "postpone", "target": "overdue", "new_time": "+1j"}
- "Planning du mois" -> {"action": "month"}
- "Rappels du mois" -> {"action": "month"}
- "Mes 30 prochains jours" -> {"action": "month"}

NOTE: Les rappels marques [RETARD] dans la liste sont des rappels dont l'heure est deja passee.

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

        if (mb_strlen($parsed['message']) > 200) {
            $reply = "Le texte du rappel est trop long (max 200 caracteres). Raccourcis-le un peu.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'reminder_message_too_long']);
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
     * Show overdue, today's, and tomorrow's reminders for a quick daily view.
     */
    private function handleToday(AgentContext $context): AgentResult
    {
        $tz         = AppSetting::timezone();
        $now        = now($tz);
        $todayStart = $now->copy()->startOfDay()->utc();
        $todayEnd   = $now->copy()->endOfDay()->utc();

        // Overdue: past-due but still pending (before today started)
        $overdueReminders = Reminder::where('requester_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->where('status', 'pending')
            ->where('scheduled_at', '<', $todayStart)
            ->orderBy('scheduled_at')
            ->get();

        $todayReminders = Reminder::where('requester_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->where('status', 'pending')
            ->whereBetween('scheduled_at', [$todayStart, $todayEnd])
            ->orderBy('scheduled_at')
            ->get();

        $tomorrowStart = $now->copy()->addDay()->startOfDay()->utc();
        $tomorrowEnd   = $now->copy()->addDay()->endOfDay()->utc();

        $tomorrowReminders = Reminder::where('requester_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->where('status', 'pending')
            ->whereBetween('scheduled_at', [$tomorrowStart, $tomorrowEnd])
            ->orderBy('scheduled_at')
            ->get();

        if ($overdueReminders->isEmpty() && $todayReminders->isEmpty() && $tomorrowReminders->isEmpty()) {
            $reply = "Aucun rappel prevu aujourd'hui ni demain.\n\n"
                . "Tape \"mes rappels\" pour voir tous tes rappels.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, [
                'action'         => 'reminder_today',
                'overdue_count'  => 0,
                'today_count'    => 0,
                'tomorrow_count' => 0,
            ]);
        }

        // Build global numbering so action commands (delete, postpone…) use correct numbers
        $allPending = Reminder::where('requester_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->where('status', 'pending')
            ->orderBy('scheduled_at')
            ->limit(self::MAX_REMINDERS)
            ->get()
            ->values();

        $idToNum = [];
        foreach ($allPending as $i => $r) {
            $idToNum[$r->id] = $i + 1;
        }

        $lines = [];

        // Overdue section
        if ($overdueReminders->isNotEmpty()) {
            $lines[] = "*En retard ({$overdueReminders->count()}) :* ⚠️";
            foreach ($overdueReminders as $reminder) {
                $parisTime = $reminder->scheduled_at->copy()->setTimezone($tz);
                $dateStr   = $parisTime->format('d/m H:i');
                $recIcon   = $reminder->recurrence_rule ? ' [rec]' : '';
                $num       = $idToNum[$reminder->id] ?? '?';
                $lines[]   = "  #{$num} {$dateStr} — {$reminder->message}{$recIcon}";
            }
            $lines[] = '';
        }

        if ($todayReminders->isNotEmpty()) {
            $lines[] = "*Aujourd'hui ({$todayReminders->count()}) :*";
            foreach ($todayReminders as $reminder) {
                $time    = $reminder->scheduled_at->copy()->setTimezone($tz)->format('H:i');
                $recIcon = $reminder->recurrence_rule ? ' [rec]' : '';
                $num     = $idToNum[$reminder->id] ?? '?';
                $lines[] = "  #{$num} {$time} — {$reminder->message}{$recIcon}";
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
                $num     = $idToNum[$reminder->id] ?? '?';
                $lines[] = "  #{$num} {$time} — {$reminder->message}{$recIcon}";
            }
        }

        $reply = implode("\n", $lines);
        $this->sendText($context->from, $reply);

        $this->log($context, 'Reminder today viewed', [
            'overdue_count'  => $overdueReminders->count(),
            'today_count'    => $todayReminders->count(),
            'tomorrow_count' => $tomorrowReminders->count(),
        ]);

        return AgentResult::reply($reply, [
            'action'         => 'reminder_today',
            'overdue_count'  => $overdueReminders->count(),
            'today_count'    => $todayReminders->count(),
            'tomorrow_count' => $tomorrowReminders->count(),
        ]);
    }

    /**
     * NEW: Show only the single next upcoming reminder.
     */
    private function handleNext(AgentContext $context): AgentResult
    {
        $tz  = AppSetting::timezone();
        $now = now($tz)->utc();

        $reminder = Reminder::where('requester_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->where('status', 'pending')
            ->where('scheduled_at', '>=', $now)
            ->orderBy('scheduled_at')
            ->first();

        if (!$reminder) {
            $reply = "Aucun prochain rappel prevu.\n\n"
                . "Cree un rappel avec:\n"
                . "\"Rappelle-moi de [chose] [quand]\"";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'reminder_next', 'found' => false]);
        }

        $parisTime = $reminder->scheduled_at->copy()->setTimezone($tz);
        $dayLabel  = $this->getDayLabel($parisTime, now($tz)->startOfDay());
        $recText   = $reminder->recurrence_rule
            ? "\nRecurrence : " . $this->formatRecurrenceHuman($reminder->recurrence_rule)
            : '';

        $reply = "*Prochain rappel :*\n"
            . "{$reminder->message}\n"
            . "{$dayLabel} a {$parisTime->format('H:i')}"
            . $recText;

        $this->sendText($context->from, $reply);
        $this->log($context, 'Next reminder viewed', ['reminder_id' => $reminder->id]);

        return AgentResult::reply($reply, [
            'action'      => 'reminder_next',
            'found'       => true,
            'reminder_id' => $reminder->id,
        ]);
    }

    /**
     * Show completion statistics and recent history.
     */
    private function handleStats(AgentContext $context): AgentResult
    {
        $tz = AppSetting::timezone();

        $nowUtc    = now()->utc();
        $weekStart = now($tz)->startOfWeek(Carbon::MONDAY)->utc();

        $pendingCount = Reminder::where('requester_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->where('status', 'pending')
            ->count();

        $overdueCount = Reminder::where('requester_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->where('status', 'pending')
            ->where('scheduled_at', '<', $nowUtc)
            ->count();

        $recurringCount = Reminder::where('requester_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->where('status', 'pending')
            ->whereNotNull('recurrence_rule')
            ->count();

        $completedThisWeek = Reminder::where('requester_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->where('status', 'sent')
            ->where('sent_at', '>=', $weekStart)
            ->count();

        $cancelledThisWeek = Reminder::where('requester_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->where('status', 'done')
            ->whereNull('sent_at')
            ->where('updated_at', '>=', $weekStart)
            ->count();

        $completedTotal = Reminder::where('requester_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->where('status', 'sent')
            ->count();

        $cancelledTotal = Reminder::where('requester_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->where('status', 'done')
            ->whereNull('sent_at')
            ->count();

        // Completion rate
        $total = $completedTotal + $cancelledTotal;
        $completionRate = $total > 0
            ? round(($completedTotal / $total) * 100)
            : null;

        // Next upcoming reminder
        $nextReminder = Reminder::where('requester_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->where('status', 'pending')
            ->where('scheduled_at', '>=', $nowUtc)
            ->orderBy('scheduled_at')
            ->first();

        // Last 5 completed reminders
        $recentCompleted = Reminder::where('requester_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->where('status', 'sent')
            ->orderByDesc('sent_at')
            ->limit(5)
            ->get();

        $lines   = ["*Statistiques de tes rappels :*\n"];
        $lines[] = "En attente              : {$pendingCount}";

        if ($overdueCount > 0) {
            $lines[] = "En retard (a traiter)   : {$overdueCount} ⚠️";
        }

        if ($recurringCount > 0) {
            $lines[] = "Recurrents actifs       : {$recurringCount}";
        }

        $lines[] = "Completes cette semaine : {$completedThisWeek}";
        if ($cancelledThisWeek > 0) {
            $lines[] = "Annules cette semaine   : {$cancelledThisWeek}";
        }
        $lines[] = "Completes au total      : {$completedTotal}";
        $lines[] = "Annules au total        : {$cancelledTotal}";

        if ($completionRate !== null) {
            $lines[] = "Taux de completion      : {$completionRate}%";
        }

        if ($nextReminder) {
            $parisNext = $nextReminder->scheduled_at->copy()->setTimezone($tz);
            $dayLabel  = $this->getDayLabel($parisNext, now($tz)->startOfDay());
            $lines[]   = "\n*Prochain rappel :*";
            $lines[]   = "  {$nextReminder->message} — {$dayLabel} a {$parisNext->format('H:i')}";
        }

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
            'overdue'             => $overdueCount,
            'recurring'           => $recurringCount,
            'completed_this_week' => $completedThisWeek,
            'completed_total'     => $completedTotal,
        ]);

        return AgentResult::reply($reply, [
            'action'              => 'reminder_stats',
            'pending'             => $pendingCount,
            'overdue'             => $overdueCount,
            'recurring'           => $recurringCount,
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
                $reminder->update(['status' => 'done']);
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
                . "- \"15:30\" (meme jour ou demain si passe)\n"
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

        $pendingReminders = Reminder::where('requester_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->where('status', 'pending')
            ->where('message', 'like', '%' . $query . '%')
            ->orderBy('scheduled_at')
            ->limit(self::MAX_REMINDERS)
            ->get();

        // Also search in recent completed/cancelled (last 30 days)
        $recentDone = Reminder::where('requester_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->whereIn('status', ['sent', 'done'])
            ->where('message', 'like', '%' . $query . '%')
            ->where('updated_at', '>=', now()->subDays(30))
            ->orderByDesc('updated_at')
            ->limit(5)
            ->get();

        $totalCount = $pendingReminders->count() + $recentDone->count();

        if ($totalCount === 0) {
            $reply = "Aucun rappel (actif ou recent) avec le mot-cle \"{$query}\".";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'reminder_search', 'count' => 0]);
        }

        $tz    = AppSetting::timezone();
        $today = now($tz)->startOfDay();
        $lines = ["*Rappels contenant \"{$query}\" ({$totalCount}) :*"];

        if ($pendingReminders->isNotEmpty()) {
            $lines[] = "\n*Actifs ({$pendingReminders->count()}) :*";
            foreach ($pendingReminders as $i => $reminder) {
                $parisTime = $reminder->scheduled_at->copy()->setTimezone($tz);
                $dayLabel  = $this->getDayLabel($parisTime, $today);
                $recText   = $reminder->recurrence_rule
                    ? ' (' . $this->formatRecurrenceHuman($reminder->recurrence_rule) . ')'
                    : '';
                $lines[] = ($i + 1) . ". {$dayLabel} {$parisTime->format('H:i')} — {$reminder->message}{$recText}";
            }
        }

        if ($recentDone->isNotEmpty()) {
            $lines[] = "\n*Recents termines/annules :*";
            foreach ($recentDone as $reminder) {
                $statusLabel = $reminder->status === 'sent' ? '[fait]' : '[annule]';
                $dateRef     = ($reminder->sent_at ?? $reminder->updated_at)->copy()->setTimezone($tz)->format('d/m');
                $lines[]     = "  {$statusLabel} {$dateRef} — {$reminder->message}";
            }
        }

        $reply = implode("\n", $lines);
        $this->sendText($context->from, $reply);

        $this->log($context, 'Reminder search', ['query' => $query, 'pending' => $pendingReminders->count(), 'done' => $recentDone->count()]);

        return AgentResult::reply($reply, ['action' => 'reminder_search', 'count' => $totalCount]);
    }

    /**
     * Edit the message and/or the scheduled time of a reminder.
     */
    private function handleEdit(AgentContext $context, $reminders, array $parsed): AgentResult
    {
        $item       = $parsed['item'] ?? null;
        $newMessage = trim($parsed['new_message'] ?? '');
        $newTime    = trim($parsed['new_time'] ?? '');

        if (!$item || (empty($newMessage) && empty($newTime))) {
            $reply = "Precise le numero du rappel et ce que tu veux changer.\n"
                . "Ex: \"Modifie le rappel 1 : Appeler Marie\"\n"
                . "Ex: \"Change l'heure du rappel 2 a 15h\"";
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

        $updates = [];
        $changes = [];
        $tz      = AppSetting::timezone();

        // Update message if provided
        if (!empty($newMessage)) {
            if (mb_strlen($newMessage) > 200) {
                $reply = "Le nouveau texte est trop long (max 200 caracteres).";
                $this->sendText($context->from, $reply);
                return AgentResult::reply($reply, ['action' => 'reminder_edit_message_too_long']);
            }
            $updates['message'] = $newMessage;
            $changes[]          = "Texte : *{$newMessage}*";
        }

        // Update scheduled_at if provided
        if (!empty($newTime)) {
            $newScheduledAt = $this->parseNewTime($newTime, $reminder->scheduled_at);
            if (!$newScheduledAt) {
                $reply = "Heure non reconnue. Essaie:\n"
                    . "\"15:30\", \"demain 10h\", \"+2h\", \"lundi prochain\", \"2026-04-01 09:00\"";
                $this->sendText($context->from, $reply);
                return AgentResult::reply($reply, ['action' => 'reminder_edit_bad_time']);
            }
            if ($newScheduledAt->isPast()) {
                $reply = "La nouvelle date est dans le passe ("
                    . $newScheduledAt->copy()->setTimezone($tz)->format('d/m/Y a H:i')
                    . ").\nChoisis une date future.";
                $this->sendText($context->from, $reply);
                return AgentResult::reply($reply, ['action' => 'reminder_edit_past_date']);
            }
            $updates['scheduled_at'] = $newScheduledAt->utc();
            $parisNew                = $newScheduledAt->copy()->setTimezone($tz);
            $changes[]               = "Date : le {$parisNew->format('d/m/Y')} a {$parisNew->format('H:i')}";
        }

        $oldMessage = $reminder->message;
        $reminder->update($updates);

        // Reload scheduled_at for display (may have changed)
        $parisTime = $reminder->fresh()->scheduled_at->copy()->setTimezone($tz);
        $reply     = "Rappel modifie !\n"
            . implode("\n", $changes) . "\n"
            . "Prevu le {$parisTime->format('d/m/Y')} a {$parisTime->format('H:i')}";

        $this->sendText($context->from, $reply);
        $this->log($context, 'Reminder edited', [
            'reminder_id' => $reminder->id,
            'old_message' => $oldMessage,
            'updated'     => array_keys($updates),
        ]);

        return AgentResult::reply($reply, ['action' => 'reminder_edit', 'reminder_id' => $reminder->id]);
    }

    /**
     * Show reminders for the next 7 days, grouped by day.
     */
    private function handleWeek(AgentContext $context): AgentResult
    {
        $tz        = AppSetting::timezone();
        $weekStart = now($tz)->startOfDay()->utc();
        $weekEnd   = now($tz)->addDays(6)->endOfDay()->utc();

        $reminders = Reminder::where('requester_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->where('status', 'pending')
            ->whereBetween('scheduled_at', [$weekStart, $weekEnd])
            ->orderBy('scheduled_at')
            ->limit(self::MAX_REMINDERS)
            ->get();

        if ($reminders->isEmpty()) {
            $reply = "Aucun rappel prevu dans les 7 prochains jours.\n\n"
                . "Tape \"mes rappels\" pour voir tous tes rappels actifs.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'reminder_week', 'count' => 0]);
        }

        $today   = now($tz)->startOfDay();
        $grouped = [];

        foreach ($reminders as $reminder) {
            $parisTime = $reminder->scheduled_at->copy()->setTimezone($tz);
            $dayKey    = $parisTime->format('Y-m-d');

            if (!isset($grouped[$dayKey])) {
                $grouped[$dayKey] = [
                    'label' => $this->getDayLabel($parisTime, $today),
                    'items' => [],
                ];
            }

            $recIcon = $reminder->recurrence_rule ? ' [rec]' : '';
            $grouped[$dayKey]['items'][] = "  {$parisTime->format('H:i')} — {$reminder->message}{$recIcon}";
        }

        $total = $reminders->count();
        $lines = ["*Planning — 7 prochains jours ({$total} rappels) :*"];

        foreach ($grouped as $day) {
            $lines[] = "\n*{$day['label']} :*";
            foreach ($day['items'] as $item) {
                $lines[] = $item;
            }
        }

        $reply = implode("\n", $lines);
        $this->sendText($context->from, $reply);

        $this->log($context, 'Reminder week viewed', ['count' => $total]);

        return AgentResult::reply($reply, ['action' => 'reminder_week', 'count' => $total]);
    }

    /**
     * Clear (archive) all completed and cancelled reminders older than 30 days.
     */
    private function handleClear(AgentContext $context): AgentResult
    {
        $cutoff = now()->subDays(30)->utc();

        $deleted = Reminder::where('requester_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->whereIn('status', ['sent', 'done'])
            ->where('updated_at', '<', $cutoff)
            ->delete();

        if ($deleted === 0) {
            $reply = "Aucun rappel termine/annule de plus de 30 jours a nettoyer.\n\n"
                . "Tape \"stats rappels\" pour voir tes statistiques.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'reminder_clear', 'deleted' => 0]);
        }

        $reply = "Historique nettoye !\n"
            . "{$deleted} rappel(s) archive(s) (termines/annules depuis plus de 30 jours).\n\n"
            . "Tape \"stats rappels\" pour voir tes statistiques actuelles.";

        $this->sendText($context->from, $reply);

        $this->log($context, 'Reminder history cleared', ['deleted' => $deleted]);

        return AgentResult::reply($reply, ['action' => 'reminder_clear', 'deleted' => $deleted]);
    }

    /**
     * Show all overdue (past-due pending) reminders with their global list numbers.
     */
    private function handleOverdue(AgentContext $context): AgentResult
    {
        $tz  = AppSetting::timezone();
        $now = now()->utc();

        $overdue = Reminder::where('requester_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->where('status', 'pending')
            ->where('scheduled_at', '<', $now)
            ->orderBy('scheduled_at')
            ->get();

        if ($overdue->isEmpty()) {
            $reply = "Aucun rappel en retard. Tu es a jour !\n\n"
                . "Tape \"mes rappels\" pour voir ta liste complete.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'reminder_overdue', 'count' => 0]);
        }

        // Build global numbering so user can reference by number for delete/postpone
        $allPending = Reminder::where('requester_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->where('status', 'pending')
            ->orderBy('scheduled_at')
            ->limit(self::MAX_REMINDERS)
            ->get()
            ->values();

        $idToNum = [];
        foreach ($allPending as $i => $r) {
            $idToNum[$r->id] = $i + 1;
        }

        $total = $overdue->count();
        $lines = ["*Rappels en retard ({$total}) — a traiter :* ⚠️\n"];

        foreach ($overdue as $reminder) {
            $parisTime = $reminder->scheduled_at->copy()->setTimezone($tz);
            $num       = $idToNum[$reminder->id] ?? '?';
            $recIcon   = $reminder->recurrence_rule ? ' [rec]' : '';
            $ago       = $this->humanDiff($reminder->scheduled_at, $now);
            $lines[]   = "#{$num} {$parisTime->format('d/m H:i')} — {$reminder->message}{$recIcon} (il y a {$ago})";
        }

        $lines[] = "\nPour agir :";
        $lines[] = "\"Reporte le rappel X a [heure]\" — reporter";
        $lines[] = "\"Marque le rappel X comme fait\" — marquer fait";
        $lines[] = "\"Supprime le rappel X\" — supprimer";

        $reply = implode("\n", $lines);
        $this->sendText($context->from, $reply);

        $this->log($context, 'Overdue reminders viewed', ['count' => $total]);

        return AgentResult::reply($reply, ['action' => 'reminder_overdue', 'count' => $total]);
    }

    /**
     * Duplicate an existing reminder to a new date/time (no recurrence inherited).
     */
    private function handleCopy(AgentContext $context, $reminders, array $parsed): AgentResult
    {
        $item    = $parsed['item'] ?? null;
        $newTime = trim($parsed['new_time'] ?? '');

        if (!$item) {
            $reply = "Quel rappel veux-tu dupliquer ?\nEx: \"Duplique le rappel 1 pour demain 15h\"";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'reminder_copy_missing']);
        }

        $index    = (int) $item - 1;
        $reminder = $reminders->values()[$index] ?? null;

        if (!$reminder) {
            $reply = "Rappel #{$item} introuvable. Tape \"mes rappels\" pour voir la liste.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'reminder_copy_not_found']);
        }

        $tz = AppSetting::timezone();

        if (!empty($newTime)) {
            $newScheduledAt = $this->parseNewTime($newTime, $reminder->scheduled_at);
            if (!$newScheduledAt) {
                $reply = "Heure non reconnue. Essaie:\n"
                    . "\"demain 15h\", \"+2j\", \"lundi prochain\", \"2026-04-01 09:00\"";
                $this->sendText($context->from, $reply);
                return AgentResult::reply($reply, ['action' => 'reminder_copy_bad_time']);
            }
        } else {
            // Default: same time, tomorrow
            $currentLocal   = $reminder->scheduled_at->copy()->setTimezone($tz);
            $newScheduledAt = now($tz)->addDay()->setTime($currentLocal->hour, $currentLocal->minute, 0);
        }

        if ($newScheduledAt->isPast()) {
            $reply = "La date cible est dans le passe ("
                . $newScheduledAt->copy()->setTimezone($tz)->format('d/m/Y a H:i')
                . ").\nChoisis une date future.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'reminder_copy_past_date']);
        }

        $newReminder = Reminder::create([
            'agent_id'        => $context->agent->id,
            'requester_phone' => $context->from,
            'requester_name'  => $context->senderName,
            'message'         => $reminder->message,
            'channel'         => 'whatsapp',
            'scheduled_at'    => $newScheduledAt->copy()->utc(),
            'recurrence_rule' => null,
            'status'          => 'pending',
        ]);

        $parisTime = $newScheduledAt->copy()->setTimezone($tz);
        $reply     = "Rappel duplique !\n"
            . "*{$reminder->message}*\n"
            . "Le {$parisTime->format('d/m/Y')} a {$parisTime->format('H:i')}";

        $this->sendText($context->from, $reply);

        $this->log($context, 'Reminder copied', [
            'source_id' => $reminder->id,
            'new_id'    => $newReminder->id,
        ]);

        return AgentResult::reply($reply, [
            'action'    => 'reminder_copy',
            'source_id' => $reminder->id,
            'new_id'    => $newReminder->id,
        ]);
    }

    /**
     * Human-readable duration between two UTC Carbon instances (past -> now).
     */
    private function humanDiff(Carbon $past, Carbon $now): string
    {
        $diffMinutes = (int) abs($past->diffInMinutes($now));
        if ($diffMinutes < 60) {
            return "{$diffMinutes} min";
        }
        $diffHours = (int) abs($past->diffInHours($now));
        if ($diffHours < 24) {
            return "{$diffHours}h";
        }
        $diffDays = (int) abs($past->diffInDays($now));
        return "{$diffDays}j";
    }

    private function handleHelp(AgentContext $context): AgentResult
    {
        $reply = "*Gestion des rappels — Commandes disponibles :*\n\n"
            . "*Creer un rappel :*\n"
            . "\"Rappelle-moi d'[action] [quand]\"\n"
            . "\"Rappelle-moi en semaine a 8h de prendre mes vitamines\"\n\n"
            . "*Voir les rappels :*\n"
            . "\"Mes rappels\" ou \"Mon agenda\" (liste complete)\n"
            . "\"Rappels d'aujourd'hui\" ou \"Rappels du jour\" (avec retards)\n"
            . "\"Rappels en retard\" ou \"Mes retards\" (vue dediee retards)\n"
            . "\"Planning semaine\" ou \"Cette semaine\" (7 prochains jours)\n"
            . "\"Planning du mois\" ou \"Mes 30 prochains jours\" (vue mensuelle)\n"
            . "\"Prochain rappel\" (vue rapide, 1 seul)\n\n"
            . "*Statistiques & historique :*\n"
            . "\"Stats rappels\" ou \"Historique rappels\"\n"
            . "\"Nettoie mes rappels\" (archive l'historique > 30 jours)\n\n"
            . "*Chercher des rappels :*\n"
            . "\"Cherche rappels jean\"\n"
            . "\"Trouve mes rappels avec vitamines\"\n\n"
            . "*Modifier un rappel :*\n"
            . "\"Modifie le rappel 1 : Appeler Marie\" (texte)\n"
            . "\"Change l'heure du rappel 2 a 15h\" (heure)\n"
            . "\"Deplace le rappel 3 a demain 9h\" (texte + heure)\n\n"
            . "*Dupliquer un rappel :*\n"
            . "\"Duplique le rappel 1\" (copie pour demain meme heure)\n"
            . "\"Copie le rappel 2 pour vendredi 15h\"\n\n"
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
            . "*Actions en masse (retards) :*\n"
            . "\"Marque tous les retards comme faits\"\n"
            . "\"Supprime tous mes retards\"\n"
            . "\"Reporte tous les retards de +1j\"\n\n"
            . "*Recurrences supportees :*\n"
            . "- Chaque jour : \"tous les jours a 7h\"\n"
            . "- En semaine : \"en semaine a 8h\"\n"
            . "- Chaque semaine : \"chaque lundi a 9h\"\n"
            . "- Chaque mois : \"le 1er de chaque mois a 10h\"";

        $this->sendText($context->from, $reply);
        return AgentResult::reply($reply, ['action' => 'reminder_help']);
    }

    /**
     * Bulk action on a set of reminders (currently only target=overdue).
     */
    private function handleBulk(AgentContext $context, array $parsed): AgentResult
    {
        $operation = $parsed['operation'] ?? null;
        $target    = $parsed['target']    ?? 'overdue';
        $newTime   = trim($parsed['new_time'] ?? '');
        $tz        = AppSetting::timezone();

        if (!in_array($operation, ['complete', 'delete', 'postpone'], true)) {
            $reply = "Operation non reconnue. Dis-moi:\n"
                . "\"Marque tous les retards comme faits\"\n"
                . "\"Supprime tous mes retards\"\n"
                . "\"Reporte tous les retards de +1j\"";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'reminder_bulk_bad_operation']);
        }

        $query = Reminder::where('requester_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->where('status', 'pending');

        if ($target === 'overdue') {
            $query->where('scheduled_at', '<', now()->utc());
        }

        $reminders = $query->orderBy('scheduled_at')->get();

        if ($reminders->isEmpty()) {
            $what  = $target === 'overdue' ? 'en retard' : 'concernes';
            $reply = "Aucun rappel {$what} a traiter. Tu es a jour !";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'reminder_bulk_empty']);
        }

        $count = $reminders->count();

        switch ($operation) {
            case 'complete':
                $reminders->each(fn($r) => $r->update(['status' => 'sent', 'sent_at' => now()]));
                $reply = "{$count} rappel(s) marque(s) comme faits !\n\n"
                    . "Tape \"stats rappels\" pour voir ton bilan.";
                break;

            case 'delete':
                $reminders->each(fn($r) => $r->update(['status' => 'done']));
                $reply = "{$count} rappel(s) supprime(s) !\n\n"
                    . "Tape \"stats rappels\" pour voir ton bilan.";
                break;

            case 'postpone':
                if (empty($newTime)) {
                    $reply = "Precise de combien reporter. Ex:\n"
                        . "\"Reporte tous les retards de +1j\"\n"
                        . "\"Reporte tous les retards a demain 9h\"";
                    $this->sendText($context->from, $reply);
                    return AgentResult::reply($reply, ['action' => 'reminder_bulk_postpone_missing_time']);
                }

                $postponed = 0;
                foreach ($reminders as $reminder) {
                    $newScheduledAt = $this->parseNewTime($newTime, $reminder->scheduled_at);
                    if ($newScheduledAt && !$newScheduledAt->isPast()) {
                        $reminder->update(['scheduled_at' => $newScheduledAt->copy()->utc()]);
                        $postponed++;
                    }
                }

                $firstNew = $this->parseNewTime($newTime, $reminders->first()->scheduled_at);
                $timeLabel = $firstNew
                    ? $firstNew->copy()->setTimezone($tz)->format('d/m a H:i')
                    : $newTime;
                $reply = "{$postponed}/{$count} rappel(s) reporte(s) (a partir du {$timeLabel}).";
                break;

            default:
                $reply = "Operation inconnue.";
        }

        $this->sendText($context->from, $reply);
        $this->log($context, 'Bulk reminder action', [
            'operation' => $operation,
            'target'    => $target,
            'count'     => $count,
        ]);

        return AgentResult::reply($reply, [
            'action'    => 'reminder_bulk',
            'operation' => $operation,
            'target'    => $target,
            'count'     => $count,
        ]);
    }

    /**
     * Show reminders for the next 30 days, grouped by week.
     */
    private function handleMonth(AgentContext $context): AgentResult
    {
        $tz         = AppSetting::timezone();
        $monthStart = now($tz)->startOfDay()->utc();
        $monthEnd   = now($tz)->addDays(29)->endOfDay()->utc();

        $reminders = Reminder::where('requester_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->where('status', 'pending')
            ->whereBetween('scheduled_at', [$monthStart, $monthEnd])
            ->orderBy('scheduled_at')
            ->limit(self::MAX_REMINDERS)
            ->get();

        if ($reminders->isEmpty()) {
            $reply = "Aucun rappel prevu dans les 30 prochains jours.\n\n"
                . "Tape \"mes rappels\" pour voir tous tes rappels actifs.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'reminder_month', 'count' => 0]);
        }

        $today   = now($tz)->startOfDay();
        $grouped = [];

        foreach ($reminders as $reminder) {
            $parisTime = $reminder->scheduled_at->copy()->setTimezone($tz);
            $weekKey   = $parisTime->format('o') . '-W' . $parisTime->format('W');

            if (!isset($grouped[$weekKey])) {
                $weekMonday = $parisTime->copy()->startOfWeek(Carbon::MONDAY);
                $weekSunday = $parisTime->copy()->endOfWeek(Carbon::SUNDAY);
                $grouped[$weekKey] = [
                    'label' => 'Semaine du ' . $weekMonday->format('d/m') . ' au ' . $weekSunday->format('d/m'),
                    'items' => [],
                ];
            }

            $dayLabel = $this->getDayLabel($parisTime, $today);
            $recIcon  = $reminder->recurrence_rule ? ' [rec]' : '';
            $grouped[$weekKey]['items'][] = "  {$dayLabel} {$parisTime->format('H:i')} — {$reminder->message}{$recIcon}";
        }

        $total = $reminders->count();
        $lines = ["*Planning mensuel — 30 prochains jours ({$total} rappels) :*"];

        foreach ($grouped as $week) {
            $lines[] = "\n*{$week['label']} :*";
            foreach ($week['items'] as $item) {
                $lines[] = $item;
            }
        }

        $reply = implode("\n", $lines);
        $this->sendText($context->from, $reply);

        $this->log($context, 'Reminder month viewed', ['count' => $total]);

        return AgentResult::reply($reply, ['action' => 'reminder_month', 'count' => $total]);
    }

    private function handleUnknownAction(AgentContext $context, string $action): AgentResult
    {
        $reply = "Action non reconnue. Tape \"aide\" pour voir les commandes disponibles.";
        $this->sendText($context->from, $reply);
        $this->log($context, 'Unknown action', ['action' => $action], 'warn');
        return AgentResult::reply($reply, ['action' => 'reminder_unknown_action']);
    }

    private function parseNewTime(string $expr, Carbon $currentScheduledAt): ?Carbon
    {
        $expr = trim($expr);
        $tz   = AppSetting::timezone();
        $now  = now($tz);

        // "ce soir" -> today at 20:00 (or tomorrow if past)
        if (preg_match('/^ce\s+soir$/i', $expr)) {
            $candidate = $now->copy()->setTime(20, 0, 0);
            return $candidate->isPast() ? $candidate->addDay() : $candidate;
        }

        // "ce matin" -> today at 08:00 (or tomorrow if past)
        if (preg_match('/^ce\s+matin$/i', $expr)) {
            $candidate = $now->copy()->setTime(8, 0, 0);
            return $candidate->isPast() ? $candidate->addDay() : $candidate;
        }

        // "ce midi" -> today at 12:00 (or tomorrow if past)
        if (preg_match('/^ce\s+midi$/i', $expr)) {
            $candidate = $now->copy()->setTime(12, 0, 0);
            return $candidate->isPast() ? $candidate->addDay() : $candidate;
        }

        // "dans X minutes/heures" (without leading +)
        if (preg_match('/^dans\s+(\d+)\s*(min|minutes?|h|heures?)$/i', $expr, $m)) {
            $amount = (int) $m[1];
            $unit   = strtolower($m[2]);
            return str_starts_with($unit, 'h') ? $now->copy()->addHours($amount) : $now->copy()->addMinutes($amount);
        }

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

        // "HH:MM" or "HHh" or "HHhMM" — same day, or tomorrow if hour has passed
        if (preg_match('/^(\d{1,2})[h:](\d{2})?$/i', $expr, $m)) {
            $h         = (int) $m[1];
            $min       = isset($m[2]) && $m[2] !== '' ? (int) $m[2] : 0;
            $candidate = $now->copy()->setTime($h, $min, 0);
            if ($candidate->isPast()) {
                $candidate->addDay();
            }
            return $candidate;
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

        $nowUtc = now()->utc();

        foreach ($reminders->values() as $i => $reminder) {
            $num        = $i + 1;
            $parisTime  = $reminder->scheduled_at->copy()->setTimezone($tz);
            $recText    = $reminder->recurrence_rule ? " [recurrent: {$reminder->recurrence_rule}]" : '';
            $overdueTag = $reminder->scheduled_at->lt($nowUtc) ? ' [RETARD]' : '';
            $lines[]    = "#{$num} {$parisTime->format('Y-m-d H:i')} — {$reminder->message}{$recText}{$overdueTag}";
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
