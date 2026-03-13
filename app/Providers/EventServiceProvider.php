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
        ],
    ];

    public function boot(): void
    {
        parent::boot();
    }
}
