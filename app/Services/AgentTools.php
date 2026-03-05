<?php

namespace App\Services;

use App\Models\Project;
use App\Models\Reminder;
use App\Models\Todo;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Defines all tools available to the agentic loop.
 * Each tool has a definition (for the Anthropic API) and an executor.
 */
class AgentTools
{
    /**
     * Get all tool definitions for the Anthropic API.
     */
    public static function definitions(): array
    {
        return [
            // ── Reminder tools ──
            [
                'name' => 'create_reminder',
                'description' => 'Create a reminder/alarm for the user. Use this when the user asks to be reminded of something at a specific time.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'message' => ['type' => 'string', 'description' => 'Short description of what to remind (e.g. "Appeler Jean")'],
                        'scheduled_at' => ['type' => 'string', 'description' => 'When to trigger, format YYYY-MM-DD HH:MM (Europe/Paris timezone)'],
                        'recurrence' => ['type' => 'string', 'description' => 'Recurrence rule or null. Formats: "daily:HH:MM", "weekly:DAYNAME:HH:MM", "monthly:DAY:HH:MM", "weekdays:HH:MM"', 'nullable' => true],
                    ],
                    'required' => ['message', 'scheduled_at'],
                ],
            ],
            [
                'name' => 'list_reminders',
                'description' => 'List all active/pending reminders for the user. Use when the user asks about their reminders or schedule.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [],
                ],
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

