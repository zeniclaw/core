<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserBriefPreference extends Model
{
    protected $fillable = [
        'user_phone',
        'brief_time',
        'enabled',
        'preferred_sections',
        'weather_city',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'preferred_sections' => 'array',
    ];
}
