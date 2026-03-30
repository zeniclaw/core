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

            \App\Services\Agents\BaseAgent::$whatsappSent = false;

            Log::info('ProcessWhatsAppMessageJob: processing', [
                'from' => $this->from,
                'body' => substr($this->body ?? '', 0, 50),
                'hasMedia' => $this->hasMedia,
            ]);

            $orchestrator = new AgentOrchestrator();
            $result = $orchestrator->process($context);

            Log::info('ProcessWhatsAppMessageJob: done', [
                'action' => $result->action,
                'reply_len' => strlen($result->reply ?? ''),
            ]);

            // Ensure reply is sent to WhatsApp (some agents don't call sendText themselves)
            $alreadySent = \App\Services\Agents\BaseAgent::$whatsappSent;
            Log::info('ProcessWhatsAppMessageJob: reply check', [
                'action' => $result->action,
                'has_reply' => !empty($result->reply),
                'already_sent' => $alreadySent,
                'from' => $this->from,
            ]);

            if ($result->action === 'reply' && $result->reply && !str_starts_with($this->from, 'web-') && !$alreadySent) {
                try {
                    \Illuminate\Support\Facades\Http::timeout(15)
                        ->withHeaders(['X-Api-Key' => 'zeniclaw-waha-2026'])
                        ->post('http://waha:3000/api/sendText', [
                            'chatId' => $this->from,
                            'text' => $result->reply,
                            'session' => 'default',
                        ]);
                } catch (\Exception $e) {
                    Log::warning('ProcessWhatsAppMessageJob: sendText fallback failed', [
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        } catch (\Exception $e) {
            Log::error('ProcessWhatsAppMessageJob failed', [
                'error' => $e->getMessage(),
                'trace' => substr($e->getTraceAsString(), 0, 300),
                'from' => $this->from,
                'body' => substr($this->body ?? '', 0, 100),
            ]);
        }
    }
}
