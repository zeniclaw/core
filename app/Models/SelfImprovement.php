<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SelfImprovement extends Model
{
    protected $fillable = [
        'agent_id',
        'trigger_message',
        'agent_response',
        'routed_agent',
        'analysis',
        'improvement_title',
        'development_plan',
        'status',
        'admin_notes',
        'sub_agent_id',
    ];

    protected $casts = [
        'analysis' => 'array',
    ];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function subAgent(): BelongsTo
    {
        return $this->belongsTo(SubAgent::class);
    }
}
