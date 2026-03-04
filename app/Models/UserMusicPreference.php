<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserMusicPreference extends Model
{
    protected $fillable = [
        'agent_id',
        'phone',
        'favorite_genres',
        'favorite_artists',
        'preferred_mood',
    ];

    protected $casts = [
        'favorite_genres' => 'array',
        'favorite_artists' => 'array',
    ];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }
}
