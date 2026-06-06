<?php

declare(strict_types = 1);

namespace ScriptDevelopment\KendoErrorTracker;

use const DIRECTORY_SEPARATOR;

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
 */
final readonly class PathNormalizer
{
    private string $prefix;

    public function __construct(string $basePath)
    {
        // Mirror nightwatch: the prefix carries a trailing separator so the
        // strip leaves a clean relative path with no leading slash.
        $this->prefix = $basePath . DIRECTORY_SEPARATOR;
    }

    /**
     * Replace every occurrence of the base-path prefix in the trace string.
     *
     * `getTraceAsString()` embeds absolute file paths inline (`#3 /abs/app/Foo.php(10): ...`),
     * so a global replace of the prefix normalizes every frame at once. Paths
     * that do not start with the prefix (vendor under a symlinked store, the
     * trailing `{main}` marker) are left untouched — exactly nightwatch's
     * "return unchanged when the prefix does not match" behavior.
     */
    public function normalize(string $trace): string
    {
        return str_replace($this->prefix, '', $trace);
    }
}
