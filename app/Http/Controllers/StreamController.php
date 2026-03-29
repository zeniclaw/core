<?php

namespace App\Http\Controllers;

use App\Models\Agent;
use App\Models\AgentSession;
use App\Services\AgentContext;
use App\Services\AgentOrchestrator;
use App\Services\RateLimiter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * SSE streaming controller for web chat (D1.4).
 * Streams agent responses token-by-token using Server-Sent Events.
 */
class StreamController extends Controller
{
    /**
     * Stream a chat response via SSE.
     * Falls back to standard JSON if streaming is not possible.
     */
    public function stream(Request $request): StreamedResponse
    {
        $user = $request->user();
        $body = $request->input('message', '');
        $agentId = $request->input('agent_id');

        if (empty(trim($body))) {
            return $this->sseError('Message is required', 400);
        }

        // Rate limiting
        $peerId = 'web-' . $user->id;
        $rateLimitError = RateLimiter::check($peerId);
        if ($rateLimitError) {
            return $this->sseError($rateLimitError, 429);
        }
        RateLimiter::hit($peerId);

        $agent = $agentId
            ? $user->agents()->findOrFail($agentId)
            : $user->agents()->where('status', 'active')->first();

        if (!$agent) {
            return $this->sseError('No active agent found', 404);
        }

        // Create/reuse session
        $sessionKey = AgentSession::keyFor($agent->id, 'web', $peerId);
        $session = AgentSession::updateOrCreate(
            ['session_key' => $sessionKey],
            [
                'agent_id' => $agent->id,
                'channel' => 'web',
                'peer_id' => $peerId,
                'last_message_at' => now(),
            ]
        );
        $session->increment('message_count');

        $context = new AgentContext(
            agent: $agent,
            session: $session,
            from: $peerId,
            senderName: $user->name,
            body: $body,
            hasMedia: false,
            mediaUrl: null,
            mimetype: null,
            media: null,
        );

        return new StreamedResponse(function () use ($context) {
            // Disable output buffering
            if (ob_get_level()) ob_end_clean();

            // Send start event
            $this->sendSSE('start', ['status' => 'processing']);

            try {
                // Try direct Anthropic streaming for simple chat (no tools needed)
                $orchestrator = new AgentOrchestrator();

                // Check if this is a simple chat that can use direct streaming
                $agentType = $context->agent->type ?? 'chat';
                $isCommand = preg_match('/^[#\/](private|debug|nodebug)\b/i', trim($context->body));
                $hasPending = !empty($context->session->pending_agent_context);
                $useDirectStream = !$isCommand && !$hasPending && in_array($agentType, ['chat', 'general', 'default']);

                if ($useDirectStream) {
                    $this->streamDirect($context);
                    return;
                }

                // Fallback: full orchestrator processing with chunked output
                $result = $orchestrator->process($context);

                // Stream the reply in chunks (simulates token-by-token)
                $reply = $result->reply ?? 'No response';
                $chunks = $this->chunkText($reply, 50); // ~50 char chunks

                foreach ($chunks as $i => $chunk) {
                    $this->sendSSE('token', [
                        'text' => $chunk,
                        'index' => $i,
                    ]);
                    // Small delay for streaming effect
                    usleep(15000); // 15ms
                }

                // Send completion event
                $subAgentId = $result->metadata['sub_agent_id'] ?? $result->metadata['background_task_id'] ?? null;
                $this->sendSSE('done', [
                    'full_reply' => $reply,
                    'action' => $result->action,
                    'sub_agent_id' => $subAgentId,
                    'files' => $result->metadata['files'] ?? [],
                ]);
            } catch (\Exception $e) {
                Log::error('SSE stream error: ' . $e->getMessage());
                $this->sendSSE('error', ['message' => $e->getMessage()]);
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no', // Disable nginx buffering
        ]);
    }

    /**
     * Stream directly from the Anthropic API for simple chat messages.
     */
    private function streamDirect(AgentContext $context): void
    {
        $claude = new \App\Services\AnthropicClient();
        $model = \App\Services\ModelResolver::balanced();

        $systemPrompt = "Tu es ZeniClaw, un assistant IA personnel intelligent et bienveillant. Reponds en francais sauf si l'utilisateur parle une autre langue.";

        $messages = [
            ['role' => 'user', 'content' => $context->body],
        ];

        $fullText = '';
        foreach ($claude->chatStream($messages, $model, $systemPrompt) as $event) {
            match ($event['type']) {
                'text_delta' => (function () use ($event, &$fullText) {
                    $fullText .= $event['data'];
                    $this->sendSSE('token', ['text' => $event['data']]);
                })(),
                'done' => $this->sendSSE('done', [
                    'full_reply' => $fullText,
                    'action' => 'reply',
                    'streamed' => true,
                ]),
                'error' => $this->sendSSE('error', ['message' => $event['data']]),
                default => null,
            };
        }

        // Save to conversation memory
        if ($fullText) {
            $memory = new \App\Services\ConversationMemoryService();
            $memory->append(
                $context->agent->id,
                $context->from,
                $context->senderName,
                $context->body,
                $fullText,
                ''
            );
        }
    }

    private function sendSSE(string $event, array $data): void
    {
        echo "event: {$event}\n";
        echo "data: " . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n\n";
        flush();
    }

    private function sseError(string $message, int $code): StreamedResponse
    {
        return new StreamedResponse(function () use ($message) {
            $this->sendSSE('error', ['message' => $message]);
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
        ]);
    }

    /**
     * Split text into chunks at word boundaries.
     */
    private function chunkText(string $text, int $chunkSize): array
    {
        $chunks = [];
        $words = preg_split('/(\s+)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
        $current = '';

        foreach ($words as $word) {
            if (mb_strlen($current . $word) > $chunkSize && $current !== '') {
                $chunks[] = $current;
                $current = $word;
            } else {
                $current .= $word;
            }
        }
        if ($current !== '') {
            $chunks[] = $current;
        }

        return $chunks;
    }
}
