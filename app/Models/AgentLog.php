<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentLog extends Model
{
    public $timestamps = false;
    protected $fillable = ['agent_id', 'level', 'message', 'context'];

    protected $casts = [
        'context' => 'array',
        'created_at' => 'datetime',
    ];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }
}
