<?php

namespace App\Services\Agents;

use App\Models\Reminder;
use App\Models\Todo;
use App\Services\AgentContext;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class TodoAgent extends BaseAgent
{
    public function name(): string
    {
        return 'todo';
    }

    public function canHandle(AgentContext $context): bool
    {
        return $context->routedAgent === 'todo';
    }

    public function handle(AgentContext $context): AgentResult
    {
        $todos = Todo::where('requester_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->orderBy('id')
            ->get();

        $listText = $this->formatList($todos);

        $response = $this->claude->chat(
            "Message: \"{$context->body}\"\n\nListe actuelle des todos:\n{$listText}",
            'claude-haiku-4-5-20251001',
            $this->buildPrompt()
        );

        $parsed = $this->parseJson($response);

        if (!$parsed || empty($parsed['action'])) {
            $reply = $this->buildReply($todos);
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'todo_list']);
        }

        $action = $parsed['action'];
        $items = $parsed['items'] ?? [];
        $recurrence = $parsed['recurrence'] ?? null;

        switch ($action) {
            case 'add':
                foreach ($items as $title) {
                    $todo = Todo::create([
                        'agent_id' => $context->agent->id,
                        'requester_phone' => $context->from,
                        'requester_name' => $context->senderName,
                        'title' => $title,
                    ]);

                    if ($recurrence) {
                        $reminder = $this->createRecurringReminder($context, $title, $recurrence);
                        if ($reminder) {
                            $todo->update(['reminder_id' => $reminder->id]);
                        }
                    }
                }
                break;

            case 'check':
                $this->updateTodoStatus($todos, $items, true);
                break;

            case 'uncheck':
                $this->updateTodoStatus($todos, $items, false);
                break;

            case 'delete':
                $this->deleteTodos($todos, $items);
                break;

            case 'list':
                break;
        }

        // Reload and reply
        $todos = Todo::where('requester_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->orderBy('id')
            ->get();

        $reply = $this->buildReply($todos);
        $this->sendText($context->from, $reply);

        $this->log($context, "Todo action: {$action}", [
            'items' => $items,
            'recurrence' => $recurrence,
            'todo_count' => $todos->count(),
        ]);

        return AgentResult::reply($reply, ['action' => "todo_{$action}"]);
    }

    private function buildPrompt(): string
    {
        return <<<'PROMPT'
Tu es un assistant de gestion de liste de taches (todo list).
L'utilisateur te donne un message et tu dois determiner l'action a effectuer.

Reponds UNIQUEMENT en JSON valide, sans markdown, sans explication:
{"action": "add|check|uncheck|delete|list", "items": [...], "recurrence": "weekly:thursday:09:00" | null}

ACTIONS:
- "add": ajouter des taches. items = liste de titres (strings). Si l'utilisateur mentionne un horaire recurrent (chaque jour/semaine/mois), remplis "recurrence".
- "check": cocher des taches. items = liste de numeros (integers, base 1).
- "uncheck": decocher des taches. items = liste de numeros (integers, base 1).
- "delete": supprimer des taches. items = liste de numeros (integers, base 1).
- "list": afficher la liste. items = [] (vide).

FORMAT RECURRENCE (uniquement pour "add"):
- "daily:HH:MM" — chaque jour a HH:MM
- "weekly:DAYNAME:HH:MM" — chaque semaine le jour donne (monday, tuesday, wednesday, thursday, friday, saturday, sunday)
- "monthly:DAY:HH:MM" — chaque mois le jour du mois (1-31)
- null si pas de recurrence

EXEMPLES:
- "ajoute acheter du pain" → {"action": "add", "items": ["Acheter du pain"], "recurrence": null}
- "coche le 2" → {"action": "check", "items": [2], "recurrence": null}
- "supprime le 1 et le 3" → {"action": "delete", "items": [1, 3], "recurrence": null}
- "ma liste" → {"action": "list", "items": [], "recurrence": null}
- "ajoute sortir les poubelles chaque jeudi a 9h" → {"action": "add", "items": ["Sortir les poubelles"], "recurrence": "weekly:thursday:09:00"}
- "ajoute faire du sport tous les jours a 7h" → {"action": "add", "items": ["Faire du sport"], "recurrence": "daily:07:00"}

Reponds UNIQUEMENT avec le JSON.
PROMPT;
    }

    private function formatList($todos): string
    {
        if ($todos->isEmpty()) {
            return "(liste vide)";
        }

        $lines = [];
        foreach ($todos->values() as $i => $todo) {
            $num = $i + 1;
            $check = $todo->is_done ? 'x' : ' ';
            $recurrenceHint = $todo->reminder_id ? $this->formatRecurrenceHint($todo) : '';
            $lines[] = "#{$num} [{$check}] {$todo->title}{$recurrenceHint}";
        }

        return implode("\n", $lines);
    }

    private function formatRecurrenceHint(Todo $todo): string
    {
        if (!$todo->reminder || !$todo->reminder->recurrence_rule) {
            return '';
        }

        $rule = $todo->reminder->recurrence_rule;
        $parts = explode(':', $rule);

        return match ($parts[0] ?? '') {
            'daily' => ' (chaque jour ' . ($parts[1] ?? '') . ')',
            'weekly' => ' (chaque ' . $this->translateDay($parts[1] ?? '') . ' ' . ($parts[2] ?? '') . ')',
            'monthly' => ' (le ' . ($parts[1] ?? '') . ' de chaque mois ' . ($parts[2] ?? '') . ')',
            default => '',
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

    private function buildReply($todos): string
    {
        if ($todos->isEmpty()) {
            return "📋 Ta liste de todos est vide !";
        }

        $lines = ["📋 *Ta liste de todos :*"];
        foreach ($todos->values() as $i => $todo) {
            $num = $i + 1;
            $check = $todo->is_done ? '✅' : '⬜';
            $recurrenceHint = $todo->reminder_id ? $this->formatRecurrenceHint($todo) : '';
            $lines[] = "{$num}. {$check} {$todo->title}{$recurrenceHint}";
        }

        return implode("\n", $lines);
    }

    private function updateTodoStatus($todos, array $numbers, bool $isDone): void
    {
        foreach ($numbers as $num) {
            $index = (int) $num - 1;
            if (isset($todos->values()[$index])) {
                $todos->values()[$index]->update(['is_done' => $isDone]);
            }
        }
    }

    private function deleteTodos($todos, array $numbers): void
    {
        foreach ($numbers as $num) {
            $index = (int) $num - 1;
            $todo = $todos->values()[$index] ?? null;
            if ($todo) {
                if ($todo->reminder_id && $todo->reminder) {
                    $todo->reminder->delete();
                }
                $todo->delete();
            }
        }
    }

    private function createRecurringReminder(AgentContext $context, string $title, string $recurrence): ?Reminder
    {
        $nextAt = $this->getNextOccurrence($recurrence, now('Europe/Paris'));
        if (!$nextAt) {
            return null;
        }

        return Reminder::create([
            'agent_id' => $context->agent->id,
            'requester_phone' => $context->from,
            'requester_name' => $context->senderName,
            'message' => $title,
            'channel' => 'whatsapp',
            'scheduled_at' => $nextAt->utc(),
            'recurrence_rule' => $recurrence,
            'status' => 'pending',
        ]);
    }

    private function getNextOccurrence(string $rule, Carbon $from): ?Carbon
    {
        $parts = explode(':', $rule);
        $type = $parts[0] ?? '';

        return match ($type) {
            'daily' => $this->nextDaily($parts, $from),
            'weekly' => $this->nextWeekly($parts, $from),
            'monthly' => $this->nextMonthly($parts, $from),
            default => null,
        };
    }

    private function nextDaily(array $parts, Carbon $from): Carbon
    {
        $time = $parts[1] ?? '08:00';
        [$h, $m] = explode(':', $time) + [0 => 8, 1 => 0];

        $next = $from->copy()->setTime((int) $h, (int) $m, 0);
        if ($next->lte($from)) {
            $next->addDay();
        }

        return $next;
    }

    private function nextWeekly(array $parts, Carbon $from): Carbon
    {
        $day = strtolower($parts[1] ?? 'monday');
        $time = $parts[2] ?? '09:00';
        [$h, $m] = explode(':', $time) + [0 => 9, 1 => 0];

        $next = $from->copy()->next($day)->setTime((int) $h, (int) $m, 0);

        return $next;
    }

    private function nextMonthly(array $parts, Carbon $from): Carbon
    {
        $dayOfMonth = (int) ($parts[1] ?? 1);
        $time = $parts[2] ?? '09:00';
        [$h, $m] = explode(':', $time) + [0 => 9, 1 => 0];

        $next = $from->copy()->day($dayOfMonth)->setTime((int) $h, (int) $m, 0);
        if ($next->lte($from)) {
            $next->addMonth();
        }

        return $next;
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

        return json_decode($clean, true);
    }
}
