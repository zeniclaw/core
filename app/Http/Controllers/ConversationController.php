<?php

namespace App\Http\Controllers;

use App\Models\AgentLog;
use App\Models\AgentSession;
use App\Models\Project;
use App\Services\ConversationMemoryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

class ConversationController extends Controller
{
    public function index(Request $request): View
    {
        $filter = $request->input('filter', '');

        $query = AgentSession::with('agent')
            ->orderByDesc('last_message_at');

        if ($filter === 'dm') {
            $query->where('peer_id', 'not like', '%@g.us');
        } elseif ($filter === 'group') {
            $query->where('peer_id', 'like', '%@g.us');
        }

        $conversations = $query->paginate(20)->appends($request->query());

        // Build name map: display_name from session + group names from WAHA + project names
        $nameMap = $this->buildNameMap($conversations->items());

        return view('conversations.index', [
            'conversations' => $conversations,
            'filter' => $filter,
            'nameMap' => $nameMap,
        ]);
    }

    public function show(AgentSession $conversation): View
    {
        $conversation->load('agent');

        // Get messages from AgentLog for this peer
        // Incoming: "WhatsApp message received" with context->payload->from = peer_id
        // Outgoing: "Reply sent" logs with context->from = peer_id
        $messages = AgentLog::where('agent_id', $conversation->agent_id)
            ->where('level', 'info')
            ->where(function ($q) use ($conversation) {
                $q->where('context->payload->from', $conversation->peer_id)
                  ->orWhere(function ($q2) use ($conversation) {
                      $q2->where('context->from', $conversation->peer_id)
                          ->where(function ($q3) {
                              $q3->where('message', 'like', '%Reply sent%')
                                 ->orWhere('message', 'like', '%Document created%')
                                 ->orWhere('message', 'like', '%reply sent%');
                          });
                  });
            })
            ->orderBy('created_at')
            ->get()
            ->map(function ($log) use ($conversation) {
                $context = $log->context ?? [];
                $isIncoming = isset($context['payload']);

                $payload = $context['payload'] ?? [];
                $hasMedia = $payload['hasMedia'] ?? false;
                $media = $payload['media'] ?? null;
                $mediaUrl = $media['url'] ?? null;
                $mimetype = $media['mimetype'] ?? null;

                $model = null;
                $routedAgent = null;
                if (!$isIncoming) {
                    $model = $context['model'] ?? null;
                    // Extract routed agent from log context or message prefix
                    $routedAgent = $context['routed_agent'] ?? null;
                    if (!$routedAgent && preg_match('/^\[(\w+)\]/', $log->message ?? '', $m)) {
                        $routedAgent = $m[1];
                    }
                }

                // Build reply text: try 'reply' field, then reconstruct from context
                $replyBody = '';
                if (!$isIncoming) {
                    $replyBody = $context['reply'] ?? '';
                    if (!$replyBody && !empty($context['filename'])) {
                        $title = $context['title'] ?? $context['filename'];
                        $format = $context['format'] ?? '';
                        $replyBody = "Document *{$title}* ({$format}) cree avec succes !";
                    }
                }

                return [
                    'direction' => $isIncoming ? 'in' : 'out',
                    'body' => $isIncoming
                        ? ($payload['body'] ?? '')
                        : $replyBody,
                    'sender' => $isIncoming
                        ? ($payload['_data']['pushName'] ?? $payload['_data']['notifyName'] ?? $conversation->display_name ?? $conversation->displayName())
                        : 'ZeniClaw',
                    'timestamp' => $log->created_at,
                    'has_media' => $isIncoming && $hasMedia,
                    'media_url' => $isIncoming ? $mediaUrl : null,
                    'media_type' => $isIncoming ? $mimetype : null,
                    'model' => $model,
                    'routed_agent' => $routedAgent,
                ];
            })
            ->filter(fn ($m) => !empty($m['body']) || $m['has_media']);

        // Load memory
        $memory = new ConversationMemoryService();
        $memoryData = $memory->read($conversation->agent_id, $conversation->peer_id);

        // Debug: all logs for this peer (routing, agent activity, errors)
        $debugLogs = AgentLog::where('agent_id', $conversation->agent_id)
            ->where(function ($q) use ($conversation) {
                $q->where('context->from', $conversation->peer_id)
                  ->orWhere('context->payload->from', $conversation->peer_id);
            })
            ->orderByDesc('created_at')
            ->limit(100)
            ->get();

        // Routing decisions for this peer
        $routingLogs = AgentLog::where('agent_id', $conversation->agent_id)
            ->where('message', 'Router decision')
            ->where('context->from', $conversation->peer_id)
            ->orderByDesc('created_at')
            ->limit(30)
            ->get();

        return view('conversations.show', [
            'conversation' => $conversation,
            'messages' => $messages,
            'memoryEntries' => $memoryData['entries'] ?? [],
            'debugLogs' => $debugLogs,
            'routingLogs' => $routingLogs,
        ]);
    }

    /**
     * Build a peer_id => display name map from session display_name, projects, and WAHA groups.
     */
    private function buildNameMap(array $sessions): array
    {
        $nameMap = [];

        // 1. display_name stored on session (WhatsApp pushName)
        foreach ($sessions as $session) {
            if ($session->display_name) {
                $nameMap[$session->peer_id] = $session->display_name;
            }
        }

        // 2. Project requester names (override for DMs)
        $peerIds = array_map(fn($s) => $s->peer_id, $sessions);
        $projectNames = Project::whereIn('requester_phone', $peerIds)
            ->orderByDesc('created_at')
            ->pluck('requester_name', 'requester_phone')
            ->all();
        foreach ($projectNames as $peerId => $name) {
            if ($name) {
                $nameMap[$peerId] = $name;
            }
        }

        // 3. Group names from WAHA
        $groupIds = array_filter($peerIds, fn($id) => str_ends_with($id, '@g.us'));
        if (!empty($groupIds)) {
            try {
                $response = Http::timeout(5)
                    ->withHeaders(['X-Api-Key' => 'zeniclaw-waha-2026'])
                    ->get('http://waha:3000/api/default/groups');

                if ($response->successful()) {
                    $groups = $response->json();
                    foreach ($groupIds as $gid) {
                        if (isset($groups[$gid]['subject'])) {
                            $nameMap[$gid] = $groups[$gid]['subject'];
                        }
                    }
                }
            } catch (\Exception $e) {
                Log::warning('Failed to fetch WAHA groups: ' . $e->getMessage());
            }
        }

        return $nameMap;
    }
}
