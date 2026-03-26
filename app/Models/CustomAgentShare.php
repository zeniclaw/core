<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomAgentShare extends Model
{
    protected $fillable = [
        'custom_agent_id', 'token', 'partner_name', 'permissions',
        'expires_at', 'last_accessed_at', 'access_count', 'is_revoked',
    ];

    protected $casts = [
        'permissions' => 'array',
        'expires_at' => 'datetime',
        'last_accessed_at' => 'datetime',
        'is_revoked' => 'boolean',
    ];

    public function customAgent(): BelongsTo
    {
        return $this->belongsTo(CustomAgent::class);
    }

    public function isValid(): bool
    {
        return !$this->is_revoked && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    public function recordAccess(): void
    {
        $this->increment('access_count');
        $this->update(['last_accessed_at' => now()]);
    }

    public function hasPermission(string $permission): bool
    {
        $perms = $this->permissions ?? ['documents', 'chat', 'skills', 'scripts'];
        return in_array($permission, $perms);
    }
}
