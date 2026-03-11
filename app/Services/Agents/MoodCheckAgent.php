<?php

namespace App\Services\Agents;

use App\Models\AppSetting;
use App\Models\MoodLog;
use App\Services\AgentContext;
use App\Services\ModelResolver;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class MoodCheckAgent extends BaseAgent
{
    public function name(): string
    {
        return 'mood_check';
    }

    public function description(): string
    {
        return 'Agent de suivi d\'humeur et bien-etre. Enregistre le niveau d\'humeur (1-5 ou emoji), detecte les tendances, identifie les heures de baisse d\'energie, fournit des recommandations personnalisees et empathiques. Commandes: mood today, mood stats [7|14|30], mood history, mood streak, mood weekly, mood compare, mood goal [1-5], mood goal reset, mood help.';
    }

    public function keywords(): array
    {
        return [
            'mood', 'mood check', 'humeur', 'mon humeur', 'my mood',
            'comment je me sens', 'how am i doing', 'how do i feel',
            'comment ca va', 'comment tu te sens', 'ca va pas',
            'je me sens', 'je suis stresse', 'je suis fatigue', 'je suis triste',
            'je suis heureux', 'je suis deprime', 'je suis anxieux',
            'i feel', 'i am feeling',
            'stresse', 'stressed', 'fatigue', 'tired', 'epuise',
            'energique', 'motive', 'deprime', 'depressed', 'anxieux', 'anxious',
            'mood stats', 'stats humeur', 'statistiques humeur',
            'mood today', 'humeur aujourd\'hui', 'mon humeur du jour',
            'tendance humeur', 'mood trend',
            'bien-etre', 'wellness', 'mental health', 'sante mentale',
            'mood history', 'historique humeur', 'mes humeurs',
            'mood streak', 'serie humeur', 'streak humeur',
            'mood help', 'aide humeur', 'commandes humeur',
            'mood log',
            'mood weekly', 'semaine humeur', 'pattern humeur', 'hebdomadaire humeur',
            'mood goal', 'objectif humeur', 'mon objectif humeur',
            'mood compare', 'comparer humeur', 'comparaison humeur',
        ];
    }

    public function version(): string
    {
        return '1.4.0';
    }

    public function canHandle(AgentContext $context): bool
    {
        if (!$context->body) return false;

        $body = mb_strtolower(trim($context->body));

        $patterns = [
            '/\bmood\b/',
            '/\bmood[\s_-]?check\b/',
            '/\bhow\s+am\s+i\s+doing\b/',
            '/\bcomment\s+(tu\s+te\s+sens|ca\s+va|ça\s+va)\b/',
            '/\bje\s+(me\s+sens|suis)\s+(bien|mal|triste|stresse|fatigue|energique|heureux|deprime|anxieux|epuise)/i',
            '/\bmood[\s_-]?stats?\b/',
            '/\bmood[\s_-]?today\b/',
            '/\bhumeur\s+(aujourd\'hui|du\s+jour|stats?|historique|streak|semaine)\b/',
            '/\bcomment\s+je\s+me\s+sens\b/',
            '/\bmood[\s_-]?history\b/',
            '/\bhistorique\s+humeur\b/',
            '/\bmood[\s_-]?streak\b/',
            '/\bserie\s+humeur\b/',
            '/\bmood[\s_-]?help\b/',
            '/\baide\s+humeur\b/',
            '/\bmes\s+humeurs\b/',
            '/\bmood\s+[1-5]\b/',
            '/\bmood[\s_-]?weekly\b/',
            '/\bsemaine\s+humeur\b/',
            '/\bpattern\s+humeur\b/',
            '/\bmood[\s_-]?goal\b/',
            '/\bobjectif\s+humeur\b/',
            '/\bmood[\s_-]?compare\b/',
            '/\bcomparer\s+humeur\b/',
            '/\bcomparaison\s+humeur\b/',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $body)) return true;
        }

        return false;
    }

    public function handle(AgentContext $context): AgentResult
    {
        $body = trim($context->body ?? '');

        // "mood help" — liste des commandes
        if (preg_match('/mood[\s_-]?help|aide\s+humeur|commandes\s+humeur/i', $body)) {
            $help = $this->buildHelpMessage();
            $this->sendText($context->from, $help);
            $this->log($context, 'Help requested');
            return AgentResult::reply($help);
        }

        // "mood weekly" — pattern par jour de semaine
        if (preg_match('/mood[\s_-]?weekly|semaine\s+humeur|pattern\s+humeur|hebdomadaire\s+humeur/i', $body)) {
            $weekly = $this->generateWeeklyPattern($context->from);
            $this->sendText($context->from, $weekly);
            $this->log($context, 'Weekly pattern requested');
            return AgentResult::reply($weekly);
        }

        // "mood compare" — comparer cette semaine vs semaine passee
        if (preg_match('/mood[\s_-]?compare|comparer\s+humeur|comparaison\s+humeur/i', $body)) {
            $compare = $this->generateCompare($context->from);
            $this->sendText($context->from, $compare);
            $this->log($context, 'Week comparison requested');
            return AgentResult::reply($compare);
        }

        // "mood goal reset" — supprimer l'objectif
        if (preg_match('/mood[\s_-]?goal[\s_-]?reset|reset[\s_-]?goal|supprimer\s+objectif\s+humeur/i', $body)) {
            $resetMsg = $this->resetMoodGoal($context->from);
            $this->sendText($context->from, $resetMsg);
            $this->log($context, 'Mood goal reset');
            return AgentResult::reply($resetMsg);
        }

        // "mood goal [1-5]" — definir ou consulter un objectif
        if (preg_match('/mood[\s_-]?goal|objectif\s+humeur/i', $body)) {
            if (preg_match('/mood[\s_-]?goal\s+([1-5])|objectif\s+humeur\s+([1-5])/i', $body, $m)) {
                $level   = (int) ($m[1] ?: $m[2]);
                $goalMsg = $this->setMoodGoal($context->from, $level);
                $this->sendText($context->from, $goalMsg);
                $this->log($context, "Mood goal set to {$level}");
                return AgentResult::reply($goalMsg);
            }
            $goalMsg = $this->showMoodGoal($context->from);
            $this->sendText($context->from, $goalMsg);
            $this->log($context, 'Mood goal consulted');
            return AgentResult::reply($goalMsg);
        }

        // "mood today" — resume du jour
        if (preg_match('/mood[\s_-]?today|humeur\s+(aujourd\'hui|du\s+jour)/i', $body)) {
            $summary = $this->generateTodaySummary($context->from);
            $this->sendText($context->from, $summary);
            $this->log($context, 'Today mood summary requested');
            return AgentResult::reply($summary);
        }

        // "mood stats [7|14|30]" — stats sur N jours
        if (preg_match('/mood[\s_-]?stats?|stats\s+humeur|statistiques\s+humeur/i', $body)) {
            $days = 7;
            if (preg_match('/\b30\b/', $body)) $days = 30;
            elseif (preg_match('/\b14\b/', $body)) $days = 14;
            $stats = $this->generateStats($context->from, $days);
            $this->sendText($context->from, $stats);
            $this->log($context, "Mood stats requested ({$days}j)");
            return AgentResult::reply($stats);
        }

        // "mood history" — dernieres entrees
        if (preg_match('/mood[\s_-]?history|historique\s+humeur|mes\s+humeurs|mood[\s_-]?log/i', $body)) {
            $limit   = preg_match('/\b(\d+)\b/', $body, $m) && (int)$m[1] <= 20 && (int)$m[1] > 1 ? (int)$m[1] : 10;
            $history = $this->generateHistory($context->from, $limit);
            $this->sendText($context->from, $history);
            $this->log($context, "Mood history requested ({$limit})");
            return AgentResult::reply($history);
        }

        // "mood streak" — serie de jours consecutifs
        if (preg_match('/mood[\s_-]?streak|serie\s+humeur|streak\s+humeur/i', $body)) {
            $streakMsg = $this->generateStreakMessage($context->from);
            $this->sendText($context->from, $streakMsg);
            $this->log($context, 'Streak requested');
            return AgentResult::reply($streakMsg);
        }

        // Parse mood from message
        $moodData = $this->parseMood($body, $context);

        // Store in DB with error handling
        try {
            MoodLog::create([
                'user_phone' => $context->from,
                'mood_level' => $moodData['level'],
                'mood_label' => $moodData['label'],
                'notes'      => $body,
            ]);
        } catch (\Exception $e) {
            Log::error("[mood_check] Failed to save mood log: " . $e->getMessage(), [
                'from'  => $context->from,
                'level' => $moodData['level'],
            ]);
        }

        $this->log($context, 'Mood logged', [
            'mood_level' => $moodData['level'],
            'mood_label' => $moodData['label'],
        ]);

        // Gather context for recommendations
        $hour           = (int) Carbon::now(AppSetting::timezone())->format('H');
        $trend          = MoodLog::getDailyTrend($context->from, 7);
        $lowEnergyHours = MoodLog::detectLowEnergyHours($context->from);
        $streak         = MoodLog::getStreak($context->from);
        $goal           = $this->getMoodGoal($context->from);
        $trendSummary   = $this->buildTrendSummary($trend);
        $trendDirection = $this->detectTrendDirection($trend);
        $contextMemory  = $this->formatContextMemoryForPrompt($context->from);

        // Generate empathetic response with Claude
        $response = $this->claude->chat(
            $this->buildAnalysisMessage($moodData, $hour, $trendSummary, $trendDirection, $lowEnergyHours, $contextMemory, $context->senderName, $streak, $goal),
            $this->resolveModel($context),
            $this->buildSystemPrompt()
        );

        if (!$response) {
            $response = $this->buildFallbackResponse($moodData);
        }

        $this->sendText($context->from, $response);

        return AgentResult::reply($response, [
            'mood_level' => $moodData['level'],
            'mood_label' => $moodData['label'],
            'streak'     => $streak,
            'goal'       => $goal,
        ]);
    }

    // ─────────────────────────────────────────────
    // COMMANDE: HELP
    // ─────────────────────────────────────────────

    private function buildHelpMessage(): string
    {
        return "🧠 *Mood Check — Commandes disponibles*\n\n"
            . "📝 *Enregistrer ton humeur:*\n"
            . "  • `mood 3` ou `mood 4/5`\n"
            . "  • `mood 😊` (emoji directement)\n"
            . "  • `je suis fatigue` / `je me sens super`\n\n"
            . "📊 *Consulter tes stats:*\n"
            . "  • `mood today` — resume du jour\n"
            . "  • `mood stats` — tendance 7 jours\n"
            . "  • `mood stats 14` — tendance 14 jours\n"
            . "  • `mood stats 30` — tendance 30 jours\n"
            . "  • `mood history` — 10 dernieres entrees\n"
            . "  • `mood streak` — jours consecutifs\n"
            . "  • `mood weekly` — pattern par jour de semaine\n"
            . "  • `mood compare` — cette semaine vs semaine passee\n\n"
            . "🎯 *Objectif:*\n"
            . "  • `mood goal 4` — definir un objectif (1-5)\n"
            . "  • `mood goal` — consulter ton objectif actuel\n"
            . "  • `mood goal reset` — supprimer l'objectif\n\n"
            . "_Echelle: 1=😢 tres bas | 3=😐 neutre | 5=🤩 excellent_";
    }

    // ─────────────────────────────────────────────
    // COMMANDE: TODAY SUMMARY
    // ─────────────────────────────────────────────

    public function generateTodaySummary(string $userPhone): string
    {
        $tz    = AppSetting::timezone();
        $today = Carbon::now($tz)->startOfDay();

        $logs = MoodLog::where('user_phone', $userPhone)
            ->where('created_at', '>=', $today)
            ->orderBy('created_at')
            ->get();

        $streak = MoodLog::getStreak($userPhone);
        $goal   = $this->getMoodGoal($userPhone);

        if ($logs->isEmpty()) {
            $streakInfo = $streak > 0 ? " 🔥 Serie: {$streak} jour(s)" : '';
            $goalInfo   = $goal ? " | 🎯 Objectif: {$goal}/5" : '';
            return "📋 *Humeur du jour*{$streakInfo}{$goalInfo}\n\n"
                . "Pas encore d'entree aujourd'hui.\n"
                . "Enregistre ton humeur avec `mood [1-5]` ou un emoji !";
        }

        $avg    = round($logs->avg('mood_level'), 1);
        $count  = $logs->count();
        $emoji  = $this->levelToEmoji((int) round($avg));
        $streakLine = $streak > 1 ? " 🔥 Streak: {$streak}j" : '';

        $output = "📋 *Humeur du jour* — {$count} entree(s){$streakLine}\n\n";

        foreach ($logs as $log) {
            $time       = Carbon::parse($log->created_at)->timezone($tz)->format('H:i');
            $levelEmoji = $this->levelToEmoji($log->mood_level);
            $output    .= "{$time} {$levelEmoji} {$log->mood_level}/5 — {$log->mood_label}\n";
        }

        $output .= "\n{$emoji} Moyenne du jour: *{$avg}/5*";

        if ($goal) {
            $diff = $avg - $goal;
            if ($diff >= 0) {
                $output .= " ✅ Objectif {$goal}/5 atteint !";
            } else {
                $remaining = abs(round($diff, 1));
                $output .= " 🎯 Objectif: {$goal}/5 (−{$remaining})";
            }
        }

        return $output;
    }

    // ─────────────────────────────────────────────
    // COMMANDE: HISTORY
    // ─────────────────────────────────────────────

    public function generateHistory(string $userPhone, int $limit = 10): string
    {
        $tz      = AppSetting::timezone();
        $entries = MoodLog::getLastEntries($userPhone, $limit);

        if ($entries->isEmpty()) {
            return "📖 *Historique d'humeur*\n\n"
                . "Aucune entree trouvee.\n"
                . "Commence avec `mood [1-5]` ou un emoji !";
        }

        $output = "📖 *Historique d'humeur* (derniers {$entries->count()})\n\n";

        foreach ($entries as $log) {
            $dt    = Carbon::parse($log->created_at)->timezone($tz);
            $date  = $dt->format('d/m');
            $time  = $dt->format('H:i');
            $emoji = $this->levelToEmoji($log->mood_level);
            $label = $log->mood_label ?? $this->levelToLabel($log->mood_level);
            $output .= "{$date} {$time} {$emoji} *{$log->mood_level}/5* — {$label}\n";
        }

        $output .= "\n_`mood stats` pour voir les tendances_";

        return $output;
    }

    // ─────────────────────────────────────────────
    // COMMANDE: STREAK
    // ─────────────────────────────────────────────

    public function generateStreakMessage(string $userPhone): string
    {
        $streak = MoodLog::getStreak($userPhone);

        if ($streak === 0) {
            return "🔥 *Streak d'humeur*\n\n"
                . "Pas encore de serie en cours.\n"
                . "Enregistre ton humeur aujourd'hui pour commencer !\n\n"
                . "_Conseil: une entree par jour = progression visible_";
        }

        $medal = match (true) {
            $streak >= 30 => '🏆',
            $streak >= 14 => '🥇',
            $streak >= 7  => '🥈',
            $streak >= 3  => '🥉',
            default       => '🔥',
        };

        $message = $streak === 1
            ? "C'est le debut de ta serie, continue demain !"
            : ($streak >= 7
                ? "Impressionnant ! Tu suis ton humeur regulierement."
                : "Belle regularite, keep going !");

        return "🔥 *Streak d'humeur*\n\n"
            . "{$medal} Serie actuelle: *{$streak} jour(s) consecutif(s)*\n\n"
            . "{$message}\n\n"
            . "_`mood today` pour le resume du jour_";
    }

    // ─────────────────────────────────────────────
    // COMMANDE: WEEKLY PATTERN
    // ─────────────────────────────────────────────

    public function generateWeeklyPattern(string $userPhone): string
    {
        $pattern = MoodLog::getWeeklyPattern($userPhone);
        $hasData = collect($pattern)->contains(fn ($d) => $d['count'] > 0);

        if (!$hasData) {
            return "📅 *Pattern hebdomadaire*\n\n"
                . "Pas encore assez de donnees.\n"
                . "Continue d'enregistrer ton humeur chaque jour pour voir ton pattern !";
        }

        $output = "📅 *Pattern hebdomadaire* (4 dernieres semaines)\n\n";

        foreach ($pattern as $day) {
            if ($day['avg_mood'] !== null) {
                $filled = (int) round($day['avg_mood']);
                $bar    = str_repeat('█', $filled) . str_repeat('░', 5 - $filled);
                $emoji  = $day['avg_mood'] >= 4 ? '😊' : ($day['avg_mood'] >= 3 ? '😐' : '😔');
                $output .= "{$day['day']}: {$bar} {$emoji} {$day['avg_mood']}/5 ({$day['count']}x)\n";
            } else {
                $output .= "{$day['day']}: ·····  —\n";
            }
        }

        // Identify best and worst days
        $withData = array_filter($pattern, fn ($d) => $d['avg_mood'] !== null);
        if (count($withData) >= 2) {
            $best  = max(array_column($withData, 'avg_mood'));
            $worst = min(array_column($withData, 'avg_mood'));

            $bestDay  = collect($withData)->firstWhere('avg_mood', $best);
            $worstDay = collect($withData)->firstWhere('avg_mood', $worst);

            $output .= "\n⭐ Meilleur jour: *{$bestDay['day']}* ({$best}/5)";
            if ($worst < $best) {
                $output .= "\n💙 Jour difficile: *{$worstDay['day']}* ({$worst}/5)";
            }
        }

        $output .= "\n\n_`mood compare` pour cette semaine vs semaine passee_";

        return $output;
    }

    // ─────────────────────────────────────────────
    // NOUVELLE COMMANDE: COMPARE SEMAINES
    // ─────────────────────────────────────────────

    public function generateCompare(string $userPhone): string
    {
        $tz = AppSetting::timezone();
        $now = Carbon::now($tz);

        $thisWeekStart = $now->copy()->startOfWeek(Carbon::MONDAY)->startOfDay();
        $lastWeekStart = $thisWeekStart->copy()->subWeek();
        $lastWeekEnd   = $thisWeekStart->copy()->subSecond();

        $thisWeekLogs = MoodLog::where('user_phone', $userPhone)
            ->where('created_at', '>=', $thisWeekStart)
            ->where('created_at', '<=', $now)
            ->get();

        $lastWeekLogs = MoodLog::where('user_phone', $userPhone)
            ->where('created_at', '>=', $lastWeekStart)
            ->where('created_at', '<=', $lastWeekEnd)
            ->get();

        if ($thisWeekLogs->isEmpty() && $lastWeekLogs->isEmpty()) {
            return "📊 *Comparaison semaines*\n\n"
                . "Pas encore assez de donnees.\n"
                . "Enregistre ton humeur chaque jour avec `mood [1-5]` !";
        }

        $thisAvg = $thisWeekLogs->isNotEmpty() ? round($thisWeekLogs->avg('mood_level'), 1) : null;
        $lastAvg = $lastWeekLogs->isNotEmpty() ? round($lastWeekLogs->avg('mood_level'), 1) : null;

        $output = "📊 *Comparaison semaines*\n\n";

        if ($lastAvg !== null) {
            $lastEmoji = $this->levelToEmoji((int) round($lastAvg));
            $output .= "Semaine passee: {$lastEmoji} *{$lastAvg}/5* ({$lastWeekLogs->count()} entree(s))\n";
        } else {
            $output .= "Semaine passee: — (pas de donnees)\n";
        }

        if ($thisAvg !== null) {
            $thisEmoji = $this->levelToEmoji((int) round($thisAvg));
            $output .= "Cette semaine:  {$thisEmoji} *{$thisAvg}/5* ({$thisWeekLogs->count()} entree(s))\n";
        } else {
            $output .= "Cette semaine:  — (pas encore d'entree cette semaine)\n";
        }

        if ($thisAvg !== null && $lastAvg !== null) {
            $diff = round($thisAvg - $lastAvg, 1);
            if ($diff > 0) {
                $output .= "\n📈 +{$diff} vs semaine passee — belle progression !";
            } elseif ($diff < 0) {
                $output .= "\n📉 {$diff} vs semaine passee — garde courage, ca va aller !";
            } else {
                $output .= "\n→ Stable par rapport a la semaine passee.";
            }
        }

        $output .= "\n\n_`mood weekly` pour le pattern | `mood stats 14` pour 2 semaines_";

        return $output;
    }

    // ─────────────────────────────────────────────
    // COMMANDE: MOOD GOAL
    // ─────────────────────────────────────────────

    public function setMoodGoal(string $userPhone, int $level): string
    {
        $level = max(1, min(5, $level));
        AppSetting::set('mood_goal_' . md5($userPhone), (string) $level);

        $emoji = $this->levelToEmoji($level);
        $label = $this->levelToLabel($level);

        return "🎯 *Objectif d'humeur defini*\n\n"
            . "Objectif: {$emoji} *{$level}/5* ({$label})\n\n"
            . "Je comparerai tes entrees journalieres avec cet objectif.\n"
            . "_`mood today` pour voir ta progression | `mood goal` pour consulter | `mood goal reset` pour supprimer_";
    }

    public function showMoodGoal(string $userPhone): string
    {
        $goal = $this->getMoodGoal($userPhone);

        if (!$goal) {
            return "🎯 *Objectif d'humeur*\n\n"
                . "Aucun objectif defini.\n"
                . "Utilise `mood goal [1-5]` pour en definir un !\n\n"
                . "_Ex: `mood goal 4` pour viser une bonne humeur quotidienne_";
        }

        $emoji = $this->levelToEmoji($goal);
        $label = $this->levelToLabel($goal);

        // Show recent performance vs goal
        $trend     = MoodLog::getDailyTrend($userPhone, 7);
        $daysAbove = 0;
        $daysTotal = 0;

        foreach ($trend as $day) {
            if ($day['avg_mood'] !== null) {
                $daysTotal++;
                if ($day['avg_mood'] >= $goal) {
                    $daysAbove++;
                }
            }
        }

        $output = "🎯 *Objectif d'humeur*\n\n"
            . "Objectif actuel: {$emoji} *{$goal}/5* ({$label})\n";

        if ($daysTotal > 0) {
            $output .= "\n📊 Performance 7j: *{$daysAbove}/{$daysTotal}* jours atteints";
            $pct = round($daysAbove / $daysTotal * 100);
            $output .= " ({$pct}%)\n";
        }

        $output .= "\n_`mood goal [1-5]` pour changer | `mood goal reset` pour supprimer | `mood today` pour le detail du jour_";

        return $output;
    }

    public function resetMoodGoal(string $userPhone): string
    {
        AppSetting::where('key', 'mood_goal_' . md5($userPhone))->delete();

        return "🎯 *Objectif d'humeur supprime*\n\n"
            . "Ton objectif a ete reinitialise.\n"
            . "_`mood goal [1-5]` pour en definir un nouveau_";
    }

    private function getMoodGoal(string $userPhone): ?int
    {
        $val = AppSetting::get('mood_goal_' . md5($userPhone));
        return ($val !== null && $val !== '') ? (int) $val : null;
    }

    // ─────────────────────────────────────────────
    // PARSING
    // ─────────────────────────────────────────────

    private function parseMood(string $body, AgentContext $context): array
    {
        // Direct numeric level (1-5) — ex: "mood 3" or "3/5"
        if (preg_match('/(?:^|\s)([1-5])(?:\s*\/\s*5)?\b/', $body, $m)) {
            $level = (int) $m[1];
            return ['level' => $level, 'label' => $this->levelToLabel($level)];
        }

        // Emoji detection — enriched map (32 emojis)
        $emojiMap = [
            '😢' => ['level' => 1, 'label' => 'tres triste'],
            '😭' => ['level' => 1, 'label' => 'en pleurs'],
            '😞' => ['level' => 1, 'label' => 'triste'],
            '😩' => ['level' => 1, 'label' => 'epuise'],
            '😡' => ['level' => 1, 'label' => 'en colere'],
            '😫' => ['level' => 1, 'label' => 'a bout'],
            '😣' => ['level' => 1, 'label' => 'souffrant'],
            '🥺' => ['level' => 1, 'label' => 'vulnerable'],
            '😤' => ['level' => 2, 'label' => 'frustre'],
            '😔' => ['level' => 2, 'label' => 'morose'],
            '😰' => ['level' => 2, 'label' => 'anxieux'],
            '😴' => ['level' => 2, 'label' => 'fatigue'],
            '😟' => ['level' => 2, 'label' => 'preoccupe'],
            '😒' => ['level' => 2, 'label' => 'mecontent'],
            '🤕' => ['level' => 2, 'label' => 'souffrant'],
            '😐' => ['level' => 3, 'label' => 'neutre'],
            '🙂' => ['level' => 3, 'label' => 'ok'],
            '😌' => ['level' => 3, 'label' => 'tranquille'],
            '😑' => ['level' => 3, 'label' => 'indifferent'],
            '😶' => ['level' => 3, 'label' => 'sans expression'],
            '😊' => ['level' => 4, 'label' => 'bien'],
            '🥰' => ['level' => 4, 'label' => 'heureux'],
            '💪' => ['level' => 4, 'label' => 'energique'],
            '🤗' => ['level' => 4, 'label' => 'chaleureux'],
            '😃' => ['level' => 4, 'label' => 'joyeux'],
            '😎' => ['level' => 4, 'label' => 'cool'],
            '😄' => ['level' => 5, 'label' => 'excellent'],
            '😁' => ['level' => 5, 'label' => 'super'],
            '🤩' => ['level' => 5, 'label' => 'euphorique'],
            '🎉' => ['level' => 5, 'label' => 'festif'],
            '🥳' => ['level' => 5, 'label' => 'en fete'],
            '🌟' => ['level' => 5, 'label' => 'rayonnant'],
        ];

        foreach ($emojiMap as $emoji => $data) {
            if (str_contains($body, $emoji)) return $data;
        }

        // Text-based mood keywords — ordered: most specific FIRST to avoid false positives
        $moodKeywords = [
            1 => ['horrible', 'terrible', 'tres mal', 'au plus bas', 'desespere', 'deprime', 'effondre', 'en detresse', 'triste'],
            2 => ['stresse', 'fatigue', 'anxieux', 'epuise', 'morose', 'down', 'bof', 'mal', 'mauvais'],
            3 => ['pas mal', 'neutre', 'ca va', 'ça va', 'normal', 'tranquille', 'ok', 'moyen'],
            4 => ['pas mal du tout', 'content', 'energique', 'motive', 'positif', 'heureux', 'bien', 'good'],
            5 => ['super', 'excellent', 'genial', 'top', 'parfait', 'euphorique', 'incroyable', 'amazing', 'fantastique'],
        ];

        $lower = mb_strtolower($body);

        // First pass: check level 5 (most positive), then 1 (most negative), then 4, 2, 3
        foreach ([5, 1, 4, 2, 3] as $level) {
            foreach ($moodKeywords[$level] as $keyword) {
                if (str_contains($lower, $keyword)) {
                    return ['level' => $level, 'label' => $keyword];
                }
            }
        }

        // Fall back to Claude inference
        $inferred = $this->inferMoodWithClaude($body);
        if ($inferred) return $inferred;

        return ['level' => 3, 'label' => 'non specifie'];
    }

    private function inferMoodWithClaude(string $body): ?array
    {
        $response = $this->claude->chat(
            "Message utilisateur: \"{$body}\"",
            ModelResolver::fast(),
            "Tu analyses le niveau d'humeur d'un message en francais ou anglais.\n"
            . "Echelle: 1=tres negatif/en detresse, 2=bas/difficile, 3=neutre/ok, 4=bien/positif, 5=excellent/euphorique.\n"
            . "Reponds UNIQUEMENT en JSON strict (pas de markdown): {\"level\": X, \"label\": \"mot descriptif court en francais\"}\n"
            . "Si aucune emotion claire n'est exprimee: {\"level\": 3, \"label\": \"neutre\"}\n"
            . "Exemples:\n"
            . "\"j'en peux plus\" -> {\"level\": 1, \"label\": \"epuise\"}\n"
            . "\"rien de special\" -> {\"level\": 3, \"label\": \"neutre\"}\n"
            . "\"super journee!\" -> {\"level\": 5, \"label\": \"enthousiaste\"}\n"
            . "\"j'ai mal dormi\" -> {\"level\": 2, \"label\": \"fatigue\"}\n"
            . "\"ca avance pas mal\" -> {\"level\": 4, \"label\": \"content\"}"
        );

        if (!$response) return null;

        $clean = trim($response);
        if (preg_match('/```(?:json)?\s*(.*?)\s*```/s', $clean, $m)) {
            $clean = $m[1];
        }
        if (!str_starts_with($clean, '{') && preg_match('/(\{.*\})/s', $clean, $m)) {
            $clean = $m[1];
        }

        $parsed = json_decode($clean, true);
        if ($parsed && isset($parsed['level'])) {
            $level = max(1, min(5, (int) $parsed['level']));
            return ['level' => $level, 'label' => $parsed['label'] ?? $this->levelToLabel($level)];
        }

        return null;
    }

    private function levelToLabel(int $level): string
    {
        return match ($level) {
            1 => 'tres bas',
            2 => 'bas',
            3 => 'neutre',
            4 => 'bien',
            5 => 'excellent',
            default => 'neutre',
        };
    }

    private function levelToEmoji(int $level): string
    {
        return match ($level) {
            1 => '😢',
            2 => '😔',
            3 => '😐',
            4 => '😊',
            5 => '🤩',
            default => '😐',
        };
    }

    // ─────────────────────────────────────────────
    // PROMPTS LLM
    // ─────────────────────────────────────────────

    private function buildSystemPrompt(): string
    {
        return <<<'PROMPT'
Tu es un assistant empathique et bienveillant specialise dans le bien-etre emotionnel. Tu reponds via WhatsApp.

ROLE:
- Accueillir l'etat emotionnel avec empathie sincere, sans jugement ni condescendance
- Proposer 2-3 recommandations concretes et actionnables, adaptees a l'heure et au contexte
- Adapter le ton : doux et soutenant si humeur basse, energique et celebratoire si haute
- Mentionner la serie (streak) si >= 3 jours pour encourager la regularite
- Si un objectif est defini, mentionner brievement s'il est atteint ou non

FORMAT STRICT (WhatsApp):
1. Ligne 1 : emoji humeur + phrase empathique courte (max 15 mots)
2. Lignes 2-4 : recommandations avec emoji (une par ligne, max 12 mots chacune)
3. Derniere ligne : phrase d'encouragement courte ou mention du streak/objectif si applicable

REGLES:
- Maximum 150 mots au total
- Jamais condescendant, jamais trop clinique, jamais generique
- Utilise le prenom si disponible
- Si tendance EN BAISSE (↓) : sois plus doux, propose repos, contact social, gratitude
- Si tendance EN HAUSSE (↑) : felicite et encourage a capitaliser sur cet elan
- Si humeur <= 2 : propose respiration profonde, pause, parler a quelqu'un, mouvements doux
- Si humeur >= 4 : encourage a avancer sur des projets/taches importantes, partager la bonne energie
- Si streak >= 3 : mentionne brievement la serie comme motivation
- Si objectif defini et atteint : felicite avec un ✅
- Si objectif defini et non atteint : encourage sans pression
- Reponds TOUJOURS en francais
- Evite les formules creuses comme "c'est normal de se sentir ainsi"
PROMPT;
    }

    private function buildAnalysisMessage(
        array $moodData,
        int $hour,
        string $trendSummary,
        string $trendDirection,
        array $lowEnergyHours,
        string $contextMemory,
        string $senderName,
        int $streak = 0,
        ?int $goal = null
    ): string {
        $timeContext = match (true) {
            $hour >= 5  && $hour < 12 => 'matin',
            $hour >= 12 && $hour < 14 => 'midi',
            $hour >= 14 && $hour < 18 => 'apres-midi',
            $hour >= 18 && $hour < 22 => 'soiree',
            default                   => 'nuit',
        };

        $msg  = "CONTEXTE HUMEUR:\n";
        $msg .= "- Utilisateur: {$senderName}\n";
        $msg .= "- Niveau actuel: {$moodData['level']}/5 ({$moodData['label']})\n";
        $msg .= "- Moment: {$timeContext} ({$hour}h, " . AppSetting::timezone() . ")\n";

        if ($streak > 0) {
            $msg .= "- Streak: {$streak} jour(s) consecutif(s) avec entree\n";
        }

        if ($goal !== null) {
            $status = $moodData['level'] >= $goal ? 'ATTEINT' : 'non atteint';
            $msg .= "- Objectif quotidien: {$goal}/5 ({$status})\n";
        }

        if ($trendSummary) {
            $msg .= "\nTENDANCE 7 JOURS {$trendDirection}:\n{$trendSummary}\n";
        }

        if (!empty($lowEnergyHours)) {
            $hours    = array_keys($lowEnergyHours);
            $hoursStr = implode('h, ', $hours) . 'h';
            $msg .= "\nHEURES BASSE ENERGIE RECURRENTES: {$hoursStr}\n";
        }

        if ($contextMemory) {
            $msg .= "\n{$contextMemory}\n";
        }

        $msg .= "\nGenere une reponse empathique avec 2-3 recommandations concretes adaptees au contexte.";

        return $msg;
    }

    // ─────────────────────────────────────────────
    // STATS
    // ─────────────────────────────────────────────

    public function generateStats(string $userPhone, int $days = 7): string
    {
        $trend         = MoodLog::getDailyTrend($userPhone, $days);
        $weeklyPattern = MoodLog::getWeeklyPattern($userPhone);
        $lowHours      = MoodLog::detectLowEnergyHours($userPhone);
        $streak        = MoodLog::getStreak($userPhone);
        $goal          = $this->getMoodGoal($userPhone);

        $hasData = collect($trend)->contains(fn ($d) => $d['count'] > 0);

        if (!$hasData) {
            return "📊 Pas encore de donnees d'humeur sur {$days} jours.\n"
                . "Utilise `mood [1-5]` ou `mood [emoji]` pour enregistrer !";
        }

        $periodLabel = match ($days) {
            14      => '14 derniers jours',
            30      => '30 derniers jours',
            default => '7 derniers jours',
        };
        $output = "📊 *Stats d'humeur ({$periodLabel})* 📊\n\n";

        // Trend chart — weekly summary for 14/30 days to keep it concise
        if ($days >= 14) {
            $weeklySummary = MoodLog::getWeeklySummary($userPhone, $days);
            $output .= "📈 TENDANCE (par semaine):\n";
            foreach ($weeklySummary as $week) {
                if ($week['avg_mood'] !== null) {
                    $filled  = (int) round($week['avg_mood']);
                    $bar     = str_repeat('█', $filled) . str_repeat('░', 5 - $filled);
                    $output .= "  {$week['from']}-{$week['to']}: {$bar} {$week['avg_mood']}/5 ({$week['count']}x)\n";
                } else {
                    $output .= "  {$week['from']}-{$week['to']}: ·····  —\n";
                }
            }
        } else {
            $output .= "📈 TENDANCE:\n";
            foreach ($trend as $day) {
                $date = Carbon::parse($day['date'])->format('D d/m');
                if ($day['avg_mood'] !== null) {
                    $filled  = (int) round($day['avg_mood']);
                    $bar     = str_repeat('█', $filled) . str_repeat('░', 5 - $filled);
                    $output .= "  {$date}: {$bar} {$day['avg_mood']}/5 ({$day['count']}x)\n";
                } else {
                    $output .= "  {$date}: ·····  —\n";
                }
            }
        }

        // Trend direction
        $direction = $this->detectTrendDirection($trend);
        if ($direction) {
            $output .= "  Tendance: {$direction}\n";
        }

        // Streak
        if ($streak > 0) {
            $output .= "\n🔥 Streak actuel: *{$streak} jour(s)*\n";
        }

        // Goal performance
        if ($goal !== null) {
            $daysAbove = collect($trend)->filter(fn ($d) => $d['avg_mood'] !== null && $d['avg_mood'] >= $goal)->count();
            $daysTotal = collect($trend)->filter(fn ($d) => $d['avg_mood'] !== null)->count();
            if ($daysTotal > 0) {
                $emoji  = $this->levelToEmoji($goal);
                $output .= "\n🎯 Objectif {$goal}/5 {$emoji}: *{$daysAbove}/{$daysTotal}* jours atteints\n";
            }
        }

        // Weekly pattern (seulement pour 7 jours)
        if ($days <= 7) {
            $patternData = array_filter($weeklyPattern, fn ($d) => $d['count'] > 0);
            if (!empty($patternData)) {
                $output .= "\n📅 PATTERN PAR JOUR:\n";
                foreach ($weeklyPattern as $day) {
                    if ($day['count'] > 0) {
                        $emoji   = $day['avg_mood'] >= 4 ? '😊' : ($day['avg_mood'] >= 3 ? '😐' : '😔');
                        $output .= "  {$day['day']}: {$emoji} {$day['avg_mood']}/5\n";
                    }
                }
            }
        }

        // Low energy hours
        if (!empty($lowHours)) {
            $output .= "\n⚠️ HEURES BASSE ENERGIE:\n";
            foreach ($lowHours as $hour => $count) {
                $output .= "  {$hour}h → {$count} occurrence(s)\n";
            }
        }

        // Overall average
        $allMoods = array_filter(array_column($trend, 'avg_mood'));
        if (!empty($allMoods)) {
            $avg   = round(array_sum($allMoods) / count($allMoods), 1);
            $emoji = $avg >= 4 ? '🌟' : ($avg >= 3 ? '👍' : '💙');
            $output .= "\n{$emoji} Moyenne sur {$days}j: *{$avg}/5*";
        }

        $output .= "\n\n💡 _'mood stats 14' | 'mood stats 30' | 'mood weekly' | 'mood compare' | 'mood history'_";

        return $output;
    }

    // ─────────────────────────────────────────────
    // HELPERS
    // ─────────────────────────────────────────────

    private function buildTrendSummary(array $trend): string
    {
        $lines = [];
        foreach ($trend as $day) {
            if ($day['avg_mood'] !== null) {
                $filled = (int) round($day['avg_mood']);
                $bar    = str_repeat('█', $filled) . str_repeat('░', 5 - $filled);
                $date   = Carbon::parse($day['date'])->format('D d/m');
                $lines[] = "{$date}: {$bar} {$day['avg_mood']}/5";
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Detecte la direction de la tendance sur les 3 derniers jours avec donnees.
     */
    private function detectTrendDirection(array $trend): string
    {
        $withData = array_filter($trend, fn ($d) => $d['avg_mood'] !== null);
        $withData = array_values($withData);

        if (count($withData) < 2) return '';

        $recent = array_slice($withData, -3);
        if (count($recent) < 2) return '';

        $first = $recent[0]['avg_mood'];
        $last  = $recent[count($recent) - 1]['avg_mood'];
        $diff  = $last - $first;

        if ($diff >= 0.5) return '↑ En hausse';
        if ($diff <= -0.5) return '↓ En baisse';

        return '→ Stable';
    }

    private function buildFallbackResponse(array $moodData): string
    {
        $emoji = $this->levelToEmoji($moodData['level']);
        $msg   = "{$emoji} Humeur enregistree : {$moodData['level']}/5 ({$moodData['label']}).\n\n";

        if ($moodData['level'] <= 2) {
            $msg .= "Quelques idees pour toi :\n";
            $msg .= "🧘 5 min de respiration profonde\n";
            $msg .= "☕ Une pause avec une boisson chaude\n";
            $msg .= "💬 Parler a quelqu'un de confiance\n\n";
            $msg .= "Tu n'es pas seul(e). 💙";
        } elseif ($moodData['level'] == 3) {
            $msg .= "Pour booster ta journee :\n";
            $msg .= "🚶 10 min de marche a l'air libre\n";
            $msg .= "🎵 Ta playlist preferee !\n\n";
            $msg .= "Ca va aller. 😊";
        } else {
            $msg .= "Super energie ! Profites-en pour :\n";
            $msg .= "🚀 Attaquer une tache importante\n";
            $msg .= "💪 Partager cette energie positive\n\n";
            $msg .= "Continue sur cette lancee ! 🌟";
        }

        return $msg;
    }
}
