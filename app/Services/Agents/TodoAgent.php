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
            ->orderByRaw("FIELD(priority, 'high', 'normal', 'low')")
            ->orderBy('id')
            ->get();

        $listText = $this->formatList($todos);

        $response = $this->claude->chat(
            "Message: \"{$context->body}\"\n\nListe actuelle des todos:\n{$listText}\n\nDate actuelle: " . now('Europe/Paris')->format('Y-m-d H:i (l)'),
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
        $category = $parsed['category'] ?? null;
        $priority = $parsed['priority'] ?? 'normal';
        $dueAt = $parsed['due_at'] ?? null;

        switch ($action) {
            case 'add':
                foreach ($items as $title) {
                    $todoData = [
                        'agent_id' => $context->agent->id,
                        'requester_phone' => $context->from,
                        'requester_name' => $context->senderName,
                        'title' => $title,
                        'category' => $category,
                        'priority' => in_array($priority, ['high', 'normal', 'low']) ? $priority : 'normal',
                    ];

                    if ($dueAt) {
                        try {
                            $todoData['due_at'] = Carbon::parse($dueAt, 'Europe/Paris')->utc();
                        } catch (\Exception $e) {
                            // Ignore invalid date
                        }
                    }

                    $todo = Todo::create($todoData);

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

            case 'stats':
                $reply = $this->buildStats($context);
                $this->sendText($context->from, $reply);
                $this->log($context, "Todo action: stats", ['todo_count' => $todos->count()]);
                return AgentResult::reply($reply, ['action' => 'todo_stats']);

            case 'list':
                break;
        }

        // Reload and reply
        $todos = Todo::where('requester_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->orderByRaw("FIELD(priority, 'high', 'normal', 'low')")
            ->orderBy('id')
            ->get();

        $reply = $this->buildReply($todos);
        $this->sendText($context->from, $reply);

        $this->log($context, "Todo action: {$action}", [
            'items' => $items,
            'recurrence' => $recurrence,
            'category' => $category,
            'priority' => $priority,
            'due_at' => $dueAt,
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
{"action": "add|check|uncheck|delete|list|stats", "items": [...], "recurrence": "weekly:thursday:09:00" | null, "category": "string" | null, "priority": "high|normal|low", "due_at": "YYYY-MM-DD HH:MM" | null}

ACTIONS:
- "add": ajouter des taches. items = liste de titres (strings). Si l'utilisateur mentionne un horaire recurrent (chaque jour/semaine/mois), remplis "recurrence".
- "check": cocher des taches. items = liste de numeros (integers, base 1).
- "uncheck": decocher des taches. items = liste de numeros (integers, base 1).
- "delete": supprimer des taches. items = liste de numeros (integers, base 1).
- "list": afficher la liste. items = [] (vide).
- "stats": afficher les statistiques. items = [] (vide). Declenchee par "mes stats", "statistiques", "mon historique", "bilan", etc.

FORMAT RECURRENCE (uniquement pour "add"):
- "daily:HH:MM" — chaque jour a HH:MM
- "weekly:DAYNAME:HH:MM" — chaque semaine le jour donne (monday, tuesday, wednesday, thursday, friday, saturday, sunday)
- "monthly:DAY:HH:MM" — chaque mois le jour du mois (1-31)
- null si pas de recurrence

CATEGORY (uniquement pour "add"):
- Extraire la categorie si l'utilisateur mentionne une liste/categorie : "ajoute pain dans courses" → category: "courses"
- Mots-cles : "dans", "liste", "categorie", "dossier"
- null si pas de categorie mentionnee

PRIORITY (uniquement pour "add"):
- "high" si l'utilisateur dit "urgent", "important", "prioritaire", "URGENT", "critique"
- "low" si l'utilisateur dit "quand j'ai le temps", "pas urgent", "basse priorite", "optionnel"
- "normal" par defaut

DUE_AT (uniquement pour "add"):
- Extraire la date/heure limite si mentionnee : "pour vendredi", "avant le 15 mars", "pour demain"
- Format : "YYYY-MM-DD HH:MM" (utiliser 23:59 si pas d'heure precise)
- Utiliser la date actuelle fournie dans le message pour calculer les dates relatives
- null si pas de deadline

EXEMPLES:
- "ajoute acheter du pain" → {"action": "add", "items": ["Acheter du pain"], "recurrence": null, "category": null, "priority": "normal", "due_at": null}
- "ajoute pain dans courses" → {"action": "add", "items": ["Pain"], "recurrence": null, "category": "courses", "priority": "normal", "due_at": null}
- "ajoute URGENT appeler le dentiste" → {"action": "add", "items": ["Appeler le dentiste"], "recurrence": null, "category": null, "priority": "high", "due_at": null}
- "ajoute finir le rapport pour vendredi" → {"action": "add", "items": ["Finir le rapport"], "recurrence": null, "category": null, "priority": "normal", "due_at": "2026-03-06 23:59"}
- "coche le 2" → {"action": "check", "items": [2], "recurrence": null, "category": null, "priority": "normal", "due_at": null}
- "supprime le 1 et le 3" → {"action": "delete", "items": [1, 3], "recurrence": null, "category": null, "priority": "normal", "due_at": null}
- "ma liste" → {"action": "list", "items": [], "recurrence": null, "category": null, "priority": "normal", "due_at": null}
- "mes stats" → {"action": "stats", "items": [], "recurrence": null, "category": null, "priority": "normal", "due_at": null}
- "ajoute sortir les poubelles chaque jeudi a 9h" → {"action": "add", "items": ["Sortir les poubelles"], "recurrence": "weekly:thursday:09:00", "category": null, "priority": "normal", "due_at": null}
- "ajoute faire du sport tous les jours a 7h" → {"action": "add", "items": ["Faire du sport"], "recurrence": "daily:07:00", "category": null, "priority": "normal", "due_at": null}

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
            $categoryHint = $todo->category ? " [{$todo->category}]" : '';
            $priorityHint = $todo->priority !== 'normal' ? " ({$todo->priority})" : '';
            $dueHint = $todo->due_at ? " (echeance: {$todo->due_at->format('Y-m-d')})" : '';
            $lines[] = "#{$num} [{$check}] {$todo->title}{$categoryHint}{$priorityHint}{$dueHint}{$recurrenceHint}";
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

        // Check if any todo has a category
        $hasCategories = $todos->whereNotNull('category')->isNotEmpty();

        if ($hasCategories) {
            return $this->buildGroupedReply($todos);
        }

        return $this->buildFlatReply($todos);
    }

    private function buildFlatReply($todos): string
    {
        $lines = ["📋 *Ta liste de todos :*"];
        foreach ($todos->values() as $i => $todo) {
            $num = $i + 1;
            $lines[] = "{$num}. " . $this->formatTodoLine($todo);
        }

        return implode("\n", $lines);
    }

    private function buildGroupedReply($todos): string
    {
        $lines = ["📋 *Ta liste de todos :*"];

        // Group by category
        $grouped = $todos->groupBy(fn ($todo) => $todo->category ?? '__sans_categorie__');

        // Global numbering across all categories
        $num = 1;

        // Uncategorized first if they exist
        if ($grouped->has('__sans_categorie__')) {
            $lines[] = '';
            foreach ($grouped->get('__sans_categorie__') as $todo) {
                $lines[] = "{$num}. " . $this->formatTodoLine($todo);
                $num++;
            }
            $grouped->forget('__sans_categorie__');
        }

        // Then each category
        foreach ($grouped as $category => $categoryTodos) {
            $emoji = $this->getCategoryEmoji($category);
            $lines[] = '';
            $lines[] = "{$emoji} *" . ucfirst($category) . " :*";
            foreach ($categoryTodos as $todo) {
                $lines[] = "{$num}. " . $this->formatTodoLine($todo);
                $num++;
            }
        }

        return implode("\n", $lines);
    }

    private function formatTodoLine(Todo $todo): string
    {
        $priorityIcon = match ($todo->priority) {
            'high' => '🔴',
            'low' => '🔵',
            default => $todo->is_done ? '✅' : '⬜',
        };

        // For high/low priority, still show check status
        if ($todo->priority !== 'normal') {
            $check = $todo->is_done ? '✅' : $priorityIcon;
        } else {
            $check = $priorityIcon;
        }

        $line = "{$check} {$todo->title}";

        // Add deadline info
        if ($todo->due_at) {
            $line .= $this->formatDueDate($todo);
        }

        // Add recurrence info
        if ($todo->reminder_id) {
            $line .= $this->formatRecurrenceHint($todo);
        }

        return $line;
    }

    private function formatDueDate(Todo $todo): string
    {
        $now = now('Europe/Paris');
        $due = $todo->due_at->copy()->timezone('Europe/Paris');

        $dayNames = [
            'Monday' => 'lun.',
            'Tuesday' => 'mar.',
            'Wednesday' => 'mer.',
            'Thursday' => 'jeu.',
            'Friday' => 'ven.',
            'Saturday' => 'sam.',
            'Sunday' => 'dim.',
        ];

        $dayName = $dayNames[$due->format('l')] ?? $due->format('l');
        $dateStr = $dayName . ' ' . $due->format('d/m');

        // Check if overdue (and not done)
        if (!$todo->is_done && $due->lt($now)) {
            return " (📅 {$dateStr} ⚠️ EN RETARD)";
        }

        return " (📅 {$dateStr})";
    }

    private function getCategoryEmoji(string $category): string
    {
        $map = [
            'courses' => '🛒',
            'travail' => '💼',
            'boulot' => '💼',
            'perso' => '🏠',
            'personnel' => '🏠',
            'maison' => '🏠',
            'sante' => '🏥',
            'santé' => '🏥',
            'sport' => '🏃',
            'admin' => '📄',
            'administratif' => '📄',
            'projet' => '🚀',
            'projets' => '🚀',
            'urgent' => '🔴',
        ];

        return $map[strtolower($category)] ?? '📌';
    }

    private function buildStats(AgentContext $context): string
    {
        $allTodos = Todo::where('requester_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->get();

        $completed = $allTodos->where('is_done', true)->count();
        $pending = $allTodos->where('is_done', false)->count();
        $total = $allTodos->count();

        if ($total === 0) {
            return "📊 Pas encore de todos ! Ajoute ta premiere tache.";
        }

        $rate = round(($completed / $total) * 100);

        $lines = [
            "📊 *Tes stats :*",
            "✅ {$completed} completee" . ($completed > 1 ? 's' : ''),
            "⬜ {$pending} en cours",
            "📈 Taux : {$rate}%",
        ];

        // Overdue count
        $overdue = $allTodos->where('is_done', false)
            ->filter(fn ($t) => $t->due_at && $t->due_at->lt(now()))
            ->count();

        if ($overdue > 0) {
            $lines[] = "⚠️ {$overdue} en retard";
        }

        // Category breakdown if categories exist
        $withCategories = $allTodos->whereNotNull('category');
        if ($withCategories->isNotEmpty()) {
            $lines[] = '';
            $lines[] = '*Par categorie :*';
            $grouped = $withCategories->groupBy('category');
            foreach ($grouped as $cat => $catTodos) {
                $catDone = $catTodos->where('is_done', true)->count();
                $catTotal = $catTodos->count();
                $emoji = $this->getCategoryEmoji($cat);
                $lines[] = "{$emoji} " . ucfirst($cat) . " : {$catDone}/{$catTotal}";
            }
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
