<?php

namespace App\Services\Agents;

use App\Models\AppSetting;
use App\Models\Reminder;
use App\Services\AgentContext;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class ReminderAgent extends BaseAgent
{
    public function name(): string
    {
        return 'reminder';
    }

    public function description(): string
    {
        return 'Agent de gestion des rappels et alarmes. Permet de creer, lister, supprimer et reporter des rappels ponctuels ou recurrents. Gere aussi l\'agenda des rappels.';
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
            'supprimer rappel', 'supprime rappel', 'delete reminder', 'annuler rappel',
            'reporter rappel', 'reporte rappel', 'postpone', 'snooze', 'repousser',
            'recurrent', 'recurrence', 'periodique',
            'n\'oublie pas', 'noublie pas', 'pense a', 'faut que je',
        ];
    }

    public function version(): string
    {
        return '1.0.0';
    }

    public function canHandle(AgentContext $context): bool
    {
        return $context->routedAgent === 'reminder';
    }

    public function handle(AgentContext $context): AgentResult
    {
        // Fetch active reminders for this user
        $reminders = Reminder::where('requester_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->where('status', 'pending')
            ->orderBy('scheduled_at')
            ->get();

        $listText = $this->formatReminderList($reminders);
        $now = now(AppSetting::timezone())->format('Y-m-d H:i (l)');

        $response = $this->claude->chat(
            "Date et heure actuelles (" . AppSetting::timezone() . "): {$now}\nMessage: \"{$context->body}\"\n\nRappels actifs:\n{$listText}",
            'claude-haiku-4-5-20251001',
            $this->buildPrompt()
        );

        $parsed = $this->parseJson($response);

        if (!$parsed || empty($parsed['action'])) {
            $reply = "J'ai pas bien compris. Essaie un truc comme:\n"
                . "\"Rappelle-moi d'appeler Jean demain a 10h\"\n"
                . "\"Mes rappels\"\n"
                . "\"Supprime le rappel 2\"\n"
                . "\"Reporte le rappel 1 a demain\"";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'reminder_parse_failed']);
        }

        $action = $parsed['action'];

        switch ($action) {
            case 'create':
                return $this->handleCreate($context, $parsed);

            case 'list':
                return $this->handleList($context, $reminders);

            case 'delete':
                return $this->handleDelete($context, $reminders, $parsed);

            case 'postpone':
                return $this->handlePostpone($context, $reminders, $parsed);

            default:
                $reply = "Action non reconnue. Essaie : creer, lister, supprimer ou reporter un rappel.";
                $this->sendText($context->from, $reply);
                return AgentResult::reply($reply, ['action' => 'reminder_unknown_action']);
        }
    }

    private function buildPrompt(): string
    {
        return <<<'PROMPT'
Tu es un assistant de gestion de rappels (reminders).
L'utilisateur te donne un message et tu dois determiner l'action a effectuer.

Reponds UNIQUEMENT en JSON valide, sans markdown, sans explication.

ACTIONS POSSIBLES:

1. CREER un rappel:
{"action": "create", "message": "texte du rappel", "scheduled_at": "YYYY-MM-DD HH:MM", "recurrence": "weekly:thursday:09:00" | null}

2. LISTER les rappels:
{"action": "list"}

3. SUPPRIMER des rappels:
{"action": "delete", "items": [1, 2]}

4. REPORTER un rappel:
{"action": "postpone", "item": 1, "new_time": "expression temporelle"}

REGLES POUR "create":
- 'message' = reformule le rappel de maniere claire et courte (ex: 'Appeler Jean')
- 'scheduled_at' = la date/heure de la PROCHAINE occurrence au format YYYY-MM-DD HH:MM
- 'recurrence' = regle de recurrence si le rappel est periodique, sinon null
  Formats: "daily:HH:MM", "weekly:DAYNAME:HH:MM" (monday,...,sunday), "monthly:DAY:HH:MM", "weekdays:HH:MM" (lundi-vendredi)
  Exemples: "chaque lundi a 8h" -> "weekly:monday:08:00", "tous les jours a 7h" -> "daily:07:00", "en semaine a 8h" -> "weekdays:08:00"
- Si l'heure est mentionnee sans date, utilise la date du jour (ou demain si l'heure est passee)
- Si 'demain' est mentionne, utilise la date de demain
- Si 'dans X minutes/heures', calcule a partir de la date actuelle

