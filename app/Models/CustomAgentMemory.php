<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomAgentMemory extends Model
{
    protected $fillable = [
        'custom_agent_id', 'category', 'content', 'source',
    ];

    public function customAgent(): BelongsTo
    {
        return $this->belongsTo(CustomAgent::class);
    }
}
