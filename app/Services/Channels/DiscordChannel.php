<?php

namespace App\Services\Channels;

use App\Models\AppSetting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Discord channel implementation (D13.4).
 * Requires: discord_bot_token in AppSettings.
 */
class DiscordChannel implements ChannelInterface
{
    private ?string $botToken;
    private string $apiBase = 'https://discord.com/api/v10';

    public function __construct()
    {
        $this->botToken = AppSetting::get('discord_bot_token');
    }

    public function name(): string
    {
        return 'discord';
    }

    public function isAvailable(): bool
    {
        return !empty($this->botToken);
    }

    public function normalize(array $raw): NormalizedMessage
    {
        $message = $raw;
        $author = $message['author'] ?? [];

        $text = $message['content'] ?? null;
        $hasMedia = !empty($message['attachments']);
        $mediaUrl = $message['attachments'][0]['url'] ?? null;
        $mimetype = $message['attachments'][0]['content_type'] ?? null;

        return new NormalizedMessage(
            channel: 'discord',
            peerId: $message['channel_id'] ?? '',
            senderName: $author['global_name'] ?? $author['username'] ?? 'Discord User',
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
            // Discord limit: 2000 chars per message
            $chunks = mb_str_split($text, 1990);
            foreach ($chunks as $chunk) {
                Http::timeout(10)
                    ->withHeaders(['Authorization' => "Bot {$this->botToken}"])
                    ->post("{$this->apiBase}/channels/{$peerId}/messages", [
                        'content' => $chunk,
                    ]);
            }
            return true;
        } catch (\Exception $e) {
            Log::error("Discord sendText failed: " . $e->getMessage());
            return false;
        }
    }

    public function sendFile(string $peerId, string $filePath, string $filename, ?string $caption = null): bool
    {
        if (!$this->botToken) return false;

        try {
            $response = Http::timeout(30)
                ->withHeaders(['Authorization' => "Bot {$this->botToken}"])
                ->attach('files[0]', file_get_contents($filePath), $filename)
                ->post("{$this->apiBase}/channels/{$peerId}/messages", [
                    'content' => $caption ?? '',
                ]);
            return $response->successful();
        } catch (\Exception $e) {
            Log::error("Discord sendFile failed: " . $e->getMessage());
            return false;
        }
    }
}
