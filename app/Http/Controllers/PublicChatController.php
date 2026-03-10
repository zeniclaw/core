<?php

namespace App\Http\Controllers;

use App\Models\AgentSession;
use App\Models\ApiToken;
use App\Models\AppSetting;
use App\Services\AgentContext;
use App\Services\AgentOrchestrator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PublicChatController extends Controller
{
    /**
     * Display the public chat page (no auth required).
     */
    public function index()
    {
        $config = [
            'title'       => AppSetting::get('public_chat_title') ?? 'ZeniClaw AI',
            'subtitle'    => AppSetting::get('public_chat_subtitle') ?? 'Assistant IA',
            'welcome'     => AppSetting::get('public_chat_welcome') ?? 'Bonjour ! Comment puis-je vous aider ?',
            'primary'     => AppSetting::get('public_chat_color') ?? '#4f46e5',
            'logo_url'    => AppSetting::get('public_chat_logo') ?? null,
            'placeholder' => AppSetting::get('public_chat_placeholder') ?? 'Tapez votre message...',
        ];

        return view('public-chat', compact('config'));
    }

    /**
     * Handle public chat messages (authenticated via API token or CHAT_API_KEY).
     */
    public function send(Request $request): JsonResponse
    {
        try {
            $body = $request->input('message', '');
            if (empty(trim($body))) {
                return response()->json(['error' => 'Message is required'], 400);
            }

            $user = $this->resolveUser($request);
            if (!$user) {
                return response()->json(['error' => 'Invalid or missing API key'], 401);
            }

            $agent = $user->agents()->where('status', 'active')->first();
            if (!$agent) {
                return response()->json(['error' => 'No active agent configured'], 404);
            }

            $guestId = 'public-' . substr(md5($request->ip() . $request->userAgent()), 0, 12);
            $sessionKey = AgentSession::keyFor($agent->id, 'public', $guestId);
            $session = AgentSession::updateOrCreate(
                ['session_key' => $sessionKey],
                [
                    'agent_id'        => $agent->id,
                    'channel'         => 'public',
                    'peer_id'         => $guestId,
                    'last_message_at' => now(),
                ]
            );
            $session->increment('message_count');

            $senderName = $request->input('name', 'Visiteur');

            $context = new AgentContext(
                agent: $agent,
                session: $session,
                from: $guestId,
                senderName: $senderName,
                body: $body,
                hasMedia: false,
                mediaUrl: null,
                mimetype: null,
                media: null,
            );

            $orchestrator = new AgentOrchestrator();
            $result = $orchestrator->process($context);

            return response()->json([
                'ok'    => true,
                'reply' => $result->reply ?? 'No response',
                'agent' => $agent->name,
            ]);
        } catch (\Exception $e) {
            Log::error('Public chat error: ' . $e->getMessage());
            return response()->json(['error' => 'Internal error'], 500);
        }
    }

    /**
     * Resolve user from API token or env CHAT_API_KEY.
     */
    private function resolveUser(Request $request): ?\App\Models\User
    {
        $token = $request->bearerToken()
            ?? $request->header('X-Api-Key')
            ?? $request->input('api_key');

        if (!$token) {
            return null;
        }

        // Check against env CHAT_API_KEY (uses first user)
        $envKey = config('services.public_chat.api_key');
        if ($envKey && hash_equals($envKey, $token)) {
            return \App\Models\User::first();
        }

        // Check against user API tokens
        $apiToken = ApiToken::findByPlain($token);
        if ($apiToken) {
            $apiToken->update(['last_used_at' => now()]);
            return $apiToken->user;
        }

        return null;
    }
}
