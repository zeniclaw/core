<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Flashcard extends Model
{
    protected $fillable = [
        'user_phone',
        'agent_id',
        'deck_name',
        'question',
        'answer',
        'ease_factor',
        'interval',
        'next_review_at',
        'repetitions',
        'last_reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'ease_factor' => 'float',
            'interval' => 'integer',
            'repetitions' => 'integer',
            'next_review_at' => 'datetime',
            'last_reviewed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_phone', 'phone');
    }

    public function deck(): BelongsTo
    {
        return $this->belongsTo(FlashcardDeck::class, 'deck_name', 'name')
            ->where('user_phone', $this->user_phone)
            ->where('agent_id', $this->agent_id);
    }
}
