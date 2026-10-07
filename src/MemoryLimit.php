<?php

declare(strict_types = 1);

namespace ScriptDevelopment\KendoErrorTracker;

use const PHP_INT_MAX;

use function intdiv;
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
     * unparsable value, and for a value past PHP_INT_MAX.
     */
    public static function toBytes(false|string $value): ?int
    {
        if ($value === false || preg_match('/\A\s*(\d+)\s*([kmg]?)\s*\z/i', $value, $matches) !== 1) {
            return null;
        }

        $number = (int) $matches[1];
        $multiplier = self::MULTIPLIERS[mb_strtoupper($matches[2])];

        return $number > intdiv(PHP_INT_MAX, $multiplier) ? null : $number * $multiplier;
    }
}
