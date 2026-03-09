<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Quiz extends Model
{
    protected $fillable = [
        'user_phone',
        'agent_id',
        'category',
        'difficulty',
        'questions',
        'current_question_index',
        'correct_answers',
        'status',
        'challenger_phone',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'questions' => 'array',
            'current_question_index' => 'integer',
            'correct_answers' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function scores(): HasMany
    {
        return $this->hasMany(QuizScore::class);
    }

    public function getCurrentQuestion(): ?array
    {
        $questions = $this->questions ?? [];
        $index = $this->current_question_index ?? 0;

        return $questions[$index] ?? null;
    }

    public function getTotalQuestions(): int
    {
        return count($this->questions ?? []);
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }
}
