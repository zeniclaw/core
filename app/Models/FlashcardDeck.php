<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FlashcardDeck extends Model
{
    protected $fillable = [
        'user_phone',
        'agent_id',
        'name',
        'description',
        'language',
        'difficulty',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_phone', 'phone');
    }

    public function flashcards(): HasMany
    {
        return $this->hasMany(Flashcard::class, 'deck_name', 'name')
            ->where('user_phone', $this->user_phone)
            ->where('agent_id', $this->agent_id);
    }

    public function cardCount(): int
    {
        return Flashcard::where('user_phone', $this->user_phone)
            ->where('agent_id', $this->agent_id)
            ->where('deck_name', $this->name)
            ->count();
    }

    public function dueCount(): int
    {
        return Flashcard::where('user_phone', $this->user_phone)
            ->where('agent_id', $this->agent_id)
            ->where('deck_name', $this->name)
            ->where('next_review_at', '<=', now())
            ->count();
    }
}
