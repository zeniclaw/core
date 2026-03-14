<?php

namespace App\Console\Commands;

use App\Jobs\RunTaskJob;
use App\Models\SubAgent;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Cron\CronExpression;

/**
 * Process recurring tasks (D8.3).
 * Run via scheduler every minute: * * * * *
 */
class ProcessRecurringTasks extends Command
{
    protected $signature = 'tasks:process-recurring';
    protected $description = 'Check and spawn recurring tasks based on cron expressions';

    public function handle(): int
    {
        $recurring = SubAgent::where('is_recurring', true)
            ->whereNotNull('cron_expression')
            ->where('status', 'completed') // Only completed ones get re-spawned
            ->get();

        $spawned = 0;

        foreach ($recurring as $task) {
            try {
                $cron = new CronExpression($task->cron_expression);

                if (!$cron->isDue()) {
                    continue;
                }

                // Check if a duplicate is already running
                $alreadyRunning = SubAgent::where('requester_phone', $task->requester_phone)
                    ->where('task_description', $task->task_description)
                    ->whereIn('status', ['queued', 'running'])
                    ->exists();

                if ($alreadyRunning) {
                    continue;
                }

                // Spawn a new instance
                $newTask = SubAgent::create([
                    'type' => $task->type,
                    'requester_phone' => $task->requester_phone,
                    'status' => 'queued',
                    'task_description' => $task->task_description,
                    'timeout_minutes' => $task->timeout_minutes,
                    'spawning_agent' => $task->spawning_agent ?? 'scheduler',
                    'priority' => $task->priority ?? 'normal',
                    'is_recurring' => false, // Instance is not recurring itself
                ]);

                RunTaskJob::dispatch($newTask)->onQueue($newTask->getQueueName());

                Log::info("Recurring task spawned: #{$newTask->id} from template #{$task->id}");
                $spawned++;
            } catch (\Exception $e) {
                Log::error("Failed to process recurring task #{$task->id}: " . $e->getMessage());
            }
        }

        if ($spawned > 0) {
            $this->info("Spawned {$spawned} recurring task(s).");
        }

        return self::SUCCESS;
    }
}
