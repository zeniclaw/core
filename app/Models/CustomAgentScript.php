<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomAgentScript extends Model
{
    protected $fillable = [
        'custom_agent_id', 'name', 'description', 'language',
        'code', 'is_active', 'created_by_share_id',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function customAgent(): BelongsTo
    {
        return $this->belongsTo(CustomAgent::class);
    }

    public function createdByShare(): BelongsTo
    {
        return $this->belongsTo(CustomAgentShare::class, 'created_by_share_id');
    }
}
