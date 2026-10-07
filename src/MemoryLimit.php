<?php

declare(strict_types = 1);

namespace ScriptDevelopment\KendoErrorTracker;

use const FILTER_VALIDATE_INT;
use const PHP_INT_MAX;

use function filter_var;
use function intdiv;
use function mb_ltrim;
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
     * unparsable value, and for a value past PHP_INT_MAX. The digits are read
     * with FILTER_VALIDATE_INT, which refuses an overflow, because an int cast
     * saturates at PHP_INT_MAX and would pass the overflow off as that value.
     */
    public static function toBytes(false|string $value): ?int
    {
        if ($value === false || preg_match('/\A\s*(\d+)\s*([kmg]?)\s*\z/i', $value, $matches) !== 1) {
            return null;
        }

        $number = filter_var(mb_ltrim($matches[1], '0') ?: '0', FILTER_VALIDATE_INT);
        $multiplier = self::MULTIPLIERS[mb_strtoupper($matches[2])];

        return $number === false || $number > intdiv(PHP_INT_MAX, $multiplier) ? null : $number * $multiplier;
    }
}
