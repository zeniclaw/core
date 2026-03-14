<?php

namespace App\Services\Channels;

/**
 * Normalized message format (D13.5) — unified across all channels.
 */
class NormalizedMessage
{
    public function __construct(
        public readonly string $channel,
        public readonly string $from,
        public readonly string $senderName,
        public readonly ?string $body,
        public readonly bool $hasMedia,
        public readonly ?string $mediaUrl,
        public readonly ?string $mimetype,
        public readonly ?array $media,
        public readonly bool $fromMe = false,
        public readonly array $raw = [],
    ) {}

    /**
     * Check if this is a text-only message.
     */
    public function isTextOnly(): bool
    {
        return !$this->hasMedia && $this->body !== null;
    }

    /**
     * Check if this is a media message.
     */
    public function isMedia(): bool
    {
        return $this->hasMedia;
    }

    /**
     * Check if the message is from a group.
     */
    public function isGroup(): bool
    {
        return str_ends_with($this->from, '@g.us') || str_starts_with($this->from, 'group-');
    }
}
