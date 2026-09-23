<?php

declare(strict_types = 1);

namespace ScriptDevelopment\KendoErrorTracker\Tests\Fixtures;

use RuntimeException;

/**
 * A named exception whose class name the Scrubber would redact (it carries an
 * eleven-test-valid BSN digit run). Class names are code, not data: the
 * tracker must send them unchanged.
 */
final class Order123456782Exception extends RuntimeException {}
