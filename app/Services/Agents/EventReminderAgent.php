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
        return 'Agent de gestion d\'evenements et calendrier. Permet de creer, lister, rechercher, modifier, dupliquer, reporter et supprimer des evenements avec date, heure, lieu, participants et rappels automatiques configurables. Supporte la vue du jour, de la semaine, du mois, l\'agenda par date precise, l\'historique des evenements passes/annules, le detail par ID, la confirmation avant suppression, l\'ajout de notes sur un evenement et l\'export formaté de la semaine.';
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
            'history', 'historique', 'history events', 'historique evenements',
            'prochains jours', 'next days', 'prochains evenements', 'upcoming',
            'restore event', 'restaurer evenement', 'reactiver evenement', 'restore',
            'evenements passes', 'événements passés', 'archives', 'archives evenements',
            'termines', 'annules', 'evenements termines', 'evenements annules',
            'agenda', 'agenda date', 'evenements le', 'events on',
            'briefing', 'briefing agenda', 'resume agenda', 'digest agenda', 'resume du jour',
            'clear history', 'vider historique', 'purge history', 'purger evenements',
            'effacer historique', 'supprimer historique',
            'note event', 'ajouter note', 'note evenement', 'append note',
            'export week', 'export semaine', 'recap semaine', 'recap week',
            'partager semaine', 'partager agenda', 'summary week',
            'conflicts', 'conflits', 'check conflicts', 'verifier conflits', 'conflit agenda',
            'conflit horaire', 'chevauchement', 'overlap events',
        ];
    }

    public function version(): string
    {
        return '1.9.0';
    }

    public function canHandle(AgentContext $context): bool
    {
        if (!$context->body) return false;
        return (bool) preg_match('/\b(event|evenement|événement|calendrier|calendar|agenda|remind\s+me\s+about|add\s+event|list\s+events?|remove\s+event|update\s+event|modifier\s+evenement|event\s+on|rdv|rendez-vous|rendez\s+vous|appointment|planifier|search\s+event|chercher\s+evenement|week\s+events?|cette\s+semaine|this\s+week|duplicate\s+event|dupliquer|copier\s+evenement|copy\s+event|show\s+event|detail\s+event|month\s+events?|ce\s+mois|this\s+month|postpone|reporter\s+evenement|decaler|next\s+event|prochain\s+evenement|prochain\s+événement|stats\s+events?|statistiques|done\s+event|terminer\s+evenement|mark\s+done|marquer\s+fait|history|historique|archives?|evenements?\s+passes?|termines?|annules?|prochains?\s+\d+\s+jours?|next\s+\d+\s+days?|restore\s+event|restaurer\s+evenement|reactiver\s+evenement|upcoming|briefing|digest\s+agenda|resume\s+agenda|clear\s+history|vider\s+historique|purge\s+history|purger\s+evenements|effacer\s+historique|supprimer\s+historique|note\s+event|ajouter\s+note|note\s+evenement|append\s+note|export\s+(?:week|semaine)|recap\s+(?:week|semaine)|partager\s+(?:semaine|agenda)|summary\s+week|conflicts?|conflits?|check\s+conflicts?|v[eé]rifier\s+conflits?|conflit\s+(?:agenda|horaire)|chevauchement)\b/iu', $context->body);
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

        // Batch mark events as done: done events #1 #2 #3
        if (preg_match('/\b(done|terminer|termine|fait|completed?|mark\s+done|marquer\s+(?:fait|termine))\s*(?:events?|evenements?)?\s+((?:#?\d+[\s,]+){1,}#?\d+)\b/iu', $body, $bm)) {
            preg_match_all('/\d+/', $bm[2], $bids);
            if (!empty($bids[0]) && count($bids[0]) > 1) {
                return $this->batchMarkDone($context, array_map('intval', $bids[0]));
            }
        }

        // Mark event as done
        if (preg_match('/\b(done|terminer|termine|fait|completed?|mark\s+done|marquer\s+(?:fait|termine))\s*(?:event|evenement)?\s*#?(\d+)/iu', $lower, $m)) {
            return $this->markEventDone($context, (int) $m[2]);
        }

        // Briefing (smart daily briefing with LLM)
        if (preg_match('/\b(briefing|digest\s+agenda|resume\s+agenda|resume\s+du\s+jour)\b/iu', $lower)) {
            return $this->briefingEvents($context);
        }

        // Clear history (purge done/cancelled events)
        if (preg_match('/\b(clear\s+history|vider\s+historique|purge\s+history|purger\s+evenements?|effacer\s+historique|supprimer\s+historique)\b/iu', $lower)) {
            return $this->confirmClearHistory($context);
        }

        // Stats
        if (preg_match('/\b(stats?|statistiques?|statistics?|bilan)\s*(?:events?|evenements?|calendrier)?/iu', $lower)) {
            return $this->statsEvents($context);
        }

        // Detect scheduling conflicts
        if (preg_match('/\b(conflicts?|conflits?|check\s+conflicts?|v[eé]rifier\s+conflits?|conflit\s+(?:agenda|horaire)|chevauchement|overlap\s+events?)\b/iu', $lower)) {
            return $this->detectConflicts($context);
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

        // History (past/done/cancelled events) with optional status filter
        if (preg_match('/\b(history|historique|archives?|evenements?\s+passes?|evenements?\s+termines?|evenements?\s+annules?)\b/iu', $lower)) {
            $statusFilter = null;
            if (preg_match('/\b(?:history|historique)\s+(?:done|termines?)\b/iu', $lower)
                || preg_match('/\bevenements?\s+termines?\b/iu', $lower)) {
                $statusFilter = 'done';
            } elseif (preg_match('/\b(?:history|historique)\s+(?:cancel(?:led)?|annules?)\b/iu', $lower)
                || preg_match('/\bevenements?\s+annules?\b/iu', $lower)) {
                $statusFilter = 'cancelled';
            }
            return $this->historyEvents($context, $statusFilter);
        }

        // Restore event
        if (preg_match('/\b(restore|restaurer|reactiver)\s*(?:event|evenement)?\s*#?(\d+)/iu', $lower, $m)) {
            return $this->restoreEvent($context, (int) $m[2]);
        }

        // Upcoming N days
        if (preg_match('/\b(?:prochains?\s+(\d+)\s+jours?|next\s+(\d+)\s+days?|upcoming\s+(\d+))\b/iu', $lower, $m)) {
            $days = (int) ($m[1] ?: $m[2] ?: $m[3]);
            return $this->upcomingDays($context, max(1, min($days, 90)));
        }

        // Upcoming without N → default 7 days
        if (preg_match('/\b(upcoming|prochains?\s+evenements?|prochains?\s+jours?|next\s+events?)\b/iu', $lower)) {
            return $this->upcomingDays($context, 7);
        }

        // Agenda for a specific date
        if (preg_match('/\b(?:agenda|events?\s+on|evenements?\s+(?:le|du|pour))\s+(.+)/iu', $body, $m)) {
            return $this->agendaDate($context, trim($m[1]));
        }

        // Append note to event
        if (preg_match('/\b(?:note|ajouter?\s*(?:une?\s*)?note|append\s+note)\s*(?:event|evenement|evenement)?\s*#?(\d+)\s+(.+)/iu', $body, $m)) {
            return $this->appendNoteToEvent($context, (int) $m[1], trim($m[2]));
        }

        // Export / recap week
        if (preg_match('/\b(export|recap|recapitulatif|partager|partage|summary)\s*(?:de\s+(?:la\s+)?)?(?:semaine|week|agenda)?\b/iu', $lower)) {
            return $this->exportWeek($context);
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

        if ($type === 'confirm_clear_history') {
            $body  = mb_strtolower(trim($context->body ?? ''));
            $count = (int) ($pendingContext['data']['count'] ?? 0);

            $this->clearPendingContext($context);

            if (preg_match('/^(oui|yes|o|y|ok|confirme|confirm|delete|si|1)\b/iu', $body)) {
                return $this->clearHistory($context, $count);
            }

            $reply = "Purge annulee. Ton historique est conserve.";
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
            'history'        => $this->historyEvents($context),
            'agenda'         => isset($parsed['date'])
                ? $this->agendaDate($context, $parsed['date'])
                : $this->showHelp($context),
            'upcoming_days'  => isset($parsed['days'])
                ? $this->upcomingDays($context, max(1, min((int) $parsed['days'], 90)))
                : $this->upcomingDays($context, 7),
            'restore'        => isset($parsed['event_id'])
                ? $this->restoreEvent($context, (int) $parsed['event_id'])
                : $this->showHelp($context),
            'briefing'       => $this->briefingEvents($context),
            'clear_history'  => $this->confirmClearHistory($context),
            'note'           => isset($parsed['event_id'], $parsed['note'])
                ? $this->appendNoteToEvent($context, (int) $parsed['event_id'], $parsed['note'])
                : $this->showHelp($context),
            'export_week'    => $this->exportWeek($context),
            'conflicts'      => $this->detectConflicts($context),
            'upcoming'       => $this->upcomingDays($context, 7),
            'help'           => $this->showHelp($context),
            default          => $this->showHelp($context),
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
{"action": "create|list|today|week|month|show|remove|update|search|duplicate|postpone|next|stats|done|history|agenda|upcoming_days|restore|briefing|clear_history|note|export_week|conflicts|upcoming|help", ...champs selon action}

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

15. HISTORIQUE (evenements passes, termines, annules):
{"action": "history"}

16. AGENDA pour une date precise:
{"action": "agenda", "date": "YYYY-MM-DD"}

17. AIDE:
{"action": "help"}

18. PROCHAINS N JOURS (liste events des N prochains jours):
{"action": "upcoming_days", "days": 5}
days = nombre de jours (1 a 90)

19. RESTAURER un evenement annule ou termine:
{"action": "restore", "event_id": 5}

20. BRIEFING AGENDA (resume IA du jour + semaine):
{"action": "briefing"}

21. VIDER L'HISTORIQUE (supprimer tous les evenements termines/annules):
{"action": "clear_history"}

22. AJOUTER UNE NOTE a un evenement (ajoute du texte a la description):
{"action": "note", "event_id": 5, "note": "texte de la note"}

23. EXPORTER / RECAP de la semaine (resume partageabe des evenements de la semaine):
{"action": "export_week"}

24. DETECTER LES CONFLITS D'HORAIRE (evenements au meme moment):
{"action": "conflicts"}

25. VOIR LES PROCHAINS EVENEMENTS (sans nombre de jours specifie, defaut 7 jours):
{"action": "upcoming"}

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
- 'history' / 'historique' / 'mes evenements passes' / 'archives' → history
- 'agenda 15 mars' / 'events on 2026-03-20' / 'evenements du 20 mars' → agenda avec date
- 'prochains 5 jours' / 'next 5 days' / 'evenements prochains 3 jours' → upcoming_days avec days
- 'restore event #5' / 'restaurer evenement #5' / 'reactiver event #5' → restore avec event_id
- 'briefing' / 'resume agenda' / 'digest agenda' / 'mon briefing du jour' → briefing
- 'clear history' / 'vider historique' / 'purge history' / 'supprimer historique' → clear_history
- 'note event #5 Apporter le rapport' / 'ajouter note evenement #3 Call conference' → note avec event_id et note
- 'export semaine' / 'recap semaine' / 'partager agenda' / 'summary week' → export_week
- 'conflicts' / 'conflits' / 'check conflicts' / 'verifier conflits' / 'chevauchement' → conflicts
- 'upcoming' / 'prochains evenements' → upcoming
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
- "mes evenements passes" → {"action":"history"}
- "agenda du 20 mars" → {"action":"agenda","date":"{$nextWeek}"}
- "evenements le 2026-03-20" → {"action":"agenda","date":"2026-03-20"}
- "prochains 5 jours" → {"action":"upcoming_days","days":5}
- "next 3 days events" → {"action":"upcoming_days","days":3}
- "restaure l'event #7" → {"action":"restore","event_id":7}

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

        // Conflict detection: warn if another active event exists on same date/time
        $conflictQuery = EventReminder::where('user_phone', $context->from)
            ->active()
            ->whereDate('event_date', $parsedDate->format('Y-m-d'));

        if ($eventTime) {
            $conflictQuery->where('event_time', $eventTime);
        }

        $conflict = $conflictQuery->first();
        $conflictWarning = '';
        if ($conflict) {
            $conflictTime = $eventTime ?: 'toute la journee';
            $conflictWarning = "\n\n⚠️ _Attention : tu as deja un evenement ce jour"
                . ($eventTime ? " a {$eventTime}" : '') . " : *{$conflict->event_name}* (#{$conflict->id})._";
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

        $reply = $this->enrichResponse($event, 'create') . $conflictWarning;
        $this->sendText($context->from, $reply);

        return AgentResult::reply($reply, ['event_id' => $event->id, 'conflict' => $conflict?->id]);
    }

    private function listEvents(AgentContext $context): AgentResult
    {
        $totalCount = EventReminder::where('user_phone', $context->from)
            ->active()->upcoming()->count();

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

        $shown = $events->count();
        $reply = "*Tes evenements a venir ({$totalCount}) :*\n";

        $currentDay = null;
        foreach ($events as $event) {
            $dayLabel = $event->event_date->translatedFormat('l j F Y');
            if ($dayLabel !== $currentDay) {
                $reply     .= "\n_{$dayLabel}_\n";
                $currentDay = $dayLabel;
            }
            $reply .= $this->formatEventLine($event) . "\n";
        }

        if ($totalCount > 20) {
            $reply .= "\n_... et " . ($totalCount - 20) . " autre(s). Tape *prochains 30 jours* ou *search event [mot-cle]* pour voir plus._\n";
        }

        $reply .= "\n_Commandes : show/done/remove/update/postpone/duplicate event #ID_";

        $this->sendText($context->from, $reply);
        $this->log($context, 'Events listed', ['count' => $totalCount]);

        return AgentResult::reply($reply, ['count' => $totalCount]);
    }

    private function todayEvents(AgentContext $context): AgentResult
    {
        $today = Carbon::today(AppSetting::timezone());

        $events = EventReminder::where('user_phone', $context->from)
            ->active()
            ->whereDate('event_date', $today->format('Y-m-d'))
            ->orderBy('event_time')
            ->get();

        // Also fetch overdue active events (previous days, still active)
        $overdueEvents = EventReminder::where('user_phone', $context->from)
            ->active()
            ->whereDate('event_date', '<', $today->format('Y-m-d'))
            ->orderBy('event_date', 'desc')
            ->orderBy('event_time')
            ->limit(5)
            ->get();

        if ($events->isEmpty() && $overdueEvents->isEmpty()) {
            $reply = "Aucun evenement prevu aujourd'hui (" . $today->translatedFormat('l j F') . ").\n\n"
                . "Tape *list events* pour voir tous tes evenements a venir.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply);
        }

        $reply = "*Agenda du " . $today->translatedFormat('l j F Y') . " :*\n\n";

        if ($events->isNotEmpty()) {
            foreach ($events as $event) {
                $reply .= $this->formatEventLine($event) . "\n";
            }
        } else {
            $reply .= "_Aucun evenement aujourd'hui._\n";
        }

        if ($overdueEvents->isNotEmpty()) {
            $reply .= "\n*En retard ({$overdueEvents->count()}) :*\n";
            foreach ($overdueEvents as $event) {
                $reply .= $this->formatEventLine($event) . "\n";
            }
            $reply .= "\n_Ces evenements sont passes mais toujours actifs. Tape *done event #ID* ou *remove event #ID*._";
        }

        $this->sendText($context->from, $reply);
        $this->log($context, 'Today events listed', ['count' => $events->count(), 'overdue' => $overdueEvents->count()]);

        return AgentResult::reply($reply, ['count' => $events->count(), 'overdue' => $overdueEvents->count()]);
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
        $isOverdue     = $event->status === 'active' && $event->event_date->isPast() && !$event->event_date->isToday();
        $statusLabel   = $isOverdue ? 'En retard ⚠️' : ($event->status === 'active' ? 'Actif' : ucfirst($event->status));

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

        // Fetch next upcoming event for summary at top
        $nextEvent = EventReminder::where('user_phone', $context->from)
            ->active()->upcoming()
            ->orderBy('event_date')->orderBy('event_time')
            ->first();

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

        $overdueCount = EventReminder::where('user_phone', $context->from)
            ->active()
            ->whereDate('event_date', '<', $today->format('Y-m-d'))
            ->count();

        $cancelledCount = EventReminder::where('user_phone', $context->from)
            ->where('status', 'cancelled')->count();

        $doneCount = EventReminder::where('user_phone', $context->from)
            ->where('status', 'done')->count();

        $next30Count = EventReminder::where('user_phone', $context->from)
            ->active()
            ->whereBetween('event_date', [
                $today->format('Y-m-d'),
                $today->copy()->addDays(29)->format('Y-m-d'),
            ])
            ->count();

        // Busiest upcoming week (next 28 days, grouped by ISO week)
        $busiestWeekLabel = null;
        $busiestWeekCount = 0;
        $upcomingEvents   = EventReminder::where('user_phone', $context->from)
            ->active()
            ->where('event_date', '>=', $today->format('Y-m-d'))
            ->where('event_date', '<=', $today->copy()->addDays(27)->format('Y-m-d'))
            ->get(['event_date']);

        if ($upcomingEvents->isNotEmpty()) {
            $weekGroups = $upcomingEvents->groupBy(fn ($e) => $e->event_date->format('W'));
            $busiest    = $weekGroups->map->count()->sortDesc()->first();
            $busiestKey = $weekGroups->map->count()->sortDesc()->keys()->first();
            if ($busiest > 1) {
                $busiestWeekCount = $busiest;
                // Find a representative date from that week
                $sampleDate       = $weekGroups[$busiestKey]->first()->event_date;
                $weekStart        = $sampleDate->copy()->startOfWeek(\Carbon\Carbon::MONDAY)->format('d/m');
                $weekEnd          = $sampleDate->copy()->endOfWeek(\Carbon\Carbon::SUNDAY)->format('d/m');
                $busiestWeekLabel = "{$weekStart}-{$weekEnd}";
            }
        }

        $monthLabel = $today->translatedFormat('F Y');

        $reply = "*Statistiques de ton calendrier :*\n\n";

        if ($nextEvent) {
            $nextDateFmt  = $nextEvent->event_date->translatedFormat('l j F');
            $nextTimeFmt  = $nextEvent->event_time ? ' a ' . Carbon::parse($nextEvent->event_time)->format('H:i') : '';
            $nextTimeUntil = $nextEvent->timeUntilEvent();
            $reply .= "Prochain : *{$nextEvent->event_name}* — {$nextDateFmt}{$nextTimeFmt} _{$nextTimeUntil}_\n\n";
        }

        $reply .= "Aujourd'hui : *{$todayCount}*\n";
        $reply .= "Cette semaine (7j) : *{$weekCount}*\n";
        $reply .= "Ce mois ({$monthLabel}) : *{$monthCount}*\n";
        $reply .= "Prochains 30 jours : *{$next30Count}*\n";
        $reply .= "Total actifs a venir : *{$upcoming}*\n";

        if ($overdueCount > 0) {
            $reply .= "En retard (a traiter) : *{$overdueCount}* ⚠️\n";
        }

        if ($busiestWeekLabel && $busiestWeekCount > 1) {
            $reply .= "\nSemaine la plus chargee : *{$busiestWeekLabel}* ({$busiestWeekCount} events)\n";
        }

        $reply .= "\nTermines : *{$doneCount}*\n";
        $reply .= "Annules : *{$cancelledCount}*\n";
        $reply .= "Total actifs : *{$totalActive}*";

        // Completion rate (done / (done + cancelled), i.e. among resolved events)
        $resolved = $doneCount + $cancelledCount;
        if ($resolved > 0) {
            $completionRate = round(($doneCount / $resolved) * 100);
            $reply .= "\nTaux de completion : *{$completionRate}%*";
        }

        if ($overdueCount > 0) {
            $reply .= "\n\n_Tape *today events* pour voir les evenements en retard._";
        }

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
            'overdue'    => $overdueCount,
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

        $activeCount    = $events->where('status', 'active')->count();
        $archivedCount  = $events->whereIn('status', ['done', 'cancelled'])->count();
        $total          = $events->count();

        $reply = "*Resultats pour \"{$keyword}\" ({$total}) :*\n\n";
        foreach ($events as $event) {
            if ($event->status === 'done') {
                $time = $event->event_time ? Carbon::parse($event->event_time)->format('H:i') : '--:--';
                $reply .= "✓ #{$event->id} _{$event->event_name}_ — " . $event->event_date->format('d/m/Y') . " {$time} [termine]\n";
            } elseif ($event->status === 'cancelled') {
                $time = $event->event_time ? Carbon::parse($event->event_time)->format('H:i') : '--:--';
                $reply .= "✗ #{$event->id} _{$event->event_name}_ — " . $event->event_date->format('d/m/Y') . " {$time} [annule]\n";
            } else {
                $reply .= $this->formatEventLine($event) . "\n";
            }
        }

        if ($archivedCount > 0) {
            $reply .= "\n_Actifs : {$activeCount} | Archives : {$archivedCount}_";
        }

        $reply .= "\n\nTape *show event #ID* pour voir les details.";

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

        $overdueTag = ($event->status === 'active' && $event->event_date->isPast() && !$event->event_date->isToday())
            ? ' ⚠️' : '';

        return "#{$event->id} *{$event->event_name}*{$overdueTag} — {$date} {$time}{$location}{$extra} — _{$timeUntil}_";
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
        $reply = "*Event Reminder v1.9 — Gestion d'evenements :*\n\n"
            . "*Creer :*\n"
            . "remind me about [evenement] on [date] at [heure]\n"
            . "add event [nom] [date] [heure]\n\n"
            . "*Consulter :*\n"
            . "briefing — Briefing IA du jour + semaine\n"
            . "list events — Tous les evenements a venir\n"
            . "next event — Prochain evenement\n"
            . "upcoming — Prochains 7 jours\n"
            . "today events — Agenda du jour + retards\n"
            . "week events — Evenements de la semaine\n"
            . "month events — Evenements du mois\n"
            . "prochains N jours — Evenements des N prochains jours\n"
            . "agenda [date] — Evenements d'une date precise\n"
            . "show event #ID — Detail d'un evenement\n"
            . "stats events — Statistiques + taux de completion\n"
            . "conflicts — Detecter les conflits horaires\n"
            . "history — Evenements termines / annules\n"
            . "history done — Uniquement les termines\n"
            . "history cancelled — Uniquement les annules\n"
            . "export semaine — Resume partageble de la semaine\n\n"
            . "*Gerer :*\n"
            . "done event #ID — Marquer comme termine\n"
            . "done events #1 #2 #3 — Marquer plusieurs comme termines\n"
            . "restore event #ID — Restaurer un evenement annule/termine\n"
            . "remove event #ID — Supprimer (avec confirmation)\n"
            . "update event #ID [champ] [valeur] — Modifier\n"
            . "postpone event #ID by [duree] — Reporter\n"
            . "duplicate event #ID to [date] — Dupliquer\n"
            . "note event #ID [texte] — Ajouter une note\n"
            . "search event [mot-cle] — Rechercher (actifs + archives)\n"
            . "clear history — Vider l'historique (confirmation requise)\n\n"
            . "*Exemples :*\n"
            . "- briefing\n"
            . "- remind me about Reunion equipe on 2026-03-15 at 14:00\n"
            . "- add event Dentiste demain a 10h\n"
            . "- next event\n"
            . "- upcoming\n"
            . "- conflicts\n"
            . "- stats events\n"
            . "- prochains 5 jours\n"
            . "- agenda 2026-03-20\n"
            . "- history\n"
            . "- history done\n"
            . "- clear history\n"
            . "- show event #5\n"
            . "- done event #5\n"
            . "- done events #5 #6 #7\n"
            . "- note event #5 Apporter le rapport\n"
            . "- export semaine\n"
            . "- restore event #5\n"
            . "- month events\n"
            . "- postpone event #3 by 1 semaine\n"
            . "- update event #3 location Salle A\n"
            . "- update event #3 participants Alice, Bob\n"
            . "- duplicate event #3 to 2026-03-20\n"
            . "- remove event #3";

        $this->sendText($context->from, $reply);
        return AgentResult::reply($reply);
    }

    private function agendaDate(AgentContext $context, string $dateStr): AgentResult
    {
        try {
            $date = Carbon::parse($dateStr, AppSetting::timezone());
        } catch (\Exception $e) {
            $reply = "Date non reconnue : *{$dateStr}*.\n"
                . "Exemples : *agenda 2026-03-20*, *agenda demain*, *agenda lundi prochain*";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply);
        }

        $events = EventReminder::where('user_phone', $context->from)
            ->whereDate('event_date', $date->format('Y-m-d'))
            ->whereIn('status', ['active', 'done'])
            ->orderBy('event_time')
            ->get();

        $dayLabel = $date->translatedFormat('l j F Y');
        $isToday  = $date->isToday();
        $isPast   = $date->isPast() && !$isToday;

        if ($events->isEmpty()) {
            $qualifier = $isPast ? 'ce jour-la' : ($isToday ? "aujourd'hui" : 'ce jour');
            $reply     = "Aucun evenement {$qualifier} ({$dayLabel}).";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply);
        }

        $total = $events->count();
        $emoji = $isPast ? '📅' : ($isToday ? '📌' : '📆');
        $reply = "{$emoji} *Agenda du {$dayLabel} ({$total}) :*\n\n";

        foreach ($events as $event) {
            $statusTag = $event->status === 'done' ? ' ✓' : '';
            $time      = $event->event_time ? Carbon::parse($event->event_time)->format('H:i') : '--:--';
            $location  = $event->location ? " ({$event->location})" : '';
            $reply    .= "#{$event->id} *{$event->event_name}*{$statusTag} — {$time}{$location}\n";
        }

        $reply .= "\n_Tape *show event #ID* pour les details._";

        $this->sendText($context->from, $reply);
        $this->log($context, 'Agenda date shown', ['date' => $date->format('Y-m-d'), 'count' => $total]);

        return AgentResult::reply($reply, ['date' => $date->format('Y-m-d'), 'count' => $total]);
    }

    private function historyEvents(AgentContext $context, ?string $statusFilter = null): AgentResult
    {
        $query = EventReminder::where('user_phone', $context->from);

        if ($statusFilter === 'done') {
            $query->where('status', 'done');
        } elseif ($statusFilter === 'cancelled') {
            $query->where('status', 'cancelled');
        } else {
            $query->whereIn('status', ['done', 'cancelled']);
        }

        $events = $query->orderBy('event_date', 'desc')
            ->orderBy('event_time', 'desc')
            ->limit(15)
            ->get();

        $filterLabel = match ($statusFilter) {
            'done'      => ' (termines)',
            'cancelled' => ' (annules)',
            default     => '',
        };

        if ($events->isEmpty()) {
            $emptyMsg = match ($statusFilter) {
                'done'      => "Aucun evenement termine dans ton historique.",
                'cancelled' => "Aucun evenement annule dans ton historique.",
                default     => "Aucun evenement termine ou annule dans ton historique.",
            };
            $this->sendText($context->from, $emptyMsg);
            return AgentResult::reply($emptyMsg);
        }

        $doneCount      = $events->where('status', 'done')->count();
        $cancelledCount = $events->where('status', 'cancelled')->count();
        $total          = $events->count();

        $reply = "*Historique de tes evenements{$filterLabel} ({$total}) :*\n";
        $reply .= "_Termines : {$doneCount} | Annules : {$cancelledCount}_\n\n";

        $currentDay = null;
        foreach ($events as $event) {
            $dayLabel = $event->event_date->translatedFormat('l j F Y');
            if ($dayLabel !== $currentDay) {
                $reply     .= "\n_{$dayLabel}_\n";
                $currentDay = $dayLabel;
            }
            $statusIcon = $event->status === 'done' ? '✓' : '✗';
            $time       = $event->event_time ? Carbon::parse($event->event_time)->format('H:i') : '--:--';
            $loc        = $event->location ? " ({$event->location})" : '';
            $reply     .= "[{$statusIcon}] #{$event->id} *{$event->event_name}* — {$time}{$loc}\n";
        }

        if ($total >= 15) {
            $reply .= "\n_Affichage limite aux 15 derniers evenements._";
        }

        $reply .= "\n\n_Tape *restore event #ID* pour reactiver un evenement annule._";

        $this->sendText($context->from, $reply);
        $this->log($context, 'History shown', ['done' => $doneCount, 'cancelled' => $cancelledCount, 'filter' => $statusFilter]);

        return AgentResult::reply($reply, ['done' => $doneCount, 'cancelled' => $cancelledCount, 'total' => $total]);
    }

    private function upcomingDays(AgentContext $context, int $days): AgentResult
    {
        $today  = Carbon::today(AppSetting::timezone());
        $endDay = $today->copy()->addDays($days - 1)->endOfDay();

        $events = EventReminder::where('user_phone', $context->from)
            ->active()
            ->whereBetween('event_date', [$today->format('Y-m-d'), $endDay->format('Y-m-d')])
            ->orderBy('event_date')
            ->orderBy('event_time')
            ->get();

        $label = $days === 1 ? "aujourd'hui" : "les {$days} prochains jours";

        if ($events->isEmpty()) {
            $reply = "Aucun evenement dans {$label} "
                . "(" . $today->format('d/m') . " - " . $endDay->format('d/m/Y') . ").\n\n"
                . "Tape *list events* pour voir tous tes evenements a venir.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply);
        }

        $reply = "*Evenements : {$label} (" . $today->format('d/m') . " - " . $endDay->format('d/m') . ") — {$events->count()} event(s) :*\n";

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
        $this->log($context, 'Upcoming days listed', ['days' => $days, 'count' => $events->count()]);

        return AgentResult::reply($reply, ['days' => $days, 'count' => $events->count()]);
    }

    private function briefingEvents(AgentContext $context): AgentResult
    {
        $tz    = AppSetting::timezone();
        $today = Carbon::today($tz);
        $now   = Carbon::now($tz);

        $todayEvents = EventReminder::where('user_phone', $context->from)
            ->active()
            ->whereDate('event_date', $today->format('Y-m-d'))
            ->orderBy('event_time')
            ->get();

        $upcomingEvents = EventReminder::where('user_phone', $context->from)
            ->active()
            ->whereBetween('event_date', [
                $today->copy()->addDay()->format('Y-m-d'),
                $today->copy()->addDays(7)->format('Y-m-d'),
            ])
            ->orderBy('event_date')
            ->orderBy('event_time')
            ->limit(10)
            ->get();

        $overdueCount = EventReminder::where('user_phone', $context->from)
            ->active()
            ->whereDate('event_date', '<', $today->format('Y-m-d'))
            ->count();

        $dayLabel = $now->translatedFormat('l j F Y');

        // Build a context string for the LLM
        $eventsContext = "Aujourd'hui ({$dayLabel}) : ";
        if ($todayEvents->isNotEmpty()) {
            $items = $todayEvents->map(fn ($e) => $e->event_name . ($e->event_time ? ' à ' . Carbon::parse($e->event_time)->format('H:i') : ''))->implode(', ');
            $eventsContext .= $items;
        } else {
            $eventsContext .= 'aucun evenement';
        }

        $eventsContext .= "\nProchains 7 jours : ";
        if ($upcomingEvents->isNotEmpty()) {
            $items = $upcomingEvents->map(fn ($e) => $e->event_name . ' (' . $e->event_date->translatedFormat('l j') . ')')->implode(', ');
            $eventsContext .= $items;
        } else {
            $eventsContext .= 'aucun evenement prevu';
        }

        if ($overdueCount > 0) {
            $eventsContext .= "\nEvenements en retard (actifs mais date passee) : {$overdueCount}";
        }

        $model        = $this->resolveModel($context);
        $briefingText = $this->claude->chat(
            "Genere un briefing agenda matinal court et motivant (3-5 phrases max) pour cet utilisateur. "
            . "Contexte agenda : {$eventsContext}. "
            . "Sois chaleureux et professionnel. Texte simple sans markdown.",
            $model,
            "Tu es un assistant de calendrier personnel. Genere un briefing agenda positif et concis. "
            . "Mets en avant les evenements du jour, signale les retards si applicable, "
            . "et conclus avec une note motivante. Maximum 5 phrases. Pas de markdown."
        );

        $reply  = "*Briefing du {$dayLabel}*\n\n";
        $reply .= ($briefingText ?: "Bonjour ! Voici ton agenda du jour.") . "\n";

        if ($todayEvents->isNotEmpty()) {
            $reply .= "\n*Aujourd'hui :*\n";
            foreach ($todayEvents as $event) {
                $time         = $event->event_time ? Carbon::parse($event->event_time)->format('H:i') : '--:--';
                $loc          = $event->location ? " ({$event->location})" : '';
                $participants = !empty($event->participants) ? ' — ' . implode(', ', $event->participants) : '';
                $reply       .= "• {$time} *{$event->event_name}*{$loc}{$participants}\n";
            }
        } else {
            $reply .= "\n_Aucun evenement prevu aujourd'hui._\n";
        }

        if ($upcomingEvents->isNotEmpty()) {
            $reply .= "\n*Cette semaine :*\n";
            foreach ($upcomingEvents as $event) {
                $dayStr = $event->event_date->translatedFormat('l j');
                $time   = $event->event_time ? ' ' . Carbon::parse($event->event_time)->format('H:i') : '';
                $reply .= "• {$dayStr}{$time} — *{$event->event_name}*\n";
            }
        }

        if ($overdueCount > 0) {
            $reply .= "\n⚠️ *{$overdueCount} evenement(s) en retard* — Tape *today events* pour les voir.";
        }

        $this->sendText($context->from, $reply);
        $this->log($context, 'Briefing generated', [
            'today'    => $todayEvents->count(),
            'upcoming' => $upcomingEvents->count(),
            'overdue'  => $overdueCount,
        ]);

        return AgentResult::reply($reply, [
            'today'    => $todayEvents->count(),
            'upcoming' => $upcomingEvents->count(),
            'overdue'  => $overdueCount,
        ]);
    }

    private function confirmClearHistory(AgentContext $context): AgentResult
    {
        $count = EventReminder::where('user_phone', $context->from)
            ->whereIn('status', ['done', 'cancelled'])
            ->count();

        if ($count === 0) {
            $reply = "Ton historique est deja vide. Aucun evenement termine ou annule.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply);
        }

        $this->setPendingContext($context, 'confirm_clear_history', ['count' => $count], ttlMinutes: 3, expectRawInput: true);

        $doneCount      = EventReminder::where('user_phone', $context->from)->where('status', 'done')->count();
        $cancelledCount = EventReminder::where('user_phone', $context->from)->where('status', 'cancelled')->count();

        $reply = "Tu veux vraiment supprimer *{$count} evenement(s)* de ton historique ?\n"
            . "_Termines : {$doneCount} | Annules : {$cancelledCount}_\n\n"
            . "⚠️ Cette action est *irreversible*.\n"
            . "Reponds *oui* pour confirmer ou *non* pour annuler.";

        $this->sendText($context->from, $reply);
        $this->log($context, 'Clear history confirmation requested', ['count' => $count]);

        return AgentResult::reply($reply, ['count' => $count, 'pending' => 'confirm_clear_history']);
    }

    private function clearHistory(AgentContext $context, int $expectedCount): AgentResult
    {
        $deleted = EventReminder::where('user_phone', $context->from)
            ->whereIn('status', ['done', 'cancelled'])
            ->delete();

        $this->log($context, 'History cleared', ['deleted' => $deleted]);

        $reply = "Historique supprime : *{$deleted} evenement(s)* efface(s).\n"
            . "_Ton calendrier actif est intact._\n\n"
            . "Tape *list events* pour voir tes evenements a venir.";

        $this->sendText($context->from, $reply);

        return AgentResult::reply($reply, ['deleted' => $deleted]);
    }

    private function appendNoteToEvent(AgentContext $context, int $eventId, string $note): AgentResult
    {
        $note = trim($note);

        if (mb_strlen($note) < 1) {
            $reply = "La note est vide. Precise le texte a ajouter.\nExemple : *note event #5 Apporter le rapport*";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply);
        }

        $event = EventReminder::where('id', $eventId)
            ->where('user_phone', $context->from)
            ->first();

        if (!$event) {
            $reply = "Evenement #{$eventId} introuvable. Tape *list events* pour voir tes evenements.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply);
        }

        $existing    = $event->description ? rtrim($event->description) . "\n" : '';
        $newNote     = $existing . $note;
        $event->update(['description' => $newNote]);

        $dateStr = $event->event_date->translatedFormat('l j F Y');
        $reply   = "Note ajoutee a *{$event->event_name}* (#{$event->id}) !\n\n"
            . "_\"{$note}\"_\n\n"
            . "Date : {$dateStr}\n"
            . "_Tape *show event #{$event->id}* pour voir les details complets._";

        $this->sendText($context->from, $reply);
        $this->log($context, 'Note appended to event', ['event_id' => $eventId, 'note_length' => mb_strlen($note)]);

        return AgentResult::reply($reply, ['event_id' => $eventId]);
    }

    private function exportWeek(AgentContext $context): AgentResult
    {
        $today   = Carbon::today(AppSetting::timezone());
        $endWeek = $today->copy()->addDays(6)->endOfDay();

        $events = EventReminder::where('user_phone', $context->from)
            ->active()
            ->whereBetween('event_date', [$today->format('Y-m-d'), $endWeek->format('Y-m-d')])
            ->orderBy('event_date')
            ->orderBy('event_time')
            ->get();

        $weekRange = $today->format('d/m') . ' - ' . $endWeek->format('d/m/Y');

        if ($events->isEmpty()) {
            $reply = "Aucun evenement cette semaine ({$weekRange}) a exporter.\n\n"
                . "Ajoute-en un avec *remind me about [evenement] on [date] at [heure]*.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply);
        }

        $count = $events->count();
        $reply = "Semaine du {$weekRange} — {$count} evenement(s)\n";
        $reply .= str_repeat('-', 30) . "\n";

        $currentDay = null;
        foreach ($events as $event) {
            $dayLabel = $event->event_date->translatedFormat('l j F');
            if ($dayLabel !== $currentDay) {
                $reply     .= "\n{$dayLabel}\n";
                $currentDay = $dayLabel;
            }
            $time         = $event->event_time ? Carbon::parse($event->event_time)->format('H:i') . ' ' : '';
            $loc          = $event->location ? " — {$event->location}" : '';
            $participants = !empty($event->participants)
                ? ' (' . implode(', ', $event->participants) . ')'
                : '';
            $reply .= "  {$time}{$event->event_name}{$loc}{$participants}\n";
        }

        $reply .= str_repeat('-', 30);

        $this->sendText($context->from, $reply);
        $this->log($context, 'Week exported', ['count' => $count, 'range' => $weekRange]);

        return AgentResult::reply($reply, ['count' => $count, 'range' => $weekRange]);
    }

    private function restoreEvent(AgentContext $context, int $eventId): AgentResult
    {
        $event = EventReminder::where('id', $eventId)
            ->where('user_phone', $context->from)
            ->whereIn('status', ['done', 'cancelled'])
            ->first();

        if (!$event) {
            // Check if it exists but is already active
            $activeEvent = EventReminder::where('id', $eventId)
                ->where('user_phone', $context->from)
                ->where('status', 'active')
                ->first();

            if ($activeEvent) {
                $reply = "L'evenement *{$activeEvent->event_name}* (#{$eventId}) est deja actif.";
            } else {
                $reply = "Evenement #{$eventId} introuvable. Tape *history* pour voir les evenements termines/annules.";
            }

            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply);
        }

        $oldStatus = $event->status;
        $event->update(['status' => 'active']);

        $dateStr   = $event->event_date->translatedFormat('l j F Y');
        $timeStr   = $event->event_time ? ' a ' . Carbon::parse($event->event_time)->format('H:i') : '';
        $fromLabel = $oldStatus === 'done' ? 'termine' : 'annule';
        $isPast    = $event->event_date->isPast() && !$event->event_date->isToday();

        $reply = "Evenement reactive ! ✓\n\n"
            . "*{$event->event_name}* (#{$event->id})\n"
            . "Date : {$dateStr}{$timeStr}\n"
            . "Statut : _{$fromLabel}_ → *actif*\n\n"
            . "_Dans : " . $event->fresh()->timeUntilEvent() . "_";

        if ($isPast) {
            $reply .= "\n\n⚠️ _Attention : la date de cet evenement est passee. Pense a le reporter avec *postpone event #{$event->id} by [duree]* ou a le marquer termine avec *done event #{$event->id}*._";
        }

        $this->sendText($context->from, $reply);
        $this->log($context, 'Event restored', ['event_id' => $eventId, 'from_status' => $oldStatus]);

        return AgentResult::reply($reply, ['event_id' => $eventId, 'from_status' => $oldStatus]);
    }

    private function detectConflicts(AgentContext $context): AgentResult
    {
        $events = EventReminder::where('user_phone', $context->from)
            ->active()
            ->upcoming()
            ->orderBy('event_date')
            ->orderBy('event_time')
            ->get();

        if ($events->isEmpty()) {
            $reply = "Aucun evenement a venir dans ton calendrier. Aucun conflit possible !";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply);
        }

        // Group by date, then find same-time conflicts
        $conflicts = [];
        $grouped   = $events->groupBy(fn ($e) => $e->event_date->format('Y-m-d'));

        foreach ($grouped as $date => $dayEvents) {
            // Full-day events on a busy day (>= 3 events on same day)
            if ($dayEvents->count() >= 3) {
                $conflicts[] = [
                    'type'   => 'busy_day',
                    'date'   => $date,
                    'events' => $dayEvents,
                ];
            }

            // Same time conflicts
            $withTime = $dayEvents->filter(fn ($e) => $e->event_time !== null);
            $byTime   = $withTime->groupBy('event_time');

            foreach ($byTime as $time => $timeEvents) {
                if ($timeEvents->count() > 1) {
                    $conflicts[] = [
                        'type'   => 'same_time',
                        'date'   => $date,
                        'time'   => $time,
                        'events' => $timeEvents,
                    ];
                }
            }
        }

        // Remove duplicates: if a day is flagged as busy_day AND has a same_time conflict, keep only same_time
        $sameTimeDates = collect($conflicts)
            ->where('type', 'same_time')
            ->pluck('date')
            ->unique()
            ->toArray();

        $conflicts = array_filter($conflicts, function ($c) use ($sameTimeDates) {
            return !($c['type'] === 'busy_day' && in_array($c['date'], $sameTimeDates));
        });

        $conflicts = array_values($conflicts);

        if (empty($conflicts)) {
            $reply = "✅ Aucun conflit d'horaire detecte dans ton calendrier !\n"
                . "_Tous tes evenements sont bien espaces._";
            $this->sendText($context->from, $reply);
            $this->log($context, 'Conflicts checked', ['conflicts' => 0]);
            return AgentResult::reply($reply, ['conflicts' => 0]);
        }

        $sameTimeCount = count(array_filter($conflicts, fn ($c) => $c['type'] === 'same_time'));
        $busyDayCount  = count(array_filter($conflicts, fn ($c) => $c['type'] === 'busy_day'));
        $total         = count($conflicts);

        $reply = "*{$total} conflit(s) detecte(s) dans ton calendrier :*\n\n";

        foreach ($conflicts as $conflict) {
            $dateFmt = Carbon::parse($conflict['date'], AppSetting::timezone())->translatedFormat('l j F Y');

            if ($conflict['type'] === 'same_time') {
                $timeFmt = Carbon::parse($conflict['time'])->format('H:i');
                $reply  .= "⚠️ *{$dateFmt} a {$timeFmt}* — evenements simultanees :\n";
                foreach ($conflict['events'] as $ev) {
                    $loc    = $ev->location ? " ({$ev->location})" : '';
                    $reply .= "  • #{$ev->id} {$ev->event_name}{$loc}\n";
                }
            } else {
                $count  = $conflict['events']->count();
                $reply .= "📅 *{$dateFmt}* — journee chargee ({$count} evenements) :\n";
                foreach ($conflict['events'] as $ev) {
                    $time   = $ev->event_time ? Carbon::parse($ev->event_time)->format('H:i') . ' ' : '--:-- ';
                    $reply .= "  • {$time}{$ev->event_name}\n";
                }
            }
            $reply .= "\n";
        }

        $reply .= "_Tape *postpone event #ID by [duree]* pour decaler un evenement._";

        $this->sendText($context->from, $reply);
        $this->log($context, 'Conflicts detected', [
            'total'      => $total,
            'same_time'  => $sameTimeCount,
            'busy_days'  => $busyDayCount,
        ]);

        return AgentResult::reply($reply, ['conflicts' => $total, 'same_time' => $sameTimeCount, 'busy_days' => $busyDayCount]);
    }

    private function batchMarkDone(AgentContext $context, array $eventIds): AgentResult
    {
        $eventIds = array_unique($eventIds);
        $results  = ['done' => [], 'already_done' => [], 'not_found' => []];

        foreach ($eventIds as $id) {
            $event = EventReminder::where('id', $id)
                ->where('user_phone', $context->from)
                ->first();

            if (!$event) {
                $results['not_found'][] = $id;
                continue;
            }

            if ($event->status === 'done') {
                $results['already_done'][] = ['id' => $id, 'name' => $event->event_name];
                continue;
            }

            $event->update(['status' => 'done']);
            $results['done'][] = ['id' => $id, 'name' => $event->event_name];
        }

        $doneCount = count($results['done']);
        $reply     = "*{$doneCount} evenement(s) marque(s) comme termines !*\n\n";

        if (!empty($results['done'])) {
            foreach ($results['done'] as $item) {
                $reply .= "✓ #{$item['id']} {$item['name']}\n";
            }
        }

        if (!empty($results['already_done'])) {
            $reply .= "\n_Deja termines :_\n";
            foreach ($results['already_done'] as $item) {
                $reply .= "• #{$item['id']} {$item['name']}\n";
            }
        }

        if (!empty($results['not_found'])) {
            $ids    = implode(', #', $results['not_found']);
            $reply .= "\n_Introuvables : #{$ids}_";
        }

        $this->sendText($context->from, $reply);
        $this->log($context, 'Batch events marked done', [
            'done'        => count($results['done']),
            'already_done' => count($results['already_done']),
            'not_found'   => count($results['not_found']),
        ]);

        return AgentResult::reply($reply, [
            'done'         => count($results['done']),
            'already_done' => count($results['already_done']),
            'not_found'    => count($results['not_found']),
        ]);
    }
}
