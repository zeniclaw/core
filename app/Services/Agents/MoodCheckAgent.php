<?php

namespace App\Services\Agents;

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
        return 'Agent de suivi d\'humeur et bien-etre. Enregistre le niveau d\'humeur (1-5 ou emoji), detecte les tendances, identifie les heures de baisse d\'energie, et fournit des recommandations personnalisees et empathiques.';
    }

    public function keywords(): array
    {
        return [
            'mood', 'mood check', 'humeur', 'mon humeur', 'my mood',
            'comment je me sens', 'how am i doing', 'how do i feel',
            'comment ca va', 'comment tu te sens', 'ca va pas',
            'je me sens', 'je suis', 'i feel', 'i am feeling',
            'bien', 'mal', 'triste', 'sad', 'happy', 'heureux',
            'stresse', 'stressed', 'fatigue', 'tired', 'epuise',
            'energique', 'motive', 'deprime', 'depressed', 'anxieux', 'anxious',
            'super', 'genial', 'excellent', 'horrible', 'terrible',
            'bof', 'moyen', 'pas bien', 'au plus bas', 'down',
            'mood stats', 'stats humeur', 'statistiques humeur',
            'tendance humeur', 'mood trend',
            'bien-etre', 'wellness', 'mental health', 'sante mentale',
        ];
    }

    public function version(): string
    {
        return '1.0.0';
    }

    public function canHandle(AgentContext $context): bool
    {
        if (!$context->body) return false;

        $body = mb_strtolower(trim($context->body));

        // Mood check triggers
        $patterns = [
            '/\bmood\b/', '/\bmood[\s_-]?check\b/', '/\bhow\s+am\s+i\s+doing\b/',
            '/\bcomment\s+(tu\s+te\s+sens|ca\s+va|ça\s+va)\b/',
            '/\bje\s+(me\s+sens|suis)\s+(bien|mal|triste|stresse|fatigue|energique|heureux|deprime|anxieux)/i',
            '/\bmood[\s_-]?stats?\b/',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $body)) return true;
        }

        return false;
    }

    public function handle(AgentContext $context): AgentResult
    {
        $body = trim($context->body ?? '');

        // Handle /mood_stats command
        if (preg_match('/mood[\s_-]?stats?/i', $body)) {
            $stats = $this->generateStats($context->from);
            $this->sendText($context->from, $stats);
            $this->log($context, 'Mood stats requested');
            return AgentResult::reply($stats);
        }

        // Parse mood from message
        $moodData = $this->parseMood($body, $context);

        // Store in DB
        $moodLog = MoodLog::create([
            'user_phone' => $context->from,
            'mood_level' => $moodData['level'],
            'mood_label' => $moodData['label'],
            'notes' => $body,
        ]);

        $this->log($context, 'Mood logged', [
            'mood_level' => $moodData['level'],
            'mood_label' => $moodData['label'],
        ]);

        // Get context for recommendations
        $hour = (int) Carbon::now('Europe/Paris')->format('H');
        $trend = MoodLog::getDailyTrend($context->from, 7);
        $lowEnergyHours = MoodLog::detectLowEnergyHours($context->from);

        // Build recommendation context
        $trendSummary = $this->buildTrendSummary($trend);
        $contextMemory = $this->formatContextMemoryForPrompt($context->from);

        // Generate empathetic response with Claude
        $response = $this->claude->chat(
            $this->buildAnalysisMessage($moodData, $hour, $trendSummary, $lowEnergyHours, $contextMemory, $context->senderName),
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

    private function parseMood(string $body, AgentContext $context): array
    {
        // Direct numeric level (1-5)
        if (preg_match('/\b([1-5])\s*\/?\s*5?\b/', $body, $m)) {
            $level = (int) $m[1];
            return ['level' => $level, 'label' => $this->levelToLabel($level)];
        }

        // Emoji detection
        $emojiMap = [
            '😢' => ['level' => 1, 'label' => 'tres triste'],
            '😞' => ['level' => 1, 'label' => 'triste'],
            '😔' => ['level' => 2, 'label' => 'morose'],
            '😐' => ['level' => 3, 'label' => 'neutre'],
            '🙂' => ['level' => 3, 'label' => 'ok'],
            '😊' => ['level' => 4, 'label' => 'bien'],
            '😄' => ['level' => 5, 'label' => 'excellent'],
            '😁' => ['level' => 5, 'label' => 'super'],
            '🤩' => ['level' => 5, 'label' => 'euphorique'],
            '😡' => ['level' => 1, 'label' => 'en colere'],
            '😰' => ['level' => 2, 'label' => 'anxieux'],
            '😴' => ['level' => 2, 'label' => 'fatigue'],
            '💪' => ['level' => 4, 'label' => 'energique'],
        ];

        foreach ($emojiMap as $emoji => $data) {
            if (str_contains($body, $emoji)) return $data;
        }

        // Text-based mood keywords
        $moodKeywords = [
            1 => ['horrible', 'terrible', 'tres mal', 'au plus bas', 'deprime', 'desespere'],
            2 => ['mal', 'stresse', 'fatigue', 'anxieux', 'triste', 'epuise', 'down', 'pas bien', 'moyen'],
            3 => ['neutre', 'ok', 'bof', 'ca va', 'ça va', 'normal', 'tranquille', 'pas mal'],
            4 => ['bien', 'content', 'energique', 'motive', 'positif', 'heureux', 'good'],
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

        // Use Claude to infer mood if no explicit indicator
        $inferred = $this->inferMoodWithClaude($body);
        if ($inferred) return $inferred;

        // Default: neutral
        return ['level' => 3, 'label' => 'non specifie'];
    }

    private function inferMoodWithClaude(string $body): ?array
    {
        $response = $this->claude->chat(
            "Message: \"{$body}\"",
            'claude-haiku-4-5-20251001',
            "Analyse le message et determine le niveau d'humeur de 1 a 5 (1=tres mal, 5=excellent).\n"
            . "Reponds UNIQUEMENT en JSON: {\"level\": X, \"label\": \"mot descriptif\"}\n"
            . "Si le message ne contient pas d'indication emotionnelle claire, reponds: {\"level\": 3, \"label\": \"neutre\"}"
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
        };
    }

    private function buildSystemPrompt(): string
    {
        return <<<'PROMPT'
Tu es un assistant empathique et bienveillant specialise dans le bien-etre emotionnel.

ROLE:
- Accueillir l'etat emotionnel de l'utilisateur avec empathie
- Fournir 2-3 recommandations personnalisees et actionnables
- Adapter ton ton au niveau d'humeur (doux si bas, encourageant si haut)

FORMAT DE REPONSE:
- Commence par un emoji reflétant l'humeur + une phrase empathique
- Puis 2-3 recommandations concretes avec emoji
- Termine par une phrase d'encouragement courte

REGLES:
- Sois concis (max 200 mots)
- Ne sois pas condescendant ni trop clinique
- Propose des actions simples et immediates
- Si l'humeur est basse, suggere des pauses, exercices de respiration, contact social
- Si l'humeur est haute, encourage a capitaliser sur l'energie positive
- Utilise le contexte (heure, tendance, profil) pour personnaliser
- Reponds en francais
PROMPT;
    }

    private function buildAnalysisMessage(array $moodData, int $hour, string $trendSummary, array $lowEnergyHours, string $contextMemory, string $senderName): string
    {
        $msg = "L'utilisateur {$senderName} rapporte son humeur:\n";
        $msg .= "- Niveau: {$moodData['level']}/5 ({$moodData['label']})\n";
        $msg .= "- Heure actuelle: {$hour}h (Europe/Paris)\n";

        if ($trendSummary) {
            $msg .= "\nTENDANCE RECENTE:\n{$trendSummary}\n";
        }

        if (!empty($lowEnergyHours)) {
            $hours = array_keys($lowEnergyHours);
            $msg .= "\nHEURES DE BAISSE D'ENERGIE RECURRENTES: " . implode('h, ', $hours) . "h\n";
        }

        if ($contextMemory) {
            $msg .= "\n{$contextMemory}\n";
        }

        $msg .= "\nGenere une reponse empathique avec 2-3 recommandations personnalisees.";

        return $msg;
    }

    private function buildTrendSummary(array $trend): string
    {
        $lines = [];
        foreach ($trend as $day) {
            if ($day['avg_mood'] !== null) {
                $bar = str_repeat('█', (int) round($day['avg_mood'])) . str_repeat('░', 5 - (int) round($day['avg_mood']));
                $date = Carbon::parse($day['date'])->format('D d/m');
                $lines[] = "{$date}: {$bar} {$day['avg_mood']}/5 ({$day['count']} entree(s))";
            }
        }

        return implode("\n", $lines);
    }

    private function buildFallbackResponse(array $moodData): string
    {
        $emoji = match (true) {
            $moodData['level'] <= 1 => '💙',
            $moodData['level'] == 2 => '🫂',
            $moodData['level'] == 3 => '😊',
            $moodData['level'] == 4 => '✨',
            default => '🎉',
        };

        $msg = "{$emoji} Merci d'avoir partage ton humeur ({$moodData['level']}/5 - {$moodData['label']}).\n\n";

        if ($moodData['level'] <= 2) {
            $msg .= "Quelques suggestions :\n";
            $msg .= "🧘 Prends 5 minutes pour respirer profondement\n";
            $msg .= "☕ Une pause avec une boisson chaude peut aider\n";
            $msg .= "💬 N'hesite pas a parler a quelqu'un de confiance\n";
        } elseif ($moodData['level'] == 3) {
            $msg .= "Quelques idees pour booster ta journee :\n";
            $msg .= "🚶 Une petite marche de 10 minutes ?\n";
            $msg .= "🎵 Mets ta musique preferee !\n";
        } else {
            $msg .= "Super energie ! Profites-en pour :\n";
            $msg .= "🚀 Attaquer cette tache que tu repoussais\n";
            $msg .= "💪 Capitaliser sur ce momentum positif\n";
        }

        return $msg;
    }

    public function generateStats(string $userPhone): string
    {
        $trend = MoodLog::getDailyTrend($userPhone, 7);
        $weeklyPattern = MoodLog::getWeeklyPattern($userPhone);
        $lowHours = MoodLog::detectLowEnergyHours($userPhone);

        $hasData = false;
        foreach ($trend as $day) {
            if ($day['count'] > 0) {
                $hasData = true;
                break;
            }
        }

        if (!$hasData) {
            return "📊 Pas encore de donnees d'humeur cette semaine.\n"
                . "Utilise 'mood [1-5]' ou 'mood [emoji]' pour enregistrer ton humeur !";
        }

        $output = "📊 *Tes stats d'humeur (7 derniers jours)* 📊\n\n";

        // Weekly trend chart
        $output .= "📈 TENDANCE:\n";
        foreach ($trend as $day) {
            $date = Carbon::parse($day['date'])->format('D d/m');
            if ($day['avg_mood'] !== null) {
                $bar = str_repeat('█', (int) round($day['avg_mood'])) . str_repeat('░', 5 - (int) round($day['avg_mood']));
                $output .= "  {$date}: {$bar} {$day['avg_mood']}/5\n";
            } else {
                $output .= "  {$date}: ····· -\n";
            }
        }

        // Weekly pattern
        $patternData = array_filter($weeklyPattern, fn ($d) => $d['count'] > 0);
        if (!empty($patternData)) {
            $output .= "\n📅 PATTERN HEBDO:\n";
            foreach ($weeklyPattern as $day) {
                if ($day['count'] > 0) {
                    $emoji = $day['avg_mood'] >= 4 ? '😊' : ($day['avg_mood'] >= 3 ? '😐' : '😔');
                    $output .= "  {$day['day']}: {$emoji} {$day['avg_mood']}/5\n";
                }
            }
        }

        // Low energy hours
        if (!empty($lowHours)) {
            $output .= "\n⚠️ HEURES BASSE ENERGIE:\n";
            foreach ($lowHours as $hour => $count) {
                $output .= "  {$hour}h → {$count} occurence(s) basse humeur\n";
            }
        }

        // Overall average
        $allMoods = array_filter(array_column($trend, 'avg_mood'));
        if (!empty($allMoods)) {
            $avg = round(array_sum($allMoods) / count($allMoods), 1);
            $emoji = $avg >= 4 ? '🌟' : ($avg >= 3 ? '👍' : '💙');
            $output .= "\n{$emoji} Moyenne globale: {$avg}/5";
        }

        return $output;
    }
}
