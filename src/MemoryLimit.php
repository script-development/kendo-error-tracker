<?php

declare(strict_types = 1);

namespace ScriptDevelopment\KendoErrorTracker;

use const PHP_INT_MAX;

use function mb_strtoupper;
use function preg_match;

/**
 * Turns a `memory_limit` ini value into bytes.
 */
final class MemoryLimit
{
    private const array MULTIPLIERS = ['' => 1, 'K' => 1_024, 'M' => 1_048_576, 'G' => 1_073_741_824];

    /**
     * Bytes for a whole number with an optional K, M or G suffix, as PHP
     * reads it. Null for `-1` (unlimited), for any other negative or
     * unparsable value, and for a value at or past PHP_INT_MAX. The bound is
     * checked on a float, because an int cast saturates at PHP_INT_MAX and would
     * pass an overflowing value off as PHP_INT_MAX.
     */
    public static function toBytes(false|string $value): ?int
    {
        if ($value === false || preg_match('/\A\s*(\d+)\s*([kmg]?)\s*\z/i', $value, $matches) !== 1) {
            return null;
        }

        $multiplier = self::MULTIPLIERS[mb_strtoupper($matches[2])];

        return (float) $matches[1] * $multiplier >= PHP_INT_MAX ? null : (int) $matches[1] * $multiplier;
    }
}
