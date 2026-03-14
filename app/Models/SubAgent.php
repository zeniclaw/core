<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubAgent extends Model
{
    protected $table = 'sub_agents';

    protected $fillable = [
        'project_id',
        'parent_id',
        'type',
        'requester_phone',
        'spawning_agent',
        'depth',
        'status',
        'progress_percent',
        'progress_message',
        'task_description',
        'next_task_description',
        'branch_name',
        'commit_hash',
        'output_log',
        'result',
        'error_message',
        'api_calls_count',
        'timeout_minutes',
        'priority',
        'cron_expression',
        'is_recurring',
        'pid',
        'is_readonly',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'is_readonly' => 'boolean',
        'is_recurring' => 'boolean',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * Scope: active (queued or running) subagents for a user.
     */
    public function scopeActiveForUser($query, string $phone)
    {
        return $query->where('requester_phone', $phone)
            ->whereIn('status', ['queued', 'running']);
    }

    /**
     * Append a line to the output log incrementally.
     */
    public function appendLog(string $line): void
    {
        $this->output_log = ($this->output_log ?? '') . $line . "\n";
        $this->save();
    }

    /**
     * Update progress (D8.2 - Progress reporting).
     */
    public function updateProgress(int $percent, ?string $message = null): void
    {
        $this->progress_percent = min(100, max(0, $percent));
        if ($message) {
            $this->progress_message = $message;
        }
        $this->save();
    }

    /**
     * Chain a follow-up task (D8.1 - Task chaining).
     * When this task completes, the next_task_description will be spawned.
     */
    public function chainTask(string $nextTaskDescription): void
    {
        $this->next_task_description = $nextTaskDescription;
        $this->save();
    }

    /**
     * Spawn the chained task if one exists.
     */
    public function spawnChainedTask(): ?self
    {
        if (!$this->next_task_description) {
            return null;
        }

        return self::create([
            'type' => $this->type,
            'requester_phone' => $this->requester_phone,
            'status' => 'queued',
            'task_description' => $this->next_task_description,
            'timeout_minutes' => $this->timeout_minutes,
            'parent_id' => $this->id,
            'spawning_agent' => $this->spawning_agent,
            'depth' => ($this->depth ?? 0) + 1,
            'priority' => $this->priority ?? 'normal',
        ]);
    }

    /**
     * Get the queue name based on priority (D8.4 - Priority queues).
     */
    public function getQueueName(): string
    {
        return match ($this->priority ?? 'normal') {
            'high' => 'high',
            'low' => 'low',
            default => 'default',
        };
    }
}
