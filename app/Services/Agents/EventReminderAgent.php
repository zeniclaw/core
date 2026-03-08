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
        return 'Agent de gestion d\'evenements et calendrier. Permet de creer, lister, rechercher, modifier et supprimer des evenements avec date, heure, lieu, participants et rappels automatiques configurables (30min, 1h, 1 jour avant). Supporte la vue du jour et la recherche par mot-cle.';
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
        ];
    }

    public function version(): string
    {
        return '1.1.0';
    }

    public function canHandle(AgentContext $context): bool
    {
        if (!$context->body) return false;
        return (bool) preg_match('/\b(event|evenement|événement|calendrier|calendar|agenda|remind\s+me\s+about|add\s+event|list\s+events?|remove\s+event|update\s+event|event\s+on|rdv|rendez-vous|rendez\s+vous|appointment|planifier|search\s+event|chercher\s+evenement)\b/iu', $context->body);
    }

    public function handle(AgentContext $context): AgentResult
    {
        $body = trim($context->body ?? '');
        $lower = mb_strtolower($body);

        $this->log($context, 'Event reminder command received', ['body' => mb_substr($body, 0, 100)]);

        // List events
        if (preg_match('/\b(list|lister|voir|mes)\s*(events?|evenements?|calendrier|agenda)\b/iu', $lower)) {
            return $this->listEvents($context);
        }

        // Today's events
        if (preg_match('/\b(today|aujourd\'?hui|ce\s+jour|du\s+jour)\b.*\b(event|evenement|rdv|agenda)\b|\b(event|evenement|rdv|agenda)\b.*\b(today|aujourd\'?hui|ce\s+jour|du\s+jour)\b/iu', $lower)) {
            return $this->todayEvents($context);
        }

        // Remove event
        if (preg_match('/\b(remove|supprimer|annuler|cancel)\s*event\s*#?(\d+)/iu', $lower, $m)) {
            return $this->removeEvent($context, (int) $m[2]);
        }

        // Update event — supports multi-word values
        if (preg_match('/\bupdate\s*event\s*#?(\d+)\s+(\w+)\s+(.+)/iu', $body, $m)) {
            return $this->updateEvent($context, (int) $m[1], $m[2], trim($m[3]));
        }

        // Search events by keyword
        if (preg_match('/\b(search|chercher|trouver|find)\s*(?:event|evenement)?\s+(.+)/iu', $lower, $m)) {
            return $this->searchEvents($context, trim($m[2]));
        }

        // Natural language: use Claude to parse event details
        return $this->handleNaturalLanguage($context, $body);
    }

    private function handleNaturalLanguage(AgentContext $context, string $body): AgentResult
    {
        $model = $this->resolveModel($context);
        $now = Carbon::now(AppSetting::timezone());

        $response = $this->claude->chat(
            "Message utilisateur: \"{$body}\"\n"
            . "Date/heure actuelle: {$now->format('Y-m-d H:i')} (" . AppSetting::timezone() . ")\n"
            . "Jour: {$now->translatedFormat('l')} {$now->format('d/m/Y')}",
            $model,
            $this->buildSystemPrompt()
        );

        $parsed = $this->parseJson($response);

        if (!$parsed || empty($parsed['action'])) {
            return $this->showHelp($context);
        }

        return match ($parsed['action']) {
            'create' => $this->createEvent($context, $parsed),
            'list' => $this->listEvents($context),
            'today' => $this->todayEvents($context),
            'remove' => isset($parsed['event_id'])
                ? $this->removeEvent($context, (int) $parsed['event_id'])
                : $this->listEvents($context),
            'update' => isset($parsed['event_id'])
                ? $this->updateEventFromNl($context, (int) $parsed['event_id'], $parsed)
                : $this->showHelp($context),
            'search' => isset($parsed['keyword'])
                ? $this->searchEvents($context, $parsed['keyword'])
                : $this->showHelp($context),
            'help' => $this->showHelp($context),
            default => $this->showHelp($context),
        };
    }

    private function buildSystemPrompt(): string
    {
        return <<<'PROMPT'
Tu es un assistant de gestion d'evenements. Analyse le message et reponds UNIQUEMENT en JSON valide, sans markdown.

FORMAT JSON:
{"action": "create|list|today|remove|update|search|help", ...champs selon action}

ACTIONS:

1. CREER un evenement:
{"action": "create", "event_name": "nom", "event_date": "YYYY-MM-DD", "event_time": "HH:MM", "location": "lieu", "participants": ["nom1"], "description": "details", "reminder_minutes": [30, 60, 1440]}

2. LISTER les evenements:
{"action": "list"}

3. VOIR les evenements DU JOUR:
{"action": "today"}

4. SUPPRIMER un evenement:
{"action": "remove", "event_id": 5}

5. MODIFIER un evenement:
{"action": "update", "event_id": 5, "field": "event_name|event_date|event_time|location|description", "value": "nouvelle valeur"}

6. RECHERCHER par mot-cle:
{"action": "search", "keyword": "mot cle"}

7. AIDE:
{"action": "help"}

REGLES:
- 'remind me about X on DATE at TIME' → create
- 'add event X DATE TIME' → create
- 'demain' = date de demain, 'lundi prochain' = prochaine occurrence du lundi, etc.
- Convertis les heures informelles: '14h' → '14:00', '9h30' → '09:30', 'midi' → '12:00'
- Si pas de time precise, utilise null
- Si pas de reminder_minutes, utilise [30, 60, 1440] par defaut (30min, 1h, 1 jour avant)
- Pour supprimer: detecte l'ID dans 'supprime l'evenement #3', 'annule event 5', etc.
- Pour modifier: detecte l'ID et le champ a modifier
- Ne mets que les champs pertinents pour l'action

EXEMPLES:
- "remind me about Reunion equipe on 2026-03-10 at 14:00" → {"action":"create","event_name":"Reunion equipe","event_date":"2026-03-10","event_time":"14:00","reminder_minutes":[30,60,1440]}
- "add event Dentiste demain a 10h" → {"action":"create","event_name":"Dentiste","event_date":"2026-03-09","event_time":"10:00","reminder_minutes":[30,60,1440]}
- "mes evenements" → {"action":"list"}
- "evenements aujourd'hui" → {"action":"today"}
- "supprime evenement #3" → {"action":"remove","event_id":3}
- "change le lieu de l'event #5 a Paris" → {"action":"update","event_id":5,"field":"location","value":"Paris"}
- "cherche dentiste" → {"action":"search","keyword":"dentiste"}

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

        // Warn if date is in the past
        $now = Carbon::now(AppSetting::timezone());
        $eventDatetime = $parsedDate->copy();
        if (!empty($data['event_time'])) {
            try {
                $timeParts = explode(':', $data['event_time']);
                $eventDatetime->setTime((int) $timeParts[0], (int) ($timeParts[1] ?? 0));
            } catch (\Exception $e) {
                // keep date-only comparison
            }
        }

        if ($eventDatetime->isPast()) {
            $reply = "La date *{$parsedDate->format('d/m/Y')}* est dans le passe. Verifie la date et reessaie.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply);
        }

        $event = EventReminder::create([
            'user_phone' => $context->from,
            'event_name' => $eventName,
            'event_date' => $parsedDate->format('Y-m-d'),
            'event_time' => $data['event_time'] ?? null,
            'location' => $data['location'] ?? null,
            'participants' => $data['participants'] ?? null,
            'description' => $data['description'] ?? null,
            'reminder_times' => $data['reminder_minutes'] ?? [30, 60, 1440],
            'notification_escalation' => false,
            'status' => 'active',
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
            ->get();

        if ($events->isEmpty()) {
            $reply = "Aucun evenement a venir !\n\n"
                . "Ajoute-en un avec :\n"
                . "*remind me about [evenement] on [date] at [heure]*";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply);
        }

        $reply = "*Tes evenements a venir :*\n\n";
        foreach ($events as $event) {
            $reply .= $this->formatEventLine($event) . "\n";
        }

        $reply .= "\nCommandes :\n"
            . "- *remove event #ID* — Supprimer\n"
            . "- *update event #ID champ valeur* — Modifier\n"
            . "- *search event [mot-cle]* — Rechercher";

        $this->sendText($context->from, $reply);
        $this->log($context, 'Events listed', ['count' => $events->count()]);

        return AgentResult::reply($reply, ['count' => $events->count()]);
    }

    private function todayEvents(AgentContext $context): AgentResult
    {
        $today = Carbon::today(AppSetting::timezone());

        $events = EventReminder::where('user_phone', $context->from)
            ->where('status', 'active')
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
                  ->orWhere('location', 'like', "%{$keyword}%");
            })
            ->orderBy('event_date')
            ->orderBy('event_time')
            ->get();

        if ($events->isEmpty()) {
            $reply = "Aucun evenement trouve pour *{$keyword}*.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply);
        }

        $reply = "*Resultats pour \"{$keyword}\" :*\n\n";
        foreach ($events as $event) {
            $reply .= $this->formatEventLine($event) . "\n";
        }

        $this->sendText($context->from, $reply);
        $this->log($context, 'Events searched', ['keyword' => $keyword, 'count' => $events->count()]);

        return AgentResult::reply($reply, ['keyword' => $keyword, 'count' => $events->count()]);
    }

    private function removeEvent(AgentContext $context, int $eventId): AgentResult
    {
        $event = EventReminder::where('id', $eventId)
            ->where('user_phone', $context->from)
            ->first();

        if (!$event) {
            $reply = "Evenement #{$eventId} introuvable. Tape *list events* pour voir tes evenements.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply);
        }

        $name = $event->event_name;
        $event->update(['status' => 'cancelled']);

        $this->log($context, 'Event cancelled', ['event_id' => $eventId]);

        $reply = "Evenement #{$eventId} annule : _{$name}_";
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

        $allowedFields = ['event_name', 'event_date', 'event_time', 'location', 'description'];
        $fieldMap = [
            'name' => 'event_name',
            'nom' => 'event_name',
            'date' => 'event_date',
            'time' => 'event_time',
            'heure' => 'event_time',
            'lieu' => 'location',
            'location' => 'location',
            'description' => 'description',
        ];

        $dbField = $fieldMap[mb_strtolower($field)] ?? null;
        if (!$dbField || !in_array($dbField, $allowedFields)) {
            $reply = "Champ *{$field}* non reconnu.\n"
                . "Champs modifiables : name, date, time, location, description";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply);
        }

        // Validate date if updating event_date
        if ($dbField === 'event_date') {
            try {
                $value = Carbon::parse($value, AppSetting::timezone())->format('Y-m-d');
            } catch (\Exception $e) {
                $reply = "Date invalide : *{$value}*. Utilise le format YYYY-MM-DD.";
                $this->sendText($context->from, $reply);
                return AgentResult::reply($reply);
            }
        }

        $event->update([$dbField => $value]);

        $this->log($context, 'Event updated', ['event_id' => $eventId, 'field' => $dbField, 'value' => $value]);

        $fresh = $event->fresh();
        $reply = "Evenement #{$eventId} mis a jour !\n"
            . "*{$field}* → {$value}\n\n"
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

        return $this->updateEvent($context, $eventId, $field, (string) $value);
    }

    private function enrichResponse(EventReminder $event, string $action): string
    {
        $dateFormatted = $event->event_date->translatedFormat('l j F Y');
        $timeFormatted = $event->event_time ? Carbon::parse($event->event_time)->format('H:i') : 'non definie';
        $timeUntil = $event->timeUntilEvent();

        $response = match ($action) {
            'create' => "Evenement cree ! (#{$event->id})\n\n",
            'reminder' => "Rappel evenement :\n\n",
            default => '',
        };

        $response .= "*{$event->event_name}*\n"
            . "Date : {$dateFormatted}\n"
            . "Heure : {$timeFormatted}\n";

        if ($event->location) {
            $response .= "Lieu : {$event->location}\n";
        }

        if ($event->participants && count($event->participants) > 0) {
            $response .= "Participants : " . implode(', ', $event->participants) . "\n";
        }

        if ($event->description) {
            $response .= "Description : _{$event->description}_\n";
        }

        $response .= "\nDans : *{$timeUntil}*\n";

        $reminderTimes = $event->reminder_times ?? [30, 60, 1440];
        $reminderLabels = array_map(fn ($m) => $this->minutesToLabel($m), $reminderTimes);
        $response .= "Rappels : " . implode(', ', $reminderLabels);

        return $response;
    }

    private function formatEventLine(EventReminder $event): string
    {
        $date = $event->event_date->format('d/m/Y');
        $time = $event->event_time ? Carbon::parse($event->event_time)->format('H:i') : '--:--';
        $location = $event->location ? " ({$event->location})" : '';
        $timeUntil = $event->timeUntilEvent();

        return "#{$event->id} *{$event->event_name}* — {$date} {$time}{$location} — _{$timeUntil}_";
    }

    private function minutesToLabel(int $minutes): string
    {
        if ($minutes >= 1440) {
            $days = intdiv($minutes, 1440);
            return "{$days}j avant";
        }
        if ($minutes >= 60) {
            $hours = intdiv($minutes, 60);
            return "{$hours}h avant";
        }
        return "{$minutes}min avant";
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
        $reply = "*Event Reminder — Gestion d'evenements :*\n\n"
            . "*Creer :*\n"
            . "remind me about [evenement] on [date] at [heure]\n"
            . "add event [nom] [date] [heure]\n\n"
            . "*Gerer :*\n"
            . "list events — Voir les evenements a venir\n"
            . "today events — Evenements du jour\n"
            . "remove event #ID — Annuler un evenement\n"
            . "update event #ID [champ] [valeur] — Modifier\n"
            . "search event [mot-cle] — Rechercher\n\n"
            . "*Exemples :*\n"
            . "- remind me about Reunion equipe on 2026-03-10 at 14:00\n"
            . "- add event Dentiste demain a 10h\n"
            . "- evenements aujourd'hui\n"
            . "- cherche dentiste\n"
            . "- remove event #3\n"
            . "- update event #3 location Salle A";

        $this->sendText($context->from, $reply);
        return AgentResult::reply($reply);
    }
}
