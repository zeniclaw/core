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

    public function credentials(): HasMany
    {
        return $this->hasMany(CustomAgentCredential::class);
    }

    public function memories(): HasMany
    {
        return $this->hasMany(CustomAgentMemory::class);
    }

    public function endpoints(): HasMany
    {
        return $this->hasMany(CustomAgentEndpoint::class);
    }

    /**
     * Get a decrypted credential value by key. Only accessible by the agent itself.
     */
    public function getCredential(string $key): ?string
    {
        $cred = $this->credentials()->where('key', $key)->where('is_active', true)->first();
        return $cred?->decrypted_value;
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
        return !empty($this->agent_class) && class_exists($this->resolvedAgentClass());
    }

    /**
     * Resolve the agent class to a FQCN.
     */
    private function resolvedAgentClass(): string
    {
        $class = $this->agent_class ?? '';
        if ($class && !str_contains($class, '\\')) {
            $class = "App\\Services\\Agents\\{$class}";
        }
        return $class;
    }

    /**
     * Instantiate the coded agent class.
     */
    public function makeCodedAgent(): ?\App\Services\Agents\BaseAgent
    {
        $class = $this->resolvedAgentClass();
        if (!$class || !class_exists($class)) {
            return null;
        }
        return new $class();
    }

    /**
     * Get the routing key used by the orchestrator: "custom_{id}"
     */
    public function routingKey(): string
    {
        return 'custom_' . $this->id;
    }

    /**
     * Get the isolated workspace path for this agent.
     * Creates the directory if it doesn't exist.
     */
    public function workspacePath(string $subdir = ''): string
    {
        $base = storage_path("app/private/custom-agents/{$this->id}");
        $path = $subdir ? "{$base}/{$subdir}" : $base;

        if (!is_dir($path)) {
            mkdir($path, 0775, true);
        }

        return $path;
    }

    /**
     * Get the relative storage path for this agent (for Laravel Storage facade).
     */
    public function storagePath(string $subdir = ''): string
    {
        $base = "custom-agents/{$this->id}";
        return $subdir ? "{$base}/{$subdir}" : $base;
    }
}
