<?php

namespace App\Http\Controllers;

use App\Models\Agent;
use App\Models\AgentLog;
use App\Models\AgentSession;
use App\Services\AgentContext;
use App\Services\AgentOrchestrator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ChannelController extends Controller
{
    private string $wahaBase = 'http://waha:3000';
    private string $wahaApiKey = 'zeniclaw-waha-2026';
    private string $sessionName = 'default';

    private function waha(int $timeout = 10)
    {
        return Http::timeout($timeout)->withHeaders(['X-Api-Key' => $this->wahaApiKey]);
    }

    /**
     * Ensure a WAHA session exists and is started.
     * Handles all states: missing, STOPPED, FAILED, SCAN_QR_CODE, WORKING.
     */
    private function ensureSession(): string
    {
        $status = $this->waha()->get("{$this->wahaBase}/api/sessions/{$this->sessionName}");

        if (!$status->successful()) {
            // Session doesn't exist → create it
            $this->waha()->post("{$this->wahaBase}/api/sessions/start", [
                'name' => $this->sessionName,
            ]);
            return 'STARTING';
        }

        $sessionStatus = $status->json()['status'] ?? '';

        if ($sessionStatus === 'WORKING' || $sessionStatus === 'SCAN_QR_CODE') {
            return $sessionStatus;
        }

        if ($sessionStatus === 'STOPPED') {
            // Restart — preserves auth data, no new QR needed
            $this->waha()->post("{$this->wahaBase}/api/sessions/{$this->sessionName}/start");
            return 'STARTING';
        }

        // FAILED or unknown — auth is broken, delete and recreate
        $this->waha()->delete("{$this->wahaBase}/api/sessions/{$this->sessionName}");
        usleep(500_000);
        $this->waha()->post("{$this->wahaBase}/api/sessions/start", [
            'name' => $this->sessionName,
        ]);
        return 'STARTING';
    }

    public function startWhatsapp(Request $request): JsonResponse
    {
        try {
            $result = $this->ensureSession();
            return response()->json(['status' => $result]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 503);
        }
    }

    public function getQr(): JsonResponse
    {
        try {
            $status = $this->waha()->get("{$this->wahaBase}/api/sessions/{$this->sessionName}");

            if ($status->successful()) {
                $data = $status->json();
                $sessionStatus = $data['status'] ?? '';

                if ($sessionStatus === 'WORKING') {
                    return response()->json([
                        'connected' => true,
                        'phone' => $data['me']['pushname'] ?? $data['me']['id'] ?? 'Connected',
                    ]);
                }

                if ($sessionStatus === 'SCAN_QR_CODE') {
                    $response = $this->waha()->get("{$this->wahaBase}/api/{$this->sessionName}/auth/qr", ['format' => 'image']);
                    if ($response->successful()) {
                        $base64 = base64_encode($response->body());
                        return response()->json(['qr' => "data:image/png;base64,{$base64}"]);
                    }
                }

                // STARTING, FAILED, etc. — tell frontend to keep waiting
                return response()->json(['status' => $sessionStatus, 'waiting' => true]);
            }

            return response()->json(['status' => 'NO_SESSION', 'waiting' => true]);
        } catch (\Exception $e) {
            return response()->json(['error' => 'WAHA unavailable'], 503);
        }
    }

    public function statusWhatsapp(): JsonResponse
    {
        try {
            $response = $this->waha()->get("{$this->wahaBase}/api/sessions/{$this->sessionName}");
            if ($response->successful()) {
                $data = $response->json();
                return response()->json([
                    'connected' => ($data['status'] ?? '') === 'WORKING',
                    'status' => $data['status'] ?? 'STOPPED',
                    'phone' => $data['me']['pushname'] ?? null,
                ]);
            }
            return response()->json(['connected' => false, 'status' => 'STOPPED']);
        } catch (\Exception $e) {
            return response()->json(['connected' => false, 'status' => 'ERROR']);
        }
    }

    public function stopWhatsapp(): JsonResponse
    {
        try {
            $this->waha()->post("{$this->wahaBase}/api/sessions/{$this->sessionName}/stop");
            return response()->json(['status' => 'stopped']);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 503);
        }
    }

    public function whatsappWebhook(Request $request, Agent $agent): JsonResponse
    {
        try {
            $payload = $request->input('payload', []);
            $body = $payload['body'] ?? null;
            $from = $payload['from'] ?? null;
            $fromMe = $payload['fromMe'] ?? false;
            $hasMedia = $payload['hasMedia'] ?? false;
            $media = $payload['media'] ?? null;
            $mediaUrl = $media['url'] ?? null;
            $mimetype = $media['mimetype'] ?? null;

            // WAHA returns localhost URLs — rewrite to internal Docker hostname
            if ($mediaUrl) {
                $mediaUrl = str_replace('http://localhost:3000', $this->wahaBase, $mediaUrl);
            }

            // Log incoming message
            AgentLog::create([
                'agent_id' => $agent->id,
                'level' => 'info',
                'message' => 'WhatsApp message received',
                'context' => $request->all(),
            ]);

            // Skip: sent by us, system messages, status broadcasts, or no content at all
            if ($fromMe || !$from || $from === 'status@broadcast' || (!$body && !$hasMedia)) {
                return response()->json(['ok' => true]);
            }

            // Whitelist check: if enabled on agent, only allow whitelisted contacts/groups
            if ($agent->whitelist_enabled) {
                $sessionKey = AgentSession::keyFor($agent->id, 'whatsapp', $from);
                $existing = AgentSession::where('session_key', $sessionKey)->first();
                if (!$existing || !$existing->whitelisted) {
                    AgentLog::create([
                        'agent_id' => $agent->id,
                        'level' => 'warn',
                        'message' => 'Blocked by whitelist',
                        'context' => ['from' => $from, 'body' => $body],
                    ]);
                    return response()->json(['ok' => true, 'blocked' => 'whitelist']);
                }
            }

            // Create or update AgentSession
            $sessionKey = AgentSession::keyFor($agent->id, 'whatsapp', $from);
            $session = AgentSession::updateOrCreate(
                ['session_key' => $sessionKey],
                [
                    'agent_id' => $agent->id,
                    'channel' => 'whatsapp',
                    'peer_id' => $from,
                    'last_message_at' => now(),
                ]
            );
            $session->increment('message_count');

            // Build context and delegate to orchestrator
            $context = new AgentContext(
                agent: $agent,
                session: $session,
                from: $from,
                senderName: $payload['_data']['pushName'] ?? 'ami',
                body: $body,
                hasMedia: $hasMedia,
                mediaUrl: $mediaUrl,
                mimetype: $mimetype,
                media: $media,
            );

            $orchestrator = new AgentOrchestrator();
            $result = $orchestrator->process($context);

            return response()->json(['ok' => true, 'action' => $result->action, 'metadata' => $result->metadata]);
        } catch (\Exception $e) {
            Log::error('WhatsApp webhook error: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Web chat — process message through orchestrator and return reply directly.
     */
    public function webChat(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            $body = $request->input('message', '');
            $agentId = $request->input('agent_id');

            if (empty(trim($body))) {
                return response()->json(['error' => 'Message is required'], 400);
            }

            // Use specified agent or first active agent
            $agent = $agentId
                ? $user->agents()->findOrFail($agentId)
                : $user->agents()->where('status', 'active')->first();

            if (!$agent) {
                return response()->json(['error' => 'No active agent found. Create one first.'], 404);
            }

            // Create or reuse web chat session
            $peerId = 'web-' . $user->id;
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

            $orchestrator = new AgentOrchestrator();
            $result = $orchestrator->process($context);

            return response()->json([
                'ok' => true,
                'reply' => $result->reply ?? 'No response',
                'action' => $result->action,
                'agent' => $agent->name,
            ]);
        } catch (\Exception $e) {
            Log::error('Web chat error: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}
