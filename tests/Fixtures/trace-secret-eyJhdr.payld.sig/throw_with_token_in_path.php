<?php

declare(strict_types = 1);

/*
 * Fixture for the trace-level scrubbing tests. getTraceAsString() elides
 * argument values and the message but embeds each frame's defining FILE PATH,
 * so the scrubable token planted in this file's directory name genuinely
 * appears in the trace string before scrubbing runs.
 */

return (static function(): never {
    throw new RuntimeException('error reported from a token-bearing path');
})();
