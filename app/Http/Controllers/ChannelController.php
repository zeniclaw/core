<?php

namespace App\Http\Controllers;

use App\Models\Agent;
use App\Models\AgentLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ChannelController extends Controller
{
    private string $wahaBase = 'http://waha:3000';
    private string $sessionName = 'zeniclaw';

    public function startWhatsapp(Request $request): JsonResponse
    {
        try {
            $response = Http::timeout(10)->post("{$this->wahaBase}/api/sessions", [
                'name' => $this->sessionName,
            ]);

            // Auto-configure webhook
            $appUrl = config('app.url');
            $agent = $request->user()->agents()->first();
            $agentId = $agent?->id ?? 1;

            Http::timeout(5)->post("{$this->wahaBase}/api/sessions/{$this->sessionName}/webhooks", [
                'url' => "{$appUrl}/webhook/whatsapp/{$agentId}",
                'events' => ['message'],
            ]);

            return response()->json(['status' => 'starting', 'session' => $this->sessionName]);
        } catch (\Exception $e) {
            return response()->json(['error' => 'WAHA not available: ' . $e->getMessage()], 503);
        }
    }

    public function getQr(): JsonResponse
    {
        try {
            // Try QR endpoint first
            $response = Http::timeout(10)->get("{$this->wahaBase}/api/{$this->sessionName}/auth/qr", [
                'format' => 'image',
            ]);

            if ($response->successful()) {
                $base64 = base64_encode($response->body());
                $contentType = $response->header('Content-Type') ?? 'image/png';
                return response()->json(['qr' => "data:{$contentType};base64,{$base64}"]);
            }

            // Try session status
            $status = Http::timeout(10)->get("{$this->wahaBase}/api/sessions/{$this->sessionName}");
            if ($status->successful()) {
                $data = $status->json();
                if (($data['status'] ?? '') === 'WORKING') {
                    return response()->json(['connected' => true, 'phone' => $data['me']['id'] ?? null]);
                }
            }

            return response()->json(['error' => 'QR not ready yet'], 202);
        } catch (\Exception $e) {
            return response()->json(['error' => 'WAHA not available'], 503);
        }
    }

    public function stopWhatsapp(): JsonResponse
    {
        try {
            Http::timeout(10)->delete("{$this->wahaBase}/api/sessions/{$this->sessionName}");
            return response()->json(['status' => 'stopped']);
        } catch (\Exception $e) {
            return response()->json(['error' => 'WAHA not available: ' . $e->getMessage()], 503);
        }
    }

    public function whatsappWebhook(Request $request, Agent $agent): JsonResponse
    {
        try {
            $payload = $request->all();
            $event = $payload['event'] ?? 'message';
            $body = $payload['payload']['body'] ?? json_encode($payload);

            AgentLog::create([
                'agent_id' => $agent->id,
                'level' => 'info',
                'message' => "WhatsApp inbound [{$event}]: {$body}",
                'context' => $payload,
            ]);

            return response()->json(['ok' => true]);
        } catch (\Exception $e) {
            Log::error('WhatsApp webhook error: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function statusWhatsapp(): JsonResponse
    {
        try {
            $response = Http::timeout(5)->get("{$this->wahaBase}/api/sessions/{$this->sessionName}");
            if ($response->successful()) {
                $data = $response->json();
                $connected = ($data['status'] ?? '') === 'WORKING';
                return response()->json([
                    'connected' => $connected,
                    'status' => $data['status'] ?? 'UNKNOWN',
                    'phone' => $data['me']['id'] ?? null,
                ]);
            }
            return response()->json(['connected' => false, 'status' => 'NOT_STARTED']);
        } catch (\Exception $e) {
            return response()->json(['connected' => false, 'status' => 'WAHA_UNAVAILABLE'], 200);
        }
    }
}
