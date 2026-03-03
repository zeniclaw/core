<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Project extends Model
{
    protected $fillable = [
        'name',
        'gitlab_url',
        'request_description',
        'requester_phone',
        'requester_name',
        'allowed_phones',
        'notify_groups',
        'agent_id',
        'status',
        'approved_by',
        'rejection_reason',
        'approved_at',
    ];

    protected $casts = [
        'approved_at' => 'datetime',
        'allowed_phones' => 'array',
        'notify_groups' => 'array',
    ];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function subAgents(): HasMany
    {
        return $this->hasMany(SubAgent::class);
    }

    public function latestSubAgent(): HasOne
    {
        return $this->hasOne(SubAgent::class)->latestOfMany();
    }
}
