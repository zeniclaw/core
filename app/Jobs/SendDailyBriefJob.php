<?php

namespace App\Jobs;

use App\Models\UserBriefPreference;
use App\Services\Agents\DailyBriefAgent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SendDailyBriefJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private string $wahaBase = 'http://waha:3000';
    private string $wahaApiKey = 'zeniclaw-waha-2026';

    public function handle(): void
    {
        $currentTime = now()->format('H:i');

        $users = UserBriefPreference::where('enabled', true)
            ->where('brief_time', $currentTime)
            ->get();

        if ($users->isEmpty()) {
            return;
        }

        $agent = new DailyBriefAgent();

        foreach ($users as $pref) {
            try {
                $message = $agent->generateBriefForPhone($pref->user_phone);
                $this->sendWhatsApp($pref->user_phone, $message);

                Log::info("[SendDailyBrief] Brief sent to {$pref->user_phone}");
            } catch (\Throwable $e) {
                Log::error("[SendDailyBrief] Failed for {$pref->user_phone}: " . $e->getMessage());
            }
        }
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
            Log::warning("[SendDailyBrief] WhatsApp send failed for {$chatId}: " . $e->getMessage());
        }
    }
}
