<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HangmanGame extends Model
{
    protected $fillable = [
        'user_phone',
        'agent_id',
        'word',
        'guessed_letters',
        'wrong_count',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'guessed_letters' => 'array',
            'wrong_count' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_phone', 'phone');
    }

    public function getMaskedWord(): string
    {
        $guessed = $this->guessed_letters ?? [];
        $word = mb_strtoupper($this->word);
        $masked = '';

        for ($i = 0; $i < mb_strlen($word); $i++) {
            $char = mb_substr($word, $i, 1);
            if ($char === ' ' || $char === '-' || $char === "'") {
                $masked .= $char;
            } elseif (in_array(mb_strtoupper($char), array_map('mb_strtoupper', $guessed))) {
                $masked .= $char;
            } else {
                $masked .= '_';
            }
        }

        return $masked;
    }

    public function isWon(): bool
    {
        return !str_contains($this->getMaskedWord(), '_');
    }

    public function isLost(): bool
    {
        return $this->wrong_count >= 6;
    }
}
