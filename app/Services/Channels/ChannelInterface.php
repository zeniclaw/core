<?php

namespace App\Services\Channels;

/**
 * Channel abstraction (D13.1) — normalized interface for all messaging platforms.
 * All channels implement this interface to provide unified message sending.
 */
interface ChannelInterface
{
    /**
     * Get the channel name (whatsapp, web, telegram, discord, etc.).
     */
    public function name(): string;

    /**
     * Send a text message to a recipient.
     */
    public function sendText(string $to, string $text): bool;

    /**
     * Send a file/document to a recipient.
     */
    public function sendFile(string $to, string $filePath, string $filename, ?string $caption = null): bool;

    /**
     * Send an image to a recipient.
     */
    public function sendImage(string $to, string $imagePath, ?string $caption = null): bool;

    /**
     * Send an audio message to a recipient.
     */
    public function sendAudio(string $to, string $audioPath): bool;

    /**
     * Check if the channel is currently connected/available.
     */
    public function isConnected(): bool;

    /**
     * Normalize an incoming message to a standard format.
     */
    public function normalizeMessage(array $rawPayload): NormalizedMessage;
}
