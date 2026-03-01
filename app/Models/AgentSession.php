<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentSession extends Model
{
    protected $fillable = ['agent_id', 'session_key', 'channel', 'peer_id', 'last_message_at', 'message_count'];

    protected $casts = ['last_message_at' => 'datetime'];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public static function keyFor(int $agentId, string $channel, string $peerId): string
    {
        return "agent:{$agentId}:{$channel}:dm:{$peerId}";
    }
}
