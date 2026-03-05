<?php

namespace App\Services\Agents;

use App\Models\EventReminder;
use App\Services\AgentContext;
use Illuminate\Support\Carbon;

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

    public function canHandle(AgentContext $context): bool
    {
        if (!$context->body) return false;
        return (bool) preg_match('/\b(event|evenement|calendrier|calendar|remind\s+me\s+about|add\s+event|list\s+events?|remove\s+event|update\s+event|event\s+on)\b/iu', $context->body);
    }

    public function handle(AgentContext $context): AgentResult
    {
        $body = trim($context->body ?? '');
        $lower = mb_strtolower($body);

        $this->log($context, 'Event reminder command received', ['body' => mb_substr($body, 0, 100)]);

        // Parse commands
        if (preg_match('/\b(list|lister|voir|mes)\s*(events?|evenements?|calendrier)\b/iu', $lower)) {
            return $this->listEvents($context);
        }

        if (preg_match('/\b(remove|supprimer|annuler|cancel)\s*event\s*#?(\d+)/iu', $lower, $m)) {
            return $this->removeEvent($context, (int) $m[2]);
        }

        if (preg_match('/\bupdate\s*event\s*#?(\d+)\s+(\w+)\s+(.+)/iu', $lower, $m)) {
            return $this->updateEvent($context, (int) $m[1], $m[2], trim($m[3]));
        }

        // Natural language: use Claude to parse event details
        return $this->handleNaturalLanguage($context, $body);
    }

    private function handleNaturalLanguage(AgentContext $context, string $body): AgentResult
    {
        $model = $this->resolveModel($context);
        $now = Carbon::now('Europe/Paris');

        $response = $this->claude->chat(
            "Message utilisateur: \"{$body}\"\nDate/heure actuelle: {$now->format('Y-m-d H:i')} (Europe/Paris)\nJour: {$now->translatedFormat('l')}",
            $model,
            "Tu es un assistant de gestion d'evenements. Analyse le message et reponds UNIQUEMENT en JSON.\n\n"
            . "Format JSON attendu:\n"
            . "{\"action\": \"create|list|remove|help\", \"event_name\": \"...\", \"event_date\": \"YYYY-MM-DD\", \"event_time\": \"HH:MM\", \"location\": \"...\", \"participants\": [\"...\"], \"description\": \"...\", \"reminder_minutes\": [30, 60, 1440]}\n\n"
            . "Regles:\n"
            . "- 'remind me about X on DATE at TIME' → create\n"
            . "- 'add event X DATE TIME' → create\n"
            . "- 'demain' = date de demain, 'lundi prochain' = date du prochain lundi, etc.\n"
            . "- Si pas de time precise, utilise null\n"
            . "- Si pas de reminder_minutes, utilise [30, 60, 1440] par defaut (30min, 1h, 1 jour avant)\n"
            . "- Reponds UNIQUEMENT avec le JSON, rien d'autre."
        );

        $parsed = json_decode(trim($response ?? ''), true);

        if (!$parsed || empty($parsed['action'])) {
            return $this->showHelp();
        }

        return match ($parsed['action']) {
            'create' => $this->createEvent($context, $parsed),
            'list' => $this->listEvents($context),
            'remove' => isset($parsed['event_id']) ? $this->removeEvent($context, (int) $parsed['event_id']) : $this->listEvents($context),
            default => $this->showHelp(),
        };
    }

    private function createEvent(AgentContext $context, array $data): AgentResult
    {
        $eventName = $data['event_name'] ?? null;
        $eventDate = $data['event_date'] ?? null;

        if (!$eventName || !$eventDate) {
            return AgentResult::reply(
                "Je n'ai pas pu comprendre l'evenement. Precise au moins un nom et une date.\n\n"
                . "Exemple : *remind me about Reunion equipe on 2026-03-10 at 14:00*"
            );
        }

        $event = EventReminder::create([
            'user_phone' => $context->from,
            'event_name' => $eventName,
            'event_date' => $eventDate,
            'event_time' => $data['event_time'] ?? null,
            'location' => $data['location'] ?? null,
            'participants' => $data['participants'] ?? null,
            'description' => $data['description'] ?? null,
            'reminder_times' => $data['reminder_minutes'] ?? [30, 60, 1440],
            'notification_escalation' => false,
            'status' => 'active',
        ]);

        $this->log($context, 'Event created', ['event_id' => $event->id, 'name' => $eventName]);

        return AgentResult::reply($this->enrichResponse($event, 'create'));
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
            return AgentResult::reply(
                "Aucun evenement a venir !\n\n"
                . "Ajoute-en un avec :\n"
                . "*remind me about [evenement] on [date] at [heure]*"
            );
        }

        $response = "*Tes evenements a venir :*\n\n";
        foreach ($events as $event) {
            $response .= $this->formatEventLine($event) . "\n";
        }

        $response .= "\nCommandes :\n"
            . "- *remove event #ID* — Supprimer\n"
            . "- *update event #ID field value* — Modifier";

        return AgentResult::reply($response);
    }

    private function removeEvent(AgentContext $context, int $eventId): AgentResult
    {
        $event = EventReminder::where('id', $eventId)
            ->where('user_phone', $context->from)
            ->first();

        if (!$event) {
            return AgentResult::reply("Evenement #{$eventId} introuvable.");
        }

        $name = $event->event_name;
        $event->update(['status' => 'cancelled']);

        $this->log($context, 'Event cancelled', ['event_id' => $eventId]);

        return AgentResult::reply("Evenement #{$eventId} annule : _{$name}_");
    }

    private function updateEvent(AgentContext $context, int $eventId, string $field, string $value): AgentResult
    {
        $event = EventReminder::where('id', $eventId)
            ->where('user_phone', $context->from)
            ->first();

        if (!$event) {
            return AgentResult::reply("Evenement #{$eventId} introuvable.");
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
            return AgentResult::reply(
                "Champ *{$field}* non reconnu.\n"
                . "Champs modifiables : name, date, time, location, description"
            );
        }

        $event->update([$dbField => $value]);

        $this->log($context, 'Event updated', ['event_id' => $eventId, 'field' => $dbField, 'value' => $value]);

        return AgentResult::reply(
            "Evenement #{$eventId} mis a jour !\n"
            . "*{$field}* → {$value}\n\n"
            . $this->formatEventLine($event->fresh())
        );
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

        // Reminder schedule
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

    private function showHelp(): AgentResult
    {
        return AgentResult::reply(
            "*Event Reminder — Gestion d'evenements :*\n\n"
            . "*Creer :*\n"
            . "remind me about [evenement] on [date] at [heure]\n"
            . "add event [nom] [date] [heure]\n\n"
            . "*Gerer :*\n"
            . "list events — Voir les evenements a venir\n"
            . "remove event #ID — Annuler un evenement\n"
            . "update event #ID [champ] [valeur] — Modifier\n\n"
            . "*Exemples :*\n"
            . "- remind me about Reunion equipe on 2026-03-10 at 14:00\n"
            . "- add event Dentiste demain a 10h\n"
            . "- list events\n"
            . "- remove event #3"
        );
    }
}