REGLES POUR "delete":
- items = liste des numeros de rappels a supprimer (integers, base 1, correspondant a la liste des rappels actifs)

REGLES POUR "postpone":
- item = numero du rappel a reporter (integer, base 1)
- new_time = expression temporelle. Formats acceptes:
  - "YYYY-MM-DD HH:MM" (date absolue)
  - "demain HH:MM" ou "demain" (lendemain, meme heure si pas precisee)
  - "+Xmin", "+Xh", "+Xj" (relatif: minutes, heures, jours)

REGLES POUR "list":
- Quand l'utilisateur demande a voir ses rappels, son agenda, etc.

EXEMPLES:
- "Rappelle-moi d'appeler Jean demain a 10h" -> {"action": "create", "message": "Appeler Jean", "scheduled_at": "2026-03-05 10:00", "recurrence": null}
- "Mes rappels" -> {"action": "list"}
- "Mon agenda" -> {"action": "list"}
- "Supprime le rappel 2" -> {"action": "delete", "items": [2]}
- "Supprime les rappels 1 et 3" -> {"action": "delete", "items": [1, 3]}
- "Reporte le rappel 1 a demain 10h" -> {"action": "postpone", "item": 1, "new_time": "demain 10:00"}
- "Reporte le rappel 1 de 1h" -> {"action": "postpone", "item": 1, "new_time": "+1h"}
- "Rappelle-moi en semaine a 8h de prendre mes vitamines" -> {"action": "create", "message": "Prendre mes vitamines", "scheduled_at": "2026-03-05 08:00", "recurrence": "weekdays:08:00"}

