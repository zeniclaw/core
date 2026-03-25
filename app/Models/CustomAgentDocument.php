<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CustomAgentDocument extends Model
{
    protected $fillable = [
        'custom_agent_id', 'title', 'type', 'source',
        'raw_content', 'chunk_count', 'status', 'error_message',
    ];

    public function customAgent(): BelongsTo
    {
        return $this->belongsTo(CustomAgent::class);
    }

    public function chunks(): HasMany
    {
        return $this->hasMany(CustomAgentChunk::class, 'document_id');
    }
}
