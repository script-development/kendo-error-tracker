<?php

declare(strict_types = 1);

use ScriptDevelopment\KendoErrorTracker\Tests\TestCase;

// Feature tests need a booted Laravel app (Testbench); Unit tests are pure and
// need no application container.
pest()->extend(TestCase::class)->in('Feature');

/**
 * Throw + catch a RuntimeException from a fixture file whose path carries an
 * eleven-test-valid BSN token, so the captured Throwable's getTraceAsString()
 * genuinely contains the token (in the frame's file path) before scrubbing
 * runs.
 */
function captureThrowableFromTokenBearingPath(): Throwable
{
    return captureThrowableFromFixture('trace-secret-123456782');
}

/**
 * Throw + catch a RuntimeException from a fixture directory whose NAME carries a
 * scrubable token. getTraceAsString() embeds each frame's defining file path, so
 * the token in the directory name genuinely appears in the trace string before
 * scrubbing runs — letting us prove a token of any kind (email / JWT / Bearer /
 * BSN) is scrubbed when it surfaces inside a stack trace, not just a message.
 */
function captureThrowableFromFixture(string $fixtureDir): Throwable
{
    try {
        require __DIR__ . '/Fixtures/' . $fixtureDir . '/throw_with_token_in_path.php';
    } catch (Throwable $throwable) {
        return $throwable;
    }

    throw new RuntimeException('fixture did not throw');
}