Reponds UNIQUEMENT avec le JSON.
PROMPT;
    }

    private function handleCreate(AgentContext $context, array $parsed): AgentResult
    {
        if (empty($parsed['message']) || empty($parsed['scheduled_at'])) {
            $reply = "J'ai pas bien compris ton rappel. Essaie un truc comme:\n"
                . "\"Rappelle-moi d'appeler Jean demain a 10h\"";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'reminder_parse_failed']);
        }

        try {
            $scheduledAt = Carbon::parse($parsed['scheduled_at'], AppSetting::timezone())->utc();
        } catch (\Exception $e) {
            $reply = "J'ai pas reussi a comprendre la date/heure. Precise un peu plus ?";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'reminder_date_failed']);
        }

        $reminder = Reminder::create([
            'agent_id' => $context->agent->id,
            'requester_phone' => $context->from,
            'requester_name' => $context->senderName,
            'message' => $parsed['message'],
            'channel' => 'whatsapp',
            'scheduled_at' => $scheduledAt,
            'recurrence_rule' => $parsed['recurrence'] ?? null,
            'status' => 'pending',
        ]);

        $parisTime = $scheduledAt->copy()->setTimezone(AppSetting::timezone());
        $recurrenceText = '';
        if (!empty($parsed['recurrence'])) {
            $recurrenceText = "\nRecurrence : " . $this->formatRecurrenceHuman($parsed['recurrence']);
        }
        $reply = "Rappel cree !\n"
            . "{$parsed['message']}\n"
            . "Le {$parisTime->format('d/m/Y')} a {$parisTime->format('H:i')}"
            . $recurrenceText;

        $this->sendText($context->from, $reply);

        $this->log($context, 'Reminder created', [
            'reminder_id' => $reminder->id,
            'message' => $parsed['message'],
            'scheduled_at' => $scheduledAt->toISOString(),
        ]);

        return AgentResult::reply($reply, ['reminder_id' => $reminder->id]);
    }

    private function handleList(AgentContext $context, $reminders): AgentResult
    {
        if ($reminders->isEmpty()) {
            $reply = "Tu n'as aucun rappel actif pour le moment.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'reminder_list']);
        }

        $reply = $this->formatAgendaView($reminders);
        $this->sendText($context->from, $reply);

        $this->log($context, 'Reminder list viewed', ['count' => $reminders->count()]);

        return AgentResult::reply($reply, ['action' => 'reminder_list']);
    }

    private function handleDelete(AgentContext $context, $reminders, array $parsed): AgentResult
    {
        $items = $parsed['items'] ?? [];

        if (empty($items)) {
            $reply = "Quel rappel veux-tu supprimer ? Donne-moi le numero.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'reminder_delete_no_items']);
        }

        $deleted = [];
        $values = $reminders->values();

        foreach ($items as $num) {
            $index = (int) $num - 1;
            $reminder = $values[$index] ?? null;
            if ($reminder) {
                $deleted[] = $reminder->message;
                $reminder->update(['status' => 'cancelled']);
            }
        }

        if (empty($deleted)) {
            $reply = "Aucun rappel trouve avec ce numero.";
        } else {
            $reply = "Rappel(s) supprime(s) :\n" . implode("\n", array_map(fn($m) => "- {$m}", $deleted));
        }

        $this->sendText($context->from, $reply);
        $this->log($context, 'Reminder deleted', ['items' => $items, 'deleted' => $deleted]);

        return AgentResult::reply($reply, ['action' => 'reminder_delete']);
    }

    private function handlePostpone(AgentContext $context, $reminders, array $parsed): AgentResult
    {
        $item = $parsed['item'] ?? null;
        $newTime = $parsed['new_time'] ?? null;

        if (!$item || !$newTime) {
            $reply = "Quel rappel veux-tu reporter et a quand ?\nEx: \"Reporte le rappel 1 a demain 10h\"";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'reminder_postpone_missing']);
        }

        $index = (int) $item - 1;
        $reminder = $reminders->values()[$index] ?? null;

        if (!$reminder) {
            $reply = "Rappel #{$item} introuvable.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'reminder_postpone_not_found']);
        }

        $newScheduledAt = $this->parseNewTime($newTime, $reminder->scheduled_at);

        if (!$newScheduledAt) {
            $reply = "J'ai pas compris la nouvelle heure. Essaie:\n"
                . "- \"demain 10:00\"\n"
                . "- \"+1h\", \"+30min\", \"+2j\"\n"
                . "- \"2026-03-10 14:00\"";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'reminder_postpone_parse_failed']);
        }

        $reminder->update(['scheduled_at' => $newScheduledAt->utc()]);

        $parisTime = $newScheduledAt->copy()->setTimezone(AppSetting::timezone());
        $reply = "Rappel reporte !\n"
            . "{$reminder->message}\n"
            . "Nouveau : le {$parisTime->format('d/m/Y')} a {$parisTime->format('H:i')}";

        $this->sendText($context->from, $reply);
        $this->log($context, 'Reminder postponed', [
            'reminder_id' => $reminder->id,
            'new_scheduled_at' => $newScheduledAt->toISOString(),
        ]);

        return AgentResult::reply($reply, ['action' => 'reminder_postpone']);
    }

    private function parseNewTime(string $expr, Carbon $currentScheduledAt): ?Carbon
    {
        $expr = trim($expr);
        $now = now(AppSetting::timezone());

        // Relative: +Xmin, +Xh, +Xj
        if (preg_match('/^\+(\d+)\s*(min|h|j)$/i', $expr, $m)) {
            $amount = (int) $m[1];
            $unit = strtolower($m[2]);
            return match ($unit) {
                'min' => $now->copy()->addMinutes($amount),
                'h' => $now->copy()->addHours($amount),
                'j' => $now->copy()->addDays($amount),
                default => null,
            };
        }

        // "demain" with optional time
        if (preg_match('/^demain\s*(?:a\s*)?(\d{1,2})[h:]?(\d{2})?$/i', $expr, $m)) {
            $h = (int) $m[1];
            $min = (int) ($m[2] ?? 0);
            return $now->copy()->addDay()->setTime($h, $min, 0);
        }

        // "demain" alone — keep same time
        if (strtolower($expr) === 'demain') {
            $currentParis = $currentScheduledAt->copy()->setTimezone(AppSetting::timezone());
            return $now->copy()->addDay()->setTime($currentParis->hour, $currentParis->minute, 0);
        }

        // Absolute YYYY-MM-DD HH:MM
        if (preg_match('/^\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}$/', $expr)) {
            try {
                return Carbon::parse($expr, AppSetting::timezone());
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
        $today = now(AppSetting::timezone())->startOfDay();
        $grouped = [];

        foreach ($reminders as $i => $reminder) {
            $parisTime = $reminder->scheduled_at->copy()->setTimezone(AppSetting::timezone());
            $dayKey = $parisTime->format('Y-m-d');

            if (!isset($grouped[$dayKey])) {
                $grouped[$dayKey] = [
                    'label' => $this->getDayLabel($parisTime, $today),
                    'items' => [],
                ];
            }

            $num = $i + 1;
            $recText = $reminder->recurrence_rule
                ? ' (' . $this->formatRecurrenceHuman($reminder->recurrence_rule) . ')'
                : '';

            $grouped[$dayKey]['items'][] = "  {$num}. {$parisTime->format('H:i')} — {$reminder->message}{$recText}";
        }

        $lines = ["Tes prochains rappels :"];

        foreach ($grouped as $day) {
            $lines[] = "\n{$day['label']} :";
            foreach ($day['items'] as $item) {
                $lines[] = $item;
            }
        }

        return implode("\n", $lines);
    }

    private function getDayLabel(Carbon $date, Carbon $today): string
    {
        $diff = $today->diffInDays($date->copy()->startOfDay(), false);

        if ($diff == 0) {
            return "Aujourd'hui";
        }
        if ($diff == 1) {
            return 'Demain';
        }

        $days = [
            'Monday' => 'Lundi',
            'Tuesday' => 'Mardi',
            'Wednesday' => 'Mercredi',
            'Thursday' => 'Jeudi',
            'Friday' => 'Vendredi',
            'Saturday' => 'Samedi',
            'Sunday' => 'Dimanche',
        ];

        // Within the same week: use day name
        if ($diff >= 2 && $diff <= 6) {
            $dayName = $days[$date->format('l')] ?? $date->format('l');
            return $dayName;
        }

        // Further out: full date
        $dayName = $days[$date->format('l')] ?? $date->format('l');
        return "{$dayName} {$date->format('d/m')}";
    }

    private function formatRecurrenceHuman(string $rule): string
    {
        $parts = explode(':', $rule);
        $type = $parts[0] ?? '';

        return match ($type) {
            'daily' => 'chaque jour a ' . ($parts[1] ?? '08:00'),
            'weekdays' => 'en semaine a ' . ($parts[1] ?? '08:00'),
            'weekly' => 'chaque ' . $this->translateDay($parts[1] ?? '') . ' a ' . ($parts[2] ?? '09:00'),
            'monthly' => 'le ' . ($parts[1] ?? '1') . ' de chaque mois a ' . ($parts[2] ?? '09:00'),
            default => $rule,
        };
    }

    private function translateDay(string $day): string
    {
        return match (strtolower($day)) {
            'monday' => 'lundi',
            'tuesday' => 'mardi',
            'wednesday' => 'mercredi',
            'thursday' => 'jeudi',
            'friday' => 'vendredi',
            'saturday' => 'samedi',
            'sunday' => 'dimanche',
            default => $day,
        };
    }

    /**
     * Format the reminder list for injection into the Haiku prompt
     */
    private function formatReminderList($reminders): string
    {
        if ($reminders->isEmpty()) {
            return "(aucun rappel actif)";
        }

        $lines = [];
        foreach ($reminders->values() as $i => $reminder) {
            $num = $i + 1;
            $parisTime = $reminder->scheduled_at->copy()->setTimezone(AppSetting::timezone());
            $recText = $reminder->recurrence_rule ? " [recurrent: {$reminder->recurrence_rule}]" : '';
            $lines[] = "#{$num} {$parisTime->format('Y-m-d H:i')} — {$reminder->message}{$recText}";
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

        Log::info("Reminder parse - cleaned: {$clean}");

        return json_decode($clean, true);
    }
}
