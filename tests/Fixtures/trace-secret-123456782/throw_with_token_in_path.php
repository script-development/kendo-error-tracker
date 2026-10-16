<?php

declare(strict_types = 1);

/*
 * Fixture for the stack-trace scrubbing test. The client sends no argument
 * values and no message in a stack trace, but it does send each frame's FILE
 * PATH, so an eleven-test-valid BSN token (123456782) sits in this file's
 * directory name.
 */
return (static function(): never {
    throw new RuntimeException('error reported from a token-bearing path');
})();
