<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;

class ConversationMemoryService
{
    private function path(int $agentId, string $peerId): string
    {
        // Sanitize peerId for filesystem (replace @ and . with safe chars)
        $safePeer = str_replace(['@', '.'], ['_at_', '_'], $peerId);
        return "memory/{$agentId}/{$safePeer}.json";
    }

    public function read(int $agentId, string $peerId): array
    {
        $path = $this->path($agentId, $peerId);

        if (!Storage::disk('local')->exists($path)) {
            return ['peer_id' => $peerId, 'entries' => []];
        }

        $content = Storage::disk('local')->get($path);
        $data = json_decode($content, true);

        return $data ?: ['peer_id' => $peerId, 'entries' => []];
    }

    public function append(int $agentId, string $peerId, string $sender, string $senderMessage, string $agentReply, string $summary = ''): void
    {
        $data = $this->read($agentId, $peerId);

        $data['entries'][] = [
            'timestamp' => now()->toIso8601String(),
            'sender' => $sender,
            'sender_message' => $senderMessage,
            'agent_reply' => $agentReply,
            'summary' => $summary,
        ];

        // Bound conversation files: archive old entries beyond 500
        if (count($data['entries']) > 500) {
            $overflow = array_slice($data['entries'], 0, -500);
            $data['entries'] = array_slice($data['entries'], -500);

            // Archive overflow to a separate file
            $archivePath = $this->path($agentId, $peerId) . '.archive';
            $archiveData = [];
            if (Storage::disk('local')->exists($archivePath)) {
                $archiveData = json_decode(Storage::disk('local')->get($archivePath), true) ?: [];
            }
            $archiveData = array_merge($archiveData, $overflow);
            Storage::disk('local')->put($archivePath, json_encode($archiveData, JSON_UNESCAPED_UNICODE));
        }

        $path = $this->path($agentId, $peerId);
        Storage::disk('local')->put($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    public function clear(int $agentId, string $peerId): void
    {
        $path = $this->path($agentId, $peerId);
        Storage::disk('local')->delete($path);
    }

    public function formatForPrompt(int $agentId, string $peerId, int $maxEntries = 20): string
    {
        $data = $this->read($agentId, $peerId);
        $entries = $data['entries'] ?? [];

        if (empty($entries)) {
            return '';
        }

        // Take only the last N entries to keep prompt manageable
        $recent = array_slice($entries, -$maxEntries);

        $lines = ["--- Mémoire de conversation avec {$peerId} ---"];
        foreach ($recent as $entry) {
            $time = $entry['timestamp'] ?? '';
            $summary = $entry['summary'] ?? '';
            $sender = $entry['sender'] ?? 'inconnu';

            if ($summary) {
                $lines[] = "[{$time}] {$sender}: {$summary}";
            } else {
                $msg = mb_substr($entry['sender_message'] ?? '', 0, 100);
                $reply = mb_substr($entry['agent_reply'] ?? '', 0, 100);
                $lines[] = "[{$time}] {$sender}: {$msg} → Réponse: {$reply}";
            }
        }
        $lines[] = "--- Fin mémoire ---";

        return implode("\n", $lines);
    }
}
