<?php

declare(strict_types = 1);

use ScriptDevelopment\KendoErrorTracker\Tests\TestCase;

// Feature tests need a booted Laravel app (Testbench); Unit tests are pure and
// need no application container.
pest()->extend(TestCase::class)->in('Feature');

/**
 * Throw + catch a RuntimeException from a fixture file whose path carries a
 * BSN-shaped token, so the captured Throwable's getTraceAsString() genuinely
 * contains the token (in the frame's file path) before scrubbing runs.
 */
function captureThrowableFromTokenBearingPath(): Throwable
{
    try {
        require __DIR__ . '/Fixtures/trace-secret-123456789/throw_with_token_in_path.php';
    } catch (Throwable $throwable) {
        return $throwable;
    }

    throw new RuntimeException('fixture did not throw');
}
