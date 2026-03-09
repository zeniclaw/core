<?php

namespace App\Services\Agents;

use App\Models\AppSetting;
use App\Models\EventReminder;
use App\Services\AgentContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class EventReminderAgent extends BaseAgent
{
    public function __construct()
    {
        parent::__construct();
    }

    public function name(): string
    {
        return 'event_reminder';
    }

    public function description(): string
    {
        return 'Agent de gestion d\'evenements et calendrier. Permet de creer, lister, rechercher, modifier, dupliquer, reporter et supprimer des evenements avec date, heure, lieu, participants et rappels automatiques configurables. Supporte la vue du jour, de la semaine, du mois, le detail par ID et la confirmation avant suppression.';
    }

    public function keywords(): array
    {
        return [
            'event', 'events', 'evenement', 'evenements', 'événement', 'événements',
            'calendrier', 'calendar', 'agenda',
            'add event', 'ajouter evenement', 'creer evenement', 'create event',
            'list events', 'lister evenements', 'mes evenements', 'my events',
            'voir evenements', 'show events', 'upcoming events',
            'remove event', 'supprimer evenement', 'annuler evenement', 'cancel event',
            'update event', 'modifier evenement',
            'remind me about', 'rappelle-moi pour',
            'event on', 'evenement le',
            'rdv', 'rendez-vous', 'rendez vous', 'appointment',
            'conference', 'séminaire', 'seminaire', 'workshop',
            'planning', 'planifier', 'plan',
            'search event', 'chercher evenement', 'trouver evenement', 'find event',
            'today events', 'evenements aujourd\'hui', 'evenements du jour',
            'week events', 'evenements semaine', 'cette semaine', 'this week',
            'duplicate event', 'dupliquer evenement', 'copier evenement', 'copy event',
            'show event', 'voir event', 'detail event', 'details event',
            'month events', 'evenements du mois', 'ce mois', 'this month',
            'postpone event', 'reporter evenement', 'decaler evenement', 'reschedule event',
            'next event', 'prochain evenement', 'prochain événement', 'prochaine réunion',
            'stats', 'statistiques', 'statistics', 'bilan calendrier',
            'done event', 'terminer evenement', 'evenement termine', 'mark done',
            'marquer fait', 'marquer termine',
        ];
    }

    public function version(): string
    {
        return '1.4.0';
    }

    public function canHandle(AgentContext $context): bool
    {
        if (!$context->body) return false;
        return (bool) preg_match('/\b(event|evenement|événement|calendrier|calendar|agenda|remind\s+me\s+about|add\s+event|list\s+events?|remove\s+event|update\s+event|modifier\s+evenement|event\s+on|rdv|rendez-vous|rendez\s+vous|appointment|planifier|search\s+event|chercher\s+evenement|week\s+events?|cette\s+semaine|this\s+week|duplicate\s+event|dupliquer|copier\s+evenement|copy\s+event|show\s+event|detail\s+event|month\s+events?|ce\s+mois|this\s+month|postpone|reporter\s+evenement|decaler|next\s+event|prochain\s+evenement|prochain\s+événement|stats\s+events?|statistiques|done\s+event|terminer\s+evenement|mark\s+done|marquer\s+fait)\b/iu', $context->body);
    }

    public function handle(AgentContext $context): AgentResult
    {
        $body  = trim($context->body ?? '');
        $lower = mb_strtolower($body);

        $this->log($context, 'Event reminder command received', ['body' => mb_substr($body, 0, 100)]);

        // Show event details by ID
        if (preg_match('/\b(show|voir|detail|details|afficher)\s*(?:event|evenement)?\s*#?(\d+)/iu', $lower, $m)) {
            return $this->showEventDetails($context, (int) $m[2]);
        }

        // Mark event as done
        if (preg_match('/\b(done|terminer|termine|fait|completed?|mark\s+done|marquer\s+(?:fait|termine))\s*(?:event|evenement)?\s*#?(\d+)/iu', $lower, $m)) {
            return $this->markEventDone($context, (int) $m[2]);
        }

        // Stats
        if (preg_match('/\b(stats?|statistiques?|statistics?|bilan)\s*(?:events?|evenements?|calendrier)?/iu', $lower)) {
            return $this->statsEvents($context);
        }

        // Next event
        if (preg_match('/\b(next\s+event|prochain\s+evenement|prochain\s+(?:événement|evenement)|prochaine?\s+(?:reunion|rdv|seance|seance))\b/iu', $lower)) {
            return $this->nextEvent($context);
        }

        // Month events
        if (preg_match('/\b(month\s+events?|events?\s+(?:this\s+)?month|evenements?\s+(?:du\s+)?mois|ce\s+mois(?:-ci)?|this\s+month)\b/iu', $lower)) {
            return $this->monthEvents($context);
        }

        // List events
        if (preg_match('/\b(list|lister|voir|mes)\s*(events?|evenements?|calendrier|agenda)\b/iu', $lower)) {
            return $this->listEvents($context);
        }

        // Week events
        if (preg_match('/\b(week\s+events?|events?\s+(?:this\s+)?week|evenements?\s+(?:de\s+la\s+)?semaine|cette\s+semaine|semaine\s+(?:en\s+cours|courante))\b/iu', $lower)) {
            return $this->weekEvents($context);
        }

        // Today's events
        if (preg_match('/\b(today|aujourd\'?hui|ce\s+jour|du\s+jour)\b.*\b(event|evenement|rdv|agenda)\b|\b(event|evenement|rdv|agenda)\b.*\b(today|aujourd\'?hui|ce\s+jour|du\s+jour)\b/iu', $lower)) {
            return $this->todayEvents($context);
        }

        // Postpone event
        if (preg_match('/\b(postpone|reporter|decaler|reschedule)\s*(?:event|evenement)?\s*#?(\d+)\s+(?:by|de|d\')\s+(.+)/iu', $body, $m)) {
            return $this->postponeEvent($context, (int) $m[2], trim($m[3]));
        }

        // Duplicate event
        if (preg_match('/\b(duplicate|dupliquer|copier|copy)\s*(?:event|evenement)?\s*#?(\d+)(?:\s+(?:to|vers|au|le|on)\s+(.+))?/iu', $body, $m)) {
            $newDate = isset($m[3]) ? trim($m[3]) : null;
            return $this->duplicateEvent($context, (int) $m[2], $newDate);
        }

        // Remove event
        if (preg_match('/\b(remove|supprimer|annuler|cancel|delete)\s*(?:event|evenement)?\s*#?(\d+)/iu', $lower, $m)) {
            return $this->confirmRemoveEvent($context, (int) $m[2]);
        }

        // Update event — supports multi-word values
        if (preg_match('/\b(?:update|modifier)\s*(?:event|evenement)?\s*#?(\d+)\s+(\w+)\s+(.+)/iu', $body, $m)) {
            return $this->updateEvent($context, (int) $m[1], $m[2], trim($m[3]));
        }

        // Search events by keyword
        if (preg_match('/\b(search|chercher|trouver|find)\s*(?:event|evenement)?\s+(.+)/iu', $lower, $m)) {
            return $this->searchEvents($context, trim($m[2]));
        }

        // Natural language: use Claude to parse event details
        return $this->handleNaturalLanguage($context, $body);
    }

    public function handlePendingContext(AgentContext $context, array $pendingContext): ?AgentResult
    {
        $type = $pendingContext['type'] ?? null;

        if ($type === 'confirm_remove') {
            $body    = mb_strtolower(trim($context->body ?? ''));
            $eventId = (int) ($pendingContext['data']['event_id'] ?? 0);

            $this->clearPendingContext($context);

            if (preg_match('/^(oui|yes|o|y|ok|confirme|confirm|supprime|annule|delete|si|1)\b/iu', $body)) {
                return $this->removeEvent($context, $eventId);
            }

            $reply = "Suppression annulee. L'evenement #{$eventId} est conserve.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply);
        }

        return null;
    }

    private function handleNaturalLanguage(AgentContext $context, string $body): AgentResult
    {
        $model = $this->resolveModel($context);
        $now   = Carbon::now(AppSetting::timezone());

        $response = $this->claude->chat(
            "Message utilisateur: \"{$body}\"\n"
            . "Date/heure actuelle: {$now->format('Y-m-d H:i')} (" . AppSetting::timezone() . ")\n"
            . "Jour: {$now->translatedFormat('l')} {$now->format('d/m/Y')}",
            $model,
            $this->buildSystemPrompt($now)
        );

        $parsed = $this->parseJson($response);

        if (!$parsed || empty($parsed['action'])) {
            return $this->showHelp($context);
        }

        return match ($parsed['action']) {
            'create'    => $this->createEvent($context, $parsed),
            'list'      => $this->listEvents($context),
            'today'     => $this->todayEvents($context),
            'week'      => $this->weekEvents($context),
            'month'     => $this->monthEvents($context),
            'next'      => $this->nextEvent($context),
            'stats'     => $this->statsEvents($context),
            'show'      => isset($parsed['event_id'])
                ? $this->showEventDetails($context, (int) $parsed['event_id'])
                : $this->showHelp($context),
            'remove'    => isset($parsed['event_id'])
                ? $this->confirmRemoveEvent($context, (int) $parsed['event_id'])
                : $this->listEvents($context),
            'update'    => isset($parsed['event_id'])
                ? $this->updateEventFromNl($context, (int) $parsed['event_id'], $parsed)
                : $this->showHelp($context),
            'search'    => isset($parsed['keyword'])
                ? $this->searchEvents($context, $parsed['keyword'])
                : $this->showHelp($context),
            'duplicate' => isset($parsed['event_id'])
                ? $this->duplicateEvent($context, (int) $parsed['event_id'], $parsed['new_date'] ?? null)
                : $this->showHelp($context),
            'postpone'  => isset($parsed['event_id'], $parsed['days'])
                ? $this->postponeEvent($context, (int) $parsed['event_id'], (int) $parsed['days'] . ' days')
                : $this->showHelp($context),
            'done'      => isset($parsed['event_id'])
                ? $this->markEventDone($context, (int) $parsed['event_id'])
                : $this->showHelp($context),
            'help'      => $this->showHelp($context),
            default     => $this->showHelp($context),
        };
    }

    private function buildSystemPrompt(Carbon $now): string
    {
        $today    = $now->format('Y-m-d');
        $tomorrow = $now->copy()->addDay()->format('Y-m-d');
        $nextWeek = $now->copy()->addWeek()->format('Y-m-d');

        return <<<PROMPT
Tu es un assistant de gestion d'evenements. Analyse le message et reponds UNIQUEMENT en JSON valide, sans markdown.

FORMAT JSON:
{"action": "create|list|today|week|month|show|remove|update|search|duplicate|postpone|next|stats|done|help", ...champs selon action}

ACTIONS:

1. CREER un evenement:
{"action": "create", "event_name": "nom", "event_date": "YYYY-MM-DD", "event_time": "HH:MM", "location": "lieu", "participants": ["nom1"], "description": "details", "reminder_minutes": [30, 60, 1440]}

2. LISTER les evenements:
{"action": "list"}

3. VOIR les evenements DU JOUR:
{"action": "today"}

4. VOIR les evenements DE LA SEMAINE (7 prochains jours):
{"action": "week"}

5. VOIR les evenements DU MOIS:
{"action": "month"}

6. VOIR LE PROCHAIN evenement a venir:
{"action": "next"}

7. STATISTIQUES du calendrier:
{"action": "stats"}

8. VOIR le detail d'un evenement:
{"action": "show", "event_id": 5}

9. SUPPRIMER un evenement (demande confirmation):
{"action": "remove", "event_id": 5}

10. MODIFIER un evenement:
{"action": "update", "event_id": 5, "field": "event_name|event_date|event_time|location|description|participants|reminder_minutes", "value": "nouvelle valeur"}
Pour participants, value est une liste JSON: ["Alice", "Bob"]
Pour reminder_minutes, value est une liste JSON: [15, 60, 1440]

11. RECHERCHER par mot-cle:
{"action": "search", "keyword": "mot cle"}

12. DUPLIQUER un evenement:
{"action": "duplicate", "event_id": 3, "new_date": "YYYY-MM-DD"}

13. REPORTER/DECALER un evenement:
{"action": "postpone", "event_id": 3, "days": 7}
days = nombre de jours entier positif (1 semaine = 7, 2 semaines = 14, 1 mois ≈ 30)

14. MARQUER un evenement comme TERMINE:
{"action": "done", "event_id": 5}

15. AIDE:
{"action": "help"}

REGLES:
- 'remind me about X on DATE at TIME' → create
- 'add event X DATE TIME' → create
- 'demain' = {$tomorrow}, 'aujourd\'hui' = {$today}, 'semaine prochaine' = {$nextWeek}
- 'lundi prochain' = prochaine occurrence du lundi, etc.
- Convertis les heures informelles: '14h' → '14:00', '9h30' → '09:30', 'midi' → '12:00', 'minuit' → '00:00'
- Si pas de time precise, utilise null
- Si pas de reminder_minutes, utilise [30, 60, 1440] par defaut (30min, 1h, 1 jour avant)
- Pour supprimer: detecte l'ID dans 'supprime l\'evenement #3', 'annule event 5', etc.
- Pour modifier: detecte l'ID et le champ a modifier
- Pour dupliquer: copie l'evenement vers une nouvelle date
- Pour reporter: 'dans 2 jours' → days: 2, '1 semaine' → days: 7, '1 mois' → days: 30
- 'cette semaine' / 'week events' → week
- 'ce mois' / 'month events' → month
- 'show event #5' / 'detail evenement 5' / 'voir event 5' → show
- 'prochain event' / 'next event' / 'c\'est quoi le prochain' → next
- 'stats events' / 'statistiques' / 'bilan' → stats
- 'event #5 done' / 'mark event #5 done' / 'terminer event #5' → done
- Ne mets que les champs pertinents pour l'action

EXEMPLES:
- "remind me about Reunion equipe on {$tomorrow} at 14:00" → {"action":"create","event_name":"Reunion equipe","event_date":"{$tomorrow}","event_time":"14:00","reminder_minutes":[30,60,1440]}
- "add event Dentiste demain a 10h" → {"action":"create","event_name":"Dentiste","event_date":"{$tomorrow}","event_time":"10:00","reminder_minutes":[30,60,1440]}
- "mes evenements" → {"action":"list"}
- "evenements aujourd'hui" → {"action":"today"}
- "evenements cette semaine" → {"action":"week"}
- "evenements ce mois" → {"action":"month"}
- "c'est quoi mon prochain event ?" → {"action":"next"}
- "stats de mon calendrier" → {"action":"stats"}
- "montre-moi l'event #5" → {"action":"show","event_id":5}
- "supprime evenement #3" → {"action":"remove","event_id":3}
- "change le lieu de l'event #5 a Paris" → {"action":"update","event_id":5,"field":"location","value":"Paris"}
- "ajoute Alice et Bob a l'event #5" → {"action":"update","event_id":5,"field":"participants","value":["Alice","Bob"]}
- "cherche dentiste" → {"action":"search","keyword":"dentiste"}
- "duplique l'event #3 au {$nextWeek}" → {"action":"duplicate","event_id":3,"new_date":"{$nextWeek}"}
- "reporte l'event #3 de 1 semaine" → {"action":"postpone","event_id":3,"days":7}
- "postpone event #2 by 3 days" → {"action":"postpone","event_id":2,"days":3}
- "l'event #4 est termine" → {"action":"done","event_id":4}

Reponds UNIQUEMENT avec le JSON.
PROMPT;
    }

    private function createEvent(AgentContext $context, array $data): AgentResult
    {
        $eventName = $data['event_name'] ?? null;
        $eventDate = $data['event_date'] ?? null;

        if (!$eventName || !$eventDate) {
            $reply = "Je n'ai pas pu comprendre l'evenement. Precise au moins un nom et une date.\n\n"
                . "Exemple : *remind me about Reunion equipe on 2026-03-10 at 14:00*";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply);
        }

        // Validate date format
        try {
            $parsedDate = Carbon::parse($eventDate, AppSetting::timezone());
        } catch (\Exception $e) {
            $reply = "La date *{$eventDate}* n'est pas valide. Utilise le format YYYY-MM-DD ou une expression naturelle (demain, lundi prochain...).";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply);
        }

        // Validate and normalize event_time if provided
        $eventTime = null;
        if (!empty($data['event_time'])) {
            $eventTime = $this->normalizeTime($data['event_time']);
            if ($eventTime === null) {
                $reply = "L'heure *{$data['event_time']}* n'est pas valide. Utilise le format HH:MM (ex: 14:30).";
                $this->sendText($context->from, $reply);
                return AgentResult::reply($reply);
            }
        }

        // Validate reminder_minutes
        $reminderMinutes = $this->validateReminderMinutes($data['reminder_minutes'] ?? [30, 60, 1440]);

        // Warn if date is in the past
        $now           = Carbon::now(AppSetting::timezone());
        $eventDatetime = $parsedDate->copy();
        if ($eventTime) {
            [$h, $m] = explode(':', $eventTime);
            $eventDatetime->setTime((int) $h, (int) $m);
        }

        if ($eventDatetime->isPast()) {
            $reply = "La date *{$parsedDate->format('d/m/Y')}* est dans le passe. Verifie la date et reessaie.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply);
        }

        $event = EventReminder::create([
            'user_phone'              => $context->from,
            'event_name'              => $eventName,
            'event_date'              => $parsedDate->format('Y-m-d'),
            'event_time'              => $eventTime,
            'location'                => $data['location'] ?? null,
            'participants'            => $data['participants'] ?? null,
            'description'             => $data['description'] ?? null,
            'reminder_times'          => $reminderMinutes,
            'notification_escalation' => false,
            'status'                  => 'active',
        ]);

        $this->log($context, 'Event created', ['event_id' => $event->id, 'name' => $eventName]);

        $reply = $this->enrichResponse($event, 'create');
        $this->sendText($context->from, $reply);

        return AgentResult::reply($reply, ['event_id' => $event->id]);
    }

    private function listEvents(AgentContext $context): AgentResult
    {
        $events = EventReminder::where('user_phone', $context->from)
            ->active()
            ->upcoming()
            ->orderBy('event_date')
            ->orderBy('event_time')
            ->limit(20)
            ->get();

        if ($events->isEmpty()) {
            $reply = "Aucun evenement a venir !\n\n"
                . "Ajoute-en un avec :\n"
                . "*remind me about [evenement] on [date] at [heure]*\n\n"
                . "Tape *help events* pour voir toutes les commandes.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply);
        }

        $total = $events->count();
        $reply = "*Tes evenements a venir ({$total}) :*\n";

        $currentDay = null;
        foreach ($events as $event) {
            $dayLabel = $event->event_date->translatedFormat('l j F Y');
            if ($dayLabel !== $currentDay) {
                $reply     .= "\n_{$dayLabel}_\n";
                $currentDay = $dayLabel;
            }
            $reply .= $this->formatEventLine($event) . "\n";
        }

        $reply .= "\n_Commandes : show/remove/update/postpone/duplicate event #ID_";

        $this->sendText($context->from, $reply);
        $this->log($context, 'Events listed', ['count' => $total]);

        return AgentResult::reply($reply, ['count' => $total]);
    }

    private function todayEvents(AgentContext $context): AgentResult
    {
        $today = Carbon::today(AppSetting::timezone());

        $events = EventReminder::where('user_phone', $context->from)
            ->active()
            ->whereDate('event_date', $today->format('Y-m-d'))
            ->orderBy('event_time')
            ->get();

        if ($events->isEmpty()) {
            $reply = "Aucun evenement prevu aujourd'hui (" . $today->translatedFormat('l j F') . ").\n\n"
                . "Tape *list events* pour voir tous tes evenements a venir.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply);
        }

        $reply = "*Evenements du " . $today->translatedFormat('l j F Y') . " :*\n\n";
        foreach ($events as $event) {
            $reply .= $this->formatEventLine($event) . "\n";
        }

        $this->sendText($context->from, $reply);
        $this->log($context, 'Today events listed', ['count' => $events->count()]);

        return AgentResult::reply($reply, ['count' => $events->count()]);
    }

    private function weekEvents(AgentContext $context): AgentResult
    {
        $today   = Carbon::today(AppSetting::timezone());
        $endWeek = $today->copy()->addDays(6)->endOfDay();

        $events = EventReminder::where('user_phone', $context->from)
            ->active()
            ->whereBetween('event_date', [$today->format('Y-m-d'), $endWeek->format('Y-m-d')])
            ->orderBy('event_date')
            ->orderBy('event_time')
            ->get();

        if ($events->isEmpty()) {
            $reply = "Aucun evenement cette semaine "
                . "(" . $today->format('d/m') . " - " . $endWeek->format('d/m/Y') . ").\n\n"
                . "Tape *list events* pour voir tous tes evenements a venir.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply);
        }

        $reply = "*Evenements de la semaine (" . $today->format('d/m') . " - " . $endWeek->format('d/m') . ") :*\n\n";

        $currentDay = null;
        foreach ($events as $event) {
            $dayLabel = $event->event_date->translatedFormat('l j F');
            if ($dayLabel !== $currentDay) {
                $reply     .= "\n_{$dayLabel}_\n";
                $currentDay = $dayLabel;
            }
            $reply .= $this->formatEventLine($event) . "\n";
        }

        $this->sendText($context->from, $reply);
        $this->log($context, 'Week events listed', ['count' => $events->count()]);

        return AgentResult::reply($reply, ['count' => $events->count()]);
    }

    private function monthEvents(AgentContext $context): AgentResult
    {
        $today      = Carbon::today(AppSetting::timezone());
        $startMonth = $today->copy()->startOfMonth();
        $endMonth   = $today->copy()->endOfMonth();

        $events = EventReminder::where('user_phone', $context->from)
            ->active()
            ->whereBetween('event_date', [$startMonth->format('Y-m-d'), $endMonth->format('Y-m-d')])
            ->orderBy('event_date')
            ->orderBy('event_time')
            ->get();

        $monthLabel = $today->translatedFormat('F Y');

        if ($events->isEmpty()) {
            $reply = "Aucun evenement en {$monthLabel}.\n\n"
                . "Tape *list events* pour voir tous tes evenements a venir.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply);
        }

        $reply = "*Evenements de {$monthLabel} ({$events->count()}) :*\n\n";

        $currentDay = null;
        foreach ($events as $event) {
            $dayLabel = $event->event_date->translatedFormat('l j F');
            if ($dayLabel !== $currentDay) {
                $isPast     = $event->event_date->isPast() && !$event->event_date->isToday();
                $dayDisplay = $isPast ? "~{$dayLabel}~" : "_{$dayLabel}_";
                $reply     .= "\n{$dayDisplay}\n";
                $currentDay = $dayLabel;
            }
            $reply .= $this->formatEventLine($event) . "\n";
        }

        $this->sendText($context->from, $reply);
        $this->log($context, 'Month events listed', ['month' => $monthLabel, 'count' => $events->count()]);

        return AgentResult::reply($reply, ['count' => $events->count()]);
    }

    private function showEventDetails(AgentContext $context, int $eventId): AgentResult
    {
        $event = EventReminder::where('id', $eventId)
            ->where('user_phone', $context->from)
            ->first();

        if (!$event) {
            $reply = "Evenement #{$eventId} introuvable. Tape *list events* pour voir tes evenements.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply);
        }

        $dateFormatted = $event->event_date->translatedFormat('l j F Y');
        $timeFormatted = $event->event_time ? Carbon::parse($event->event_time)->format('H:i') : 'non definie';
        $timeUntil     = $event->timeUntilEvent();
        $statusLabel   = $event->status === 'active' ? 'Actif' : ucfirst($event->status);

        $reply  = "*Detail de l'evenement #{$event->id}*\n\n";
        $reply .= "*{$event->event_name}*\n";
        $reply .= "Statut : {$statusLabel}\n";
        $reply .= "Date : {$dateFormatted}\n";
        $reply .= "Heure : {$timeFormatted}\n";

        if ($event->location) {
            $reply .= "Lieu : {$event->location}\n";
        }

        if (!empty($event->participants)) {
            $count  = count($event->participants);
            $reply .= "Participants ({$count}) : " . implode(', ', $event->participants) . "\n";
        }

        if ($event->description) {
            $reply .= "Note : _{$event->description}_\n";
        }

        $reply .= "\nDans : *{$timeUntil}*\n";

        $reminderTimes  = $event->reminder_times ?? [30, 60, 1440];
        $reminderLabels = array_map(fn ($m) => $this->minutesToLabel($m), $reminderTimes);
        $reply         .= "Rappels : " . implode(', ', $reminderLabels) . "\n";

        $reply .= "\n_Commandes :_\n"
            . "- *update event #{$event->id} [champ] [valeur]* — Modifier\n"
            . "- *postpone event #{$event->id} by [duree]* — Reporter\n"
            . "- *duplicate event #{$event->id} to [date]* — Dupliquer\n"
            . "- *remove event #{$event->id}* — Supprimer";

        $this->sendText($context->from, $reply);
        $this->log($context, 'Event details shown', ['event_id' => $eventId]);

        return AgentResult::reply($reply, ['event_id' => $eventId]);
    }

    private function postponeEvent(AgentContext $context, int $eventId, string $duration): AgentResult
    {
        $event = EventReminder::where('id', $eventId)
            ->where('user_phone', $context->from)
            ->first();

        if (!$event) {
            $reply = "Evenement #{$eventId} introuvable. Tape *list events* pour voir tes evenements.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply);
        }

        $days = $this->parseDurationToDays($duration);

        if ($days === null || $days <= 0) {
            $reply = "Duree non reconnue : *{$duration}*.\n"
                . "Exemples valides : *2 days*, *1 week*, *1 semaine*, *3 jours*, *10 days*";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply);
        }

        $oldDate    = $event->event_date->format('d/m/Y');
        $newDate    = $event->event_date->copy()->addDays($days);
        $newDateFmt = $newDate->translatedFormat('l j F Y');

        $event->update(['event_date' => $newDate->format('Y-m-d')]);
        $fresh = $event->fresh();

        $this->log($context, 'Event postponed', [
            'event_id' => $eventId,
            'old_date'  => $oldDate,
            'new_date'  => $newDate->format('Y-m-d'),
            'days'      => $days,
        ]);

        $reply = "Evenement #{$eventId} reporte de *{$days} jour(s)* !\n\n"
            . "*{$event->event_name}*\n"
            . "Ancienne date : {$oldDate}\n"
            . "Nouvelle date : {$newDateFmt}\n"
            . ($event->event_time ? "Heure : " . Carbon::parse($event->event_time)->format('H:i') . "\n" : '')
            . "\nDans : *" . ($fresh ? $fresh->timeUntilEvent() : 'N/A') . "*";

        $this->sendText($context->from, $reply);

        return AgentResult::reply($reply, ['event_id' => $eventId, 'days' => $days]);
    }

    private function duplicateEvent(AgentContext $context, int $eventId, ?string $newDate): AgentResult
    {
        $event = EventReminder::where('id', $eventId)
            ->where('user_phone', $context->from)
            ->first();

        if (!$event) {
            $reply = "Evenement #{$eventId} introuvable. Tape *list events* pour voir tes evenements.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply);
        }

        // Parse new date
        $parsedDate = null;
        if ($newDate) {
            try {
                $parsedDate = Carbon::parse($newDate, AppSetting::timezone());
            } catch (\Exception $e) {
                $reply = "La date *{$newDate}* n'est pas valide. Utilise le format YYYY-MM-DD.";
                $this->sendText($context->from, $reply);
                return AgentResult::reply($reply);
            }

            if ($parsedDate->isPast()) {
                $reply = "La date *{$parsedDate->format('d/m/Y')}* est dans le passe. Verifie la date et reessaie.";
                $this->sendText($context->from, $reply);
                return AgentResult::reply($reply);
            }
        }

        $newEvent = EventReminder::create([
            'user_phone'              => $context->from,
            'event_name'              => $event->event_name,
            'event_date'              => $parsedDate ? $parsedDate->format('Y-m-d') : $event->event_date->format('Y-m-d'),
            'event_time'              => $event->event_time,
            'location'                => $event->location,
            'participants'            => $event->participants,
            'description'             => $event->description,
            'reminder_times'          => $event->reminder_times,
            'notification_escalation' => false,
            'status'                  => 'active',
        ]);

        $this->log($context, 'Event duplicated', ['source_id' => $eventId, 'new_id' => $newEvent->id]);

        $dateStr = $parsedDate
            ? $parsedDate->translatedFormat('l j F Y')
            : $newEvent->event_date->translatedFormat('l j F Y');

        $reply = "Evenement duplique ! (#{$newEvent->id})\n\n"
            . "*{$newEvent->event_name}*\n"
            . "Date : {$dateStr}\n"
            . ($newEvent->event_time ? "Heure : " . Carbon::parse($newEvent->event_time)->format('H:i') . "\n" : '')
            . ($newEvent->location ? "Lieu : {$newEvent->location}\n" : '')
            . "\nDans : *" . $newEvent->timeUntilEvent() . "*\n"
            . "\n_Copie de l'evenement #{$eventId}_";

        $this->sendText($context->from, $reply);

        return AgentResult::reply($reply, ['event_id' => $newEvent->id, 'source_id' => $eventId]);
    }

    private function nextEvent(AgentContext $context): AgentResult
    {
        $event = EventReminder::where('user_phone', $context->from)
            ->active()
            ->upcoming()
            ->orderBy('event_date')
            ->orderBy('event_time')
            ->first();

        if (!$event) {
            $reply = "Aucun evenement a venir dans ton calendrier.\n\n"
                . "Ajoute-en un avec :\n"
                . "*remind me about [evenement] on [date] at [heure]*";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply);
        }

        $dateFormatted = $event->event_date->translatedFormat('l j F Y');
        $timeFormatted = $event->event_time ? Carbon::parse($event->event_time)->format('H:i') : 'heure non definie';
        $timeUntil     = $event->timeUntilEvent();

        $reply  = "*Prochain evenement :*\n\n";
        $reply .= "*{$event->event_name}* (#{$event->id})\n";
        $reply .= "Date : {$dateFormatted}\n";
        $reply .= "Heure : {$timeFormatted}\n";

        if ($event->location) {
            $reply .= "Lieu : {$event->location}\n";
        }
        if (!empty($event->participants)) {
            $reply .= "Participants : " . implode(', ', $event->participants) . "\n";
        }

        $reply .= "\nDans : *{$timeUntil}*\n";
        $reply .= "\n_Tape *show event #{$event->id}* pour plus de details._";

        $this->sendText($context->from, $reply);
        $this->log($context, 'Next event shown', ['event_id' => $event->id]);

        return AgentResult::reply($reply, ['event_id' => $event->id]);
    }

    private function statsEvents(AgentContext $context): AgentResult
    {
        $tz    = AppSetting::timezone();
        $today = Carbon::today($tz);

        $totalActive = EventReminder::where('user_phone', $context->from)
            ->active()->count();

        $upcoming = EventReminder::where('user_phone', $context->from)
            ->active()->upcoming()->count();

        $todayCount = EventReminder::where('user_phone', $context->from)
            ->active()
            ->whereDate('event_date', $today->format('Y-m-d'))
            ->count();

        $weekCount = EventReminder::where('user_phone', $context->from)
            ->active()
            ->whereBetween('event_date', [
                $today->format('Y-m-d'),
                $today->copy()->addDays(6)->format('Y-m-d'),
            ])
            ->count();

        $monthCount = EventReminder::where('user_phone', $context->from)
            ->active()
            ->whereBetween('event_date', [
                $today->copy()->startOfMonth()->format('Y-m-d'),
                $today->copy()->endOfMonth()->format('Y-m-d'),
            ])
            ->count();

        $cancelledCount = EventReminder::where('user_phone', $context->from)
            ->where('status', 'cancelled')->count();

        $doneCount = EventReminder::where('user_phone', $context->from)
            ->where('status', 'done')->count();

        $monthLabel = $today->translatedFormat('F Y');

        $reply  = "*Statistiques de ton calendrier :*\n\n";
        $reply .= "Actifs a venir : *{$upcoming}*\n";
        $reply .= "Aujourd'hui : *{$todayCount}*\n";
        $reply .= "Cette semaine (7j) : *{$weekCount}*\n";
        $reply .= "Ce mois ({$monthLabel}) : *{$monthCount}*\n";
        $reply .= "\nTermines : *{$doneCount}*\n";
        $reply .= "Annules : *{$cancelledCount}*\n";
        $reply .= "Total actifs : *{$totalActive}*";

        $this->sendText($context->from, $reply);
        $this->log($context, 'Stats shown', [
            'upcoming' => $upcoming,
            'today'    => $todayCount,
            'week'     => $weekCount,
            'month'    => $monthCount,
        ]);

        return AgentResult::reply($reply, [
            'upcoming'   => $upcoming,
            'today'      => $todayCount,
            'week'       => $weekCount,
            'month'      => $monthCount,
            'cancelled'  => $cancelledCount,
            'done'       => $doneCount,
        ]);
    }

    private function markEventDone(AgentContext $context, int $eventId): AgentResult
    {
        $event = EventReminder::where('id', $eventId)
            ->where('user_phone', $context->from)
            ->first();

        if (!$event) {
            $reply = "Evenement #{$eventId} introuvable. Tape *list events* pour voir tes evenements.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply);
        }

        if ($event->status === 'done') {
            $reply = "L'evenement *{$event->event_name}* (#{$eventId}) est deja marque comme termine.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply);
        }

        $event->update(['status' => 'done']);

        $dateStr = $event->event_date->translatedFormat('l j F Y');
        $reply   = "Evenement marque comme termine !\n\n"
            . "*{$event->event_name}* (#{$event->id})\n"
            . "Date : {$dateStr}\n\n"
            . "_Bravo ! L'evenement est archive._";

        $this->sendText($context->from, $reply);
        $this->log($context, 'Event marked done', ['event_id' => $eventId]);

        return AgentResult::reply($reply, ['event_id' => $eventId]);
    }

    private function searchEvents(AgentContext $context, string $keyword): AgentResult
    {
        $keyword = trim($keyword);

        if (mb_strlen($keyword) < 2) {
            $reply = "Le mot-cle est trop court. Essaie avec au moins 2 caracteres.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply);
        }

        $events = EventReminder::where('user_phone', $context->from)
            ->active()
            ->where(function ($q) use ($keyword) {
                $q->where('event_name', 'like', "%{$keyword}%")
                    ->orWhere('description', 'like', "%{$keyword}%")
                    ->orWhere('location', 'like', "%{$keyword}%")
                    ->orWhereRaw('LOWER(CAST(participants AS CHAR)) LIKE ?', ['%' . mb_strtolower($keyword) . '%']);
            })
            ->orderBy('event_date')
            ->orderBy('event_time')
            ->get();

        if ($events->isEmpty()) {
            $reply = "Aucun evenement trouve pour *{$keyword}*.\n\n"
                . "Essaie *list events* pour voir tous tes evenements.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply);
        }

        $reply = "*Resultats pour \"{$keyword}\" ({$events->count()}) :*\n\n";
        foreach ($events as $event) {
            $reply .= $this->formatEventLine($event) . "\n";
        }

        $reply .= "\nTape *show event #ID* pour voir les details.";

        $this->sendText($context->from, $reply);
        $this->log($context, 'Events searched', ['keyword' => $keyword, 'count' => $events->count()]);

        return AgentResult::reply($reply, ['keyword' => $keyword, 'count' => $events->count()]);
    }

    private function confirmRemoveEvent(AgentContext $context, int $eventId): AgentResult
    {
        $event = EventReminder::where('id', $eventId)
            ->where('user_phone', $context->from)
            ->first();

        if (!$event) {
            $reply = "Evenement #{$eventId} introuvable. Tape *list events* pour voir tes evenements.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply);
        }

        $dateStr = $event->event_date->translatedFormat('l j F Y');
        $timeStr = $event->event_time ? ' a ' . Carbon::parse($event->event_time)->format('H:i') : '';

        $this->setPendingContext($context, 'confirm_remove', ['event_id' => $eventId], ttlMinutes: 3, expectRawInput: true);

        $reply = "Tu veux vraiment supprimer cet evenement ?\n\n"
            . "*{$event->event_name}* — {$dateStr}{$timeStr}\n\n"
            . "Reponds *oui* pour confirmer ou *non* pour annuler.";

        $this->sendText($context->from, $reply);
        $this->log($context, 'Remove confirmation requested', ['event_id' => $eventId]);

        return AgentResult::reply($reply, ['event_id' => $eventId, 'pending' => 'confirm_remove']);
    }

    private function removeEvent(AgentContext $context, int $eventId): AgentResult
    {
        $event = EventReminder::where('id', $eventId)
            ->where('user_phone', $context->from)
            ->first();

        if (!$event) {
            $reply = "Evenement #{$eventId} introuvable.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply);
        }

        $name    = $event->event_name;
        $dateStr = $event->event_date->format('d/m/Y');
        $event->update(['status' => 'cancelled']);

        $this->log($context, 'Event cancelled', ['event_id' => $eventId]);

        $reply = "Evenement annule : _{$name}_ ({$dateStr})";
        $this->sendText($context->from, $reply);

        return AgentResult::reply($reply);
    }

    private function updateEvent(AgentContext $context, int $eventId, string $field, string $value): AgentResult
    {
        $event = EventReminder::where('id', $eventId)
            ->where('user_phone', $context->from)
            ->first();

        if (!$event) {
            $reply = "Evenement #{$eventId} introuvable. Tape *list events* pour voir tes evenements.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply);
        }

        $allowedFields = ['event_name', 'event_date', 'event_time', 'location', 'description', 'participants', 'reminder_times'];
        $fieldMap      = [
            'name'             => 'event_name',
            'nom'              => 'event_name',
            'titre'            => 'event_name',
            'date'             => 'event_date',
            'time'             => 'event_time',
            'heure'            => 'event_time',
            'lieu'             => 'location',
            'location'         => 'location',
            'endroit'          => 'location',
            'description'      => 'description',
            'note'             => 'description',
            'notes'            => 'description',
            'participants'     => 'participants',
            'reminder_times'   => 'reminder_times',
            'reminder_minutes' => 'reminder_times',
            'rappels'          => 'reminder_times',
            'rappel'           => 'reminder_times',
        ];

        $dbField = $fieldMap[mb_strtolower($field)] ?? null;
        if (!$dbField || !in_array($dbField, $allowedFields)) {
            $reply = "Champ *{$field}* non reconnu.\n"
                . "Champs modifiables : name, date, time, location, description, participants, rappels";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply);
        }

        // Validate and transform the value depending on field
        $updateValue = $value;

        if ($dbField === 'event_date') {
            try {
                $parsed = Carbon::parse($value, AppSetting::timezone());
                if ($parsed->isPast()) {
                    $reply = "La date *{$value}* est dans le passe. Verifie la date et reessaie.";
                    $this->sendText($context->from, $reply);
                    return AgentResult::reply($reply);
                }
                $updateValue = $parsed->format('Y-m-d');
            } catch (\Exception $e) {
                $reply = "Date invalide : *{$value}*. Utilise le format YYYY-MM-DD.";
                $this->sendText($context->from, $reply);
                return AgentResult::reply($reply);
            }
        }

        if ($dbField === 'event_time') {
            $updateValue = $this->normalizeTime($value);
            if ($updateValue === null) {
                $reply = "Heure invalide : *{$value}*. Utilise le format HH:MM (ex: 14:30).";
                $this->sendText($context->from, $reply);
                return AgentResult::reply($reply);
            }
        }

        if ($dbField === 'participants') {
            // Accept JSON array or comma-separated string
            $decoded     = json_decode($value, true);
            $updateValue = is_array($decoded)
                ? $decoded
                : array_map('trim', explode(',', $value));
        }

        if ($dbField === 'reminder_times') {
            $decoded     = json_decode($value, true);
            $raw         = is_array($decoded) ? $decoded : array_map('trim', explode(',', $value));
            $updateValue = $this->validateReminderMinutes(array_map('intval', $raw));
        }

        $event->update([$dbField => $updateValue]);

        $this->log($context, 'Event updated', ['event_id' => $eventId, 'field' => $dbField, 'value' => $updateValue]);

        $fresh        = $event->fresh();
        $displayValue = is_array($updateValue) ? implode(', ', $updateValue) : $updateValue;
        $reply        = "Evenement #{$eventId} mis a jour !\n"
            . "*{$field}* → {$displayValue}\n\n"
            . ($fresh ? $this->formatEventLine($fresh) : '');

        $this->sendText($context->from, $reply);

        return AgentResult::reply($reply);
    }

    private function updateEventFromNl(AgentContext $context, int $eventId, array $data): AgentResult
    {
        $field = $data['field'] ?? null;
        $value = $data['value'] ?? null;

        if (!$field || $value === null) {
            $reply = "Precise le champ et la valeur a modifier.\n"
                . "Exemple : *update event #5 location Salle B*";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply);
        }

        // Arrays (participants, reminder_minutes) → encode as JSON string for updateEvent()
        $valueStr = is_array($value) ? json_encode($value) : (string) $value;

        return $this->updateEvent($context, $eventId, $field, $valueStr);
    }

    private function enrichResponse(EventReminder $event, string $action): string
    {
        $dateFormatted = $event->event_date->translatedFormat('l j F Y');
        $timeFormatted = $event->event_time ? Carbon::parse($event->event_time)->format('H:i') : 'non definie';
        $timeUntil     = $event->timeUntilEvent();

        $response = match ($action) {
            'create'   => "Evenement cree ! (#{$event->id})\n\n",
            'reminder' => "Rappel evenement :\n\n",
            default    => '',
        };

        $response .= "*{$event->event_name}*\n"
            . "Date : {$dateFormatted}\n"
            . "Heure : {$timeFormatted}\n";

        if ($event->location) {
            $response .= "Lieu : {$event->location}\n";
        }

        if (!empty($event->participants)) {
            $count     = count($event->participants);
            $response .= "Participants ({$count}) : " . implode(', ', $event->participants) . "\n";
        }

        if ($event->description) {
            $response .= "Note : _{$event->description}_\n";
        }

        $response .= "\nDans : *{$timeUntil}*\n";

        $reminderTimes  = $event->reminder_times ?? [30, 60, 1440];
        $reminderLabels = array_map(fn ($m) => $this->minutesToLabel($m), $reminderTimes);
        $response      .= "Rappels : " . implode(', ', $reminderLabels);

        return $response;
    }

    private function formatEventLine(EventReminder $event): string
    {
        $date      = $event->event_date->format('d/m/Y');
        $time      = $event->event_time ? Carbon::parse($event->event_time)->format('H:i') : '--:--';
        $location  = $event->location ? " ({$event->location})" : '';
        $timeUntil = $event->timeUntilEvent();
        $parts     = [];
        if (!empty($event->participants)) {
            $parts[] = count($event->participants) . ' participant(s)';
        }
        $extra = $parts ? ' [' . implode(', ', $parts) . ']' : '';

        return "#{$event->id} *{$event->event_name}* — {$date} {$time}{$location}{$extra} — _{$timeUntil}_";
    }

    private function minutesToLabel(int $minutes): string
    {
        if ($minutes >= 1440) {
            $days = intdiv($minutes, 1440);
            return $days === 1 ? '1j avant' : "{$days}j avant";
        }
        if ($minutes >= 60) {
            $hours = intdiv($minutes, 60);
            return $hours === 1 ? '1h avant' : "{$hours}h avant";
        }
        return "{$minutes}min avant";
    }

    /**
     * Parse a human-readable duration string into a number of days.
     * Returns null if the duration cannot be parsed.
     */
    private function parseDurationToDays(string $duration): ?int
    {
        $duration = trim(mb_strtolower($duration));

        // Numeric days passed directly (from NL action)
        if (ctype_digit($duration)) {
            return (int) $duration;
        }

        // "X days" / "X jours" / "X jour"
        if (preg_match('/^(\d+)\s*(?:days?|jours?|d)$/i', $duration, $m)) {
            return (int) $m[1];
        }

        // "X weeks" / "X semaines" / "X semaine"
        if (preg_match('/^(\d+)\s*(?:weeks?|semaines?)$/i', $duration, $m)) {
            return (int) $m[1] * 7;
        }

        // "X months" / "X mois"
        if (preg_match('/^(\d+)\s*(?:months?|mois)$/i', $duration, $m)) {
            return (int) $m[1] * 30;
        }

        // "X hours" / "X heures" → not supported for days postpone
        return null;
    }

    /**
     * Normalize a time string to HH:MM format.
     * Returns null if the input cannot be parsed.
     */
    private function normalizeTime(string $time): ?string
    {
        $time = trim($time);

        // Already HH:MM or HH:MM:SS
        if (preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?$/', $time, $m)) {
            $h   = (int) $m[1];
            $min = (int) $m[2];
            if ($h >= 0 && $h <= 23 && $min >= 0 && $min <= 59) {
                return sprintf('%02d:%02d', $h, $min);
            }
            return null;
        }

        // Informal: 14h, 9h30, 9h
        if (preg_match('/^(\d{1,2})h(\d{0,2})$/i', $time, $m)) {
            $h   = (int) $m[1];
            $min = $m[2] !== '' ? (int) $m[2] : 0;
            if ($h >= 0 && $h <= 23 && $min >= 0 && $min <= 59) {
                return sprintf('%02d:%02d', $h, $min);
            }
            return null;
        }

        // Named times
        $named = ['midi' => '12:00', 'minuit' => '00:00', 'noon' => '12:00', 'midnight' => '00:00'];
        if (isset($named[mb_strtolower($time)])) {
            return $named[mb_strtolower($time)];
        }

        return null;
    }

    /**
     * Validate and filter reminder minutes: must be positive integers, deduplicated, sorted.
     */
    private function validateReminderMinutes(array $minutes): array
    {
        $valid = array_values(array_unique(
            array_filter(
                array_map('intval', $minutes),
                fn ($m) => $m > 0
            )
        ));
        sort($valid);
        return $valid ?: [30, 60, 1440];
    }

    private function parseJson(?string $response): ?array
    {
        if (!$response) return null;

        $clean = trim($response);

        // Strip markdown code fences
        if (preg_match('/```(?:json)?\s*(.*?)\s*```/s', $clean, $m)) {
            $clean = $m[1];
        }

        // Extract JSON object if surrounded by extra text
        if (!str_starts_with($clean, '{') && preg_match('/(\{.*\})/s', $clean, $m)) {
            $clean = $m[1];
        }

        $decoded = json_decode($clean, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            Log::warning('[EventReminderAgent] JSON parse failed', ['raw' => mb_substr($clean, 0, 200)]);
            return null;
        }

        return $decoded;
    }

    private function showHelp(AgentContext $context): AgentResult
    {
        $reply = "*Event Reminder v1.4 — Gestion d'evenements :*\n\n"
            . "*Creer :*\n"
            . "remind me about [evenement] on [date] at [heure]\n"
            . "add event [nom] [date] [heure]\n\n"
            . "*Consulter :*\n"
            . "list events — Tous les evenements a venir\n"
            . "next event — Prochain evenement\n"
            . "today events — Evenements du jour\n"
            . "week events — Evenements de la semaine\n"
            . "month events — Evenements du mois\n"
            . "show event #ID — Detail d'un evenement\n"
            . "stats events — Statistiques du calendrier\n\n"
            . "*Gerer :*\n"
            . "done event #ID — Marquer comme termine\n"
            . "remove event #ID — Supprimer (avec confirmation)\n"
            . "update event #ID [champ] [valeur] — Modifier\n"
            . "postpone event #ID by [duree] — Reporter\n"
            . "duplicate event #ID to [date] — Dupliquer\n"
            . "search event [mot-cle] — Rechercher\n\n"
            . "*Exemples :*\n"
            . "- remind me about Reunion equipe on 2026-03-15 at 14:00\n"
            . "- add event Dentiste demain a 10h\n"
            . "- next event\n"
            . "- stats events\n"
            . "- show event #5\n"
            . "- done event #5\n"
            . "- month events\n"
            . "- postpone event #3 by 1 semaine\n"
            . "- update event #3 location Salle A\n"
            . "- update event #3 participants Alice, Bob\n"
            . "- duplicate event #3 to 2026-03-20\n"
            . "- remove event #3";

        $this->sendText($context->from, $reply);
        return AgentResult::reply($reply);
    }
}
