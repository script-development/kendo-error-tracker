<?php

declare(strict_types = 1);

namespace ScriptDevelopment\KendoErrorTracker\Tests\Fixtures;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;
use ScriptDevelopment\KendoErrorTracker\ErrorTracker;

/**
 * Reports a caught exception and finishes normally.
 */
final class ReportingJob implements ShouldQueue
{
    use Queueable;

    public function handle(ErrorTracker $tracker): void
    {
        $tracker->report(new RuntimeException('caught inside the job'));
    }
}
