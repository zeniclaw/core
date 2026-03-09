<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserPreference extends Model
{
    protected $fillable = [
        'user_id',
        'language',
        'timezone',
        'date_format',
        'unit_system',
        'communication_style',
        'notification_enabled',
        'phone',
        'email',
    ];

    protected $casts = [
        'notification_enabled' => 'boolean',
    ];

    public static array $validKeys = [
        'language',
        'timezone',
        'date_format',
        'unit_system',
        'communication_style',
        'notification_enabled',
        'phone',
        'email',
    ];

    public static array $defaults = [
        'language' => 'fr',
        'timezone' => 'Europe/Paris',
        'date_format' => 'd/m/Y',
        'unit_system' => 'metric',
        'communication_style' => 'friendly',
        'notification_enabled' => true,
        'phone' => null,
        'email' => null,
    ];
}
