<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ProviderFallback
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly string $failedModel,
        public readonly string $fallbackModel,
        public readonly string $reason,
        public readonly int $httpStatus,
    ) {}
}
