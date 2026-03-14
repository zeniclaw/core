<?php

namespace App\Services\Channels;

/**
 * Web chat channel implementation (D13.2).
 * Messages go through HTTP response, not push messaging.
 */
class WebChannel implements ChannelInterface
{
    public function name(): string
    {
        return 'web';
    }

    public function sendText(string $to, string $text): bool
    {
        // Web chat: messages are returned via HTTP response, not pushed
        return true;
    }

    public function sendFile(string $to, string $filePath, string $filename, ?string $caption = null): bool
    {
        return true;
    }

    public function sendImage(string $to, string $imagePath, ?string $caption = null): bool
    {
        return true;
    }

    public function sendAudio(string $to, string $audioPath): bool
    {
        return true;
    }

    public function isConnected(): bool
    {
        return true; // Web channel is always available
    }

    public function normalizeMessage(array $rawPayload): NormalizedMessage
    {
        return new NormalizedMessage(
            channel: 'web',
            from: $rawPayload['from'] ?? 'web-unknown',
            senderName: $rawPayload['sender_name'] ?? 'Web User',
            body: $rawPayload['message'] ?? null,
            hasMedia: false,
            mediaUrl: null,
            mimetype: null,
            media: null,
            fromMe: false,
            raw: $rawPayload,
        );
    }
}
