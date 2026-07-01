<?php

declare(strict_types = 1);

namespace ScriptDevelopment\KendoErrorTracker;

use const DIRECTORY_SEPARATOR;

use function preg_replace;
use function str_replace;

/**
 * Strips the app's own base path from every absolute path in a stack trace.
 *
 * Fingerprint stability is the client's responsibility (KD-0771 D6): the same
 * exception thrown from `/var/www/html/app/Foo.php` and `/home/forge/app/Foo.php`
 * must normalize to the identical `app/Foo.php` so the kendo server hashes them
 * to one fingerprint. The strip is an EXACT prefix removal of `base_path()`,
 * mirroring `laravel/nightwatch`'s `Location::normalizeFile()` — not a guessed
 * list of common deploy-root prefixes (that is the server's best-effort fallback
 * for raw-HTTP callers, never the client's).
 *
 * A frame whose path does NOT start with `base_path()` (vendor installed
 * outside the app root, a globally-installed tool) is left otherwise
 * untouched by that strip, but still leaks the OS username via `/home/<user>/`
 * or `/Users/<user>/` (M-3). A secondary redaction pass replaces just the
 * username segment of those two shapes, everywhere in the trace, after the
 * exact-prefix strip runs.
 */
final readonly class PathNormalizer
{
    private const string HOME_USERNAME = '#/home/[^/\s]+/#';

    private const string MAC_USERNAME = '#/Users/[^/\s]+/#';

    private string $prefix;

    public function __construct(string $basePath)
    {
        // Mirror nightwatch: the prefix carries a trailing separator so the
        // strip leaves a clean relative path with no leading slash.
        $this->prefix = $basePath . DIRECTORY_SEPARATOR;
    }

    /**
     * Replace every occurrence of the base-path prefix in the trace string,
     * then redact the username segment of any remaining `/home/<user>/` or
     * `/Users/<user>/` path that did not start with the prefix.
     *
     * `getTraceAsString()` embeds absolute file paths inline (`#3 /abs/app/Foo.php(10): ...`),
     * so a global replace of the prefix normalizes every frame at once. Paths
     * that do not start with the prefix (vendor under a symlinked store, the
     * trailing `{main}` marker) are left otherwise untouched — exactly
     * nightwatch's "return unchanged when the prefix does not match" behavior
     * — but still pass through the username-redaction fallback below.
     */
    public function normalize(string $trace): string
    {
        $trace = str_replace($this->prefix, '', $trace);
        $trace = (string) preg_replace(self::HOME_USERNAME, '/home/[REDACTED:user]/', $trace);

        return (string) preg_replace(self::MAC_USERNAME, '/Users/[REDACTED:user]/', $trace);
    }
}
