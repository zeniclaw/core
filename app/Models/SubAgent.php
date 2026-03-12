<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubAgent extends Model
{
    protected $table = 'sub_agents';

    protected $fillable = [
        'project_id',
        'type',
        'requester_phone',
        'status',
        'task_description',
        'branch_name',
        'commit_hash',
        'output_log',
        'result',
        'error_message',
        'api_calls_count',
        'timeout_minutes',
        'pid',
        'is_readonly',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'is_readonly' => 'boolean',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Append a line to the output log incrementally.
     */
    public function appendLog(string $line): void
    {
        $this->output_log = ($this->output_log ?? '') . $line . "\n";
        $this->save();
    }
}
