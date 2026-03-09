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
    ];

    protected $casts = [
        'tags' => 'array',
        'expires_at' => 'datetime',
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
}
