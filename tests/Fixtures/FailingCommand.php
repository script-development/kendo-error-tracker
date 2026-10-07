<?php

declare(strict_types = 1);

namespace ScriptDevelopment\KendoErrorTracker\Tests\Fixtures;

use Illuminate\Console\Command;
use RuntimeException;

final class FailingCommand extends Command
{
    protected $signature = 'kendo:fail';

    public function handle(): never
    {
        throw new RuntimeException('command failed');
    }
}
