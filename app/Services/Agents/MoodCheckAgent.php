<?php

namespace App\Services\Agents;

use App\Models\AppSetting;
use App\Models\MoodLog;
use App\Services\AgentContext;
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
        return 'Agent de suivi d\'humeur et bien-etre. Enregistre le niveau d\'humeur (1-5 ou emoji), detecte les tendances, identifie les heures de baisse d\'energie, fournit des recommandations personnalisees et empathiques. Commandes: mood today, mood stats [30], mood history, mood streak, mood help.';
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
        ];
    }

    public function version(): string
    {
        return '1.2.0';
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
            '/\bhumeur\s+(aujourd\'hui|du\s+jour|stats?|historique|streak)\b/',
            '/\bcomment\s+je\s+me\s+sens\b/',
            '/\bmood[\s_-]?history\b/',
            '/\bhistorique\s+humeur\b/',
            '/\bmood[\s_-]?streak\b/',
            '/\bserie\s+humeur\b/',
            '/\bmood[\s_-]?help\b/',
            '/\baide\s+humeur\b/',
            '/\bmes\s+humeurs\b/',
            '/\bmood\s+[1-5]\b/',
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

        // "mood today" — resume du jour
        if (preg_match('/mood[\s_-]?today|humeur\s+(aujourd\'hui|du\s+jour)/i', $body)) {
            $summary = $this->generateTodaySummary($context->from);
            $this->sendText($context->from, $summary);
            $this->log($context, 'Today mood summary requested');
            return AgentResult::reply($summary);
        }

        // "mood stats [30]" — stats sur 7 ou 30 jours
        if (preg_match('/mood[\s_-]?stats?|stats\s+humeur|statistiques\s+humeur/i', $body)) {
            $days  = preg_match('/\b30\b/', $body) ? 30 : 7;
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
        $trendSummary   = $this->buildTrendSummary($trend);
        $trendDirection = $this->detectTrendDirection($trend);
        $contextMemory  = $this->formatContextMemoryForPrompt($context->from);

        // Generate empathetic response with Claude
        $response = $this->claude->chat(
            $this->buildAnalysisMessage($moodData, $hour, $trendSummary, $trendDirection, $lowEnergyHours, $contextMemory, $context->senderName, $streak),
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
            . "  • `mood stats 30` — tendance 30 jours\n"
            . "  • `mood history` — 10 dernieres entrees\n"
            . "  • `mood streak` — jours consecutifs\n\n"
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

        if ($logs->isEmpty()) {
            $streakInfo = $streak > 0 ? " 🔥 Serie: {$streak} jour(s)" : '';
            return "📋 *Humeur du jour*{$streakInfo}\n\n"
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

        return $output;
    }

    // ─────────────────────────────────────────────
    // NOUVELLE COMMANDE: HISTORY
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
    // NOUVELLE COMMANDE: STREAK
    // ─────────────────────────────────────────────

    public function generateStreakMessage(string $userPhone): string
    {
        $streak = MoodLog::getStreak($userPhone);

        if ($streak === 0) {
            return "🔥 *Streak d'humeur*\n\n"
                . "Pas encore de serie en cours.\n"
                . "Enregistre ton humeur aujourd'hui pour commencer !\n\n"
                . "_Conseil: una entree par jour = progression visible_";
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
    // PARSING
    // ─────────────────────────────────────────────

    private function parseMood(string $body, AgentContext $context): array
    {
        // Direct numeric level (1-5) — ex: "mood 3" or "3/5"
        if (preg_match('/(?:^|\s)([1-5])(?:\s*\/\s*5)?\b/', $body, $m)) {
            $level = (int) $m[1];
            return ['level' => $level, 'label' => $this->levelToLabel($level)];
        }

        // Emoji detection — enriched map
        $emojiMap = [
            '😢' => ['level' => 1, 'label' => 'tres triste'],
            '😭' => ['level' => 1, 'label' => 'en pleurs'],
            '😞' => ['level' => 1, 'label' => 'triste'],
            '😩' => ['level' => 1, 'label' => 'epuise'],
            '😡' => ['level' => 1, 'label' => 'en colere'],
            '😤' => ['level' => 2, 'label' => 'frustre'],
            '😔' => ['level' => 2, 'label' => 'morose'],
            '😰' => ['level' => 2, 'label' => 'anxieux'],
            '😴' => ['level' => 2, 'label' => 'fatigue'],
            '😐' => ['level' => 3, 'label' => 'neutre'],
            '🙂' => ['level' => 3, 'label' => 'ok'],
            '😌' => ['level' => 3, 'label' => 'tranquille'],
            '😊' => ['level' => 4, 'label' => 'bien'],
            '🥰' => ['level' => 4, 'label' => 'heureux'],
            '💪' => ['level' => 4, 'label' => 'energique'],
            '😄' => ['level' => 5, 'label' => 'excellent'],
            '😁' => ['level' => 5, 'label' => 'super'],
            '🤩' => ['level' => 5, 'label' => 'euphorique'],
            '🎉' => ['level' => 5, 'label' => 'festif'],
        ];

        foreach ($emojiMap as $emoji => $data) {
            if (str_contains($body, $emoji)) return $data;
        }

        // Text-based mood keywords — ordered: most specific FIRST to avoid false positives
        // (e.g. "pas mal" must come before "mal", "pas bien" before "bien")
        $moodKeywords = [
            1 => ['horrible', 'terrible', 'tres mal', 'au plus bas', 'desespere', 'deprime', 'effondre', 'en detresse'],
            2 => ['stresse', 'fatigue', 'anxieux', 'epuise', 'morose', 'down', 'bof', 'mal', 'mauvais'],
            3 => ['pas mal', 'neutre', 'ca va', 'ça va', 'normal', 'tranquille', 'ok', 'moyen', 'triste'],
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
            'claude-haiku-4-5-20251001',
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

FORMAT STRICT (WhatsApp):
1. Ligne 1 : emoji humeur + phrase empathique courte (max 15 mots)
2. Lignes 2-4 : recommandations avec emoji (une par ligne, max 12 mots chacune)
3. Derniere ligne : phrase d'encouragement courte ou mention du streak si applicable

REGLES:
- Maximum 150 mots au total
- Jamais condescendant, jamais trop clinique, jamais generique
- Utilise le prenom si disponible
- Si tendance EN BAISSE (↓) : sois plus doux, propose repos, contact social, gratitude
- Si tendance EN HAUSSE (↑) : felicite et encourage a capitaliser sur cet elan
- Si humeur <= 2 : propose respiration profonde, pause, parler a quelqu'un, mouvements doux
- Si humeur >= 4 : encourage a avancer sur des projets/taches importantes, partager la bonne energie
- Si streak >= 3 : mentionne brievement la serie comme motivation
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
        int $streak = 0
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
        $trend        = MoodLog::getDailyTrend($userPhone, $days);
        $weeklyPattern = MoodLog::getWeeklyPattern($userPhone);
        $lowHours     = MoodLog::detectLowEnergyHours($userPhone);
        $streak       = MoodLog::getStreak($userPhone);

        $hasData = collect($trend)->contains(fn ($d) => $d['count'] > 0);

        if (!$hasData) {
            return "📊 Pas encore de donnees d'humeur sur {$days} jours.\n"
                . "Utilise `mood [1-5]` ou `mood [emoji]` pour enregistrer !";
        }

        $periodLabel = $days === 30 ? '30 derniers jours' : '7 derniers jours';
        $output = "📊 *Stats d'humeur ({$periodLabel})* 📊\n\n";

        // Trend chart — weekly summary for 30 days to keep it concise
        if ($days >= 30) {
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

        $output .= "\n\n💡 _'mood stats 30' pour 30 jours | 'mood history' pour le detail_";

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
            $msg .= "Ça va aller. 😊";
        } else {
            $msg .= "Super energie ! Profites-en pour :\n";
            $msg .= "🚀 Attaquer une tache importante\n";
            $msg .= "💪 Partager cette energie positive\n\n";
            $msg .= "Continue sur cette lancee ! 🌟";
        }

        return $msg;
    }
}
