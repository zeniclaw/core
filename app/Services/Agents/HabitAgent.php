<?php

namespace App\Services\Agents;

use App\Models\AppSetting;
use App\Models\Habit;
use App\Models\HabitLog;
use App\Services\AgentContext;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class HabitAgent extends BaseAgent
{
    public function name(): string
    {
        return 'habit';
    }

    public function description(): string
    {
        return 'Agent de suivi d\'habitudes (habit tracker). Permet de creer des habitudes quotidiennes ou hebdomadaires, les cocher chaque jour, suivre les streaks (series consecutives), voir les statistiques et taux de completion sur 30 jours, voir ce qu\'il reste a faire aujourd\'hui, et annuler un log accidentel.';
    }

    public function keywords(): array
    {
        return [
            'habitude', 'habitudes', 'habit', 'habits',
            'habit tracker', 'suivi habitude', 'tracker habitude',
            'nouvelle habitude', 'ajouter habitude', 'add habit', 'new habit',
            'creer habitude', 'create habit',
            'cocher habitude', 'check habit', 'log habit',
            'j\'ai fait', 'j\'ai medite', 'j\'ai couru', 'j\'ai lu',
            'mes habitudes', 'my habits', 'liste habitudes', 'list habits',
            'stats habitudes', 'habit stats', 'statistiques habitudes',
            'streak', 'streaks', 'serie', 'mon streak', 'my streak',
            'supprimer habitude', 'delete habit', 'enlever habitude',
            'reset habitude', 'reinitialiser habitude',
            'meditation', 'sport', 'lecture', 'exercice', 'marche',
            'routine', 'routines', 'routine quotidienne', 'daily routine',
            'discipline', 'regularity', 'regularite',
            'aujourd\'hui habitude', 'habitudes du jour', 'today habits',
            'reste a faire', 'pas encore fait', 'pending habits',
            'annuler log', 'decocher habitude', 'unlog habit', 'undo habit',
            'aide habitude', 'help habit',
        ];
    }

    public function version(): string
    {
        return '1.1.0';
    }

    public function canHandle(AgentContext $context): bool
    {
        return $context->routedAgent === 'habit';
    }

    public function handle(AgentContext $context): AgentResult
    {
        $habits = Habit::where('user_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->orderBy('name')
            ->get();

        $listText = $this->formatHabitList($habits);
        $now = now(AppSetting::timezone())->format('Y-m-d H:i (l)');

        $response = $this->claude->chat(
            "Date et heure actuelles (heure de Paris): {$now}\nMessage: \"{$context->body}\"\n\nHabitudes actives:\n{$listText}",
            'claude-haiku-4-5-20251001',
            $this->buildPrompt()
        );

        $parsed = $this->parseJson($response);

        if (!$parsed || empty($parsed['action'])) {
            $reply = "Je n'ai pas bien compris. Essaie :\n"
                . "- \"Ajouter habitude: Meditation, daily\"\n"
                . "- \"J'ai medite\" / \"Cocher meditation\"\n"
                . "- \"Mes habitudes\" / \"Stats habitudes\"\n"
                . "- \"Aujourd'hui\" pour voir ce qu'il reste\n"
                . "- \"Aide habitudes\" pour le guide complet";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'habit_parse_failed']);
        }

        $action = $parsed['action'];

        return match ($action) {
            'add'    => $this->handleAdd($context, $parsed),
            'log'    => $this->handleLog($context, $habits, $parsed),
            'unlog'  => $this->handleUnlog($context, $habits, $parsed),
            'list'   => $this->handleList($context, $habits),
            'today'  => $this->handleToday($context, $habits),
            'stats'  => $this->handleStats($context, $habits),
            'delete' => $this->handleDelete($context, $habits, $parsed),
            'reset'  => $this->handleReset($context, $habits, $parsed),
            'help'   => $this->handleHelp($context),
            default  => $this->handleUnknown($context),
        };
    }

    private function buildPrompt(): string
    {
        return <<<'PROMPT'
Tu es un assistant de suivi d'habitudes (habit tracker).
L'utilisateur te donne un message et tu dois determiner l'action a effectuer.

Reponds UNIQUEMENT en JSON valide, sans markdown, sans explication.

ACTIONS POSSIBLES:

1. AJOUTER une habitude:
{"action": "add", "name": "nom de l'habitude", "description": "description optionnelle ou null", "frequency": "daily|weekly"}

2. COCHER (log) une habitude comme faite aujourd'hui:
{"action": "log", "item": 1}

3. ANNULER un log d'aujourd'hui (decocher accidentellement):
{"action": "unlog", "item": 1}

4. LISTER toutes les habitudes:
{"action": "list"}

5. VOIR les habitudes d'aujourd'hui (ce qu'il reste a faire):
{"action": "today"}

6. VOIR les statistiques completes:
{"action": "stats"}

7. SUPPRIMER une habitude definitivement:
{"action": "delete", "item": 1}

8. REINITIALISER les streaks et logs d'une habitude:
{"action": "reset", "item": 1}

9. AIDE / GUIDE d'utilisation:
{"action": "help"}

REGLES:
- 'name' = nom court et clair de l'habitude (ex: "Meditation", "Sport", "Lecture")
- 'frequency' = "daily" (quotidienne) ou "weekly" (hebdomadaire). Par defaut "daily".
- 'item' = numero de l'habitude dans la liste fournie (integer, base 1). Deduis le numero depuis le NOM de l'habitude citee.
- 'description' = description optionnelle, null si non fournie

CORRESPONDANCE NOM -> NUMERO:
Si l'utilisateur mentionne une habitude par son nom (ex: "sport", "meditation"), cherche dans la liste quelle habitude correspond et utilise son numero.
Exemple: si #2 est "Sport" et l'utilisateur dit "j'ai fait du sport" -> {"action": "log", "item": 2}

EXEMPLES:
- "Ajouter habitude meditation" -> {"action": "add", "name": "Meditation", "frequency": "daily", "description": null}
- "Nouvelle habitude: sport 3x/semaine" -> {"action": "add", "name": "Sport", "frequency": "weekly", "description": "3 fois par semaine"}
- "J'ai medite" ou "Cocher meditation" -> {"action": "log", "item": X} (X = numero de "Meditation" dans la liste)
- "J'ai couru" -> {"action": "log", "item": X} (X = numero de "Course"/"Sport"/"Running" dans la liste)
- "Annuler mon log sport" ou "J'ai pas fait sport finalement" -> {"action": "unlog", "item": X}
- "Mes habitudes" ou "Liste" -> {"action": "list"}
- "Qu'est-ce que j'ai fait aujourd'hui" ou "Habitudes du jour" -> {"action": "today"}
- "Stats habitudes" ou "Mon streak" ou "Mes stats" -> {"action": "stats"}
- "Supprimer habitude 2" -> {"action": "delete", "item": 2}
- "Reset habitude 1" -> {"action": "reset", "item": 1}
- "Aide" ou "Comment ca marche" -> {"action": "help"}

Reponds UNIQUEMENT avec le JSON.
PROMPT;
    }

    private function handleAdd(AgentContext $context, array $parsed): AgentResult
    {
        $name = trim($parsed['name'] ?? '');
        if (!$name) {
            $reply = "Donne-moi le nom de l'habitude a ajouter.\nEx: \"Ajouter habitude Meditation\"";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'habit_add_no_name']);
        }

        // Check for duplicate name (case-insensitive)
        $exists = Habit::where('user_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->whereRaw('LOWER(name) = ?', [strtolower($name)])
            ->exists();

        if ($exists) {
            $reply = "Tu as deja une habitude \"$name\". Dis \"mes habitudes\" pour voir ta liste.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'habit_add_duplicate']);
        }

        $habit = Habit::create([
            'agent_id'       => $context->agent->id,
            'user_phone'     => $context->from,
            'requester_name' => $context->senderName,
            'name'           => $name,
            'description'    => $parsed['description'] ?? null,
            'frequency'      => in_array($parsed['frequency'] ?? '', ['daily', 'weekly']) ? $parsed['frequency'] : 'daily',
        ]);

        $freqLabel = $habit->frequency === 'daily' ? 'quotidienne' : 'hebdomadaire';
        $reply = "Habitude ajoutee !\n"
            . "Nom : {$habit->name}\n"
            . "Frequence : {$freqLabel}";

        if ($habit->description) {
            $reply .= "\nDescription : {$habit->description}";
        }

        $reply .= "\n\nDis \"j'ai fait {$habit->name}\" pour la cocher chaque jour !";

        $this->sendText($context->from, $reply);
        $this->log($context, 'Habit created', ['habit_id' => $habit->id, 'name' => $name]);

        return AgentResult::reply($reply, ['habit_id' => $habit->id]);
    }

    private function handleLog(AgentContext $context, $habits, array $parsed): AgentResult
    {
        $item = $parsed['item'] ?? null;
        if (!$item || $habits->isEmpty()) {
            $reply = "Quelle habitude veux-tu cocher ? Dis \"mes habitudes\" pour voir la liste.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'habit_log_no_item']);
        }

        $habit = $habits->values()[(int) $item - 1] ?? null;

        if (!$habit) {
            $reply = "Habitude #{$item} introuvable. Dis \"mes habitudes\" pour voir ta liste.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'habit_log_not_found']);
        }

        $today = now(AppSetting::timezone())->toDateString();

        $existing = HabitLog::where('habit_id', $habit->id)
            ->where('completed_date', $today)
            ->first();

        if ($existing) {
            $reply = "Tu as deja coche \"{$habit->name}\" aujourd'hui !\n"
                . "Streak actuel : {$existing->streak_count} jour(s)\n"
                . "Continue comme ca !";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'habit_already_logged']);
        }

        $streak    = $this->calculateStreak($habit->id);
        $newStreak = $streak + 1;
        $bestStreak = max($this->getBestStreak($habit->id), $newStreak);

        HabitLog::create([
            'habit_id'       => $habit->id,
            'completed_date' => $today,
            'streak_count'   => $newStreak,
            'best_streak'    => $bestStreak,
        ]);

        $this->cacheStreak($habit->id, $newStreak, $bestStreak);

        $reply = "Habitude \"{$habit->name}\" cochee !\n"
            . "Streak : {$newStreak} jour(s) | Record : {$bestStreak} jour(s)";

        $milestone = $this->getMilestoneMessage($newStreak, $newStreak === $bestStreak && $newStreak > 1);
        if ($milestone) {
            $reply .= "\n\n{$milestone}";
        }

        $this->sendText($context->from, $reply);
        $this->log($context, 'Habit logged', [
            'habit_id'   => $habit->id,
            'streak'     => $newStreak,
            'best_streak' => $bestStreak,
        ]);

        return AgentResult::reply($reply, ['habit_id' => $habit->id, 'streak' => $newStreak]);
    }

    private function handleUnlog(AgentContext $context, $habits, array $parsed): AgentResult
    {
        $item = $parsed['item'] ?? null;
        if (!$item || $habits->isEmpty()) {
            $reply = "Quelle habitude veux-tu decocher ? Donne le numero.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'habit_unlog_no_item']);
        }

        $habit = $habits->values()[(int) $item - 1] ?? null;

        if (!$habit) {
            $reply = "Habitude #{$item} introuvable. Dis \"mes habitudes\" pour voir ta liste.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'habit_unlog_not_found']);
        }

        $today = now(AppSetting::timezone())->toDateString();

        $log = HabitLog::where('habit_id', $habit->id)
            ->where('completed_date', $today)
            ->first();

        if (!$log) {
            $reply = "Tu n'as pas encore coche \"{$habit->name}\" aujourd'hui.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'habit_unlog_not_logged']);
        }

        $log->delete();

        // Invalidate cache so streak is recalculated fresh
        Cache::forget("habit_streak:{$habit->id}");

        $reply = "Log annule pour \"{$habit->name}\" (aujourd'hui). Le streak a ete mis a jour.";
        $this->sendText($context->from, $reply);
        $this->log($context, 'Habit unlogged', ['habit_id' => $habit->id]);

        return AgentResult::reply($reply, ['action' => 'habit_unlog']);
    }

    private function handleList(AgentContext $context, $habits): AgentResult
    {
        if ($habits->isEmpty()) {
            $reply = "Tu n'as aucune habitude enregistree.\nDis \"ajouter habitude Meditation\" pour commencer !";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'habit_list_empty']);
        }

        $today = now(AppSetting::timezone())->toDateString();

        // Batch-load today's logs for all habits in one query
        $habitIds    = $habits->pluck('id')->toArray();
        $doneTodayIds = HabitLog::whereIn('habit_id', $habitIds)
            ->where('completed_date', $today)
            ->pluck('habit_id')
            ->toArray();

        $lines = ["Tes habitudes :"];

        foreach ($habits->values() as $i => $habit) {
            $num       = $i + 1;
            $freqLabel = $habit->frequency === 'daily' ? 'quotidien' : 'hebdo';
            $streak    = $this->getCachedStreak($habit->id) ?? $this->calculateStreak($habit->id);
            $doneToday = in_array($habit->id, $doneTodayIds);
            $status    = $doneToday ? '[FAIT]' : '[A FAIRE]';

            $lines[] = "\n{$num}. {$habit->name} {$status}";
            $lines[] = "   {$freqLabel} | Streak: {$streak}j";
            if ($habit->description) {
                $lines[] = "   {$habit->description}";
            }
        }

        $done    = count($doneTodayIds);
        $total   = $habits->count();
        $lines[] = "\n---";
        $lines[] = "Aujourd'hui : {$done}/{$total} completees";

        $reply = implode("\n", $lines);
        $this->sendText($context->from, $reply);
        $this->log($context, 'Habit list viewed', ['count' => $total]);

        return AgentResult::reply($reply, ['action' => 'habit_list']);
    }

    private function handleToday(AgentContext $context, $habits): AgentResult
    {
        if ($habits->isEmpty()) {
            $reply = "Tu n'as aucune habitude enregistree.\nDis \"ajouter habitude Meditation\" pour commencer !";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'habit_today_empty']);
        }

        $today = now(AppSetting::timezone())->toDateString();

        $habitIds     = $habits->pluck('id')->toArray();
        $doneTodayIds = HabitLog::whereIn('habit_id', $habitIds)
            ->where('completed_date', $today)
            ->pluck('habit_id')
            ->toArray();

        $dailyHabits = $habits->where('frequency', 'daily');
        $pending     = $dailyHabits->filter(fn($h) => !in_array($h->id, $doneTodayIds));
        $done        = $dailyHabits->filter(fn($h) => in_array($h->id, $doneTodayIds));

        $lines = ["Aujourd'hui :"];

        if ($done->isNotEmpty()) {
            $lines[] = "\nFaites :";
            foreach ($done->values() as $i => $habit) {
                $num    = $habits->values()->search(fn($h) => $h->id === $habit->id) + 1;
                $streak = $this->getCachedStreak($habit->id) ?? $this->calculateStreak($habit->id);
                $lines[] = "  {$num}. {$habit->name} (streak: {$streak}j)";
            }
        }

        if ($pending->isNotEmpty()) {
            $lines[] = "\nA faire :";
            foreach ($pending->values() as $habit) {
                $num    = $habits->values()->search(fn($h) => $h->id === $habit->id) + 1;
                $streak = $this->getCachedStreak($habit->id) ?? $this->calculateStreak($habit->id);
                $lines[] = "  {$num}. {$habit->name} (streak: {$streak}j)";
            }
        }

        $doneCount    = $done->count();
        $pendingCount = $pending->count();
        $total        = $dailyHabits->count();

        $lines[] = "\n{$doneCount}/{$total} habitudes quotidiennes completees.";

        if ($pendingCount === 0 && $total > 0) {
            $lines[] = "Bravo, toutes les habitudes du jour sont faites !";
        }

        $reply = implode("\n", $lines);
        $this->sendText($context->from, $reply);
        $this->log($context, 'Habit today viewed', ['done' => $doneCount, 'pending' => $pendingCount]);

        return AgentResult::reply($reply, ['action' => 'habit_today']);
    }

    private function handleStats(AgentContext $context, $habits): AgentResult
    {
        if ($habits->isEmpty()) {
            $reply = "Tu n'as aucune habitude enregistree. Ajoute-en une d'abord !";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'habit_stats_empty']);
        }

        $today     = now(AppSetting::timezone());
        $todayStr  = $today->toDateString();
        $since30   = $today->copy()->subDays(30)->toDateString();
        $habitIds  = $habits->pluck('id')->toArray();

        // Batch queries to avoid N+1
        $doneTodayIds = HabitLog::whereIn('habit_id', $habitIds)
            ->where('completed_date', $todayStr)
            ->pluck('habit_id')
            ->toArray();

        $totalLogsMap = HabitLog::whereIn('habit_id', $habitIds)
            ->selectRaw('habit_id, COUNT(*) as cnt')
            ->groupBy('habit_id')
            ->pluck('cnt', 'habit_id')
            ->toArray();

        $last30Map = HabitLog::whereIn('habit_id', $habitIds)
            ->where('completed_date', '>=', $since30)
            ->selectRaw('habit_id, COUNT(*) as cnt')
            ->groupBy('habit_id')
            ->pluck('cnt', 'habit_id')
            ->toArray();

        $lines          = ["Stats de tes habitudes :"];
        $totalCompleted = 0;
        $totalDoneToday = 0;

        foreach ($habits->values() as $i => $habit) {
            $num        = $i + 1;
            $streak     = $this->getCachedStreak($habit->id) ?? $this->calculateStreak($habit->id);
            $bestStreak = $this->getBestStreak($habit->id);
            $totalLogs  = $totalLogsMap[$habit->id] ?? 0;
            $last30     = $last30Map[$habit->id] ?? 0;
            $doneToday  = in_array($habit->id, $doneTodayIds);

            if ($doneToday) $totalDoneToday++;
            $totalCompleted += $totalLogs;

            // Completion rate over last 30 days
            $denominator = $habit->frequency === 'daily' ? 30 : round(30 / 7, 1);
            $rate        = $denominator > 0 ? min(100, round(($last30 / $denominator) * 100)) : 0;

            $status  = $doneToday ? ' [FAIT]' : '';
            $lines[] = "\n{$num}. {$habit->name}{$status}";
            $lines[] = "   Streak: {$streak}j | Record: {$bestStreak}j | Total: {$totalLogs}";
            $lines[] = "   Taux 30j: {$rate}%";
        }

        $lines[] = "\n---";
        $lines[] = "Aujourd'hui: {$totalDoneToday}/{$habits->count()} completees";
        $lines[] = "Total completions: {$totalCompleted}";

        $reply = implode("\n", $lines);
        $this->sendText($context->from, $reply);
        $this->log($context, 'Habit stats viewed', ['count' => $habits->count()]);

        return AgentResult::reply($reply, ['action' => 'habit_stats']);
    }

    private function handleDelete(AgentContext $context, $habits, array $parsed): AgentResult
    {
        $item = $parsed['item'] ?? null;
        if (!$item || $habits->isEmpty()) {
            $reply = "Quelle habitude veux-tu supprimer ? Donne le numero.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'habit_delete_no_item']);
        }

        $habit = $habits->values()[(int) $item - 1] ?? null;

        if (!$habit) {
            $reply = "Habitude #{$item} introuvable. Dis \"mes habitudes\" pour voir ta liste.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'habit_delete_not_found']);
        }

        $name = $habit->name;
        $id   = $habit->id;
        $habit->delete(); // soft delete

        Cache::forget("habit_streak:{$id}");
        Cache::forget("habit_best_streak:{$id}");

        $reply = "Habitude \"{$name}\" supprimee.";
        $this->sendText($context->from, $reply);
        $this->log($context, 'Habit deleted', ['habit_id' => $id, 'name' => $name]);

        return AgentResult::reply($reply, ['action' => 'habit_delete']);
    }

    private function handleReset(AgentContext $context, $habits, array $parsed): AgentResult
    {
        $item = $parsed['item'] ?? null;
        if (!$item || $habits->isEmpty()) {
            $reply = "Quelle habitude veux-tu reinitialiser ?";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'habit_reset_no_item']);
        }

        $habit = $habits->values()[(int) $item - 1] ?? null;

        if (!$habit) {
            $reply = "Habitude #{$item} introuvable. Dis \"mes habitudes\" pour voir ta liste.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'habit_reset_not_found']);
        }

        HabitLog::where('habit_id', $habit->id)->delete();

        Cache::forget("habit_streak:{$habit->id}");
        Cache::forget("habit_best_streak:{$habit->id}");

        $reply = "Habitude \"{$habit->name}\" reinitialisee. Tous les streaks et logs effaces. On recommence a zero !";
        $this->sendText($context->from, $reply);
        $this->log($context, 'Habit reset', ['habit_id' => $habit->id, 'name' => $habit->name]);

        return AgentResult::reply($reply, ['action' => 'habit_reset']);
    }

    private function handleHelp(AgentContext $context): AgentResult
    {
        $reply = "Guide Habit Tracker :\n\n"
            . "AJOUTER\n"
            . "  \"Ajouter habitude Meditation\"\n"
            . "  \"Nouvelle habitude: Sport, hebdo\"\n\n"
            . "COCHER (faire une habitude)\n"
            . "  \"J'ai medite\" / \"J'ai fait du sport\"\n"
            . "  \"Cocher habitude 2\"\n\n"
            . "ANNULER un log\n"
            . "  \"Annuler mon log sport\"\n"
            . "  \"J'ai pas fait meditation finalement\"\n\n"
            . "VOIR\n"
            . "  \"Mes habitudes\" — liste avec streaks\n"
            . "  \"Aujourd'hui\" — ce qu'il reste a faire\n"
            . "  \"Stats habitudes\" — statistiques completes\n\n"
            . "GERER\n"
            . "  \"Supprimer habitude 2\"\n"
            . "  \"Reset habitude 1\" — remet a zero\n\n"
            . "Les habitudes sont numerotees dans ta liste.";

        $this->sendText($context->from, $reply);
        return AgentResult::reply($reply, ['action' => 'habit_help']);
    }

    private function handleUnknown(AgentContext $context): AgentResult
    {
        $reply = "Action non reconnue. Dis \"aide habitudes\" pour voir toutes les commandes.";
        $this->sendText($context->from, $reply);
        return AgentResult::reply($reply, ['action' => 'habit_unknown_action']);
    }

    /**
     * Calculate the current streak efficiently using a single DB query.
     * Fetches all logged dates and computes consecutive days in PHP.
     */
    public function calculateStreak(int $habitId): int
    {
        $cached = $this->getCachedStreak($habitId);
        if ($cached !== null) {
            return $cached;
        }

        $tz    = AppSetting::timezone();
        $today = now($tz)->toDateString();

        // Fetch all dates descending — one query only
        $dates = HabitLog::where('habit_id', $habitId)
            ->orderByDesc('completed_date')
            ->pluck('completed_date')
            ->map(fn($d) => Carbon::parse($d)->toDateString())
            ->toArray();

        if (empty($dates)) {
            $this->cacheStreak($habitId, 0, null);
            return 0;
        }

        $streak  = 0;
        $current = Carbon::parse($today, $tz);

        // If today is not logged, allow starting from yesterday
        if ($dates[0] !== $today) {
            $current->subDay();
        }

        foreach ($dates as $date) {
            if ($date === $current->toDateString()) {
                $streak++;
                $current->subDay();
            } elseif (Carbon::parse($date) < $current) {
                // Gap found — streak is broken
                break;
            }
            // If date > current (future? shouldn't happen), skip
        }

        $this->cacheStreak($habitId, $streak, null);

        return $streak;
    }

    private function getBestStreak(int $habitId): int
    {
        $cached = Cache::get("habit_best_streak:{$habitId}");
        if ($cached !== null) {
            return (int) $cached;
        }

        $best = (int) (HabitLog::where('habit_id', $habitId)->max('best_streak') ?? 0);
        Cache::put("habit_best_streak:{$habitId}", $best, 3600);

        return $best;
    }

    private function getCachedStreak(int $habitId): ?int
    {
        $val = Cache::get("habit_streak:{$habitId}");
        return $val !== null ? (int) $val : null;
    }

    private function cacheStreak(int $habitId, int $streak, ?int $bestStreak): void
    {
        Cache::put("habit_streak:{$habitId}", $streak, 3600);
        if ($bestStreak !== null) {
            Cache::put("habit_best_streak:{$habitId}", $bestStreak, 3600);
        }
    }

    private function formatHabitList($habits): string
    {
        if ($habits->isEmpty()) {
            return "(aucune habitude enregistree)";
        }

        $today = now(AppSetting::timezone())->toDateString();

        $habitIds     = $habits->pluck('id')->toArray();
        $doneTodayIds = HabitLog::whereIn('habit_id', $habitIds)
            ->where('completed_date', $today)
            ->pluck('habit_id')
            ->toArray();

        $lines = [];

        foreach ($habits->values() as $i => $habit) {
            $num       = $i + 1;
            $streak    = $this->getCachedStreak($habit->id) ?? $this->calculateStreak($habit->id);
            $doneToday = in_array($habit->id, $doneTodayIds);
            $check     = $doneToday ? '[FAIT]' : '[A FAIRE]';
            $lines[]   = "#{$num} {$habit->name} ({$habit->frequency}) — streak: {$streak}j {$check}";
        }

        return implode("\n", $lines);
    }

    private function getMilestoneMessage(int $streak, bool $isNewRecord): string
    {
        $milestones = [100, 50, 30, 21, 14, 7, 3];
        foreach ($milestones as $m) {
            if ($streak === $m) {
                return match ($m) {
                    3   => "3 jours de suite, beau debut !",
                    7   => "1 semaine complete, continue !",
                    14  => "2 semaines sans interruption !",
                    21  => "21 jours — une nouvelle habitude est nee !",
                    30  => "30 jours d'affilee, impressionnant !",
                    50  => "50 jours — tu es une machine !",
                    100 => "100 jours ! Legendaire !",
                    default => "{$m} jours d'affilee !",
                };
            }
        }

        if ($isNewRecord && $streak > 1) {
            return "Nouveau record personnel !";
        }

        return '';
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

        Log::info("HabitAgent parse - cleaned: {$clean}");

        return json_decode($clean, true);
    }
}
