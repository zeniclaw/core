<?php

namespace App\Http\Controllers;

use App\Models\AgentLog;
use App\Models\AgentSession;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ContactController extends Controller
{
    public function index(Request $request)
    {
        $filter = $request->query('type', 'all');

        $query = AgentSession::where('channel', 'whatsapp')
            ->where('peer_id', '!=', 'status@broadcast')
            ->orderByDesc('last_message_at');

        if ($filter === 'dm') {
            $query->where('peer_id', 'NOT LIKE', '%@g.us');
        } elseif ($filter === 'group') {
            $query->where('peer_id', 'LIKE', '%@g.us');
        }

        $sessions = $query->get();

        // Build name lookup from webhook logs (pushName)
        $pushNames = $this->buildPushNameMap();

        // Build group name lookup from WAHA
        $groupNames = $this->buildGroupNameMap($sessions);

        $contacts = $sessions->map(function ($session) use ($pushNames, $groupNames) {
            $peerId = $session->peer_id;
            $isGroup = $session->isGroup();

            $name = null;

            if ($isGroup) {
                // Use WAHA group name first
                $name = $groupNames[$peerId] ?? null;
            } else {
                // For DMs: try project requester_name, then pushName from logs
                $name = Project::where('requester_phone', $peerId)
                    ->orderByDesc('created_at')
                    ->value('requester_name');
                if (!$name) {
                    $name = $pushNames[$peerId] ?? null;
                }
            }

            // Fallback to displayName (phone number without suffix)
            if (!$name) {
                $name = $session->displayName();
            }

            // Count projects for this contact
            $projectCount = Project::where('requester_phone', $peerId)->count();

            return (object) [
                'id' => $session->id,
                'peer_id' => $peerId,
                'name' => $name,
                'type' => $isGroup ? 'group' : 'dm',
                'message_count' => $session->message_count,
                'last_message_at' => $session->last_message_at,
                'project_count' => $projectCount,
                'whitelisted' => $session->whitelisted,
            ];
        });

        // Counts for filter badges (exclude status@broadcast)
        $baseQuery = AgentSession::where('channel', 'whatsapp')->where('peer_id', '!=', 'status@broadcast');
        $allCount = (clone $baseQuery)->count();
        $dmCount = (clone $baseQuery)->where('peer_id', 'NOT LIKE', '%@g.us')->count();
        $groupCount = (clone $baseQuery)->where('peer_id', 'LIKE', '%@g.us')->count();

        return view('contacts.index', compact('contacts', 'filter', 'allCount', 'dmCount', 'groupCount'));
    }

    /**
     * Extract pushName from webhook logs for each peer_id.
     * Returns the most recent pushName found per peer_id.
     */
    private function buildPushNameMap(): array
    {
        $names = [];

        $logs = AgentLog::where('message', 'WhatsApp message received')
            ->orderByDesc('id')
            ->limit(500)
            ->get(['context']);

        foreach ($logs as $log) {
            $payload = $log->context['payload'] ?? [];
            $from = $payload['from'] ?? null;
            $pushName = $payload['_data']['pushName'] ?? null;

            if ($from && $pushName && !isset($names[$from])) {
                $names[$from] = $pushName;
            }
        }

        return $names;
    }

    /**
     * Fetch group names from WAHA API.
     */
    private function buildGroupNameMap($sessions): array
    {
        $groupIds = $sessions->filter(fn($s) => $s->isGroup())->pluck('peer_id')->all();
        if (empty($groupIds)) {
            return [];
        }

        try {
            $response = Http::timeout(5)
                ->withHeaders(['X-Api-Key' => 'zeniclaw-waha-2026'])
                ->get('http://waha:3000/api/default/groups');

            if (!$response->successful()) {
                return [];
            }

            $groups = $response->json();
            $names = [];
            foreach ($groupIds as $gid) {
                if (isset($groups[$gid]['subject'])) {
                    $names[$gid] = $groups[$gid]['subject'];
                }
            }
            return $names;
        } catch (\Exception $e) {
            Log::warning('Failed to fetch WAHA groups: ' . $e->getMessage());
            return [];
        }
    }

    public function toggleWhitelist(AgentSession $session): RedirectResponse
    {
        $session->update(['whitelisted' => !$session->whitelisted]);

        $status = $session->whitelisted ? 'whitelisté' : 'retiré de la whitelist';
        return back()->with('success', "Contact {$status}.");
    }
}
