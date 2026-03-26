<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomAgentSkill extends Model
{
    protected $fillable = [
        'custom_agent_id', 'name', 'description', 'trigger_phrase',
        'routine', 'is_active', 'created_by_share_id',
    ];

    protected $casts = [
        'routine' => 'array',
        'is_active' => 'boolean',
    ];

    public function customAgent(): BelongsTo
    {
        return $this->belongsTo(CustomAgent::class);
    }

    public function createdByShare(): BelongsTo
    {
        return $this->belongsTo(CustomAgentShare::class, 'created_by_share_id');
    }
}
