<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Agent extends Model
{
    use HasFactory;

    protected $fillable = ['user_id', 'name', 'description', 'system_prompt', 'model', 'status', 'whitelist_enabled'];

    protected $casts = [
        'whitelist_enabled' => 'boolean',
    ];

    public function user(): BelongsTo        { return $this->belongsTo(User::class); }
    public function secrets(): HasMany       { return $this->hasMany(AgentSecret::class); }
    public function reminders(): HasMany     { return $this->hasMany(Reminder::class); }
    public function logs(): HasMany          { return $this->hasMany(AgentLog::class); }
    public function memory(): HasMany        { return $this->hasMany(AgentMemory::class, 'agent_id'); }
    public function sessions(): HasMany      { return $this->hasMany(AgentSession::class); }
}
