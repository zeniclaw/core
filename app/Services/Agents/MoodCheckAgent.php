<?php

namespace App\Services\Agents;

use App\Models\AppSetting;
use App\Models\MoodLog;
use App\Services\AgentContext;
use Illuminate\Support\Carbon;

class MoodCheckAgent extends BaseAgent
{
    public function name(): string
    {
        return 'mood_check';
    }

    public function description(): string
    {
        return 'Agent de suivi d\'humeur et bien-etre. Enregistre le niveau d\'humeur (1-5 ou emoji), detecte les tendances, identifie les heures de baisse d\'energie, fournit des recommandations personnalisees et empathiques. Nouvelles: resume du jour, stats sur 30 jours, indicateur de tendance.';
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
        ];
    }

    public function version(): string
    {
        return '1.1.0';
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
            '/\bhumeur\s+(aujourd\'hui|du\s+jour|stats?)\b/',
            '/\bcomment\s+je\s+me\s+sens\b/',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $body)) return true;
        }

        return false;
    }

    public function handle(AgentContext $context): AgentResult
    {
        $body = trim($context->body ?? '');

        // "mood today" — resume du jour
        if (preg_match('/mood[\s_-]?today|humeur\s+(aujourd\'hui|du\s+jour)/i', $body)) {
            $summary = $this->generateTodaySummary($context->from);
            $this->sendText($context->from, $summary);
            $this->log($context, 'Today mood summary requested');
            return AgentResult::reply($summary);
        }

        // "mood stats [30]" — stats sur 7 ou 30 jours
        if (preg_match('/mood[\s_-]?stats?|stats\s+humeur|statistiques\s+humeur/i', $body)) {
            $days = preg_match('/\b30\b/', $body) ? 30 : 7;
            $stats = $this->generateStats($context->from, $days);
            $this->sendText($context->from, $stats);
            $this->log($context, "Mood stats requested ({$days}j)");
            return AgentResult::reply($stats);
        }

        // Parse mood from message
        $moodData = $this->parseMood($body, $context);

        // Store in DB
        MoodLog::create([
            'user_phone' => $context->from,
            'mood_level' => $moodData['level'],
            'mood_label' => $moodData['label'],
            'notes'      => $body,
        ]);

        $this->log($context, 'Mood logged', [
            'mood_level' => $moodData['level'],
            'mood_label' => $moodData['label'],
        ]);

        // Gather context for recommendations
        $hour          = (int) Carbon::now(AppSetting::timezone())->format('H');
        $trend         = MoodLog::getDailyTrend($context->from, 7);
        $lowEnergyHours = MoodLog::detectLowEnergyHours($context->from);
        $trendSummary  = $this->buildTrendSummary($trend);
        $trendDirection = $this->detectTrendDirection($trend);
        $contextMemory = $this->formatContextMemoryForPrompt($context->from);

        // Generate empathetic response with Claude
        $response = $this->claude->chat(
            $this->buildAnalysisMessage($moodData, $hour, $trendSummary, $trendDirection, $lowEnergyHours, $contextMemory, $context->senderName),
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
        ]);
    }

    // ─────────────────────────────────────────────
    // NOUVELLE FONCTIONNALITE: Resume du jour
    // ─────────────────────────────────────────────

    public function generateTodaySummary(string $userPhone): string
    {
        $tz = AppSetting::timezone();
        $today = Carbon::now($tz)->startOfDay();

        $logs = MoodLog::where('user_phone', $userPhone)
            ->where('created_at', '>=', $today)
            ->orderBy('created_at')
            ->get();

        if ($logs->isEmpty()) {
            return "📋 *Humeur du jour*\n\n"
                . "Pas encore d'entree aujourd'hui.\n"
                . "Enregistre ton humeur avec 'mood [1-5]' ou un emoji !";
        }

        $avg   = round($logs->avg('mood_level'), 1);
        $count = $logs->count();
        $emoji = $this->levelToEmoji((int) round($avg));

        $output = "📋 *Humeur du jour* — {$count} entree(s)\n\n";

        foreach ($logs as $log) {
            $time       = Carbon::parse($log->created_at)->timezone($tz)->format('H:i');
            $levelEmoji = $this->levelToEmoji($log->mood_level);
            $output    .= "{$time} {$levelEmoji} {$log->mood_level}/5 — {$log->mood_label}\n";
        }

        $output .= "\n{$emoji} Moyenne du jour: *{$avg}/5*";

        return $output;
    }

    // ─────────────────────────────────────────────
    // PARSING
    // ─────────────────────────────────────────────

    private function parseMood(string $body, AgentContext $context): array
    {
        // Direct numeric level (1-5)
        if (preg_match('/\b([1-5])\s*\/?\s*5?\b/', $body, $m)) {
            $level = (int) $m[1];
            return ['level' => $level, 'label' => $this->levelToLabel($level)];
        }

        // Emoji detection — enriched map
        $emojiMap = [
            '😢' => ['level' => 1, 'label' => 'tres triste'],
            '😭' => ['level' => 1, 'label' => 'en pleurs'],
            '😞' => ['level' => 1, 'label' => 'triste'],
            '😩' => ['level' => 1, 'label' => 'epuise'],
            '😤' => ['level' => 2, 'label' => 'frustre'],
            '😔' => ['level' => 2, 'label' => 'morose'],
            '😰' => ['level' => 2, 'label' => 'anxieux'],
            '😴' => ['level' => 2, 'label' => 'fatigue'],
            '😐' => ['level' => 3, 'label' => 'neutre'],
            '🙂' => ['level' => 3, 'label' => 'ok'],
            '😌' => ['level' => 3, 'label' => 'tranquille'],
            '😊' => ['level' => 4, 'label' => 'bien'],
            '🥰' => ['level' => 4, 'label' => 'heureux'],
            '😄' => ['level' => 5, 'label' => 'excellent'],
            '😁' => ['level' => 5, 'label' => 'super'],
            '🤩' => ['level' => 5, 'label' => 'euphorique'],
            '🎉' => ['level' => 5, 'label' => 'festif'],
            '😡' => ['level' => 1, 'label' => 'en colere'],
            '💪' => ['level' => 4, 'label' => 'energique'],
        ];

        foreach ($emojiMap as $emoji => $data) {
            if (str_contains($body, $emoji)) return $data;
        }

        // Text-based mood keywords (ordered: most specific first to avoid false positives)
        $moodKeywords = [
            1 => ['horrible', 'terrible', 'tres mal', 'au plus bas', 'desespere', 'deprime', 'effondre'],
            2 => ['stresse', 'fatigue', 'anxieux', 'triste', 'epuise', 'down', 'pas bien', 'moyen', 'bof', 'mal'],
            3 => ['neutre', 'ok', 'ca va', 'ça va', 'normal', 'tranquille', 'pas mal'],
            4 => ['content', 'energique', 'motive', 'positif', 'heureux', 'bien', 'good'],
            5 => ['super', 'excellent', 'genial', 'top', 'parfait', 'euphorique', 'incroyable', 'amazing'],
        ];

        $lower = mb_strtolower($body);
        foreach ($moodKeywords as $level => $keywords) {
            foreach ($keywords as $keyword) {
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
            "Tu analyses le niveau d'humeur d'un message.\n"
            . "Echelle: 1=tres negatif/en detresse, 2=bas/difficile, 3=neutre/ok, 4=bien/positif, 5=excellent/euphorique.\n"
            . "Reponds UNIQUEMENT en JSON strict (pas de markdown): {\"level\": X, \"label\": \"mot descriptif court en francais\"}\n"
            . "Si aucune emotion claire n'est exprimee: {\"level\": 3, \"label\": \"neutre\"}\n"
            . "Exemples:\n"
            . "\"j'en peux plus\" -> {\"level\": 1, \"label\": \"epuise\"}\n"
            . "\"rien de special\" -> {\"level\": 3, \"label\": \"neutre\"}\n"
            . "\"super journee!\" -> {\"level\": 5, \"label\": \"enthousiaste\"}"
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
- Accueillir l'etat emotionnel avec empathie sincere et non condescendante
- Proposer 2-3 recommandations concretes, adaptees a l'heure et au contexte
- Adapter le ton : doux et soutenant si humeur basse, energique si haute

FORMAT STRICT (WhatsApp):
1. Ligne 1 : emoji humeur + phrase empathique courte (max 15 mots)
2. Lignes 2-4 : recommandations avec emoji (une par ligne, max 12 mots chacune)
3. Derniere ligne : phrase d'encouragement tres courte

REGLES:
- Maximum 150 mots au total
- Jamais condescendant ni trop clinique
- Utilise le prenom si disponible
- Si tendance EN BAISSE (↓) : sois plus doux, propose repos/contact social
- Si tendance EN HAUSSE (↑) : felicite et encourage a capitaliser
- Si humeur <= 2 : propose respiration, pause, parler a quelqu'un
- Si humeur >= 4 : encourage a avancer sur des projets/taches importantes
- Reponds TOUJOURS en francais
PROMPT;
    }

    private function buildAnalysisMessage(
        array $moodData,
        int $hour,
        string $trendSummary,
        string $trendDirection,
        array $lowEnergyHours,
        string $contextMemory,
        string $senderName
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

        if ($trendSummary) {
            $msg .= "\nTENDANCE 7 JOURS {$trendDirection}:\n{$trendSummary}\n";
        }

        if (!empty($lowEnergyHours)) {
            $hours = array_keys($lowEnergyHours);
            $hoursStr = implode('h, ', $hours) . 'h';
            $msg .= "\nHEURES BASSE ENERGIE RECURRENTES: {$hoursStr}\n";
        }

        if ($contextMemory) {
            $msg .= "\n{$contextMemory}\n";
        }

        $msg .= "\nGenere une reponse empathique avec 2-3 recommandations adaptees.";

        return $msg;
    }

    // ─────────────────────────────────────────────
    // STATS
    // ─────────────────────────────────────────────

    public function generateStats(string $userPhone, int $days = 7): string
    {
        $trend = MoodLog::getDailyTrend($userPhone, $days);
        $weeklyPattern = MoodLog::getWeeklyPattern($userPhone);
        $lowHours = MoodLog::detectLowEnergyHours($userPhone);

        $hasData = collect($trend)->contains(fn ($d) => $d['count'] > 0);

        if (!$hasData) {
            return "📊 Pas encore de donnees d'humeur sur {$days} jours.\n"
                . "Utilise 'mood [1-5]' ou 'mood [emoji]' pour enregistrer !";
        }

        $periodLabel = $days === 30 ? '30 derniers jours' : '7 derniers jours';
        $output = "📊 *Stats d'humeur ({$periodLabel})* 📊\n\n";

        // Trend chart
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

        // Trend direction
        $direction = $this->detectTrendDirection($trend);
        if ($direction) {
            $output .= "  Tendance: {$direction}\n";
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

        $output .= "\n\n💡 _'mood stats 30' pour voir 30 jours_";

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

        $msg = "{$emoji} Humeur enregistree : {$moodData['level']}/5 ({$moodData['label']}).\n\n";

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
