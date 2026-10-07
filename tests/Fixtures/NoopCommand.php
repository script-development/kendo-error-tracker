<?php

declare(strict_types = 1);

namespace ScriptDevelopment\KendoErrorTracker\Tests\Fixtures;

use Illuminate\Console\Command;

final class NoopCommand extends Command
{
    protected $signature = 'kendo:noop';

    public function handle(): int
    {
        return self::SUCCESS;
    }
}
