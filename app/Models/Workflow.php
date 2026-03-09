<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Workflow extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_phone',
        'agent_id',
        'name',
        'description',
        'steps',
        'triggers',
        'conditions',
        'is_active',
        'last_run_at',
        'run_count',
    ];

    protected $casts = [
        'steps' => 'array',
        'triggers' => 'array',
        'conditions' => 'array',
        'is_active' => 'boolean',
        'last_run_at' => 'datetime',
    ];

    public function agent()
    {
        return $this->belongsTo(Agent::class);
    }

    public function scopeForUser($query, string $phone)
    {
        return $query->where('user_phone', $phone);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
