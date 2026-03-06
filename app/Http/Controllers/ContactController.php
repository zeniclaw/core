<?php

namespace App\Http\Controllers;

use App\Models\Agent;
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

        // Build group name lookup from WAHA
        $groupNames = $this->buildGroupNameMap($sessions);

        $contacts = $sessions->map(function ($session) use ($groupNames) {
            $peerId = $session->peer_id;
            $isGroup = $session->isGroup();

            $name = null;

            if ($isGroup) {
                $name = $groupNames[$peerId] ?? $session->display_name;
            } else {
                // Use stored display_name (pushName from WhatsApp)
                $name = $session->display_name;

                // Override with project requester_name if available
                $projectName = Project::where('requester_phone', $peerId)
                    ->orderByDesc('created_at')
                    ->value('requester_name');
                if ($projectName) {
                    $name = $projectName;
                }
            }

            // Fallback to phone number
            if (!$name) {
                $name = $session->displayName();
            }

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

        // Counts for filter badges
        $baseQuery = AgentSession::where('channel', 'whatsapp')->where('peer_id', '!=', 'status@broadcast');
        $allCount = (clone $baseQuery)->count();
        $dmCount = (clone $baseQuery)->where('peer_id', 'NOT LIKE', '%@g.us')->count();
        $groupCount = (clone $baseQuery)->where('peer_id', 'LIKE', '%@g.us')->count();

        return view('contacts.index', compact('contacts', 'filter', 'allCount', 'dmCount', 'groupCount'));
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

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'phone' => ['required', 'string', 'regex:/^\+?[0-9]{7,15}$/'],
            'name' => ['nullable', 'string', 'max:100'],
            'greeting' => ['nullable', 'string', 'max:1000'],
        ]);

        $phone = preg_replace('/[^0-9]/', '', $request->input('phone'));
        $peerId = $phone . '@s.whatsapp.net';

        $agent = $request->user()->agents()->where('status', 'active')->first()
            ?? $request->user()->agents()->first();

        if (!$agent) {
            return back()->with('error', 'No agent found. Create an agent first.');
        }

        $sessionKey = AgentSession::keyFor($agent->id, 'whatsapp', $peerId);

        $existing = AgentSession::where('session_key', $sessionKey)->first();
        if ($existing) {
            return back()->with('error', 'This contact already exists.');
        }

        $contactName = $request->input('name', $phone);

        AgentSession::create([
            'session_key' => $sessionKey,
            'agent_id' => $agent->id,
            'channel' => 'whatsapp',
            'peer_id' => $peerId,
            'display_name' => $contactName !== $phone ? $contactName : null,
            'last_message_at' => now(),
            'message_count' => 0,
            'whitelisted' => true,
        ]);

        $greeting = $request->input('greeting')
            ?: "Hi {$contactName}! I'm *{$agent->name}*, your AI assistant powered by ZeniClaw. Feel free to send me a message anytime — I can help with questions, reminders, projects, and much more!";

        try {
            Http::timeout(15)
                ->withHeaders(['X-Api-Key' => 'zeniclaw-waha-2026'])
                ->post('http://waha:3000/api/sendText', [
                    'chatId' => $peerId,
                    'text' => $greeting,
                    'session' => 'default',
                ]);
        } catch (\Exception $e) {
            Log::warning('Failed to send greeting to new contact: ' . $e->getMessage());
            return back()->with('success', "Contact {$contactName} added but greeting could not be sent (WhatsApp may not be connected).");
        }

        return back()->with('success', "Contact {$contactName} added and greeting sent!");
    }

    public function destroy(AgentSession $session): RedirectResponse
    {
        $name = $session->display_name ?? $session->displayName();
        $session->delete();
        return back()->with('success', "Contact {$name} deleted.");
    }

    public function toggleWhitelist(AgentSession $session): RedirectResponse
    {
        $session->update(['whitelisted' => !$session->whitelisted]);

        $status = $session->whitelisted ? 'whitelisted' : 'removed from whitelist';
        return back()->with('success', "Contact {$status}.");
    }
}
