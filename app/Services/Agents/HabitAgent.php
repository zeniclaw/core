<?php

namespace App\Services\Agents;

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

        $listText = $this->formatHabitList($habits, $context->from);
        $now = now('Europe/Paris')->format('Y-m-d H:i (l)');

        $response = $this->claude->chat(
            "Date et heure actuelles (heure de Paris): {$now}\nMessage: \"{$context->body}\"\n\nHabitudes actives:\n{$listText}",
            'claude-haiku-4-5-20251001',
            $this->buildPrompt()
        );

        $parsed = $this->parseJson($response);

        if (!$parsed || empty($parsed['action'])) {
            $reply = "J'ai pas bien compris. Essaie un truc comme:\n"
                . "\"Ajouter habitude: Meditation, daily\"\n"
                . "\"Cocher meditation\"\n"
                . "\"Mes habitudes\" ou \"Stats habitudes\"\n"
                . "\"Supprimer habitude meditation\"";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'habit_parse_failed']);
        }

        $action = $parsed['action'];

        switch ($action) {
            case 'add':
                return $this->handleAdd($context, $parsed);

            case 'log':
                return $this->handleLog($context, $habits, $parsed);

            case 'list':
                return $this->handleList($context, $habits);

            case 'stats':
                return $this->handleStats($context, $habits);

            case 'delete':
                return $this->handleDelete($context, $habits, $parsed);

            case 'reset':
                return $this->handleReset($context, $habits, $parsed);

            default:
                $reply = "Action non reconnue. Essaie : ajouter, cocher, lister, stats ou supprimer une habitude.";
                $this->sendText($context->from, $reply);
                return AgentResult::reply($reply, ['action' => 'habit_unknown_action']);
        }
    }

    private function buildPrompt(): string
    {
        return <<<'PROMPT'
Tu es un assistant de suivi d'habitudes (habit tracker).
L'utilisateur te donne un message et tu dois determiner l'action a effectuer.

Reponds UNIQUEMENT en JSON valide, sans markdown, sans explication.

ACTIONS POSSIBLES:

1. AJOUTER une habitude:
{"action": "add", "name": "nom de l'habitude", "description": "description optionnelle", "frequency": "daily|weekly"}

2. COCHER (log) une habitude comme faite aujourd'hui:
{"action": "log", "item": 1}

3. LISTER les habitudes:
{"action": "list"}

4. VOIR les statistiques:
{"action": "stats"}

5. SUPPRIMER une habitude:
{"action": "delete", "item": 1}

6. REINITIALISER les streaks d'une habitude:
{"action": "reset", "item": 1}

REGLES:
- 'name' = nom court et clair de l'habitude (ex: "Meditation", "Sport", "Lecture")
- 'frequency' = "daily" (quotidienne) ou "weekly" (hebdomadaire). Par defaut "daily".
- 'item' = numero de l'habitude dans la liste (integer, base 1)
- 'description' = description optionnelle, null si non fournie

EXEMPLES:
- "Ajouter habitude meditation" -> {"action": "add", "name": "Meditation", "frequency": "daily", "description": null}
- "Nouvelle habitude: sport 3x/semaine" -> {"action": "add", "name": "Sport", "frequency": "weekly", "description": "3 fois par semaine"}
- "J'ai medite" ou "Cocher meditation" -> {"action": "log", "item": 1}
- "Mes habitudes" -> {"action": "list"}
- "Stats habitudes" ou "Mon streak" -> {"action": "stats"}
- "Supprimer habitude 2" -> {"action": "delete", "item": 2}
- "Reset habitude 1" -> {"action": "reset", "item": 1}
- "J'ai fait du sport" -> {"action": "log", "item": X} (X = numero correspondant a "Sport" dans la liste)

Reponds UNIQUEMENT avec le JSON.
PROMPT;
    }

    private function handleAdd(AgentContext $context, array $parsed): AgentResult
    {
        $name = $parsed['name'] ?? null;
        if (!$name) {
            $reply = "Donne-moi le nom de l'habitude a ajouter.\nEx: \"Ajouter habitude Meditation\"";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'habit_add_no_name']);
        }

        $habit = Habit::create([
            'agent_id' => $context->agent->id,
            'user_phone' => $context->from,
            'requester_name' => $context->senderName,
            'name' => $name,
            'description' => $parsed['description'] ?? null,
            'frequency' => $parsed['frequency'] ?? 'daily',
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

        $index = (int) $item - 1;
        $habit = $habits->values()[$index] ?? null;

        if (!$habit) {
            $reply = "Habitude #{$item} introuvable.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'habit_log_not_found']);
        }

        $today = now('Europe/Paris')->toDateString();

        $existing = HabitLog::where('habit_id', $habit->id)
            ->where('completed_date', $today)
            ->first();

        if ($existing) {
            $reply = "Tu as deja coche \"{$habit->name}\" aujourd'hui ! Bravo, continue comme ca !";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'habit_already_logged']);
        }

        $streak = $this->calculateStreak($habit->id);
        $newStreak = $streak + 1;
        $bestStreak = $this->getBestStreak($habit->id);
        if ($newStreak > $bestStreak) {
            $bestStreak = $newStreak;
        }

        HabitLog::create([
            'habit_id' => $habit->id,
            'completed_date' => $today,
            'streak_count' => $newStreak,
            'best_streak' => $bestStreak,
        ]);

        // Update Redis cache
        $this->cacheStreak($habit->id, $newStreak, $bestStreak);

        $reply = "Habitude \"{$habit->name}\" cochee !\n"
            . "Streak actuel : {$newStreak} jour(s)\n"
            . "Meilleur streak : {$bestStreak} jour(s)";

        if ($newStreak >= 7 && $newStreak % 7 === 0) {
            $reply .= "\n\nBravo ! {$newStreak} jours d'affilee !";
        } elseif ($newStreak === $bestStreak && $newStreak > 1) {
            $reply .= "\n\nNouveau record personnel !";
        }

        $this->sendText($context->from, $reply);
        $this->log($context, 'Habit logged', [
            'habit_id' => $habit->id,
            'streak' => $newStreak,
            'best_streak' => $bestStreak,
        ]);

        return AgentResult::reply($reply, ['habit_id' => $habit->id, 'streak' => $newStreak]);
    }

    private function handleList(AgentContext $context, $habits): AgentResult
    {
        if ($habits->isEmpty()) {
            $reply = "Tu n'as aucune habitude enregistree.\nDis \"ajouter habitude Meditation\" pour commencer !";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'habit_list']);
        }

        $today = now('Europe/Paris')->toDateString();
        $lines = ["Tes habitudes :"];

        foreach ($habits->values() as $i => $habit) {
            $num = $i + 1;
            $freqLabel = $habit->frequency === 'daily' ? 'quotidienne' : 'hebdomadaire';
            $streak = $this->getCachedStreak($habit->id) ?? $this->calculateStreak($habit->id);

            $doneToday = HabitLog::where('habit_id', $habit->id)
                ->where('completed_date', $today)
                ->exists();

            $check = $doneToday ? ' [FAIT]' : '';
            $lines[] = "\n{$num}. {$habit->name}{$check}";
            $lines[] = "   Frequence: {$freqLabel} | Streak: {$streak} jour(s)";
            if ($habit->description) {
                $lines[] = "   {$habit->description}";
            }
        }

        $reply = implode("\n", $lines);
        $this->sendText($context->from, $reply);
        $this->log($context, 'Habit list viewed', ['count' => $habits->count()]);

        return AgentResult::reply($reply, ['action' => 'habit_list']);
    }

    private function handleStats(AgentContext $context, $habits): AgentResult
    {
        if ($habits->isEmpty()) {
            $reply = "Tu n'as aucune habitude enregistree. Ajoute-en une d'abord !";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'habit_stats']);
        }

        $today = now('Europe/Paris');
        $lines = ["Statistiques de tes habitudes :"];

        $totalCompleted = 0;
        $totalDoneToday = 0;

        foreach ($habits->values() as $i => $habit) {
            $num = $i + 1;
            $streak = $this->getCachedStreak($habit->id) ?? $this->calculateStreak($habit->id);
            $bestStreak = $this->getBestStreak($habit->id);
            $totalLogs = HabitLog::where('habit_id', $habit->id)->count();

            $doneToday = HabitLog::where('habit_id', $habit->id)
                ->where('completed_date', $today->toDateString())
                ->exists();

            if ($doneToday) $totalDoneToday++;
            $totalCompleted += $totalLogs;

            // Completion rate (last 30 days)
            $last30 = HabitLog::where('habit_id', $habit->id)
                ->where('completed_date', '>=', $today->copy()->subDays(30)->toDateString())
                ->count();
            $rate = $habit->frequency === 'daily' ? round(($last30 / 30) * 100) : round(($last30 / 4) * 100);
            $rate = min($rate, 100);

            $check = $doneToday ? ' [FAIT]' : '';
            $lines[] = "\n{$num}. {$habit->name}{$check}";
            $lines[] = "   Streak: {$streak} | Record: {$bestStreak} | Total: {$totalLogs}";
            $lines[] = "   Taux (30j): {$rate}%";
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

        $index = (int) $item - 1;
        $habit = $habits->values()[$index] ?? null;

        if (!$habit) {
            $reply = "Habitude #{$item} introuvable.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'habit_delete_not_found']);
        }

        $name = $habit->name;
        $habit->delete(); // soft delete

        // Clear cache
        Cache::forget("habit_streak:{$habit->id}");
        Cache::forget("habit_best_streak:{$habit->id}");

        $reply = "Habitude \"{$name}\" supprimee.";
        $this->sendText($context->from, $reply);
        $this->log($context, 'Habit deleted', ['habit_id' => $habit->id, 'name' => $name]);

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

        $index = (int) $item - 1;
        $habit = $habits->values()[$index] ?? null;

        if (!$habit) {
            $reply = "Habitude #{$item} introuvable.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'habit_reset_not_found']);
        }

        HabitLog::where('habit_id', $habit->id)->delete();

        // Clear cache
        Cache::forget("habit_streak:{$habit->id}");
        Cache::forget("habit_best_streak:{$habit->id}");

        $reply = "Habitude \"{$habit->name}\" reinitialisee. Tous les streaks et logs ont ete effaces. On recommence a zero !";
        $this->sendText($context->from, $reply);
        $this->log($context, 'Habit reset', ['habit_id' => $habit->id, 'name' => $habit->name]);

        return AgentResult::reply($reply, ['action' => 'habit_reset']);
    }

    public function calculateStreak(int $habitId): int
    {
        $cached = $this->getCachedStreak($habitId);
        if ($cached !== null) {
            return $cached;
        }

        $today = now('Europe/Paris')->toDateString();
        $streak = 0;
        $date = Carbon::parse($today, 'Europe/Paris');

        // Check if today is done; if not, start from yesterday
        $todayDone = HabitLog::where('habit_id', $habitId)
            ->where('completed_date', $today)
            ->exists();

        if (!$todayDone) {
            $date->subDay();
        }

        while (true) {
            $exists = HabitLog::where('habit_id', $habitId)
                ->where('completed_date', $date->toDateString())
                ->exists();

            if (!$exists) break;

            $streak++;
            $date->subDay();
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

        $best = HabitLog::where('habit_id', $habitId)->max('best_streak') ?? 0;
        Cache::put("habit_best_streak:{$habitId}", $best, 3600);

        return (int) $best;
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

    private function formatHabitList($habits, string $userPhone): string
    {
        if ($habits->isEmpty()) {
            return "(aucune habitude enregistree)";
        }

        $today = now('Europe/Paris')->toDateString();
        $lines = [];

        foreach ($habits->values() as $i => $habit) {
            $num = $i + 1;
            $streak = $this->getCachedStreak($habit->id) ?? $this->calculateStreak($habit->id);
            $doneToday = HabitLog::where('habit_id', $habit->id)
                ->where('completed_date', $today)
                ->exists();
            $check = $doneToday ? '[FAIT]' : '[A FAIRE]';
            $lines[] = "#{$num} {$habit->name} ({$habit->frequency}) — streak: {$streak}j {$check}";
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

        Log::info("HabitAgent parse - cleaned: {$clean}");

        return json_decode($clean, true);
    }
}
