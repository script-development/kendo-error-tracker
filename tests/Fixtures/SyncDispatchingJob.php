<?php

declare(strict_types = 1);

namespace ScriptDevelopment\KendoErrorTracker\Tests\Fixtures;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Queue;

/**
 * Runs a failing job on the sync queue and lets its exception bubble up.
 */
final class SyncDispatchingJob implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        Queue::connection('sync')->push(new FailingJob);
    }
}
