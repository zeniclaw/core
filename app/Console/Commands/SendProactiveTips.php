<?php

namespace App\Console\Commands;

use App\Models\AgentSession;
use App\Models\UserAgentAnalytic;
use App\Services\LLMClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SendProactiveTips extends Command
{
    protected $signature = 'assistant:send-tips';
    protected $description = 'Send weekly proactive tips to active users based on their usage patterns';

    private string $wahaBase = 'http://waha:3000';
    private string $wahaApiKey = 'zeniclaw-waha-2026';

    public function handle(): int
    {
        $this->info('Sending proactive tips...');

        // Get active users from the last 7 days
        $activeUsers = AgentSession::where('last_message_at', '>=', now()->subDays(7))
            ->pluck('phone')
            ->unique();

        $claude = new LLMClient();
        $sentCount = 0;

        foreach ($activeUsers as $phone) {
            try {
                $stats = UserAgentAnalytic::getUserStats($phone, 7);

                if ($stats['total_interactions'] < 3) {
                    continue; // Skip very inactive users
                }

                $tip = $this->generatePersonalizedTip($claude, $phone, $stats);

                if ($tip) {
                    $this->sendWhatsApp($phone, $tip);
                    $sentCount++;
                    $this->line("  Sent tip to {$phone}");
                }
            } catch (\Throwable $e) {
                Log::warning("SendProactiveTips failed for {$phone}: " . $e->getMessage());
                $this->warn("  Failed for {$phone}: " . $e->getMessage());
            }
        }

        $this->info("Sent {$sentCount} proactive tips.");

        return self::SUCCESS;
    }

    private function generatePersonalizedTip(LLMClient $claude, string $phone, array $stats): ?string
    {
        $usedAgents = array_keys($stats['agents_used']);
        $mostUsed = $stats['most_used'] ?? 'chat';

        // All available agents
        $allAgents = [
            'todo', 'reminder', 'project', 'dev', 'finance', 'habit',
            'pomodoro', 'flashcard', 'music', 'mood_check', 'content_summarizer',
            'event_reminder', 'code_review', 'smart_meeting', 'document',
            'analysis', 'budget_tracker', 'daily_brief', 'recipe',
            'time_blocker', 'web_search', 'hangman', 'interactive_quiz',
            'game_master', 'content_curator',
        ];

        $unusedAgents = array_diff($allAgents, $usedAgents);
        $unusedStr = implode(', ', array_slice($unusedAgents, 0, 5));

        $systemPrompt = <<<PROMPT
Tu es un coach IA bienveillant. Genere un tip hebdomadaire personnalise en francais pour WhatsApp.

L'utilisateur utilise surtout: {$mostUsed}
Agents non decouverts: {$unusedStr}
Score d'adoption: {$stats['adoption_score']}%

Regles:
- Max 5 lignes
- 1 seul tip concret et actionnable
- Mentionne un agent non utilise qui complementerait son usage actuel
- Commence par un emoji contextuel
- Ton amical mais pas condescendant
- Pas de markdown complexe, juste *gras*
PROMPT;

        $response = $claude->chat(
            "Generate weekly tip for user with stats: " . json_encode($stats),
            'claude-haiku-4-5-20251001',
            $systemPrompt
        );

        return $response;
    }

    private function sendWhatsApp(string $phone, string $text): void
    {
        if (str_starts_with($phone, 'web-')) {
            return;
        }

        try {
            Http::timeout(10)
                ->withHeaders(['X-Api-Key' => $this->wahaApiKey])
                ->post("{$this->wahaBase}/api/sendText", [
                    'chatId' => $phone,
                    'text' => $text,
                    'session' => 'default',
                ]);
        } catch (\Throwable $e) {
            Log::warning("SendProactiveTips: WhatsApp send failed for {$phone}: " . $e->getMessage());
        }
    }
}
