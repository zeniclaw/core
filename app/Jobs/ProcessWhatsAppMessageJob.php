<?php

namespace App\Jobs;

use App\Models\Agent;
use App\Models\AgentSession;
use App\Services\AgentContext;
use App\Services\AgentOrchestrator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessWhatsAppMessageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;
    public int $tries = 1;

    public function __construct(
        private int $agentId,
        private int $sessionId,
        private string $from,
        private string $senderName,
        private ?string $body,
        private bool $hasMedia,
        private ?string $mediaUrl,
        private ?string $mimetype,
        private ?array $media,
    ) {
        $this->onQueue('default');
    }

    public function handle(): void
    {
        try {
            $agent = Agent::findOrFail($this->agentId);
            $session = AgentSession::findOrFail($this->sessionId);

            $context = new AgentContext(
                agent: $agent,
                session: $session,
                from: $this->from,
                senderName: $this->senderName,
                body: $this->body,
                hasMedia: $this->hasMedia,
                mediaUrl: $this->mediaUrl,
                mimetype: $this->mimetype,
                media: $this->media,
            );

            $orchestrator = new AgentOrchestrator();
            $orchestrator->process($context);
        } catch (\Exception $e) {
            Log::error('ProcessWhatsAppMessageJob failed', [
                'error' => $e->getMessage(),
                'from' => $this->from,
                'body' => substr($this->body ?? '', 0, 100),
            ]);
        }
    }
}
