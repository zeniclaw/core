<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MusicWishlist extends Model
{
    protected $table = 'music_wishlist';

    protected $fillable = [
        'agent_id',
        'user_phone',
        'song_name',
        'artist',
        'album',
        'spotify_url',
        'duration_ms',
        'spotify_id',
    ];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }
}
