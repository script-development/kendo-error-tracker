<?php

declare(strict_types = 1);

use ScriptDevelopment\KendoErrorTracker\Tests\TestCase;

// Feature tests need a booted Laravel app (Testbench); Unit tests are pure and
// need no application container.
pest()->extend(TestCase::class)->in('Feature');
