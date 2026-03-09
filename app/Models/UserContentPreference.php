<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserContentPreference extends Model
{
    protected $fillable = [
        'user_phone',
        'category',
        'keywords',
        'sources',
    ];

    protected $casts = [
        'keywords' => 'array',
        'sources' => 'array',
    ];
}
