<?php

declare(strict_types = 1);

/*
 * Fixture for the trace-level scrubbing tests. The client sends no argument
 * values and no message in a stack trace, but it does send each frame's FILE
 * PATH, so the scrubable token sits in this file's directory name.
 */

return (static function(): never {
    throw new RuntimeException('error reported from a token-bearing path');
})();