            // ── Todo tools ──
            [
                'name' => 'add_todos',
                'description' => 'Add one or more tasks to the user\'s todo list.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'items' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'List of task titles to add'],
                        'list_name' => ['type' => 'string', 'description' => 'Name of the list (null for default list)', 'nullable' => true],
                        'priority' => ['type' => 'string', 'enum' => ['high', 'normal', 'low'], 'description' => 'Priority level'],
                        'due_at' => ['type' => 'string', 'description' => 'Deadline in YYYY-MM-DD HH:MM format (Europe/Paris)', 'nullable' => true],
                        'category' => ['type' => 'string', 'description' => 'Category name', 'nullable' => true],
                    ],
                    'required' => ['items'],
                ],
            ],
            [
                'name' => 'list_todos',
                'description' => 'List all todos for the user, optionally filtered by list name.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'list_name' => ['type' => 'string', 'description' => 'Filter by list name (null for all)', 'nullable' => true],
                    ],
                ],
            ],
            [
                'name' => 'check_todos',
                'description' => 'Mark one or more todos as done by their position numbers.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'items' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Position numbers to check (1-based)'],
                        'list_name' => ['type' => 'string', 'description' => 'Scope to a specific list', 'nullable' => true],
                    ],
                    'required' => ['items'],
                ],
            ],
            [
                'name' => 'uncheck_todos',
                'description' => 'Mark one or more todos as not done by their position numbers.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'items' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Position numbers to uncheck (1-based)'],
                        'list_name' => ['type' => 'string', 'description' => 'Scope to a specific list', 'nullable' => true],
                    ],
                    'required' => ['items'],
                ],
            ],
            [
                'name' => 'delete_todos',
                'description' => 'Delete one or more todos by their position numbers.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'items' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Position numbers to delete (1-based)'],
                        'list_name' => ['type' => 'string', 'description' => 'Scope to a specific list', 'nullable' => true],
                    ],
                    'required' => ['items'],
                ],
            ],

            // ── Project tools ──
            [
                'name' => 'switch_project',
                'description' => 'Switch the active project for the user. Use when they want to work on a different project.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'project_name' => ['type' => 'string', 'description' => 'Name or partial name of the project to switch to'],
                    ],
                    'required' => ['project_name'],
                ],
            ],
            [
                'name' => 'list_projects',
                'description' => 'List all projects available to the user.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'show_archived' => ['type' => 'boolean', 'description' => 'Include archived projects'],
                    ],
                ],
            ],
            [
                'name' => 'project_stats',
                'description' => 'Get statistics for a project (tasks completed, in progress, etc.).',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'project_name' => ['type' => 'string', 'description' => 'Project name (null for active project)', 'nullable' => true],
                    ],
                ],
            ],

            // ── Music tools ──
            [
                'name' => 'search_music',
                'description' => 'Search for a song, artist, or album on Spotify.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'query' => ['type' => 'string', 'description' => 'Search query (artist name, song title, album)'],
                    ],
                    'required' => ['query'],
                ],
            ],
            [
                'name' => 'music_recommendations',
                'description' => 'Get music recommendations based on mood, genre, or activity.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'mood' => ['type' => 'string', 'description' => 'Mood, genre, or activity (e.g. "chill", "workout", "jazz", "triste")'],
                    ],
                    'required' => ['mood'],
                ],
            ],
            [
                'name' => 'search_playlist',
                'description' => 'Search for playlists on Spotify by theme.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'query' => ['type' => 'string', 'description' => 'Playlist theme or name'],
                    ],
                    'required' => ['query'],
                ],
            ],

            // ── Utility tools ──
            [
                'name' => 'get_current_datetime',
                'description' => 'Get the current date and time in Europe/Paris timezone. Use this to answer questions about the current time or date.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [],
                ],
            ],
        ];
    }

    /**
     * Execute a tool call and return the result as a string.
     */
    public static function execute(string $toolName, array $input, AgentContext $context): string
    {
        try {
            return match ($toolName) {
                'create_reminder' => self::executeCreateReminder($input, $context),
                'list_reminders' => self::executeListReminders($context),
                'delete_reminder' => self::executeDeleteReminder($input, $context),
                'postpone_reminder' => self::executePostponeReminder($input, $context),
                'add_todos' => self::executeAddTodos($input, $context),
                'list_todos' => self::executeListTodos($input, $context),
                'check_todos' => self::executeCheckTodos($input, $context),
                'uncheck_todos' => self::executeUncheckTodos($input, $context),
                'delete_todos' => self::executeDeleteTodos($input, $context),
                'switch_project' => self::executeSwitchProject($input, $context),
                'list_projects' => self::executeListProjects($input, $context),
                'project_stats' => self::executeProjectStats($input, $context),
                'search_music' => self::executeSearchMusic($input),
                'music_recommendations' => self::executeMusicRecommendations($input),
                'search_playlist' => self::executeSearchPlaylist($input),
                'get_current_datetime' => self::executeGetCurrentDatetime(),
                default => json_encode(['error' => "Unknown tool: {$toolName}"]),
            };
        } catch (\Exception $e) {
            Log::error("AgentTools::execute failed", ['tool' => $toolName, 'error' => $e->getMessage()]);
            return json_encode(['error' => $e->getMessage()]);
        }
    }

    // ── Reminder executors ──────────────────────────────────────────

    private static function executeCreateReminder(array $input, AgentContext $context): string
    {
        $message = $input['message'];
        $scheduledAt = Carbon::parse($input['scheduled_at'], 'Europe/Paris')->utc();
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

        $parisTime = $scheduledAt->copy()->setTimezone('Europe/Paris');

        return json_encode([
            'success' => true,
            'reminder_id' => $reminder->id,
            'message' => $message,
            'scheduled_at_paris' => $parisTime->format('d/m/Y H:i'),
            'recurrence' => $recurrence,
        ]);
    }

    private static function executeListReminders(AgentContext $context): string
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
            $parisTime = $r->scheduled_at->copy()->setTimezone('Europe/Paris');
            $list[] = [
                'number' => $i + 1,
                'message' => $r->message,
                'scheduled_at' => $parisTime->format('d/m/Y H:i'),
                'recurrence' => $r->recurrence_rule,
            ];
        }

        return json_encode(['reminders' => $list]);
    }

    private static function executeDeleteReminder(array $input, AgentContext $context): string
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

    private static function executePostponeReminder(array $input, AgentContext $context): string
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

        $newScheduledAt = self::parseNewTime($input['new_time'], $reminder->scheduled_at);
        if (!$newScheduledAt) {
            return json_encode(['error' => 'Could not parse the new time.']);
        }

        $reminder->update(['scheduled_at' => $newScheduledAt->utc()]);
        $parisTime = $newScheduledAt->copy()->setTimezone('Europe/Paris');

        return json_encode([
            'success' => true,
            'message' => $reminder->message,
            'new_scheduled_at' => $parisTime->format('d/m/Y H:i'),
        ]);
    }

    private static function parseNewTime(string $expr, Carbon $currentScheduledAt): ?Carbon
    {
        $expr = trim($expr);
        $now = now('Europe/Paris');

        if (preg_match('/^\+(\d+)\s*(min|h|j)$/i', $expr, $m)) {
            $amount = (int) $m[1];
            return match (strtolower($m[2])) {
                'min' => $now->copy()->addMinutes($amount),
                'h' => $now->copy()->addHours($amount),
                'j' => $now->copy()->addDays($amount),
                default => null,
            };
        }

        if (preg_match('/^demain\s*(?:a\s*)?(\d{1,2})[h:]?(\d{2})?$/i', $expr, $m)) {
            return $now->copy()->addDay()->setTime((int) $m[1], (int) ($m[2] ?? 0), 0);
        }

        if (strtolower($expr) === 'demain') {
            $currentParis = $currentScheduledAt->copy()->setTimezone('Europe/Paris');
            return $now->copy()->addDay()->setTime($currentParis->hour, $currentParis->minute, 0);
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}$/', $expr)) {
            try {
                return Carbon::parse($expr, 'Europe/Paris');
            } catch (\Exception $e) {
                return null;
            }
        }

        return null;
    }

    // ── Todo executors ──────────────────────────────────────────────

    private static function getTodos(AgentContext $context, ?string $listName = null)
    {
        $query = Todo::where('requester_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->orderByRaw("CASE priority WHEN 'high' THEN 1 WHEN 'normal' THEN 2 WHEN 'low' THEN 3 ELSE 4 END")
            ->orderBy('id');

        $todos = $query->get();

        if ($listName !== null) {
            $todos = $todos->filter(fn($t) => strtolower($t->list_name ?? '') === strtolower($listName))->values();
        }

        return $todos;
    }

    private static function executeAddTodos(array $input, AgentContext $context): string
    {
        $items = $input['items'] ?? [];
        $listName = $input['list_name'] ?? null;
        $priority = $input['priority'] ?? 'normal';
        $dueAt = $input['due_at'] ?? null;
        $category = $input['category'] ?? null;

        $created = [];
        foreach ($items as $title) {
            $data = [
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
                    $data['due_at'] = Carbon::parse($dueAt, 'Europe/Paris')->utc();
                } catch (\Exception $e) {
                    // ignore
                }
            }

            Todo::create($data);
            $created[] = $title;
        }

        return json_encode(['created' => $created, 'list_name' => $listName, 'count' => count($created)]);
    }

    private static function executeListTodos(array $input, AgentContext $context): string
    {
        $listName = $input['list_name'] ?? null;
        $todos = self::getTodos($context, $listName);

        if ($todos->isEmpty()) {
            return json_encode(['todos' => [], 'message' => $listName ? "La liste \"{$listName}\" est vide." : 'Aucun todo.']);
        }

        $list = [];
        foreach ($todos->values() as $i => $todo) {
            $list[] = [
                'number' => $i + 1,
                'title' => $todo->title,
                'is_done' => $todo->is_done,
                'list_name' => $todo->list_name,
                'priority' => $todo->priority,
                'category' => $todo->category,
                'due_at' => $todo->due_at ? $todo->due_at->copy()->timezone('Europe/Paris')->format('d/m/Y') : null,
            ];
        }

        return json_encode(['todos' => $list, 'list_name' => $listName]);
    }

    private static function executeCheckTodos(array $input, AgentContext $context): string
    {
        $listName = $input['list_name'] ?? null;
        $todos = self::getTodos($context, $listName);
        $checked = [];

        foreach ($input['items'] as $num) {
            $index = (int) $num - 1;
            $todo = $todos->values()[$index] ?? null;
            if ($todo) {
                $todo->update(['is_done' => true]);
                $checked[] = $todo->title;
            }
        }

        return json_encode(['checked' => $checked, 'count' => count($checked)]);
    }

    private static function executeUncheckTodos(array $input, AgentContext $context): string
    {
        $listName = $input['list_name'] ?? null;
        $todos = self::getTodos($context, $listName);
        $unchecked = [];

        foreach ($input['items'] as $num) {
            $index = (int) $num - 1;
            $todo = $todos->values()[$index] ?? null;
            if ($todo) {
                $todo->update(['is_done' => false]);
                $unchecked[] = $todo->title;
            }
        }

        return json_encode(['unchecked' => $unchecked, 'count' => count($unchecked)]);
    }

    private static function executeDeleteTodos(array $input, AgentContext $context): string
    {
        $listName = $input['list_name'] ?? null;
        $todos = self::getTodos($context, $listName);
        $deleted = [];

        foreach ($input['items'] as $num) {
            $index = (int) $num - 1;
            $todo = $todos->values()[$index] ?? null;
            if ($todo) {
                if ($todo->reminder_id && $todo->reminder) {
                    $todo->reminder->delete();
                }
                $deleted[] = $todo->title;
                $todo->delete();
            }
        }

        return json_encode(['deleted' => $deleted, 'count' => count($deleted)]);
    }

    // ── Project executors ───────────────────────────────────────────

    private static function executeSwitchProject(array $input, AgentContext $context): string
    {
        $name = $input['project_name'];

        $projects = Project::whereIn('status', ['approved', 'in_progress', 'completed'])
            ->orderByDesc('created_at')
            ->get();

        $match = null;

        // Exact match
        foreach ($projects as $project) {
            if (mb_stripos($project->name, $name) !== false) {
                $match = $project;
                break;
            }
        }

        // Slug match
        if (!$match) {
            foreach ($projects as $project) {
                $slug = basename(parse_url($project->gitlab_url, PHP_URL_PATH) ?? '');
                $slug = str_replace('.git', '', $slug);
                if ($slug && mb_stripos($slug, $name) !== false) {
                    $match = $project;
                    break;
                }
            }
        }

        if (!$match) {
            $available = $projects->map(fn($p) => $p->name)->implode(', ');
            return json_encode(['error' => "Project \"{$name}\" not found.", 'available_projects' => $available]);
        }

        $context->session->update(['active_project_id' => $match->id]);

        return json_encode([
            'success' => true,
            'project_name' => $match->name,
            'project_id' => $match->id,
            'gitlab_url' => $match->gitlab_url,
        ]);
    }

    private static function executeListProjects(array $input, AgentContext $context): string
    {
        $showArchived = $input['show_archived'] ?? false;

        $statuses = ['approved', 'in_progress', 'completed'];
        if ($showArchived) {
            $statuses[] = 'archived';
        }

        $projects = Project::whereIn('status', $statuses)
            ->orderByDesc('created_at')
            ->limit(20)
            ->get();

        $activeId = $context->session->active_project_id;

        $list = [];
        foreach ($projects as $p) {
            $list[] = [
                'name' => $p->name,
                'status' => $p->status,
                'gitlab_url' => $p->gitlab_url,
                'is_active' => $p->id === $activeId,
                'task_count' => $p->subAgents()->where('status', 'completed')->count(),
            ];
        }

        return json_encode(['projects' => $list]);
    }

    private static function executeProjectStats(array $input, AgentContext $context): string
    {
        $name = $input['project_name'] ?? null;
        $project = null;

        if ($name) {
            $project = Project::whereIn('status', ['approved', 'in_progress', 'completed'])
                ->where('name', 'ilike', "%{$name}%")
                ->first();
        }

        if (!$project && $context->session->active_project_id) {
            $project = Project::find($context->session->active_project_id);
        }

        if (!$project) {
            return json_encode(['error' => 'No project found. Specify a project name or switch to one first.']);
        }

        $subAgents = $project->subAgents()->get();

        return json_encode([
            'project' => $project->name,
            'gitlab_url' => $project->gitlab_url,
            'total_tasks' => $subAgents->count(),
            'completed' => $subAgents->where('status', 'completed')->count(),
            'failed' => $subAgents->where('status', 'failed')->count(),
            'running' => $subAgents->where('status', 'running')->count(),
            'pending' => $subAgents->whereIn('status', ['queued', 'pending'])->count(),
        ]);
    }

    // ── Music executors ─────────────────────────────────────────────

    private static function executeSearchMusic(array $input): string
    {
        $spotify = new SpotifyService();
        $data = $spotify->searchTrack($input['query'], 'track', 5);

        if (!$data || empty($data['tracks']['items'])) {
            return json_encode(['results' => [], 'message' => "No results for \"{$input['query']}\"."]);
        }

        $tracks = [];
        foreach ($data['tracks']['items'] as $track) {
            $tracks[] = [
                'name' => $track['name'],
                'artists' => implode(', ', array_map(fn($a) => $a['name'], $track['artists'])),
                'album' => $track['album']['name'] ?? '',
                'url' => $track['external_urls']['spotify'] ?? '',
                'duration_ms' => $track['duration_ms'] ?? 0,
            ];
        }

        return json_encode(['tracks' => $tracks]);
    }

    private static function executeMusicRecommendations(array $input): string
    {
        $spotify = new SpotifyService();
        $genres = $spotify->moodToGenres($input['mood']);
        $seedGenres = implode(',', array_slice($genres, 0, 5));

        $data = $spotify->getRecommendations([
            'seed_genres' => $seedGenres,
            'limit' => 5,
        ]);

        if (!$data || empty($data['tracks'])) {
            return json_encode(['results' => [], 'message' => "No recommendations for \"{$input['mood']}\"."]);
        }

        $tracks = [];
        foreach ($data['tracks'] as $track) {
            $tracks[] = [
                'name' => $track['name'],
                'artists' => implode(', ', array_map(fn($a) => $a['name'], $track['artists'])),
                'url' => $track['external_urls']['spotify'] ?? '',
            ];
        }

        return json_encode(['tracks' => $tracks, 'genres' => $genres]);
    }

    private static function executeSearchPlaylist(array $input): string
    {
        $spotify = new SpotifyService();
        $data = $spotify->searchPlaylist($input['query'], 5);

        if (!$data || empty($data['playlists']['items'])) {
            return json_encode(['results' => [], 'message' => "No playlists for \"{$input['query']}\"."]);
        }

        $playlists = [];
        foreach ($data['playlists']['items'] as $pl) {
            $playlists[] = [
                'name' => $pl['name'],
                'owner' => $pl['owner']['display_name'] ?? 'Spotify',
                'total_tracks' => $pl['tracks']['total'] ?? 0,
                'url' => $pl['external_urls']['spotify'] ?? '',
            ];
        }

        return json_encode(['playlists' => $playlists]);
    }

    // ── Utility executors ───────────────────────────────────────────

    private static function executeGetCurrentDatetime(): string
    {
        $now = now('Europe/Paris');
        $days = ['Monday' => 'lundi', 'Tuesday' => 'mardi', 'Wednesday' => 'mercredi',
            'Thursday' => 'jeudi', 'Friday' => 'vendredi', 'Saturday' => 'samedi', 'Sunday' => 'dimanche'];
        $dayName = $days[$now->format('l')] ?? $now->format('l');

        return json_encode([
            'datetime' => $now->format('Y-m-d H:i:s'),
            'human' => "{$dayName} {$now->format('d/m/Y')} a {$now->format('H:i')}",
            'timezone' => 'Europe/Paris',
        ]);
    }
}
