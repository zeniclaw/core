<?php

namespace App\Providers;

use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

class EventServiceProvider extends ServiceProvider
{
    /**
     * The event to listener mappings for the application.
     */
    protected $listen = [
        'message.reaction' => [
            \App\Listeners\ReactionVoteListener::class,
        ],
        \App\Events\AfterToolCall::class => [
            \App\Listeners\ToolCallMetricsListener::class,
            \App\Listeners\AuditLogListener::class,
        ],
        // New lifecycle events (D15.1, D12.1)
        \App\Events\BeforeRouting::class => [],
        \App\Events\AfterRouting::class => [],
        \App\Events\MessageReceived::class => [],
        \App\Events\MessageSent::class => [],
        \App\Events\SubagentSpawned::class => [],
        \App\Events\SubagentEnded::class => [],
    ];

    public function boot(): void
    {
        parent::boot();
    }
}
