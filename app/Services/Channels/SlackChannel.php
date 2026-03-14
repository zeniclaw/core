<?php

namespace App\Services\Channels;

use App\Models\AppSetting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Slack channel implementation (D13 enhanced).
 * Uses Slack Web API with Bot token.
 * Requires: slack_bot_token in AppSettings.
 */
class SlackChannel implements ChannelInterface
{
    private ?string $botToken;
    private string $apiBase = 'https://slack.com/api';

    public function __construct()
    {
        $this->botToken = AppSetting::get('slack_bot_token');
    }

    public function name(): string
    {
        return 'slack';
    }

    public function isAvailable(): bool
    {
        return !empty($this->botToken);
    }

    public function normalize(array $raw): NormalizedMessage
    {
        $event = $raw['event'] ?? $raw;

        $text = $event['text'] ?? null;
        $userId = $event['user'] ?? '';
        $channelId = $event['channel'] ?? '';

        // Check for file attachments
        $files = $event['files'] ?? [];
        $hasMedia = !empty($files);
        $mediaUrl = $files[0]['url_private'] ?? null;
        $mimetype = $files[0]['mimetype'] ?? null;

        return new NormalizedMessage(
            channel: 'slack',
            peerId: $channelId,
            senderName: $userId, // Would need to resolve via users.info API
            body: $text,
            hasMedia: $hasMedia,
            mediaUrl: $mediaUrl,
            mimetype: $mimetype,
            rawPayload: $raw,
        );
    }

    public function sendText(string $peerId, string $text): bool
    {
        if (!$this->botToken) return false;

        try {
            // Slack limit: 40,000 chars but practical limit ~4000 per block
            $chunks = mb_str_split($text, 3900);
            foreach ($chunks as $chunk) {
                $response = Http::timeout(10)
                    ->withHeaders(['Authorization' => "Bearer {$this->botToken}"])
                    ->post("{$this->apiBase}/chat.postMessage", [
                        'channel' => $peerId,
                        'text' => $chunk,
                        'mrkdwn' => true,
                    ]);

                if (!$response->successful() || !$response->json('ok')) {
                    Log::warning('Slack sendText failed', ['error' => $response->json('error')]);
                    return false;
                }
            }
            return true;
        } catch (\Exception $e) {
            Log::error("Slack sendText failed: " . $e->getMessage());
            return false;
        }
    }

    public function sendFile(string $peerId, string $filePath, string $filename, ?string $caption = null): bool
    {
        if (!$this->botToken) return false;

        try {
            $response = Http::timeout(30)
                ->withHeaders(['Authorization' => "Bearer {$this->botToken}"])
                ->attach('file', file_get_contents($filePath), $filename)
                ->post("{$this->apiBase}/files.upload", [
                    'channels' => $peerId,
                    'initial_comment' => $caption ?? '',
                    'filename' => $filename,
                ]);

            return $response->successful() && $response->json('ok');
        } catch (\Exception $e) {
            Log::error("Slack sendFile failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Send a rich message with blocks (Slack-specific).
     */
    public function sendBlocks(string $peerId, array $blocks, ?string $fallbackText = null): bool
    {
        if (!$this->botToken) return false;

        try {
            $response = Http::timeout(10)
                ->withHeaders(['Authorization' => "Bearer {$this->botToken}"])
                ->post("{$this->apiBase}/chat.postMessage", [
                    'channel' => $peerId,
                    'blocks' => $blocks,
                    'text' => $fallbackText ?? 'ZeniClaw message',
                ]);

            return $response->successful() && $response->json('ok');
        } catch (\Exception $e) {
            Log::error("Slack sendBlocks failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * React to a message with an emoji.
     */
    public function addReaction(string $channelId, string $timestamp, string $emoji): bool
    {
        if (!$this->botToken) return false;

        try {
            $response = Http::timeout(5)
                ->withHeaders(['Authorization' => "Bearer {$this->botToken}"])
                ->post("{$this->apiBase}/reactions.add", [
                    'channel' => $channelId,
                    'timestamp' => $timestamp,
                    'name' => $emoji,
                ]);

            return $response->successful();
        } catch (\Exception $e) {
            return false;
        }
    }
}
