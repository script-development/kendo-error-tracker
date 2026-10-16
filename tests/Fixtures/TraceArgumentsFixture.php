<?php

declare(strict_types = 1);

namespace ScriptDevelopment\KendoErrorTracker\Tests\Fixtures;

use LogicException;
use RuntimeException;

use function array_map;

/**
 * Throws through methods that take a name, one of them called back by an
 * internal function, so the trace holds `::`, `->` and `[internal function]`
 * frames.
 */
final class TraceArgumentsFixture
{
    public static function throwFor(string $name): never
    {
        throw new RuntimeException('customer lookup failed');
    }

    public static function lookUp(string $name): never
    {
        self::throwFor($name);
    }

    public function viaInternalCallback(string $name): never
    {
        array_map(static fn(string $value): never => self::lookUp($value), [$name]);

        throw new LogicException('array_map returned');
    }
}
