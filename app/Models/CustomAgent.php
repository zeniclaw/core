<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CustomAgent extends Model
{
    protected $fillable = [
        'agent_id', 'name', 'description', 'system_prompt',
        'model', 'avatar', 'agent_class', 'is_active', 'settings', 'enabled_tools', 'allowed_peers',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'settings' => 'array',
        'enabled_tools' => 'array',
        'allowed_peers' => 'array',
    ];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(CustomAgentDocument::class);
    }

    public function chunks(): HasMany
    {
        return $this->hasMany(CustomAgentChunk::class);
    }

    public function shares(): HasMany
    {
        return $this->hasMany(CustomAgentShare::class);
    }

    public function skills(): HasMany
    {
        return $this->hasMany(CustomAgentSkill::class);
    }

    public function scripts(): HasMany
    {
        return $this->hasMany(CustomAgentScript::class);
    }

    /**
     * Check if a peer (WhatsApp ID) is authorized to use this agent.
     * If allowed_peers is empty/null, the agent is open to all.
     */
    public function isPeerAllowed(string $peerId): bool
    {
        $peers = $this->allowed_peers ?? [];
        return empty($peers) || in_array($peerId, $peers);
    }

    /**
     * Whether this custom agent is backed by a coded agent class.
     */
    public function isCoded(): bool
    {
        return !empty($this->agent_class) && class_exists($this->agent_class);
    }

    /**
     * Instantiate the coded agent class.
     */
    public function makeCodedAgent(): ?\App\Services\Agents\BaseAgent
    {
        if (!$this->isCoded()) {
            return null;
        }
        return new ($this->agent_class)();
    }

    /**
     * Get the routing key used by the orchestrator: "custom_{id}"
     */
    public function routingKey(): string
    {
        return 'custom_' . $this->id;
    }
}
