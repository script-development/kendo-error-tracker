<?php

declare(strict_types = 1);

use ScriptDevelopment\KendoErrorTracker\MemoryLimit;

it('reads a memory_limit value as bytes', function(false|string $value, ?int $bytes): void {
    expect(MemoryLimit::toBytes($value))->toBe($bytes);
})->with([
    'plain bytes' => ['134217728', 134_217_728],
    'K' => ['512K', 524_288],
    'M' => ['128M', 134_217_728],
    'G' => ['2G', 2_147_483_648],
    'lowercase suffix' => ['256m', 268_435_456],
    'surrounding space' => [' 64M ', 67_108_864],
    'zero' => ['0', 0],
    'unlimited' => ['-1', null],
    'other negative' => ['-5M', null],
    'unknown suffix' => ['5T', null],
    'fraction' => ['1.5G', null],
    'empty' => ['', null],
    'unreadable' => [false, null],
    'past PHP_INT_MAX with a suffix' => ['99999999999G', null],
    'past PHP_INT_MAX in plain bytes' => ['99999999999999999999', null],
    'PHP_INT_MAX in plain bytes' => ['9223372036854775807', \PHP_INT_MAX],
    'just below PHP_INT_MAX' => ['9223372036854775806', 9_223_372_036_854_775_806],
    'one past PHP_INT_MAX' => ['9223372036854775808', null],
    'leading zeros' => ['0128M', 134_217_728],
    'all zeros' => ['000', 0],
    'largest suffixed value that fits' => ['8589934591G', 9_223_372_035_781_033_984],
]);
