<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomAgentChunk extends Model
{
    protected $fillable = [
        'document_id', 'custom_agent_id', 'content',
        'embedding', 'chunk_index', 'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function document(): BelongsTo
    {
        return $this->belongsTo(CustomAgentDocument::class, 'document_id');
    }

    public function customAgent(): BelongsTo
    {
        return $this->belongsTo(CustomAgent::class);
    }

    /**
     * Get embedding as float array (stored as JSON in DB for portability).
     */
    public function getEmbeddingVectorAttribute(): ?array
    {
        if (!$this->embedding) return null;
        return json_decode($this->embedding, true);
    }

    /**
     * Set embedding from float array.
     */
    public function setEmbeddingVectorAttribute(array $vector): void
    {
        $this->attributes['embedding'] = json_encode($vector);
    }
}
