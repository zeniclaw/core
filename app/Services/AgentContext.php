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
        public readonly ?string $autonomy = null,
        public readonly ?string $memoryContext = null,
        public ?array $routingMetadata = null,
        public ?ToolRegistry $toolRegistry = null,
        public ?int $currentSubAgentId = null,
        public int $currentDepth = 0,
        public int $interAgentCallCount = 0,
    ) {}

    public function phone(): string
    {
        return str_replace('@s.whatsapp.net', '', $this->from);
    }

    public function isGroup(): bool
    {
        return str_ends_with($this->from, '@g.us');
    }

    public function withRouting(string $agent, string $model, string $complexity, string $reasoning, string $autonomy = 'confirm'): self
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
            autonomy: $autonomy,
            memoryContext: $this->memoryContext,
            toolRegistry: $this->toolRegistry,
            currentSubAgentId: $this->currentSubAgentId,
            currentDepth: $this->currentDepth,
            interAgentCallCount: $this->interAgentCallCount,
        );
    }

    public function withMemoryContext(string $memoryContext): self
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
            routedAgent: $this->routedAgent,
            routedModel: $this->routedModel,
            complexity: $this->complexity,
            reasoning: $this->reasoning,
            autonomy: $this->autonomy,
            memoryContext: $memoryContext,
            toolRegistry: $this->toolRegistry,
            currentSubAgentId: $this->currentSubAgentId,
            currentDepth: $this->currentDepth,
            interAgentCallCount: $this->interAgentCallCount,
        );
    }

    public function withToolRegistry(ToolRegistry $toolRegistry): self
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
            routedAgent: $this->routedAgent,
            routedModel: $this->routedModel,
            complexity: $this->complexity,
            reasoning: $this->reasoning,
            autonomy: $this->autonomy,
            memoryContext: $this->memoryContext,
            toolRegistry: $toolRegistry,
            currentSubAgentId: $this->currentSubAgentId,
            currentDepth: $this->currentDepth,
            interAgentCallCount: $this->interAgentCallCount,
        );
    }
}
