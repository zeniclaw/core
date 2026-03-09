<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApiUsageLog extends Model
{
    protected $fillable = [
        'agent_id',
        'requester_phone',
        'caller_agent',
        'api_name',
        'endpoint',
        'method',
        'request_params',
        'response_status',
        'response_time_ms',
        'result_count',
        'error_message',
    ];

    protected $casts = [
        'request_params' => 'array',
        'response_time_ms' => 'integer',
        'response_status' => 'integer',
        'result_count' => 'integer',
    ];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }
}
