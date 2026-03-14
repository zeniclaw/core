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
                $orchestrator = new AgentOrchestrator();
                $result = $orchestrator->process($context);

                // Stream the reply in chunks (simulates token-by-token for now)
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
