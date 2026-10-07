<?php

declare(strict_types = 1);

namespace ScriptDevelopment\KendoErrorTracker\Tests\Fixtures;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;
use ScriptDevelopment\KendoErrorTracker\ErrorTracker;

/**
 * Runs a nested command through Artisan::call(), then reports.
 */
final class CallingCommand extends Command
{
    protected $signature = 'kendo:call';

    public function handle(ErrorTracker $tracker): int
    {
        Artisan::call('kendo:noop');

        $tracker->report(new RuntimeException('after the nested command'));

        return self::SUCCESS;
    }
}
