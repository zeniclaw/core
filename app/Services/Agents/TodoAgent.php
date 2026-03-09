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
    /** Max todos fetched to avoid giant LLM prompts */
    private const MAX_TODOS = 50;

    public function name(): string
    {
        return 'todo';
    }

    public function description(): string
    {
        return 'Agent de gestion de listes de taches (todo lists). Permet de creer, cocher, supprimer des taches, gerer plusieurs listes nommees, assigner des priorites, des echeances et des categories. Supporte les taches recurrentes, le deplacement entre listes, la modification de titre, la recherche et le nettoyage des taches terminees.';
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
            'vider', 'nettoyer', 'clear', 'efface les faits', 'supprimer les terminees',
            'deplace', 'deplacer', 'move', 'transfere',
            'modifie', 'modifier', 'renomme', 'renommer', 'edite', 'editer', 'changer le titre',
            'cherche', 'recherche', 'trouve', 'find', 'search',
            'aide todo', 'help todo', 'aide taches',
        ];
    }

    public function version(): string
    {
        return '1.2.0';
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

        // Load todos (capped to avoid huge prompts)
        $todos = Todo::where('requester_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->orderByRaw("CASE priority WHEN 'high' THEN 1 WHEN 'normal' THEN 2 WHEN 'low' THEN 3 ELSE 4 END")
            ->orderBy('id')
            ->limit(self::MAX_TODOS)
            ->get();

        $listText = $this->formatList($todos);

        // Inject user context memory for smarter categorization
        $contextMemory = $this->formatContextMemoryForPrompt($context->from);
        $contextHint   = $contextMemory ? "\n\n{$contextMemory}" : '';

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

        $action     = $parsed['action'];
        $items      = $parsed['items'] ?? [];
        $recurrence = $parsed['recurrence'] ?? null;
        $category   = $parsed['category'] ?? null;
        $priority   = $parsed['priority'] ?? 'normal';
        $dueAt      = $parsed['due_at'] ?? null;
        $listName   = $parsed['list_name'] ?? null;
        $targetList = $parsed['target_list'] ?? null;
        $newTitle   = $parsed['new_title'] ?? null;
        $query      = $parsed['query'] ?? null;

        // Confirmation prefix (used by check / uncheck / delete on success)
        $confirmationPrefix = '';

        switch ($action) {
            case 'add':
                $reply = $this->handleAdd($context, $items, $listName, $category, $priority, $dueAt, $recurrence);
                if ($reply !== null) {
                    $this->sendText($context->from, $reply);
                    $this->log($context, "Todo action: add", [
                        'items' => $items, 'list_name' => $listName, 'priority' => $priority,
                    ]);
                    return AgentResult::reply($reply, ['action' => 'todo_add']);
                }
                break;

            case 'create_list':
                if (!$listName) {
                    $reply = "Quel nom veux-tu donner a ta nouvelle liste ?";
                    $this->sendText($context->from, $reply);
                    return AgentResult::reply($reply, ['action' => 'todo_create_list_missing_name']);
                }
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
                if (empty($items)) {
                    $reply = "Quel numéro veux-tu cocher ? Ex: \"coche le 2\"";
                    $this->sendText($context->from, $reply);
                    return AgentResult::reply($reply, ['action' => 'todo_check_missing_items']);
                }
                $filteredTodos = $this->filterByList($todos, $listName);
                $result        = $this->updateTodoStatus($filteredTodos, $items, true);
                if (!empty($result['not_found'])) {
                    $reply = "⚠️ Numéro(s) introuvable(s) : " . implode(', ', $result['not_found']) . ". Tape \"ma liste\" pour voir les numéros.";
                    $this->sendText($context->from, $reply);
                    return AgentResult::reply($reply, ['action' => 'todo_check_not_found']);
                }
                $confirmationPrefix = count($items) === 1
                    ? "✅ Tâche cochée !\n\n"
                    : "✅ " . count($items) . " tâches cochées !\n\n";
                break;

            case 'uncheck':
                if (empty($items)) {
                    $reply = "Quel numéro veux-tu décocher ? Ex: \"décoche le 1\"";
                    $this->sendText($context->from, $reply);
                    return AgentResult::reply($reply, ['action' => 'todo_uncheck_missing_items']);
                }
                $filteredTodos = $this->filterByList($todos, $listName);
                $result        = $this->updateTodoStatus($filteredTodos, $items, false);
                if (!empty($result['not_found'])) {
                    $reply = "⚠️ Numéro(s) introuvable(s) : " . implode(', ', $result['not_found']) . ". Tape \"ma liste\" pour voir les numéros.";
                    $this->sendText($context->from, $reply);
                    return AgentResult::reply($reply, ['action' => 'todo_uncheck_not_found']);
                }
                $confirmationPrefix = count($items) === 1
                    ? "🔄 Tâche décochée !\n\n"
                    : "🔄 " . count($items) . " tâches décochées !\n\n";
                break;

            case 'delete':
                if (empty($items)) {
                    $reply = "Quel numéro veux-tu supprimer ? Ex: \"supprime le 3\"";
                    $this->sendText($context->from, $reply);
                    return AgentResult::reply($reply, ['action' => 'todo_delete_missing_items']);
                }
                $filteredTodos = $this->filterByList($todos, $listName);
                $result        = $this->deleteTodos($filteredTodos, $items);
                if (!empty($result['not_found'])) {
                    $reply = "⚠️ Numéro(s) introuvable(s) : " . implode(', ', $result['not_found']) . ". Tape \"ma liste\" pour voir les numéros.";
                    $this->sendText($context->from, $reply);
                    return AgentResult::reply($reply, ['action' => 'todo_delete_not_found']);
                }
                $confirmationPrefix = count($items) === 1
                    ? "🗑️ Tâche supprimée !\n\n"
                    : "🗑️ " . count($items) . " tâches supprimées !\n\n";
                break;

            case 'delete_list':
                if (!$listName) {
                    $reply = "Quelle liste veux-tu supprimer ? Tape \"mes listes\" pour voir les listes disponibles.";
                    $this->sendText($context->from, $reply);
                    return AgentResult::reply($reply, ['action' => 'todo_delete_list_missing_name']);
                }
                $deleted = Todo::where('requester_phone', $context->from)
                    ->where('agent_id', $context->agent->id)
                    ->where('list_name', $listName)
                    ->get();
                if ($deleted->isEmpty()) {
                    $reply = "❌ Liste *{$listName}* introuvable. Tape \"mes listes\" pour voir les listes disponibles.";
                    $this->sendText($context->from, $reply);
                    return AgentResult::reply($reply, ['action' => 'todo_delete_list_not_found']);
                }
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

            case 'clear_done':
                return $this->handleClearDone($context, $listName);

            case 'move':
                return $this->handleMove($context, $todos, $items, $listName, $targetList);

            case 'edit':
                return $this->handleEdit($context, $todos, $items, $listName, $newTitle);

            case 'search':
                return $this->handleSearch($context, $query, $listName);

            case 'help':
                return $this->handleHelp($context);

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
            ->orderBy('id')
            ->limit(self::MAX_TODOS);

        $todos        = $reloadQuery->get();
        $displayTodos = $this->filterByList($todos, $listName);

        $reply = $confirmationPrefix . $this->buildReply($displayTodos, $listName);
        $this->sendText($context->from, $reply);

        $this->log($context, "Todo action: {$action}", [
            'items'      => $items,
            'list_name'  => $listName,
            'recurrence' => $recurrence,
            'category'   => $category,
            'priority'   => $priority,
            'due_at'     => $dueAt,
            'todo_count' => $displayTodos->count(),
        ]);

        return AgentResult::reply($reply, ['action' => "todo_{$action}"]);
    }

    // ─── Action handlers ────────────────────────────────────────────────────────

    /**
     * Add tasks and return a confirmation reply (or null to fall through to list).
     */
    private function handleAdd(AgentContext $context, array $items, ?string $listName, ?string $category, string $priority, ?string $dueAt, ?string $recurrence): ?string
    {
        if (empty($items)) {
            return "Qu'est-ce que tu veux ajouter ? Dis-moi par exemple : \"ajoute acheter du pain\"";
        }

        foreach ($items as $title) {
            $todoData = [
                'agent_id'        => $context->agent->id,
                'requester_phone' => $context->from,
                'requester_name'  => $context->senderName,
                'list_name'       => $listName,
                'title'           => $title,
                'category'        => $category,
                'priority'        => in_array($priority, ['high', 'normal', 'low']) ? $priority : 'normal',
            ];

            if ($dueAt) {
                try {
                    $todoData['due_at'] = Carbon::parse($dueAt, AppSetting::timezone())->utc();
                } catch (\Exception $e) {
                    Log::warning('[TodoAgent] Invalid due_at', ['due_at' => $dueAt, 'error' => $e->getMessage()]);
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

        // Return null: let caller reload and show the list
        return null;
    }

    /**
     * Edit (rename) a single task title.
     */
    private function handleEdit(AgentContext $context, $todos, array $items, ?string $listName, ?string $newTitle): AgentResult
    {
        if (empty($items) || $newTitle === null || trim($newTitle) === '') {
            $reply = "Précise le numéro de la tâche et le nouveau titre.\nEx: \"modifie le 2 : acheter du lait\"";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'todo_edit_missing_params']);
        }

        $filteredTodos = $this->filterByList($todos, $listName);
        $num           = (int) $items[0];
        $index         = $num - 1;
        $todo          = $filteredTodos->values()[$index] ?? null;

        if (!$todo) {
            $reply = "⚠️ Tâche #{$num} introuvable. Tape \"ma liste\" pour voir les numéros.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'todo_edit_not_found']);
        }

        $oldTitle = $todo->title;
        $todo->update(['title' => trim($newTitle)]);

        $reply = "✏️ Tâche #{$num} modifiée :\n_{$oldTitle}_ → *{$newTitle}*";
        $this->sendText($context->from, $reply);
        $this->log($context, "Todo action: edit", ['num' => $num, 'old' => $oldTitle, 'new' => $newTitle]);

        return AgentResult::reply($reply, ['action' => 'todo_edit']);
    }

    /**
     * Search todos by keyword across title (and optionally scoped to a list).
     */
    private function handleSearch(AgentContext $context, ?string $query, ?string $listName): AgentResult
    {
        if (!$query || trim($query) === '') {
            $reply = "Que veux-tu chercher ? Ex: \"cherche pain\"";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'todo_search_missing_query']);
        }

        $dbQuery = Todo::where('requester_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->where('title', 'like', '%' . $query . '%')
            ->orderBy('is_done')
            ->orderByRaw("CASE priority WHEN 'high' THEN 1 WHEN 'normal' THEN 2 WHEN 'low' THEN 3 ELSE 4 END")
            ->orderBy('id');

        if ($listName) {
            $dbQuery->where('list_name', $listName);
        }

        $results = $dbQuery->get();

        if ($results->isEmpty()) {
            $scope = $listName ? " dans la liste *{$listName}*" : '';
            $reply = "🔍 Aucune tâche trouvée pour \"*{$query}*\"{$scope}.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'todo_search_empty']);
        }

        $scope   = $listName ? " dans *{$listName}*" : '';
        $lines   = ["🔍 *Résultats pour \"{$query}\"{$scope} :*"];

        foreach ($results->values() as $i => $todo) {
            $listHint = (!$listName && $todo->list_name) ? " _({$todo->list_name})_" : '';
            $lines[]  = ($i + 1) . ". " . $this->formatTodoLine($todo) . $listHint;
        }

        $count = $results->count();
        $lines[] = '';
        $lines[] = "_{$count} résultat(s) trouvé(s)._";

        $reply = implode("\n", $lines);
        $this->sendText($context->from, $reply);
        $this->log($context, "Todo action: search", ['query' => $query, 'count' => $count]);

        return AgentResult::reply($reply, ['action' => 'todo_search', 'count' => $count]);
    }

    /**
     * Clear all completed tasks (globally or from a specific list).
     */
    private function handleClearDone(AgentContext $context, ?string $listName): AgentResult
    {
        $query = Todo::where('requester_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->where('is_done', true);

        if ($listName) {
            $query->where('list_name', $listName);
        }

        $done = $query->get();

        if ($done->isEmpty()) {
            $msg = $listName
                ? "Aucune tâche terminée dans la liste *{$listName}*."
                : "Aucune tâche terminée à supprimer.";
            $this->sendText($context->from, $msg);
            return AgentResult::reply($msg, ['action' => 'todo_clear_done_empty']);
        }

        foreach ($done as $todo) {
            if ($todo->reminder_id && $todo->reminder) {
                $todo->reminder->delete();
            }
            $todo->delete();
        }

        $count = $done->count();
        $msg   = $listName
            ? "🧹 {$count} tâche(s) terminée(s) supprimée(s) de la liste *{$listName}*."
            : "🧹 {$count} tâche(s) terminée(s) supprimée(s).";

        $this->sendText($context->from, $msg);
        $this->log($context, "Todo action: clear_done", ['list_name' => $listName, 'count' => $count]);

        return AgentResult::reply($msg, ['action' => 'todo_clear_done', 'count' => $count]);
    }

    /**
     * Move one or more tasks to another list.
     */
    private function handleMove(AgentContext $context, $todos, array $items, ?string $listName, ?string $targetList): AgentResult
    {
        if (empty($items) || $targetList === null) {
            $reply = "Précise quel numéro déplacer et vers quelle liste.\nEx: \"déplace le 2 dans courses\"";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'todo_move_missing_params']);
        }

        $filteredTodos = $this->filterByList($todos, $listName);
        $moved         = [];
        $notFound      = [];

        foreach ($items as $num) {
            $index = (int) $num - 1;
            $todo  = $filteredTodos->values()[$index] ?? null;
            if ($todo) {
                $todo->update(['list_name' => $targetList ?: null]);
                $moved[] = $todo->title;
            } else {
                $notFound[] = $num;
            }
        }

        if (empty($moved)) {
            $reply = "⚠️ Aucun numéro valide. Tape \"ma liste\" pour voir les numéros.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'todo_move_not_found']);
        }

        $destLabel = $targetList ?: 'la liste par défaut';
        $reply     = count($moved) === 1
            ? "↪️ *{$moved[0]}* déplacé vers *{$destLabel}*."
            : "↪️ " . count($moved) . " tâche(s) déplacée(s) vers *{$destLabel}*.";

        if (!empty($notFound)) {
            $reply .= "\n⚠️ Numéro(s) introuvable(s) : " . implode(', ', $notFound);
        }

        $this->sendText($context->from, $reply);
        $this->log($context, "Todo action: move", [
            'items'       => $items,
            'from_list'   => $listName,
            'target_list' => $targetList,
            'moved'       => $moved,
        ]);

        return AgentResult::reply($reply, ['action' => 'todo_move', 'moved_count' => count($moved)]);
    }

    /**
     * Display usage help.
     */
    private function handleHelp(AgentContext $context): AgentResult
    {
        $reply = "*Gestion des todos — Commandes disponibles :*\n\n"
            . "*Ajouter des tâches :*\n"
            . "\"ajoute acheter du pain\"\n"
            . "\"ajoute lait et œufs dans courses\"\n"
            . "\"ajoute rapport urgent pour vendredi\"\n\n"
            . "*Voir les tâches :*\n"
            . "\"ma liste\" / \"ma liste courses\"\n"
            . "\"mes listes\"\n\n"
            . "*Cocher / décocher :*\n"
            . "\"coche le 2\" / \"coche 1 et 3\"\n"
            . "\"décoche le 1\"\n\n"
            . "*Modifier une tâche :*\n"
            . "\"modifie le 2 : nouveau titre\"\n\n"
            . "*Rechercher :*\n"
            . "\"cherche pain\" / \"cherche lait dans courses\"\n\n"
            . "*Supprimer :*\n"
            . "\"supprime le 3\" / \"supprime les 1 et 2\"\n"
            . "\"supprime la liste courses\"\n"
            . "\"vide les tâches terminées\"\n\n"
            . "*Déplacer :*\n"
            . "\"déplace le 2 dans courses\"\n\n"
            . "*Listes :*\n"
            . "\"crée une liste poney\"\n"
            . "\"mes listes\"\n\n"
            . "*Stats :*\n"
            . "\"mes stats\" / \"stats de la liste courses\"\n\n"
            . "*Priorités :* urgent / normal / pas urgent\n"
            . "*Échéances :* \"pour vendredi\", \"avant le 15 mars\"\n"
            . "*Récurrences :* \"tous les jours à 8h\", \"chaque lundi\"";

        $this->sendText($context->from, $reply);
        return AgentResult::reply($reply, ['action' => 'todo_help']);
    }

    // ─── Prompt ─────────────────────────────────────────────────────────────────

    private function buildPrompt(): string
    {
        return <<<'PROMPT'
Tu es un assistant de gestion de liste de taches (todo list).
L'utilisateur peut avoir PLUSIEURS listes nommees (ex: "courses", "poney", "travail") en plus de la liste par defaut.

Reponds UNIQUEMENT en JSON valide, sans markdown, sans explication:
{"action": "add|check|uncheck|delete|list|stats|create_list|show_lists|delete_list|clear_done|move|edit|search|help", "items": [...], "list_name": "nom_liste" | null, "target_list": "nom_liste" | null, "new_title": "nouveau titre" | null, "query": "mot cle" | null, "recurrence": "weekly:thursday:09:00" | null, "category": "string" | null, "priority": "high|normal|low", "due_at": "YYYY-MM-DD HH:MM" | null}

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
- "clear_done": supprimer toutes les taches cochees/terminees. items = []. list_name = null (global) ou nom de liste.
- "move": deplacer une ou plusieurs taches vers une autre liste. items = [numeros]. list_name = liste source (null = default). target_list = liste destination (null = default).
- "edit": modifier le titre d'une tache. items = [numero]. new_title = nouveau titre (string). list_name = la liste concernee (null = liste par defaut).
- "search": rechercher des taches par mot cle dans le titre. items = []. query = mot cle (string). list_name = null (global) ou nom de liste.
- "help": afficher l'aide des commandes disponibles. items = []. list_name = null.

GESTION DES LISTES:
- "cree une liste poney" → create_list, list_name: "poney"
- "ajoute pain dans poney" → add, items: ["Pain"], list_name: "poney"
- "ma liste poney" → list, list_name: "poney"
- "mes listes" → show_lists
- "supprime la liste poney" → delete_list, list_name: "poney"
- "vide les taches terminees" → clear_done, list_name: null
- "nettoie les faites dans courses" → clear_done, list_name: "courses"
- "deplace le 2 dans courses" → move, items: [2], list_name: null, target_list: "courses"
- "deplace le 3 de poney vers travail" → move, items: [3], list_name: "poney", target_list: "travail"
- "aide" → help
- Si l'utilisateur mentionne une liste existante, utilise son nom exact.
- Si l'utilisateur dit "dans X" ou "liste X", list_name = X (en minuscule).
- Sans liste specifiee, list_name = null (liste par defaut).

EDITION ET RECHERCHE:
- "modifie le 2 : acheter du lait" → edit, items: [2], new_title: "Acheter du lait", list_name: null
- "renomme la tache 3 en appeler le medecin" → edit, items: [3], new_title: "Appeler le médecin", list_name: null
- "change le 1 dans courses par eau minerale" → edit, items: [1], new_title: "Eau minérale", list_name: "courses"
- "cherche pain" → search, query: "pain", list_name: null
- "recherche lait dans courses" → search, query: "lait", list_name: "courses"
- "trouve les taches avec urgent" → search, query: "urgent", list_name: null

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
- "ajoute acheter du pain" → {"action": "add", "items": ["Acheter du pain"], "list_name": null, "target_list": null, "new_title": null, "query": null, "recurrence": null, "category": null, "priority": "normal", "due_at": null}
- "cree moi une todo list poney" → {"action": "create_list", "items": [], "list_name": "poney", "target_list": null, "new_title": null, "query": null, "recurrence": null, "category": null, "priority": "normal", "due_at": null}
- "ajoute carottes dans courses" → {"action": "add", "items": ["Carottes"], "list_name": "courses", "target_list": null, "new_title": null, "query": null, "recurrence": null, "category": null, "priority": "normal", "due_at": null}
- "ma liste courses" → {"action": "list", "items": [], "list_name": "courses", "target_list": null, "new_title": null, "query": null, "recurrence": null, "category": null, "priority": "normal", "due_at": null}
- "mes listes" → {"action": "show_lists", "items": [], "list_name": null, "target_list": null, "new_title": null, "query": null, "recurrence": null, "category": null, "priority": "normal", "due_at": null}
- "coche le 2 dans poney" → {"action": "check", "items": [2], "list_name": "poney", "target_list": null, "new_title": null, "query": null, "recurrence": null, "category": null, "priority": "normal", "due_at": null}
- "supprime la liste poney" → {"action": "delete_list", "items": [], "list_name": "poney", "target_list": null, "new_title": null, "query": null, "recurrence": null, "category": null, "priority": "normal", "due_at": null}
- "vide les taches terminees" → {"action": "clear_done", "items": [], "list_name": null, "target_list": null, "new_title": null, "query": null, "recurrence": null, "category": null, "priority": "normal", "due_at": null}
- "deplace le 2 dans courses" → {"action": "move", "items": [2], "list_name": null, "target_list": "courses", "new_title": null, "query": null, "recurrence": null, "category": null, "priority": "normal", "due_at": null}
- "modifie le 2 : acheter du lait" → {"action": "edit", "items": [2], "list_name": null, "target_list": null, "new_title": "Acheter du lait", "query": null, "recurrence": null, "category": null, "priority": "normal", "due_at": null}
- "cherche pain" → {"action": "search", "items": [], "list_name": null, "target_list": null, "new_title": null, "query": "pain", "recurrence": null, "category": null, "priority": "normal", "due_at": null}
- "ma liste" → {"action": "list", "items": [], "list_name": null, "target_list": null, "new_title": null, "query": null, "recurrence": null, "category": null, "priority": "normal", "due_at": null}
- "mes stats" → {"action": "stats", "items": [], "list_name": null, "target_list": null, "new_title": null, "query": null, "recurrence": null, "category": null, "priority": "normal", "due_at": null}
- "aide" → {"action": "help", "items": [], "list_name": null, "target_list": null, "new_title": null, "query": null, "recurrence": null, "category": null, "priority": "normal", "due_at": null}

Reponds UNIQUEMENT avec le JSON.
PROMPT;
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────────

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
            $num            = $i + 1;
            $check          = $todo->is_done ? 'x' : ' ';
            $listHint       = $todo->list_name ? " [liste:{$todo->list_name}]" : '';
            $recurrenceHint = $todo->reminder_id ? $this->formatRecurrenceHint($todo) : '';
            $categoryHint   = $todo->category ? " [cat:{$todo->category}]" : '';
            $priorityHint   = $todo->priority !== 'normal' ? " ({$todo->priority})" : '';
            $dueHint        = $todo->due_at ? " (echeance: {$todo->due_at->format('Y-m-d')})" : '';
            $lines[]        = "#{$num} [{$check}] {$todo->title}{$listHint}{$categoryHint}{$priorityHint}{$dueHint}{$recurrenceHint}";
        }

        return implode("\n", $lines);
    }

    private function formatRecurrenceHint(Todo $todo): string
    {
        if (!$todo->reminder || !$todo->reminder->recurrence_rule) {
            return '';
        }

        $rule  = $todo->reminder->recurrence_rule;
        $parts = explode(':', $rule);

        return match ($parts[0] ?? '') {
            'daily'   => ' (chaque jour ' . ($parts[1] ?? '') . ')',
            'weekly'  => ' (chaque ' . $this->translateDay($parts[1] ?? '') . ' ' . ($parts[2] ?? '') . ')',
            'monthly' => ' (le ' . ($parts[1] ?? '') . ' de chaque mois ' . ($parts[2] ?? '') . ')',
            default   => '',
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

    private function buildReply($todos, ?string $listName = null): string
    {
        if ($todos->isEmpty()) {
            if ($listName) {
                return "📋 La liste *{$listName}* est vide !\n\n_Ajoute une tâche : \"ajoute X dans {$listName}\"_";
            }
            return "📋 Ta liste de todos est vide !\n\n_Commence par : \"ajoute acheter du pain\"_";
        }

        $done    = $todos->where('is_done', true)->count();
        $total   = $todos->count();
        $pending = $total - $done;

        $header = $listName
            ? "📋 *Liste {$listName}* — {$done}/{$total} ✅"
            : "📋 *Tes todos* — {$done}/{$total} ✅";

        if ($pending > 0) {
            $header .= " ({$pending} à faire)";
        }

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
            return "📋 Tu n'as aucune liste de todos !\n\n_Commence par : \"ajoute acheter du pain\"_";
        }

        $lines = ["📋 *Tes listes de todos :*", ''];

        // Default list (no list_name)
        $defaultTodos = $todos->whereNull('list_name');
        if ($defaultTodos->isNotEmpty()) {
            $done    = $defaultTodos->where('is_done', true)->count();
            $total   = $defaultTodos->count();
            $pending = $total - $done;
            $lines[] = "📌 *Liste par défaut* — {$done}/{$total} ✅" . ($pending > 0 ? " ({$pending} restantes)" : '');
        }

        // Named lists
        $namedTodos = $todos->whereNotNull('list_name');
        $grouped    = $namedTodos->groupBy('list_name');

        foreach ($grouped as $name => $listTodos) {
            $done    = $listTodos->where('is_done', true)->count();
            $total   = $listTodos->count();
            $pending = $total - $done;

            // Highlight lists with overdue tasks
            $hasOverdue = $listTodos->where('is_done', false)
                ->filter(fn ($t) => $t->due_at && $t->due_at->lt(now()))
                ->isNotEmpty();

            $overdueHint = $hasOverdue ? ' ⚠️' : '';
            $lines[]     = "📝 *{$name}* — {$done}/{$total} ✅" . ($pending > 0 ? " ({$pending} restantes)" : '') . $overdueHint;
        }

        $lines[] = '';
        $lines[] = "_Dis \"ma liste X\" pour voir une liste en détail._";
        $lines[] = "_\"cherche X\" pour rechercher une tâche._";
        $lines[] = "_\"aide\" pour voir toutes les commandes._";

        return implode("\n", $lines);
    }

    private function buildFlatReply($todos, string $header): string
    {
        $lines = [$header];
        foreach ($todos->values() as $i => $todo) {
            $num     = $i + 1;
            $lines[] = "{$num}. " . $this->formatTodoLine($todo);
        }

        return implode("\n", $lines);
    }

    private function buildGroupedReply($todos, string $header): string
    {
        $lines   = [$header];
        $grouped = $todos->groupBy(fn ($todo) => $todo->category ?? '__sans_categorie__');
        $num     = 1;

        // Uncategorized first
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
            $emoji   = $this->getCategoryEmoji($category);
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
            'low'  => '🔵',
            default => $todo->is_done ? '✅' : '⬜',
        };

        if ($todo->priority !== 'normal') {
            $check = $todo->is_done ? '✅' : $priorityIcon;
        } else {
            $check = $priorityIcon;
        }

        $line = "{$check} {$todo->title}";

        if ($todo->due_at) {
            $line .= $this->formatDueDate($todo);
        }

        if ($todo->reminder_id) {
            $line .= $this->formatRecurrenceHint($todo);
        }

        return $line;
    }

    private function formatDueDate(Todo $todo): string
    {
        $now  = now(AppSetting::timezone());
        $due  = $todo->due_at->copy()->timezone(AppSetting::timezone());

        $dayNames = [
            'Monday'    => 'lun.',
            'Tuesday'   => 'mar.',
            'Wednesday' => 'mer.',
            'Thursday'  => 'jeu.',
            'Friday'    => 'ven.',
            'Saturday'  => 'sam.',
            'Sunday'    => 'dim.',
        ];

        $dayName = $dayNames[$due->format('l')] ?? $due->format('l');
        $dateStr = $dayName . ' ' . $due->format('d/m');

        // Show time if it's not midnight/EOD
        if ($due->format('H:i') !== '23:59' && $due->format('H:i') !== '00:00') {
            $dateStr .= ' ' . $due->format('H:i');
        }

        if (!$todo->is_done && $due->lt($now)) {
            return " (📅 {$dateStr} ⚠️ EN RETARD)";
        }

        return " (📅 {$dateStr})";
    }

    private function getCategoryEmoji(string $category): string
    {
        $map = [
            'courses'        => '🛒',
            'travail'        => '💼',
            'boulot'         => '💼',
            'perso'          => '🏠',
            'personnel'      => '🏠',
            'maison'         => '🏠',
            'sante'          => '🏥',
            'santé'          => '🏥',
            'sport'          => '🏃',
            'admin'          => '📄',
            'administratif'  => '📄',
            'projet'         => '🚀',
            'projets'        => '🚀',
            'urgent'         => '🔴',
            'famille'        => '👨‍👩‍👧',
            'finances'       => '💰',
            'loisirs'        => '🎮',
            'lecture'        => '📚',
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
        $pending   = $allTodos->where('is_done', false)->count();
        $total     = $allTodos->count();

        if ($total === 0) {
            return "📊 Pas encore de todos ! Ajoute ta première tâche.";
        }

        $rate  = round(($completed / $total) * 100);
        $title = $listName ? "Tes stats ({$listName}) :" : "Tes stats :";
        $lines = [
            "📊 *{$title}*",
            "✅ {$completed} complétée" . ($completed > 1 ? 's' : ''),
            "⬜ {$pending} en cours",
            "📈 Taux de complétion : {$rate}%",
        ];

        // Overdue count
        $now     = now();
        $overdue = $allTodos->where('is_done', false)
            ->filter(fn ($t) => $t->due_at && $t->due_at->lt($now))
            ->count();

        if ($overdue > 0) {
            $lines[] = "⚠️ {$overdue} en retard";
        }

        // Upcoming tasks (due within next 3 days, not overdue)
        $upcoming = $allTodos->where('is_done', false)
            ->filter(fn ($t) => $t->due_at && $t->due_at->gte($now) && $t->due_at->lte($now->copy()->addDays(3)))
            ->count();

        if ($upcoming > 0) {
            $lines[] = "⏰ {$upcoming} à faire dans les 3 prochains jours";
        }

        // Priority breakdown
        $highCount   = $allTodos->where('priority', 'high')->count();
        $normalCount = $allTodos->where('priority', 'normal')->count();
        $lowCount    = $allTodos->where('priority', 'low')->count();

        if ($highCount > 0 || $lowCount > 0) {
            $lines[] = '';
            $lines[] = '*Par priorité :*';
            if ($highCount > 0) $lines[] = "🔴 Urgent : {$highCount}";
            if ($normalCount > 0) $lines[] = "⬜ Normal : {$normalCount}";
            if ($lowCount > 0) $lines[] = "🔵 Bas : {$lowCount}";
        }

        // Category breakdown
        $withCategories = $allTodos->whereNotNull('category');
        if ($withCategories->isNotEmpty()) {
            $lines[]  = '';
            $lines[]  = '*Par catégorie :*';
            $grouped  = $withCategories->groupBy('category');
            foreach ($grouped as $cat => $catTodos) {
                $catDone  = $catTodos->where('is_done', true)->count();
                $catTotal = $catTodos->count();
                $emoji    = $this->getCategoryEmoji($cat);
                $lines[]  = "{$emoji} " . ucfirst($cat) . " : {$catDone}/{$catTotal}";
            }
        }

        // List breakdown (only when not scoped to a single list)
        if (!$listName) {
            $namedLists = $allTodos->whereNotNull('list_name')->groupBy('list_name');
            if ($namedLists->isNotEmpty()) {
                $lines[] = '';
                $lines[] = '*Par liste :*';
                foreach ($namedLists as $name => $listTodos) {
                    $listDone  = $listTodos->where('is_done', true)->count();
                    $listTotal = $listTodos->count();
                    $lines[]   = "📝 {$name} : {$listDone}/{$listTotal}";
                }
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @return array{not_found: int[]}
     */
    private function updateTodoStatus($todos, array $numbers, bool $isDone): array
    {
        $notFound = [];
        foreach ($numbers as $num) {
            $index = (int) $num - 1;
            if (isset($todos->values()[$index])) {
                $todos->values()[$index]->update(['is_done' => $isDone]);
            } else {
                $notFound[] = (int) $num;
            }
        }
        return ['not_found' => $notFound];
    }

    /**
     * @return array{not_found: int[]}
     */
    private function deleteTodos($todos, array $numbers): array
    {
        $notFound = [];
        foreach ($numbers as $num) {
            $index = (int) $num - 1;
            $todo  = $todos->values()[$index] ?? null;
            if ($todo) {
                if ($todo->reminder_id && $todo->reminder) {
                    $todo->reminder->delete();
                }
                $todo->delete();
            } else {
                $notFound[] = (int) $num;
            }
        }
        return ['not_found' => $notFound];
    }

    private function createRecurringReminder(AgentContext $context, string $title, string $recurrence): ?Reminder
    {
        $nextAt = $this->getNextOccurrence($recurrence, now(AppSetting::timezone()));
        if (!$nextAt) {
            Log::warning('[TodoAgent] Could not compute next occurrence', ['recurrence' => $recurrence]);
            return null;
        }

        return Reminder::create([
            'agent_id'        => $context->agent->id,
            'requester_phone' => $context->from,
            'requester_name'  => $context->senderName,
            'message'         => $title,
            'channel'         => 'whatsapp',
            'scheduled_at'    => $nextAt->utc(),
            'recurrence_rule' => $recurrence,
            'status'          => 'pending',
        ]);
    }

    private function getNextOccurrence(string $rule, Carbon $from): ?Carbon
    {
        $parts = explode(':', $rule);
        $type  = $parts[0] ?? '';

        return match ($type) {
            'daily'   => $this->nextDaily($parts, $from),
            'weekly'  => $this->nextWeekly($parts, $from),
            'monthly' => $this->nextMonthly($parts, $from),
            default   => null,
        };
    }

    private function nextDaily(array $parts, Carbon $from): Carbon
    {
        $time    = $parts[1] ?? '08:00';
        [$h, $m] = explode(':', $time) + [0 => 8, 1 => 0];

        $next = $from->copy()->setTime((int) $h, (int) $m, 0);
        if ($next->lte($from)) {
            $next->addDay();
        }

        return $next;
    }

    private function nextWeekly(array $parts, Carbon $from): Carbon
    {
        $day     = strtolower($parts[1] ?? 'monday');
        $time    = $parts[2] ?? '09:00';
        [$h, $m] = explode(':', $time) + [0 => 9, 1 => 0];

        return $from->copy()->next($day)->setTime((int) $h, (int) $m, 0);
    }

    private function nextMonthly(array $parts, Carbon $from): Carbon
    {
        $dayOfMonth = (int) ($parts[1] ?? 1);
        $time       = $parts[2] ?? '09:00';
        [$h, $m]    = explode(':', $time) + [0 => 9, 1 => 0];

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

        $decoded = json_decode($clean, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            Log::warning('[TodoAgent] JSON parse failed', [
                'error' => json_last_error_msg(),
                'raw'   => mb_substr($clean, 0, 300),
            ]);
            return null;
        }

        return $decoded;
    }
}
