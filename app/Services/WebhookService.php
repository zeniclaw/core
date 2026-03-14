<?php

namespace App\Services;

use App\Models\AppSetting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Webhook service (D12.5) — allows external services to trigger agents.
 * Sends event notifications to configured webhook URLs.
 */
class WebhookService
{
    /**
     * Fire a webhook event to all configured endpoints.
     */
    public static function fire(string $event, array $data): void
    {
        $webhooks = AppSetting::get('webhook_urls');
        if (!$webhooks) return;

        $urls = is_array($webhooks) ? $webhooks : json_decode($webhooks, true);
        if (!is_array($urls)) return;

        foreach ($urls as $url) {
            try {
                Http::timeout(5)->post($url, [
                    'event' => $event,
                    'data' => $data,
                    'timestamp' => now()->toIso8601String(),
                    'source' => 'zeniclaw',
                ]);
            } catch (\Exception $e) {
                Log::warning("Webhook delivery failed for {$url}: " . $e->getMessage());
            }
        }
    }
}
