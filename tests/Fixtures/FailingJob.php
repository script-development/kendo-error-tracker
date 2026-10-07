<?php

declare(strict_types = 1);

namespace ScriptDevelopment\KendoErrorTracker\Tests\Fixtures;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;

final class FailingJob implements ShouldQueue
{
    use Queueable;

    public function handle(): never
    {
        throw new RuntimeException('job failed');
    }
}
