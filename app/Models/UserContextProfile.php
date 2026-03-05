<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserContextProfile extends Model
{
    protected $fillable = [
        'user_phone',
        'facts',
        'last_updated_at',
    ];

    protected $casts = [
        'facts' => 'array',
        'last_updated_at' => 'datetime',
    ];
}
