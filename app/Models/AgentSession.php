<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentSession extends Model
{
    protected $fillable = ['agent_id', 'session_key', 'channel', 'peer_id', 'display_name', 'last_message_at', 'message_count', 'active_project_id', 'pending_switch_project_id', 'pending_agent_context', 'whitelisted', 'debug_mode'];

    protected $casts = ['last_message_at' => 'datetime', 'whitelisted' => 'boolean', 'debug_mode' => 'boolean', 'pending_agent_context' => 'array'];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function activeProject(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'active_project_id');
    }

    public static function keyFor(int $agentId, string $channel, string $peerId): string
    {
        $type = str_ends_with($peerId, '@g.us') ? 'group' : 'dm';
        return "agent:{$agentId}:{$channel}:{$type}:{$peerId}";
    }

    public function isGroup(): bool
    {
        return str_ends_with($this->peer_id, '@g.us');
    }

    public function displayName(): string
    {
        $id = $this->peer_id ?? '';

        if ($this->isGroup()) {
            // Remove @g.us suffix for groups
            return str_replace('@g.us', '', $id);
        }

        // Remove @s.whatsapp.net suffix for DMs
        return str_replace('@s.whatsapp.net', '', $id);
    }
}
