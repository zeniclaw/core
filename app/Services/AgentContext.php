<?php

namespace App\Services;

use App\Models\Agent;
use App\Models\AgentSession;

class AgentContext
{
    public function __construct(
        public readonly Agent $agent,
        public readonly AgentSession $session,
        public readonly string $from,
        public readonly string $senderName,
        public readonly ?string $body,
        public readonly bool $hasMedia,
        public readonly ?string $mediaUrl,
        public readonly ?string $mimetype,
        public readonly ?array $media,
        public readonly ?string $routedAgent = null,
        public readonly ?string $routedModel = null,
        public readonly ?string $complexity = null,
        public readonly ?string $reasoning = null,
    ) {}

    public function phone(): string
    {
        return str_replace('@s.whatsapp.net', '', $this->from);
    }

    public function isGroup(): bool
    {
        return str_ends_with($this->from, '@g.us');
    }

    public function withRouting(string $agent, string $model, string $complexity, string $reasoning): self
    {
        return new self(
            agent: $this->agent,
            session: $this->session,
            from: $this->from,
            senderName: $this->senderName,
            body: $this->body,
            hasMedia: $this->hasMedia,
            mediaUrl: $this->mediaUrl,
            mimetype: $this->mimetype,
            media: $this->media,
            routedAgent: $agent,
            routedModel: $model,
            complexity: $complexity,
            reasoning: $reasoning,
        );
    }
}
