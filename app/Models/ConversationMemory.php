<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ConversationMemory extends Model
{
    protected $table = 'conversation_memories';

    protected $fillable = [
        'user_id',
        'fact_type',
        'content',
        'tags',
        'status',
        'expires_at',
        'version',
        'previous_content',
    ];

    protected $casts = [
        'tags' => 'array',
        'expires_at' => 'datetime',
        'version' => 'integer',
    ];

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeForUser($query, string $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeNotExpired($query)
    {
        return $query->where(function ($q) {
            $q->whereNull('expires_at')
              ->orWhere('expires_at', '>', now());
        });
    }

    /**
     * Full-text search on content.
     * Uses PostgreSQL full-text search (tsvector) when available,
     * falls back to ILIKE for compatibility.
     */
    public function scopeSearch($query, string $keyword)
    {
        if (config('database.default') === 'pgsql') {
            // Use PostgreSQL full-text search with ranking
            return $query->whereRaw(
                "to_tsvector('simple', content) @@ plainto_tsquery('simple', ?)",
                [$keyword]
            )->orderByRaw(
                "ts_rank(to_tsvector('simple', content), plainto_tsquery('simple', ?)) DESC",
                [$keyword]
            );
        }

        // Fallback to LIKE for other databases
        return $query->where('content', 'like', '%' . $keyword . '%');
    }

    /**
     * Update content with versioning — keeps history of previous values.
     */
    public function updateContent(string $newContent): self
    {
        $this->previous_content = $this->content;
        $this->content = $newContent;
        $this->version = ($this->version ?? 1) + 1;
        $this->save();

        return $this;
    }
}
