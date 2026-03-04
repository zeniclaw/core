<?php

namespace App\Services\Agents;

class AgentResult
{
    public function __construct(
        public readonly string $action,
        public readonly ?string $reply = null,
        public readonly ?string $handoffTo = null,
        public readonly array $metadata = [],
    ) {}

    public static function reply(string $reply, array $metadata = []): self
    {
        return new self(action: 'reply', reply: $reply, metadata: $metadata);
    }

    public static function handoff(string $targetAgent, array $metadata = []): self
    {
        return new self(action: 'handoff', handoffTo: $targetAgent, metadata: $metadata);
    }

    public static function dispatched(array $metadata = []): self
    {
        return new self(action: 'dispatched', metadata: $metadata);
    }

    public static function silent(array $metadata = []): self
    {
        return new self(action: 'silent', metadata: $metadata);
    }
}
