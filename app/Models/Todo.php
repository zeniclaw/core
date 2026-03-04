<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Todo extends Model
{
    protected $fillable = ['agent_id', 'requester_phone', 'requester_name', 'list_name', 'title', 'category', 'priority', 'due_at', 'is_done', 'reminder_id'];

    protected $casts = [
        'is_done' => 'boolean',
        'due_at' => 'datetime',
    ];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function reminder(): BelongsTo
    {
        return $this->belongsTo(Reminder::class);
    }
}
