<?php

namespace App\Services\Channels;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * WhatsApp channel implementation via WAHA (D13.2).
 */
class WhatsAppChannel implements ChannelInterface
{
    private string $wahaBase = 'http://waha:3000';
    private string $wahaApiKey = 'zeniclaw-waha-2026';
    private string $sessionName = 'default';

    private function waha(int $timeout = 15)
    {
        return Http::timeout($timeout)->withHeaders(['X-Api-Key' => $this->wahaApiKey]);
    }

    public function name(): string
    {
        return 'whatsapp';
    }

    public function sendText(string $to, string $text): bool
    {
        if (str_starts_with($to, 'web-')) return false;

        $maxRetries = 3;
        for ($i = 0; $i < $maxRetries; $i++) {
            try {
                $response = $this->waha(15)->post("{$this->wahaBase}/api/sendText", [
                    'chatId' => $to,
                    'text' => $text,
                    'session' => $this->sessionName,
                ]);

                if ($response->successful()) {
                    \App\Events\MessageSent::dispatch('whatsapp', $to, $text, null);
                    return true;
                }
            } catch (\Exception $e) {
                Log::warning("WhatsAppChannel::sendText attempt " . ($i + 1) . " failed: " . $e->getMessage());
            }

            if ($i < $maxRetries - 1) {
                sleep(3 * ($i + 1));
            }
        }

        return false;
    }

    public function sendFile(string $to, string $filePath, string $filename, ?string $caption = null): bool
    {
        if (str_starts_with($to, 'web-')) return false;

        try {
            $data = base64_encode(file_get_contents($filePath));
            $mimetype = mime_content_type($filePath) ?: 'application/octet-stream';

            $this->waha(30)->post("{$this->wahaBase}/api/sendFile", [
                'chatId' => $to,
                'file' => [
                    'data' => $data,
                    'filename' => $filename,
                    'mimetype' => $mimetype,
                ],
                'caption' => $caption,
                'session' => $this->sessionName,
            ]);
            return true;
        } catch (\Exception $e) {
            Log::error("WhatsAppChannel::sendFile failed: " . $e->getMessage());
            return false;
        }
    }

    public function sendImage(string $to, string $imagePath, ?string $caption = null): bool
    {
        return $this->sendFile($to, $imagePath, basename($imagePath), $caption);
    }

    public function sendAudio(string $to, string $audioPath): bool
    {
        return $this->sendFile($to, $audioPath, basename($audioPath));
    }

    public function isConnected(): bool
    {
        try {
            $response = $this->waha(5)->get("{$this->wahaBase}/api/sessions/{$this->sessionName}");
            return $response->successful() && ($response->json('status') === 'WORKING');
        } catch (\Exception $e) {
            return false;
        }
    }

    public function normalizeMessage(array $rawPayload): NormalizedMessage
    {
        $payload = $rawPayload['payload'] ?? $rawPayload;

        $mediaUrl = $payload['media']['url'] ?? null;
        if ($mediaUrl) {
            $mediaUrl = str_replace('http://localhost:3000', $this->wahaBase, $mediaUrl);
        }

        return new NormalizedMessage(
            channel: 'whatsapp',
            from: $payload['from'] ?? '',
            senderName: $payload['_data']['pushName'] ?? $payload['_data']['notifyName'] ?? 'unknown',
            body: $payload['body'] ?? null,
            hasMedia: $payload['hasMedia'] ?? false,
            mediaUrl: $mediaUrl,
            mimetype: $payload['media']['mimetype'] ?? null,
            media: $payload['media'] ?? null,
            fromMe: $payload['fromMe'] ?? false,
            raw: $rawPayload,
        );
    }
}
