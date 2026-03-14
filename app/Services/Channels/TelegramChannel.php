<?php

namespace App\Services\Channels;

use App\Models\AppSetting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Telegram channel implementation (D13.3).
 * Requires: telegram_bot_token in AppSettings.
 */
class TelegramChannel implements ChannelInterface
{
    private ?string $botToken;

    public function __construct()
    {
        $this->botToken = AppSetting::get('telegram_bot_token');
    }

    public function name(): string
    {
        return 'telegram';
    }

    public function isAvailable(): bool
    {
        return !empty($this->botToken);
    }

    public function normalize(array $raw): NormalizedMessage
    {
        $message = $raw['message'] ?? $raw['edited_message'] ?? [];
        $from = $message['from'] ?? [];
        $chat = $message['chat'] ?? [];

        $text = $message['text'] ?? $message['caption'] ?? null;
        $hasMedia = isset($message['photo']) || isset($message['document']) || isset($message['voice']) || isset($message['audio']);
        $mediaUrl = null;
        $mimetype = null;

        // Get file URL for media
        if ($hasMedia) {
            $fileId = $message['photo'][count($message['photo'] ?? []) - 1]['file_id']
                ?? ($message['document']['file_id'] ?? null)
                ?? ($message['voice']['file_id'] ?? null)
                ?? ($message['audio']['file_id'] ?? null);

            if ($fileId && $this->botToken) {
                try {
                    $fileInfo = Http::get("https://api.telegram.org/bot{$this->botToken}/getFile", ['file_id' => $fileId]);
                    if ($fileInfo->successful()) {
                        $filePath = $fileInfo->json('result.file_path');
                        $mediaUrl = "https://api.telegram.org/file/bot{$this->botToken}/{$filePath}";
                    }
                } catch (\Exception $e) {
                    Log::warning('Telegram: failed to get file URL', ['error' => $e->getMessage()]);
                }

                $mimetype = $message['document']['mime_type']
                    ?? ($message['voice']['mime_type'] ?? null)
                    ?? ($message['audio']['mime_type'] ?? null)
                    ?? (isset($message['photo']) ? 'image/jpeg' : null);
            }
        }

        return new NormalizedMessage(
            channel: 'telegram',
            peerId: (string) $chat['id'],
            senderName: trim(($from['first_name'] ?? '') . ' ' . ($from['last_name'] ?? '')) ?: 'Telegram User',
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
            // Telegram limit: 4096 chars per message
            $chunks = mb_str_split($text, 4000);
            foreach ($chunks as $chunk) {
                $response = Http::timeout(10)->post("https://api.telegram.org/bot{$this->botToken}/sendMessage", [
                    'chat_id' => $peerId,
                    'text' => $chunk,
                    'parse_mode' => 'Markdown',
                ]);
                if (!$response->successful()) {
                    // Retry without Markdown if parsing fails
                    Http::timeout(10)->post("https://api.telegram.org/bot{$this->botToken}/sendMessage", [
                        'chat_id' => $peerId,
                        'text' => $chunk,
                    ]);
                }
            }
            return true;
        } catch (\Exception $e) {
            Log::error("Telegram sendText failed: " . $e->getMessage());
            return false;
        }
    }

    public function sendFile(string $peerId, string $filePath, string $filename, ?string $caption = null): bool
    {
        if (!$this->botToken) return false;

        try {
            $response = Http::timeout(30)
                ->attach('document', file_get_contents($filePath), $filename)
                ->post("https://api.telegram.org/bot{$this->botToken}/sendDocument", [
                    'chat_id' => $peerId,
                    'caption' => $caption ? mb_substr($caption, 0, 1024) : null,
                ]);
            return $response->successful();
        } catch (\Exception $e) {
            Log::error("Telegram sendFile failed: " . $e->getMessage());
            return false;
        }
    }
}
