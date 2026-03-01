<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentMemory extends Model
{
    protected $table = 'agent_memory';
    protected $fillable = ['agent_id', 'type', 'date', 'content'];

    protected $casts = ['date' => 'date'];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }
}
