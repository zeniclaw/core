<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MusicListenHistory extends Model
{
    protected $table = 'music_listen_history';

    protected $fillable = [
        'agent_id',
        'user_phone',
        'song_name',
        'artist',
        'action',
        'spotify_url',
    ];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }
}
