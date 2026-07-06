<?php

declare(strict_types = 1);

/*
 * Fixture for the stack-trace scrubbing test.
 *
 * PHP's getTraceAsString() elides argument *values*, the exception message, and
 * previous-exception messages — a token planted as an argument or in the message
 * never reaches the trace string (which would make a scrub assertion false-green).
 * It DOES embed the defining *file path* of each frame. This fixture therefore
 * lives at a path carrying a scrubable, eleven-test-valid BSN token (123456782)
 * so the raw token genuinely appears in getTraceAsString() before scrubbing
 * runs.
 */

return (static function(): never {
    throw new RuntimeException('error reported from a token-bearing path');
})();
