<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ContentDigestLog extends Model
{
    protected $fillable = [
        'user_phone',
        'categories',
        'article_count',
        'sent_at',
    ];

    protected $casts = [
        'categories' => 'array',
        'sent_at' => 'datetime',
    ];
}
