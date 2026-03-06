<?php

namespace App\Services\Agents;

use App\Models\AppSetting;
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

    public function description(): string
    {
        return 'Agent de gestion de listes de taches (todo lists). Permet de creer, cocher, supprimer des taches, gerer plusieurs listes nommees, assigner des priorites, des echeances et des categories. Supporte les taches recurrentes.';
    }

    public function keywords(): array
    {
        return [
            'todo', 'todos', 'todo list', 'todolist', 'to-do', 'to do',
            'tache', 'taches', 'task', 'tasks',
            'ajoute', 'ajouter', 'add', 'ajoute a',
            'ma liste', 'mes listes', 'my list', 'my lists',
            'liste de taches', 'liste de courses', 'shopping list',
            'coche', 'cocher', 'check', 'fait', 'done', 'termine',
            'decoche', 'decocher', 'uncheck', 'pas fait', 'undone',
            'supprime tache', 'delete task', 'enlever', 'retirer',
            'creer liste', 'nouvelle liste', 'create list', 'new list',
            'supprimer liste', 'delete list',
            'stats todo', 'statistiques todo', 'todo stats',
            'urgent', 'prioritaire', 'priorite', 'priority', 'important',
            'deadline', 'echeance', 'pour demain', 'pour vendredi', 'avant le',
            'categorie', 'category',
            'courses', 'acheter', 'buy',
            'a faire', 'il faut', 'je dois', 'faut que',
        ];
    }

    public function version(): string
    {
        return '1.0.0';
    }

    public function canHandle(AgentContext $context): bool
    {
        return $context->routedAgent === 'todo';
    }

    public function handle(AgentContext $context): AgentResult
    {
        // Get all user's list names for context
        $allListNames = Todo::where('requester_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->whereNotNull('list_name')
            ->distinct()
            ->pluck('list_name')
            ->toArray();

        $listsContext = !empty($allListNames)
            ? "Listes existantes: " . implode(', ', $allListNames)
            : "Aucune liste nommée (uniquement la liste par défaut)";

        // Load all todos for context
        $todos = Todo::where('requester_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->orderByRaw("CASE priority WHEN 'high' THEN 1 WHEN 'normal' THEN 2 WHEN 'low' THEN 3 ELSE 4 END")
            ->orderBy('id')
            ->get();

        $listText = $this->formatList($todos);

        // Inject user context memory for smarter categorization
        $contextMemory = $this->formatContextMemoryForPrompt($context->from);
        $contextHint = $contextMemory ? "\n\n{$contextMemory}" : '';

        $response = $this->claude->chat(
            "Message: \"{$context->body}\"\n\n{$listsContext}\n\nTous les todos:\n{$listText}\n\nDate actuelle: " . now(AppSetting::timezone())->format('Y-m-d H:i (l)') . $contextHint,
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
        $listName = $parsed['list_name'] ?? null;

        switch ($action) {
            case 'add':
                foreach ($items as $title) {
                    $todoData = [
                        'agent_id' => $context->agent->id,
                        'requester_phone' => $context->from,
                        'requester_name' => $context->senderName,
                        'list_name' => $listName,
                        'title' => $title,
                        'category' => $category,
                        'priority' => in_array($priority, ['high', 'normal', 'low']) ? $priority : 'normal',
                    ];

                    if ($dueAt) {
                        try {
                            $todoData['due_at'] = Carbon::parse($dueAt, AppSetting::timezone())->utc();
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

            case 'create_list':
                // Creating a list just means we acknowledge it — todos will be added with this list_name
                $reply = "📋 Liste *{$listName}* créée ! Ajoute des tâches avec : \"ajoute X dans {$listName}\"";
                $this->sendText($context->from, $reply);
                $this->log($context, "Todo action: create_list", ['list_name' => $listName]);
                return AgentResult::reply($reply, ['action' => 'todo_create_list']);

            case 'show_lists':
                $reply = $this->buildAllListsOverview($context);
                $this->sendText($context->from, $reply);
                $this->log($context, "Todo action: show_lists", ['lists' => $allListNames]);
                return AgentResult::reply($reply, ['action' => 'todo_show_lists']);

            case 'check':
                $filteredTodos = $this->filterByList($todos, $listName);
                $this->updateTodoStatus($filteredTodos, $items, true);
                break;

            case 'uncheck':
                $filteredTodos = $this->filterByList($todos, $listName);
                $this->updateTodoStatus($filteredTodos, $items, false);
                break;

            case 'delete':
                $filteredTodos = $this->filterByList($todos, $listName);
                $this->deleteTodos($filteredTodos, $items);
                break;

            case 'delete_list':
                $deleted = Todo::where('requester_phone', $context->from)
                    ->where('agent_id', $context->agent->id)
                    ->where('list_name', $listName)
                    ->get();
                foreach ($deleted as $todo) {
                    if ($todo->reminder_id && $todo->reminder) {
                        $todo->reminder->delete();
                    }
                    $todo->delete();
                }
                $reply = "🗑️ Liste *{$listName}* supprimée ({$deleted->count()} tâches).";
                $this->sendText($context->from, $reply);
                $this->log($context, "Todo action: delete_list", ['list_name' => $listName, 'count' => $deleted->count()]);
                return AgentResult::reply($reply, ['action' => 'todo_delete_list']);

            case 'stats':
                $reply = $this->buildStats($context, $listName);
                $this->sendText($context->from, $reply);
                $this->log($context, "Todo action: stats", ['todo_count' => $todos->count()]);
                return AgentResult::reply($reply, ['action' => 'todo_stats']);

            case 'list':
                break;
        }

        // Reload and reply (scoped to list if specified)
        $reloadQuery = Todo::where('requester_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->orderByRaw("CASE priority WHEN 'high' THEN 1 WHEN 'normal' THEN 2 WHEN 'low' THEN 3 ELSE 4 END")
            ->orderBy('id');

        // For add/check/uncheck/delete, show the relevant list
        $todos = $reloadQuery->get();
        $displayTodos = $this->filterByList($todos, $listName);

        $reply = $this->buildReply($displayTodos, $listName);
        $this->sendText($context->from, $reply);

        $this->log($context, "Todo action: {$action}", [
            'items' => $items,
            'list_name' => $listName,
            'recurrence' => $recurrence,
            'category' => $category,
            'priority' => $priority,
            'due_at' => $dueAt,
            'todo_count' => $displayTodos->count(),
        ]);

        return AgentResult::reply($reply, ['action' => "todo_{$action}"]);
    }

    private function buildPrompt(): string
    {
        return <<<'PROMPT'
Tu es un assistant de gestion de liste de taches (todo list).
L'utilisateur peut avoir PLUSIEURS listes nommees (ex: "courses", "poney", "travail") en plus de la liste par defaut.

Reponds UNIQUEMENT en JSON valide, sans markdown, sans explication:
{"action": "add|check|uncheck|delete|list|stats|create_list|show_lists|delete_list", "items": [...], "list_name": "nom_liste" | null, "recurrence": "weekly:thursday:09:00" | null, "category": "string" | null, "priority": "high|normal|low", "due_at": "YYYY-MM-DD HH:MM" | null}

ACTIONS:
- "add": ajouter des taches. items = liste de titres (strings). list_name = nom de la liste cible (null = liste par defaut).
- "check": cocher des taches. items = liste de numeros (integers, base 1). list_name = la liste concernee.
- "uncheck": decocher des taches. items = liste de numeros (integers, base 1). list_name = la liste concernee.
- "delete": supprimer des taches. items = liste de numeros (integers, base 1). list_name = la liste concernee.
- "list": afficher une liste. items = []. list_name = nom de la liste (null = toutes les listes).
- "stats": statistiques. items = []. list_name = nom de la liste (null = global).
- "create_list": creer une nouvelle liste vide. items = []. list_name = nom de la nouvelle liste.
- "show_lists": voir toutes les listes. items = []. list_name = null.
- "delete_list": supprimer une liste entiere et tous ses todos. items = []. list_name = nom de la liste.

GESTION DES LISTES:
- "cree une liste poney" → create_list, list_name: "poney"
- "ajoute pain dans poney" → add, items: ["Pain"], list_name: "poney"
- "ma liste poney" → list, list_name: "poney"
- "mes listes" → show_lists
- "supprime la liste poney" → delete_list, list_name: "poney"
- Si l'utilisateur mentionne une liste existante, utilise son nom exact.
- Si l'utilisateur dit "dans X" ou "liste X", list_name = X (en minuscule).
- Sans liste specifiee, list_name = null (liste par defaut).

FORMAT RECURRENCE (uniquement pour "add"):
- "daily:HH:MM" — chaque jour a HH:MM
- "weekly:DAYNAME:HH:MM" — chaque semaine le jour donne (monday, tuesday, wednesday, thursday, friday, saturday, sunday)
- "monthly:DAY:HH:MM" — chaque mois le jour du mois (1-31)
- null si pas de recurrence

CATEGORY (uniquement pour "add"):
- Extraire la categorie si l'utilisateur mentionne une categorie : "ajoute pain categorie alimentation" → category: "alimentation"
- IMPORTANT: Ne pas confondre list_name et category. "ajoute pain dans courses" → list_name: "courses", category: null
- null si pas de categorie mentionnee explicitement avec "categorie"

PRIORITY (uniquement pour "add"):
- "high" si l'utilisateur dit "urgent", "important", "prioritaire", "URGENT", "critique"
- "low" si l'utilisateur dit "quand j'ai le temps", "pas urgent", "basse priorite", "optionnel"
- "normal" par defaut

DUE_AT (uniquement pour "add"):
- Extraire la date/heure limite si mentionnee : "pour vendredi", "avant le 15 mars", "pour demain"
- Format : "YYYY-MM-DD HH:MM" (utiliser 23:59 si pas d'heure precise)
- null si pas de deadline

EXEMPLES:
- "ajoute acheter du pain" → {"action": "add", "items": ["Acheter du pain"], "list_name": null, "recurrence": null, "category": null, "priority": "normal", "due_at": null}
- "cree moi une todo list poney" → {"action": "create_list", "items": [], "list_name": "poney", "recurrence": null, "category": null, "priority": "normal", "due_at": null}
- "ajoute carottes dans courses" → {"action": "add", "items": ["Carottes"], "list_name": "courses", "recurrence": null, "category": null, "priority": "normal", "due_at": null}
- "ma liste courses" → {"action": "list", "items": [], "list_name": "courses", "recurrence": null, "category": null, "priority": "normal", "due_at": null}
- "mes listes" → {"action": "show_lists", "items": [], "list_name": null, "recurrence": null, "category": null, "priority": "normal", "due_at": null}
- "coche le 2 dans poney" → {"action": "check", "items": [2], "list_name": "poney", "recurrence": null, "category": null, "priority": "normal", "due_at": null}
- "supprime la liste poney" → {"action": "delete_list", "items": [], "list_name": "poney", "recurrence": null, "category": null, "priority": "normal", "due_at": null}
- "ma liste" → {"action": "list", "items": [], "list_name": null, "recurrence": null, "category": null, "priority": "normal", "due_at": null}
- "mes stats" → {"action": "stats", "items": [], "list_name": null, "recurrence": null, "category": null, "priority": "normal", "due_at": null}

Reponds UNIQUEMENT avec le JSON.
PROMPT;
    }

    private function filterByList($todos, ?string $listName)
    {
        if ($listName === null) {
            return $todos;
        }

        return $todos->filter(fn ($t) => strtolower($t->list_name ?? '') === strtolower($listName))->values();
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
            $listHint = $todo->list_name ? " [liste:{$todo->list_name}]" : '';
            $recurrenceHint = $todo->reminder_id ? $this->formatRecurrenceHint($todo) : '';
            $categoryHint = $todo->category ? " [cat:{$todo->category}]" : '';
            $priorityHint = $todo->priority !== 'normal' ? " ({$todo->priority})" : '';
            $dueHint = $todo->due_at ? " (echeance: {$todo->due_at->format('Y-m-d')})" : '';
            $lines[] = "#{$num} [{$check}] {$todo->title}{$listHint}{$categoryHint}{$priorityHint}{$dueHint}{$recurrenceHint}";
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

    private function buildReply($todos, ?string $listName = null): string
    {
        if ($todos->isEmpty()) {
            if ($listName) {
                return "📋 La liste *{$listName}* est vide !";
            }
            return "📋 Ta liste de todos est vide !";
        }

        $header = $listName
            ? "📋 *Liste {$listName} :*"
            : "📋 *Ta liste de todos :*";

        // Check if any todo has a category
        $hasCategories = $todos->whereNotNull('category')->isNotEmpty();

        if ($hasCategories) {
            return $this->buildGroupedReply($todos, $header);
        }

        return $this->buildFlatReply($todos, $header);
    }

    private function buildAllListsOverview(AgentContext $context): string
    {
        $todos = Todo::where('requester_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->orderBy('id')
            ->get();

        if ($todos->isEmpty()) {
            return "📋 Tu n'as aucune liste de todos !";
        }

        $lines = ["📋 *Tes listes de todos :*", ''];

        // Default list (no list_name)
        $defaultTodos = $todos->whereNull('list_name');
        if ($defaultTodos->isNotEmpty()) {
            $done = $defaultTodos->where('is_done', true)->count();
            $total = $defaultTodos->count();
            $lines[] = "📌 *Liste par défaut* — {$done}/{$total} ✅";
        }

        // Named lists
        $namedTodos = $todos->whereNotNull('list_name');
        $grouped = $namedTodos->groupBy('list_name');

        foreach ($grouped as $name => $listTodos) {
            $done = $listTodos->where('is_done', true)->count();
            $total = $listTodos->count();
            $lines[] = "📝 *{$name}* — {$done}/{$total} ✅";
        }

        $lines[] = '';
        $lines[] = "_Dis \"ma liste X\" pour voir une liste en détail._";

        return implode("\n", $lines);
    }

    private function buildFlatReply($todos, string $header): string
    {
        $lines = [$header];
        foreach ($todos->values() as $i => $todo) {
            $num = $i + 1;
            $lines[] = "{$num}. " . $this->formatTodoLine($todo);
        }

        return implode("\n", $lines);
    }

    private function buildGroupedReply($todos, string $header): string
    {
        $lines = [$header];

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
        $now = now(AppSetting::timezone());
        $due = $todo->due_at->copy()->timezone(AppSetting::timezone());

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

    private function buildStats(AgentContext $context, ?string $listName = null): string
    {
        $query = Todo::where('requester_phone', $context->from)
            ->where('agent_id', $context->agent->id);

        if ($listName) {
            $query->where('list_name', $listName);
        }

        $allTodos = $query->get();

        $completed = $allTodos->where('is_done', true)->count();
        $pending = $allTodos->where('is_done', false)->count();
        $total = $allTodos->count();

        if ($total === 0) {
            return "📊 Pas encore de todos ! Ajoute ta premiere tache.";
        }

        $rate = round(($completed / $total) * 100);

        $title = $listName ? "Tes stats ({$listName}) :" : "Tes stats :";
        $lines = [
            "📊 *{$title}*",
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

        // List breakdown if no specific list and multiple lists exist
        if (!$listName) {
            $namedLists = $allTodos->whereNotNull('list_name')->groupBy('list_name');
            if ($namedLists->isNotEmpty()) {
                $lines[] = '';
                $lines[] = '*Par liste :*';
                foreach ($namedLists as $name => $listTodos) {
                    $listDone = $listTodos->where('is_done', true)->count();
                    $listTotal = $listTodos->count();
                    $lines[] = "📝 {$name} : {$listDone}/{$listTotal}";
                }
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
        $nextAt = $this->getNextOccurrence($recurrence, now(AppSetting::timezone()));
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
