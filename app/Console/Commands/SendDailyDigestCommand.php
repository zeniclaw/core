<?php

namespace App\Console\Commands;

use App\Models\ContentDigestLog;
use App\Models\UserContentPreference;
use App\Services\ContentCurator\ContentAggregator;
use App\Services\ContentCurator\ContentSummarizer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SendDailyDigestCommand extends Command
{
    protected $signature = 'content:daily-digest';
    protected $description = 'Generate and send daily content digests to subscribed users via WhatsApp';

    private string $wahaBase = 'http://waha:3000';
    private string $wahaApiKey = 'zeniclaw-waha-2026';

    public function handle(): void
    {
        $this->info('Starting daily digest generation...');

        // Get all users with content preferences
        $userPhones = UserContentPreference::distinct()->pluck('user_phone');

        if ($userPhones->isEmpty()) {
            $this->info('No users with content preferences found.');
            return;
        }

        $aggregator = new ContentAggregator();
        $summarizer = new ContentSummarizer();

        foreach ($userPhones as $phone) {
            try {
                // Check if digest was already sent today
                $alreadySent = ContentDigestLog::where('user_phone', $phone)
                    ->where('sent_at', '>=', now()->startOfDay())
                    ->exists();

                if ($alreadySent) {
                    $this->info("Digest already sent today for {$phone}, skipping.");
                    continue;
                }

                // Get user preferences
                $prefs = UserContentPreference::where('user_phone', $phone)->get();
                $categories = $prefs->pluck('category')->filter(fn($c) => $c !== 'custom')->toArray();
                $keywords = $prefs->where('category', 'custom')->pluck('keywords')->flatten()->filter()->toArray();

                if (empty($categories)) {
                    $categories = ['technology'];
                }

                // Aggregate articles
                $articles = $aggregator->aggregate($categories, $keywords, 8);

                if (empty($articles)) {
                    $this->info("No articles found for {$phone}.");
                    continue;
                }

                // Summarize
                $summaries = $summarizer->summarizeBatch($articles, 6);

                // Build message
                $message = "☀️ *DIGEST QUOTIDIEN*\n";
                $message .= "_" . now()->format('d/m/Y') . "_\n\n";

                foreach ($summaries as $i => $article) {
                    $num = $i + 1;
                    $title = $article['title'] ?? 'Sans titre';
                    $summary = $article['summary'] ?? '';
                    $source = $article['source'] ?? '';
                    $url = $article['url'] ?? '';

                    $message .= "*{$num}. {$title}*";
                    if ($source) $message .= " _{$source}_";
                    $message .= "\n{$summary}\n";
                    if ($url) $message .= "🔗 {$url}\n";
                    $message .= "\n";
                }

                $message .= "---\n";
                $message .= "_Dis *digest* pour un refresh, *trending* pour les tendances_";

                // Send via WhatsApp
                $this->sendWhatsApp($phone, $message);

                // Log
                ContentDigestLog::create([
                    'user_phone' => $phone,
                    'categories' => $categories,
                    'article_count' => count($summaries),
                    'sent_at' => now(),
                ]);

                $this->info("Digest sent to {$phone}: " . count($summaries) . " articles");
            } catch (\Throwable $e) {
                Log::error("[SendDailyDigest] Failed for {$phone}: " . $e->getMessage());
                $this->error("Failed for {$phone}: " . $e->getMessage());
            }
        }

        $this->info('Daily digest generation completed.');
    }

    private function sendWhatsApp(string $chatId, string $text): void
    {
        try {
            Http::timeout(10)
                ->withHeaders(['X-Api-Key' => $this->wahaApiKey])
                ->post("{$this->wahaBase}/api/sendText", [
                    'chatId' => $chatId,
                    'text' => $text,
                    'session' => 'default',
                ]);
        } catch (\Throwable $e) {
            Log::warning("Failed to send digest to {$chatId}: " . $e->getMessage());
        }
    }
}
