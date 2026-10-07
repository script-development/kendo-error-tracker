<?php

declare(strict_types = 1);

namespace ScriptDevelopment\KendoErrorTracker\Tests\Fixtures;

use RuntimeException;

final class OrderController
{
    public function show(string $order): never
    {
        throw new RuntimeException('order not shown');
    }
}
