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
    private const MAX_HABITS = 20;
    private const MAX_NAME_LENGTH = 50;

    public function name(): string
    {
        return 'habit';
    }

    public function description(): string
    {
        return 'Agent de suivi d\'habitudes (habit tracker). Permet de creer des habitudes quotidiennes ou hebdomadaires, les cocher chaque jour, suivre les streaks (series consecutives en jours ou semaines), voir les statistiques et taux de completion sur 30 jours, voir ce qu\'il reste a faire aujourd\'hui (daily ET weekly), renommer une habitude, changer la frequence, voir l\'historique des 7 derniers jours, annuler un log accidentel, recevoir de la motivation, voir le classement des streaks et le rapport hebdomadaire.';
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
            'renommer habitude', 'rename habit', 'changer nom habitude',
            'changer frequence', 'changer la frequence', 'change frequency',
            'passer en hebdo', 'passer en quotidien', 'modifier frequence',
            'historique habitude', 'habit history', 'derniers jours habitude',
            'meditation', 'sport', 'lecture', 'exercice', 'marche',
            'routine', 'routines', 'routine quotidienne', 'daily routine',
            'discipline', 'regularity', 'regularite',
            'aujourd\'hui habitude', 'habitudes du jour', 'today habits',
            'reste a faire', 'pas encore fait', 'pending habits',
            'annuler log', 'decocher habitude', 'unlog habit', 'undo habit',
            'motivation habitude', 'motiver', 'encouragement habitude', 'motivate',
            'aide habitude', 'help habit',
            'classement streak', 'streak board', 'meilleur streak', 'top streak',
            'rapport semaine', 'bilan semaine', 'weekly report', 'rapport habitudes',
        ];
    }

    public function version(): string
    {
        return '1.4.0';
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
        $now      = now(AppSetting::timezone())->format('Y-m-d H:i (l)');

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
                . "- \"Historique habitude 1\" pour les 7 derniers jours\n"
                . "- \"Classement streaks\" pour le top streaks\n"
                . "- \"Rapport semaine\" pour le bilan hebdo\n"
                . "- \"Aide habitudes\" pour le guide complet";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'habit_parse_failed']);
        }

        $action = $parsed['action'];

        return match ($action) {
            'add'              => $this->handleAdd($context, $parsed),
            'log'              => $this->handleLog($context, $habits, $parsed),
            'unlog'            => $this->handleUnlog($context, $habits, $parsed),
            'list'             => $this->handleList($context, $habits),
            'today'            => $this->handleToday($context, $habits),
            'stats'            => $this->handleStats($context, $habits),
            'delete'           => $this->handleDelete($context, $habits, $parsed),
            'reset'            => $this->handleReset($context, $habits, $parsed),
            'rename'           => $this->handleRename($context, $habits, $parsed),
            'history'          => $this->handleHistory($context, $habits, $parsed),
            'change_frequency' => $this->handleChangeFrequency($context, $habits, $parsed),
            'motivate'         => $this->handleMotivate($context, $habits),
            'streak_board'     => $this->handleStreakBoard($context, $habits),
            'weekly_report'    => $this->handleWeeklyReport($context, $habits),
            'help'             => $this->handleHelp($context),
            default            => $this->handleUnknown($context),
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

9. RENOMMER une habitude existante:
{"action": "rename", "item": 1, "name": "nouveau nom"}

10. HISTORIQUE des 7 derniers jours pour une habitude:
{"action": "history", "item": 1}
Si l'utilisateur veut voir toutes les habitudes, item = null.

11. CHANGER LA FREQUENCE d'une habitude (daily <-> weekly):
{"action": "change_frequency", "item": 1, "frequency": "daily|weekly"}

12. MOTIVATION / BILAN MOTIVATION (streaks en jeu, habitudes faites ou non):
{"action": "motivate"}

13. CLASSEMENT DES STREAKS (top streaks de toutes les habitudes, classement):
{"action": "streak_board"}

14. RAPPORT HEBDOMADAIRE (bilan de la semaine en cours, taux de completion par habitude):
{"action": "weekly_report"}

15. AIDE / GUIDE d'utilisation:
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
- "Renommer habitude 2 en Course a pied" -> {"action": "rename", "item": 2, "name": "Course a pied"}
- "Historique meditation" ou "Derniers jours meditation" -> {"action": "history", "item": X}
- "Historique de toutes mes habitudes" -> {"action": "history", "item": null}
- "Passer habitude 2 en hebdo" ou "Changer frequence meditation en quotidien" -> {"action": "change_frequency", "item": X, "frequency": "weekly|daily"}
- "Motivation" ou "Mes streaks en jeu" ou "Encourage-moi" -> {"action": "motivate"}
- "Classement streaks" ou "Top streaks" ou "Meilleur streak" ou "Streak board" -> {"action": "streak_board"}
- "Rapport semaine" ou "Bilan semaine" ou "Comment j'ai fait cette semaine" -> {"action": "weekly_report"}
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

        if (mb_strlen($name) > self::MAX_NAME_LENGTH) {
            $reply = "Le nom de l'habitude est trop long (max " . self::MAX_NAME_LENGTH . " caracteres). Essaie un nom plus court.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'habit_add_name_too_long']);
        }

        $count = Habit::where('user_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->count();

        if ($count >= self::MAX_HABITS) {
            $reply = "Tu as atteint la limite de " . self::MAX_HABITS . " habitudes. Supprime-en une avant d'en ajouter une nouvelle.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'habit_add_limit_reached']);
        }

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
        $reply     = "Habitude ajoutee !\n"
            . "Nom : {$habit->name}\n"
            . "Frequence : {$freqLabel}";

        if ($habit->description) {
            $reply .= "\nDescription : {$habit->description}";
        }

        $reply .= "\n\nDis \"j'ai fait {$habit->name}\" pour la cocher !";

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

        $tz    = AppSetting::timezone();
        $today = now($tz)->toDateString();

        // For weekly habits: prevent double-logging the same week
        if ($habit->frequency === 'weekly') {
            $weekStart    = now($tz)->startOfWeek()->toDateString();
            $doneThisWeek = HabitLog::where('habit_id', $habit->id)
                ->whereBetween('completed_date', [$weekStart, $today])
                ->first();

            if ($doneThisWeek) {
                $doneDate = Carbon::parse($doneThisWeek->completed_date)->format('d/m');
                $streak   = $this->getCachedStreak($habit->id) ?? $this->calculateStreak($habit->id, $habit->frequency);
                $unit     = $streak <= 1 ? 'semaine' : 'semaines';
                $reply    = "Tu as deja coche \"{$habit->name}\" cette semaine (le {$doneDate}) !\n"
                    . "Streak : {$streak} {$unit} consecutives\n"
                    . "Continue comme ca !";
                $this->sendText($context->from, $reply);
                return AgentResult::reply($reply, ['action' => 'habit_already_logged_week']);
            }
        } else {
            // Daily: check if already done today
            $existing = HabitLog::where('habit_id', $habit->id)
                ->where('completed_date', $today)
                ->first();

            if ($existing) {
                $streak = $this->getCachedStreak($habit->id) ?? $this->calculateStreak($habit->id, $habit->frequency);
                $unit   = $streak <= 1 ? 'jour' : 'jours';
                $reply  = "Tu as deja coche \"{$habit->name}\" aujourd'hui !\n"
                    . "Streak actuel : {$streak} {$unit}\n"
                    . "Continue comme ca !";
                $this->sendText($context->from, $reply);
                return AgentResult::reply($reply, ['action' => 'habit_already_logged']);
            }
        }

        $oldBestStreak = $this->getBestStreak($habit->id);
        $streak        = $this->calculateStreak($habit->id, $habit->frequency);
        $newStreak     = $streak + 1;
        $bestStreak    = max($oldBestStreak, $newStreak);
        $isNewRecord   = $newStreak > $oldBestStreak && $newStreak > 1;

        HabitLog::create([
            'habit_id'       => $habit->id,
            'completed_date' => $today,
            'streak_count'   => $newStreak,
            'best_streak'    => $bestStreak,
        ]);

        $this->cacheStreak($habit->id, $newStreak, $bestStreak);

        $unit  = $habit->frequency === 'weekly' ? ($newStreak <= 1 ? 'semaine' : 'semaines') : ($newStreak <= 1 ? 'jour' : 'jours');
        $bUnit = $habit->frequency === 'weekly' ? ($bestStreak <= 1 ? 'semaine' : 'semaines') : ($bestStreak <= 1 ? 'jour' : 'jours');

        $reply = "Habitude \"{$habit->name}\" cochee !\n"
            . "Streak : {$newStreak} {$unit} | Record : {$bestStreak} {$bUnit}";

        $milestone = $this->getMilestoneMessage($newStreak, $isNewRecord, $habit->frequency);
        if ($milestone) {
            $reply .= "\n\n{$milestone}";
        }

        $this->sendText($context->from, $reply);
        $this->log($context, 'Habit logged', [
            'habit_id'    => $habit->id,
            'streak'      => $newStreak,
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

        $tz    = AppSetting::timezone();
        $today = now($tz)->toDateString();

        if ($habit->frequency === 'weekly') {
            $weekStart = now($tz)->startOfWeek()->toDateString();
            $log       = HabitLog::where('habit_id', $habit->id)
                ->whereBetween('completed_date', [$weekStart, $today])
                ->first();
        } else {
            $log = HabitLog::where('habit_id', $habit->id)
                ->where('completed_date', $today)
                ->first();
        }

        if (!$log) {
            $scope = $habit->frequency === 'weekly' ? 'cette semaine' : 'aujourd\'hui';
            $reply = "Tu n'as pas encore coche \"{$habit->name}\" {$scope}.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'habit_unlog_not_logged']);
        }

        $log->delete();
        Cache::forget("habit_streak:{$habit->id}");

        $scope = $habit->frequency === 'weekly' ? 'cette semaine' : "aujourd'hui";
        $reply = "Log annule pour \"{$habit->name}\" ({$scope}). Le streak a ete mis a jour.";
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

        $tz       = AppSetting::timezone();
        $today    = now($tz)->toDateString();
        $habitIds = $habits->pluck('id')->toArray();

        $doneTodayIds = HabitLog::whereIn('habit_id', $habitIds)
            ->where('completed_date', $today)
            ->pluck('habit_id')
            ->toArray();

        $weekStart       = now($tz)->startOfWeek()->toDateString();
        $doneThisWeekIds = HabitLog::whereIn('habit_id', $habitIds)
            ->whereBetween('completed_date', [$weekStart, $today])
            ->pluck('habit_id')
            ->unique()
            ->toArray();

        $lines = ["Tes habitudes :"];

        foreach ($habits->values() as $i => $habit) {
            $num       = $i + 1;
            $freqLabel = $habit->frequency === 'daily' ? 'quotidien' : 'hebdo';
            $streak    = $this->getCachedStreak($habit->id) ?? $this->calculateStreak($habit->id, $habit->frequency);
            $unit      = $habit->frequency === 'weekly' ? 'sem' : 'j';

            $isDone = $habit->frequency === 'weekly'
                ? in_array($habit->id, $doneThisWeekIds)
                : in_array($habit->id, $doneTodayIds);

            $status = $isDone ? '[FAIT]' : '[A FAIRE]';

            $lines[] = "\n{$num}. {$habit->name} {$status}";
            $lines[] = "   {$freqLabel} | Streak: {$streak}{$unit}";
            if ($habit->description) {
                $lines[] = "   {$habit->description}";
            }
        }

        $doneDaily  = collect($habits)->where('frequency', 'daily')->filter(fn($h) => in_array($h->id, $doneTodayIds))->count();
        $doneWeekly = collect($habits)->where('frequency', 'weekly')->filter(fn($h) => in_array($h->id, $doneThisWeekIds))->count();
        $doneCount  = $doneDaily + $doneWeekly;
        $total      = $habits->count();

        $lines[] = "\n---";
        $lines[] = "Aujourd'hui : {$doneCount}/{$total} completees";

        $reply = implode("\n", $lines);
        $this->sendText($context->from, $reply);
        $this->log($context, 'Habit list viewed', ['count' => $total]);

        return AgentResult::reply($reply, ['action' => 'habit_list']);
    }

    /**
     * Show today's status for all habits (daily AND weekly).
     */
    private function handleToday(AgentContext $context, $habits): AgentResult
    {
        if ($habits->isEmpty()) {
            $reply = "Tu n'as aucune habitude enregistree.\nDis \"ajouter habitude Meditation\" pour commencer !";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'habit_today_empty']);
        }

        $tz       = AppSetting::timezone();
        $now      = now($tz);
        $today    = $now->toDateString();
        $habitIds = $habits->pluck('id')->toArray();

        $doneTodayIds = HabitLog::whereIn('habit_id', $habitIds)
            ->where('completed_date', $today)
            ->pluck('habit_id')
            ->toArray();

        $weekStart       = $now->copy()->startOfWeek()->toDateString();
        $doneThisWeekIds = HabitLog::whereIn('habit_id', $habitIds)
            ->whereBetween('completed_date', [$weekStart, $today])
            ->pluck('habit_id')
            ->unique()
            ->toArray();

        $dailyHabits  = $habits->where('frequency', 'daily');
        $weeklyHabits = $habits->where('frequency', 'weekly');

        $doneDaily     = $dailyHabits->filter(fn($h) => in_array($h->id, $doneTodayIds));
        $pendingDaily  = $dailyHabits->filter(fn($h) => !in_array($h->id, $doneTodayIds));
        $doneWeekly    = $weeklyHabits->filter(fn($h) => in_array($h->id, $doneThisWeekIds));
        $pendingWeekly = $weeklyHabits->filter(fn($h) => !in_array($h->id, $doneThisWeekIds));

        $lines = ["Aujourd'hui :"];

        if ($doneDaily->isNotEmpty() || $doneWeekly->isNotEmpty()) {
            $lines[] = "\nFaites :";
            foreach ($doneDaily->values() as $habit) {
                $num    = $habits->values()->search(fn($h) => $h->id === $habit->id) + 1;
                $streak = $this->getCachedStreak($habit->id) ?? $this->calculateStreak($habit->id, $habit->frequency);
                $lines[] = "  {$num}. {$habit->name} (streak: {$streak}j)";
            }
            foreach ($doneWeekly->values() as $habit) {
                $num    = $habits->values()->search(fn($h) => $h->id === $habit->id) + 1;
                $streak = $this->getCachedStreak($habit->id) ?? $this->calculateStreak($habit->id, $habit->frequency);
                $lines[] = "  {$num}. {$habit->name} [hebdo, fait cette semaine] (streak: {$streak} sem)";
            }
        }

        if ($pendingDaily->isNotEmpty() || $pendingWeekly->isNotEmpty()) {
            $lines[] = "\nA faire :";
            foreach ($pendingDaily->values() as $habit) {
                $num    = $habits->values()->search(fn($h) => $h->id === $habit->id) + 1;
                $streak = $this->getCachedStreak($habit->id) ?? $this->calculateStreak($habit->id, $habit->frequency);
                $lines[] = "  {$num}. {$habit->name} (streak: {$streak}j)";
            }
            foreach ($pendingWeekly->values() as $habit) {
                $num    = $habits->values()->search(fn($h) => $h->id === $habit->id) + 1;
                $streak = $this->getCachedStreak($habit->id) ?? $this->calculateStreak($habit->id, $habit->frequency);
                $lines[] = "  {$num}. {$habit->name} [hebdo, pas encore fait cette semaine] (streak: {$streak} sem)";
            }
        }

        $doneCount    = $doneDaily->count() + $doneWeekly->count();
        $pendingCount = $pendingDaily->count() + $pendingWeekly->count();
        $total        = $habits->count();

        $lines[] = "\n{$doneCount}/{$total} habitudes completees.";

        if ($pendingCount === 0 && $total > 0) {
            $lines[] = "Bravo, toutes les habitudes sont a jour !";
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

        $tz       = AppSetting::timezone();
        $today    = now($tz);
        $todayStr = $today->toDateString();
        $since30  = $today->copy()->subDays(30)->toDateString();
        $habitIds = $habits->pluck('id')->toArray();

        $doneTodayIds = HabitLog::whereIn('habit_id', $habitIds)
            ->where('completed_date', $todayStr)
            ->pluck('habit_id')
            ->toArray();

        $weekStart       = $today->copy()->startOfWeek()->toDateString();
        $doneThisWeekIds = HabitLog::whereIn('habit_id', $habitIds)
            ->whereBetween('completed_date', [$weekStart, $todayStr])
            ->pluck('habit_id')
            ->unique()
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
            $streak     = $this->getCachedStreak($habit->id) ?? $this->calculateStreak($habit->id, $habit->frequency);
            $bestStreak = $this->getBestStreak($habit->id);
            $totalLogs  = $totalLogsMap[$habit->id] ?? 0;
            $last30     = $last30Map[$habit->id] ?? 0;

            $isDone = $habit->frequency === 'weekly'
                ? in_array($habit->id, $doneThisWeekIds)
                : in_array($habit->id, $doneTodayIds);

            if ($isDone) $totalDoneToday++;
            $totalCompleted += $totalLogs;

            $denominator = $habit->frequency === 'daily' ? 30 : round(30 / 7, 1);
            $rate        = $denominator > 0 ? min(100, round(($last30 / $denominator) * 100)) : 0;

            $freqLabel  = $habit->frequency === 'daily' ? 'quotidien' : 'hebdo';
            $streakUnit = $habit->frequency === 'weekly' ? 'sem' : 'j';
            $status     = $isDone ? ' [FAIT]' : '';

            $lines[] = "\n{$num}. {$habit->name}{$status} [{$freqLabel}]";
            $lines[] = "   Streak: {$streak}{$streakUnit} | Record: {$bestStreak}{$streakUnit} | Total: {$totalLogs}";
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
        $habit->delete();

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

    /**
     * Rename an existing habit (checks for duplicates, preserves all logs and streaks).
     */
    private function handleRename(AgentContext $context, $habits, array $parsed): AgentResult
    {
        $item    = $parsed['item'] ?? null;
        $newName = trim($parsed['name'] ?? '');

        if (!$item || $habits->isEmpty()) {
            $reply = "Quelle habitude veux-tu renommer ? Ex: \"Renommer habitude 2 en Course a pied\"";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'habit_rename_no_item']);
        }

        if (!$newName) {
            $reply = "Donne-moi le nouveau nom. Ex: \"Renommer habitude 2 en Course a pied\"";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'habit_rename_no_name']);
        }

        $habit = $habits->values()[(int) $item - 1] ?? null;

        if (!$habit) {
            $reply = "Habitude #{$item} introuvable. Dis \"mes habitudes\" pour voir ta liste.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'habit_rename_not_found']);
        }

        $exists = Habit::where('user_phone', $context->from)
            ->where('agent_id', $context->agent->id)
            ->where('id', '!=', $habit->id)
            ->whereRaw('LOWER(name) = ?', [strtolower($newName)])
            ->exists();

        if ($exists) {
            $reply = "Tu as deja une habitude nommee \"{$newName}\".";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'habit_rename_duplicate']);
        }

        $oldName = $habit->name;
        $habit->update(['name' => $newName]);

        $reply = "Habitude renommee : \"{$oldName}\" -> \"{$newName}\"";
        $this->sendText($context->from, $reply);
        $this->log($context, 'Habit renamed', [
            'habit_id' => $habit->id,
            'old_name' => $oldName,
            'new_name' => $newName,
        ]);

        return AgentResult::reply($reply, ['action' => 'habit_rename', 'habit_id' => $habit->id]);
    }

    /**
     * Change frequency of an existing habit (daily <-> weekly).
     * Clears streak cache since the calculation unit changes.
     */
    private function handleChangeFrequency(AgentContext $context, $habits, array $parsed): AgentResult
    {
        $item      = $parsed['item'] ?? null;
        $frequency = $parsed['frequency'] ?? null;

        if (!$item || $habits->isEmpty()) {
            $reply = "Quelle habitude veux-tu modifier ?\nEx: \"Passer habitude 2 en hebdo\"";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'habit_change_freq_no_item']);
        }

        if (!in_array($frequency, ['daily', 'weekly'])) {
            $reply = "Indique la frequence souhaitee :\n- \"daily\" (quotidienne)\n- \"weekly\" (hebdomadaire)";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'habit_change_freq_invalid']);
        }

        $habit = $habits->values()[(int) $item - 1] ?? null;

        if (!$habit) {
            $reply = "Habitude #{$item} introuvable. Dis \"mes habitudes\" pour voir ta liste.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'habit_change_freq_not_found']);
        }

        if ($habit->frequency === $frequency) {
            $freqLabel = $frequency === 'daily' ? 'quotidienne' : 'hebdomadaire';
            $reply     = "\"{$habit->name}\" est deja en mode {$freqLabel}.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'habit_change_freq_same']);
        }

        $oldLabel = $habit->frequency === 'daily' ? 'quotidienne' : 'hebdomadaire';
        $newLabel = $frequency === 'daily' ? 'quotidienne' : 'hebdomadaire';

        $habit->update(['frequency' => $frequency]);

        Cache::forget("habit_streak:{$habit->id}");

        $reply = "Frequence de \"{$habit->name}\" changee !\n"
            . "{$oldLabel} -> {$newLabel}\n"
            . "Le streak sera recalcule en " . ($frequency === 'weekly' ? 'semaines' : 'jours') . ".";
        $this->sendText($context->from, $reply);
        $this->log($context, 'Habit frequency changed', [
            'habit_id' => $habit->id,
            'old_freq'  => $habit->frequency,
            'new_freq'  => $frequency,
        ]);

        return AgentResult::reply($reply, ['action' => 'habit_change_frequency', 'habit_id' => $habit->id]);
    }

    /**
     * Show today's motivation: streaks at risk, habits on track, encouragements.
     */
    private function handleMotivate(AgentContext $context, $habits): AgentResult
    {
        if ($habits->isEmpty()) {
            $reply = "Tu n'as aucune habitude enregistree.\nAjoute-en une pour commencer : \"Ajouter habitude Meditation\"";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'habit_motivate_empty']);
        }

        $tz        = AppSetting::timezone();
        $today     = now($tz)->toDateString();
        $weekStart = now($tz)->startOfWeek()->toDateString();
        $habitIds  = $habits->pluck('id')->toArray();

        $doneTodayIds = HabitLog::whereIn('habit_id', $habitIds)
            ->where('completed_date', $today)
            ->pluck('habit_id')
            ->toArray();

        $doneThisWeekIds = HabitLog::whereIn('habit_id', $habitIds)
            ->whereBetween('completed_date', [$weekStart, $today])
            ->pluck('habit_id')
            ->unique()
            ->toArray();

        $atRisk     = [];
        $onTrack    = [];
        $notStarted = [];

        foreach ($habits->values() as $i => $habit) {
            $num    = $i + 1;
            $streak = $this->getCachedStreak($habit->id) ?? $this->calculateStreak($habit->id, $habit->frequency);
            $unit   = $habit->frequency === 'weekly' ? 'sem' : 'j';

            $isDone = $habit->frequency === 'weekly'
                ? in_array($habit->id, $doneThisWeekIds)
                : in_array($habit->id, $doneTodayIds);

            if ($isDone) {
                $onTrack[] = "  {$num}. {$habit->name} (streak: {$streak}{$unit})";
            } elseif ($streak > 0) {
                $atRisk[] = "  {$num}. {$habit->name} — {$streak}{$unit} de streak en jeu !";
            } else {
                $notStarted[] = "  {$num}. {$habit->name}";
            }
        }

        $lines = ["Bilan motivation :"];

        if (!empty($onTrack)) {
            $lines[] = "\nDeja faites :";
            foreach ($onTrack as $item) {
                $lines[] = $item;
            }
        }

        if (!empty($atRisk)) {
            $lines[] = "\nStreaks en jeu :";
            foreach ($atRisk as $item) {
                $lines[] = $item;
            }
            $lines[] = "Ne les oublie pas !";
        }

        if (!empty($notStarted)) {
            $lines[] = "\nPas encore commencees :";
            foreach ($notStarted as $item) {
                $lines[] = $item;
            }
            $lines[] = "Le premier pas est le plus dur — lance-toi !";
        }

        $doneCount = count($onTrack);
        $total     = $habits->count();

        if ($doneCount === $total) {
            $lines[] = "\nBravo ! Toutes tes habitudes sont a jour !";
        } elseif ($doneCount >= (int) ceil($total / 2)) {
            $lines[] = "\nBonne progression ! Plus que " . ($total - $doneCount) . " habitude(s) a cocher.";
        } elseif (!empty($atRisk)) {
            $lines[] = "\nProtege tes streaks — chaque jour compte !";
        } else {
            $lines[] = "\nChaque habitude cochee est une victoire. Vas-y !";
        }

        $reply = implode("\n", $lines);
        $this->sendText($context->from, $reply);
        $this->log($context, 'Habit motivate viewed', ['at_risk' => count($atRisk), 'on_track' => $doneCount]);

        return AgentResult::reply($reply, ['action' => 'habit_motivate']);
    }

    /**
     * Show 7-day completion history for one or all habits.
     */
    private function handleHistory(AgentContext $context, $habits, array $parsed): AgentResult
    {
        if ($habits->isEmpty()) {
            $reply = "Tu n'as aucune habitude enregistree.";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'habit_history_empty']);
        }

        $item = $parsed['item'] ?? null;
        $tz   = AppSetting::timezone();
        $now  = now($tz);

        $days = [];
        for ($i = 6; $i >= 0; $i--) {
            $days[] = $now->copy()->subDays($i)->toDateString();
        }

        if ($item !== null) {
            $targetHabit = $habits->values()[(int) $item - 1] ?? null;
            if (!$targetHabit) {
                $reply = "Habitude #{$item} introuvable. Dis \"mes habitudes\" pour voir ta liste.";
                $this->sendText($context->from, $reply);
                return AgentResult::reply($reply, ['action' => 'habit_history_not_found']);
            }
            $targetHabits = collect([$targetHabit]);
        } else {
            $targetHabits = $habits;
        }

        $habitIds = $targetHabits->pluck('id')->toArray();

        $logsByHabit = HabitLog::whereIn('habit_id', $habitIds)
            ->whereBetween('completed_date', [$days[0], $days[6]])
            ->get()
            ->groupBy('habit_id');

        $lines = ["Historique (7 derniers jours) :"];

        foreach ($targetHabits->values() as $habit) {
            $num       = $habits->values()->search(fn($h) => $h->id === $habit->id) + 1;
            $habitLogs = $logsByHabit->get($habit->id, collect());
            $logDates  = $habitLogs->pluck('completed_date')
                ->map(fn($d) => $d instanceof Carbon ? $d->toDateString() : Carbon::parse($d)->toDateString())
                ->toArray();

            $cells     = [];
            $doneCount = 0;
            foreach ($days as $day) {
                $dayLabel = Carbon::parse($day, $tz)->format('d/m');
                $done     = in_array($day, $logDates);
                if ($done) $doneCount++;
                $cells[] = "{$dayLabel}:" . ($done ? 'X' : '_');
            }

            $freqLabel = $habit->frequency === 'weekly' ? ' [hebdo]' : '';
            $lines[]   = "\n{$num}. {$habit->name}{$freqLabel}";
            $lines[]   = "   " . implode(' | ', $cells);
            $lines[]   = "   Total: {$doneCount}/7 jours";
        }

        $reply = implode("\n", $lines);
        $this->sendText($context->from, $reply);
        $this->log($context, 'Habit history viewed', ['count' => $targetHabits->count()]);

        return AgentResult::reply($reply, ['action' => 'habit_history']);
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
            . "  \"Stats habitudes\" — statistiques completes\n"
            . "  \"Historique habitude 2\" — 7 derniers jours\n"
            . "  \"Motivation\" — bilan streaks en jeu\n"
            . "  \"Classement streaks\" — top streaks de tes habitudes\n"
            . "  \"Rapport semaine\" — bilan de la semaine en cours\n\n"
            . "GERER\n"
            . "  \"Renommer habitude 2 en Course a pied\"\n"
            . "  \"Passer habitude 2 en hebdo\" (changer frequence)\n"
            . "  \"Supprimer habitude 2\"\n"
            . "  \"Reset habitude 1\" — remet a zero\n\n"
            . "Les habitudes sont numerotees dans ta liste.\n"
            . "Streaks daily = jours | Streaks weekly = semaines.";

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
     * NEW: Show habits ranked by current streak descending.
     */
    private function handleStreakBoard(AgentContext $context, $habits): AgentResult
    {
        if ($habits->isEmpty()) {
            $reply = "Tu n'as aucune habitude enregistree.\nDis \"ajouter habitude Meditation\" pour commencer !";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'habit_streak_board_empty']);
        }

        $ranked = [];
        foreach ($habits->values() as $i => $habit) {
            $streak     = $this->getCachedStreak($habit->id) ?? $this->calculateStreak($habit->id, $habit->frequency);
            $bestStreak = $this->getBestStreak($habit->id);
            $unit       = $habit->frequency === 'weekly' ? 'sem' : 'j';
            $ranked[]   = [
                'num'        => $i + 1,
                'name'       => $habit->name,
                'streak'     => $streak,
                'bestStreak' => $bestStreak,
                'unit'       => $unit,
            ];
        }

        usort($ranked, fn($a, $b) => $b['streak'] <=> $a['streak']);

        $medals = ['1' => '1er', '2' => '2eme', '3' => '3eme'];
        $lines  = ["Classement des streaks :"];

        foreach ($ranked as $rank => $data) {
            $pos     = $rank + 1;
            $prefix  = $medals[(string) $pos] ?? "{$pos}eme";
            $lines[] = "\n{$prefix}. {$data['name']}";
            $lines[] = "   Streak: {$data['streak']}{$data['unit']} | Record: {$data['bestStreak']}{$data['unit']}";
        }

        $totalStreak = array_sum(array_column($ranked, 'streak'));
        $lines[]     = "\n---";
        $lines[]     = "Total streaks cumules : {$totalStreak}";

        $reply = implode("\n", $lines);
        $this->sendText($context->from, $reply);
        $this->log($context, 'Habit streak board viewed', ['count' => count($ranked)]);

        return AgentResult::reply($reply, ['action' => 'habit_streak_board']);
    }

    /**
     * NEW: Weekly report — completion rate per habit for the current week.
     */
    private function handleWeeklyReport(AgentContext $context, $habits): AgentResult
    {
        if ($habits->isEmpty()) {
            $reply = "Tu n'as aucune habitude enregistree.\nDis \"ajouter habitude Meditation\" pour commencer !";
            $this->sendText($context->from, $reply);
            return AgentResult::reply($reply, ['action' => 'habit_weekly_report_empty']);
        }

        $tz        = AppSetting::timezone();
        $now       = now($tz);
        $weekStart = $now->copy()->startOfWeek()->toDateString();
        $weekEnd   = $now->copy()->endOfWeek()->toDateString();
        $today     = $now->toDateString();
        $habitIds  = $habits->pluck('id')->toArray();

        // Build list of days from Monday to today (within the current week)
        $days   = [];
        $cursor = Carbon::parse($weekStart, $tz);
        $limit  = Carbon::parse(min($today, $weekEnd), $tz);
        while ($cursor->lte($limit)) {
            $days[] = $cursor->toDateString();
            $cursor->addDay();
        }
        $elapsedDays = count($days);

        $logsByHabit = HabitLog::whereIn('habit_id', $habitIds)
            ->whereBetween('completed_date', [$weekStart, $today])
            ->get()
            ->groupBy('habit_id');

        $totalDone     = 0;
        $totalPossible = 0;
        $weekLabel     = Carbon::parse($weekStart, $tz)->format('d/m') . ' - ' . Carbon::parse($weekEnd, $tz)->format('d/m');
        $lines         = ["Rapport semaine ({$weekLabel}) :"];

        foreach ($habits->values() as $i => $habit) {
            $num       = $i + 1;
            $habitLogs = $logsByHabit->get($habit->id, collect());

            if ($habit->frequency === 'daily') {
                $possible  = $elapsedDays;
                $doneCount = $habitLogs->count();
            } else {
                // Weekly: 1 expected per week, count as 0 or 1
                $possible  = 1;
                $doneCount = $habitLogs->isNotEmpty() ? 1 : 0;
            }

            $rate = $possible > 0 ? round(($doneCount / $possible) * 100) : 0;
            $totalDone     += $doneCount;
            $totalPossible += $possible;

            $bar     = $this->buildMiniBar($doneCount, $possible);
            $freq    = $habit->frequency === 'daily' ? 'daily' : 'hebdo';
            $lines[] = "\n{$num}. {$habit->name} [{$freq}]";
            $lines[] = "   {$bar} {$doneCount}/{$possible} ({$rate}%)";
        }

        $globalRate = $totalPossible > 0 ? (int) round(($totalDone / $totalPossible) * 100) : 0;
        $lines[]    = "\n---";
        $lines[]    = "Bilan semaine : {$totalDone}/{$totalPossible} ({$globalRate}%)";

        if ($globalRate === 100) {
            $lines[] = "Semaine parfaite ! Continue comme ca !";
        } elseif ($globalRate >= 75) {
            $lines[] = "Tres bonne semaine !";
        } elseif ($globalRate >= 50) {
            $lines[] = "Bonne progression, on peut faire mieux !";
        } else {
            $lines[] = "Il reste du chemin — la semaine n'est pas finie !";
        }

        $reply = implode("\n", $lines);
        $this->sendText($context->from, $reply);
        $this->log($context, 'Habit weekly report viewed', ['global_rate' => $globalRate]);

        return AgentResult::reply($reply, ['action' => 'habit_weekly_report']);
    }

    /**
     * Build a simple mini progress bar (e.g. "###--" for 3/5).
     */
    private function buildMiniBar(int $done, int $total, int $width = 5): string
    {
        if ($total <= 0) {
            return str_repeat('-', $width);
        }
        $filled = (int) round(($done / $total) * $width);
        $filled = max(0, min($width, $filled));
        return str_repeat('#', $filled) . str_repeat('-', $width - $filled);
    }

    /**
     * Calculate the current streak for a habit.
     * For daily habits: consecutive days ending today or yesterday.
     * For weekly habits: consecutive weeks ending this week or last week.
     */
    public function calculateStreak(int $habitId, string $frequency = 'daily'): int
    {
        $cached = $this->getCachedStreak($habitId);
        if ($cached !== null) {
            return $cached;
        }

        $tz    = AppSetting::timezone();
        $dates = HabitLog::where('habit_id', $habitId)
            ->orderByDesc('completed_date')
            ->pluck('completed_date')
            ->map(fn($d) => $d instanceof Carbon ? $d->toDateString() : Carbon::parse($d)->toDateString())
            ->toArray();

        if (empty($dates)) {
            $this->cacheStreak($habitId, 0, null);
            return 0;
        }

        $streak = $frequency === 'weekly'
            ? $this->calculateConsecutiveWeeks($dates, $tz)
            : $this->calculateConsecutiveDays($dates, $tz);

        $this->cacheStreak($habitId, $streak, null);

        return $streak;
    }

    /**
     * Count consecutive days (for daily habits).
     */
    private function calculateConsecutiveDays(array $dates, string $tz): int
    {
        $today   = now($tz)->toDateString();
        $streak  = 0;
        $current = Carbon::parse($today, $tz);

        if ($dates[0] !== $today) {
            $current->subDay();
        }

        foreach ($dates as $date) {
            if ($date === $current->toDateString()) {
                $streak++;
                $current->subDay();
            } elseif (Carbon::parse($date) < $current) {
                break;
            }
        }

        return $streak;
    }

    /**
     * Count consecutive weeks (for weekly habits).
     * A week is "done" if at least one log exists in it.
     */
    private function calculateConsecutiveWeeks(array $dates, string $tz): int
    {
        $now           = now($tz);
        $thisWeekStart = $now->copy()->startOfWeek();

        // Map each date to its ISO week start
        $weeks = [];
        foreach ($dates as $date) {
            $weekStart          = Carbon::parse($date, $tz)->startOfWeek()->toDateString();
            $weeks[$weekStart]  = true;
        }

        $streak    = 0;
        $checkWeek = $thisWeekStart->copy();

        // If not logged this week, start from last week
        if (!isset($weeks[$checkWeek->toDateString()])) {
            $checkWeek->subWeek();
        }

        while (isset($weeks[$checkWeek->toDateString()])) {
            $streak++;
            $checkWeek->subWeek();
            if ($streak > 1000) break; // safety guard
        }

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

        $tz       = AppSetting::timezone();
        $today    = now($tz)->toDateString();
        $habitIds = $habits->pluck('id')->toArray();

        $doneTodayIds = HabitLog::whereIn('habit_id', $habitIds)
            ->where('completed_date', $today)
            ->pluck('habit_id')
            ->toArray();

        $weekStart       = now($tz)->startOfWeek()->toDateString();
        $doneThisWeekIds = HabitLog::whereIn('habit_id', $habitIds)
            ->whereBetween('completed_date', [$weekStart, $today])
            ->pluck('habit_id')
            ->unique()
            ->toArray();

        $lines = [];

        foreach ($habits->values() as $i => $habit) {
            $num    = $i + 1;
            $streak = $this->getCachedStreak($habit->id) ?? $this->calculateStreak($habit->id, $habit->frequency);
            $unit   = $habit->frequency === 'weekly' ? 'sem' : 'j';

            $isDone = $habit->frequency === 'weekly'
                ? in_array($habit->id, $doneThisWeekIds)
                : in_array($habit->id, $doneTodayIds);

            $check   = $isDone ? '[FAIT]' : '[A FAIRE]';
            $lines[] = "#{$num} {$habit->name} ({$habit->frequency}) — streak: {$streak}{$unit} {$check}";
        }

        return implode("\n", $lines);
    }

    private function getMilestoneMessage(int $streak, bool $isNewRecord, string $frequency = 'daily'): string
    {
        $unit       = $frequency === 'weekly' ? 'semaines' : 'jours';
        $milestones = $frequency === 'weekly' ? [52, 26, 12, 8, 4, 2] : [100, 50, 30, 21, 14, 7, 3];

        foreach ($milestones as $m) {
            if ($streak === $m) {
                if ($frequency === 'weekly') {
                    return match ($m) {
                        2  => "2 semaines de suite, super debut !",
                        4  => "1 mois complet sans interruption !",
                        8  => "2 mois de regularite — impressionnant !",
                        12 => "3 mois, une vraie discipline !",
                        26 => "6 mois — mi-annee de streak !",
                        52 => "1 an complet ! Legendaire !",
                        default => "{$m} {$unit} d'affilee !",
                    };
                }

                return match ($m) {
                    3   => "3 jours de suite, beau debut !",
                    7   => "1 semaine complete, continue !",
                    14  => "2 semaines sans interruption !",
                    21  => "21 jours — une nouvelle habitude est nee !",
                    30  => "30 jours d'affilee, impressionnant !",
                    50  => "50 jours — tu es une machine !",
                    100 => "100 jours ! Legendaire !",
                    default => "{$m} {$unit} d'affilee !",
                };
            }
        }

        if ($isNewRecord) {
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

        Log::debug("HabitAgent parse - cleaned: {$clean}");

        $decoded = json_decode($clean, true);
        if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
            Log::warning("HabitAgent JSON parse error: " . json_last_error_msg() . " | raw: {$clean}");
        }

        return $decoded;
    }
}
