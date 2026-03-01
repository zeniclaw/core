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
    private string $wahaApiKey = 'zeniclaw-waha-2026';
    private string $sessionName = 'default';

    private function waha(int $timeout = 10)
    {
        return Http::timeout($timeout)->withHeaders(['X-Api-Key' => $this->wahaApiKey]);
    }

    public function startWhatsapp(Request $request): JsonResponse
    {
        try {
            // Start session (already exists in WAHA Core)
            $start = $this->waha()->post("{$this->wahaBase}/api/sessions/{$this->sessionName}/start");

            $appUrl = config('app.url');
            $agent = $request->user()->agents()->first();
            $agentId = $agent?->id ?? 1;

            // Configure webhook
            $this->waha(5)->post("{$this->wahaBase}/api/sessions/{$this->sessionName}/webhooks", [
                'url' => "{$appUrl}/webhook/whatsapp/{$agentId}",
                'events' => ['message'],
            ]);

            return response()->json(['status' => 'starting']);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 503);
        }
    }

    public function getQr(): JsonResponse
    {
        try {
            // Check if already connected
            $status = $this->waha()->get("{$this->wahaBase}/api/sessions/{$this->sessionName}");
            if ($status->successful()) {
                $data = $status->json();
                if (($data['status'] ?? '') === 'WORKING') {
                    return response()->json(['connected' => true, 'phone' => $data['me']['pushname'] ?? $data['me']['id'] ?? 'Connected']);
                }
            }

            // Get QR image
            $response = $this->waha()->get("{$this->wahaBase}/api/{$this->sessionName}/auth/qr", ['format' => 'image']);
            if ($response->successful()) {
                $base64 = base64_encode($response->body());
                return response()->json(['qr' => "data:image/png;base64,{$base64}"]);
            }

            return response()->json(['error' => 'QR not ready, retrying...'], 202);
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
            AgentLog::create([
                'agent_id' => $agent->id,
                'level' => 'info',
                'message' => 'WhatsApp message received',
                'context' => $request->all(),
            ]);
            return response()->json(['ok' => true]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}
