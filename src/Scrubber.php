<?php

declare(strict_types = 1);

namespace ScriptDevelopment\KendoErrorTracker;

use function preg_replace;
use function preg_replace_callback;
use function str_replace;

/**
 * Redacts PII and secrets from outbound error payloads.
 *
 * Scrubbing happens source-side, before send, so the kendo server never
 * receives the raw values. The v1 pattern set covers the leaks seen most
 * often in exception messages and stack traces:
 *
 * - JWTs (any `eyJ...` base64url triple)
 * - HTTP `Bearer <token>` authorization values (and bare reuse of the same
 *   credential value elsewhere in the string)
 * - Dutch BSN (9-digit citizen service number, including grouped forms and
 *   runs embedded in a longer numeric string)
 * - email addresses (including IDN / unicode local parts)
 *
 * Each redaction collapses the match to a fixed `[REDACTED:<kind>]` marker so
 * the scrubbed string stays readable while carrying no recoverable value.
 *
 * Scope boundaries (deferred to v1.5):
 * - JWT detection requires the conventional `eyJ` base64url header (the encoded
 *   `{"` JSON object start). Non-`eyJ` / truncated tokens are out of scope here;
 *   widening to every JWT shape risks redacting innocuous dotted identifiers.
 * - Quoted-local-part emails (`"weird name"@example.com`) carrying an internal
 *   space are not fully covered — the unicode class deliberately excludes the
 *   space to avoid swallowing surrounding prose. IDN domains and unicode (but
 *   unquoted) local parts ARE covered.
 * - BSN widening is regex-only: it accepts increased over-redaction of legit
 *   9-digit IDs. The elfproef (11-proef) validator that resolves both the
 *   under- and over-detection directions together is deferred to v1.5.
 */
final class Scrubber
{
    /** JWT: three base64url segments separated by dots, leading `eyJ` header. */
    private const string JWT = '/eyJ[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+/';

    /**
     * Bearer token: the `Bearer` keyword, bounded horizontal whitespace (no
     * newline span), then the credential captured in group 1.
     *
     * The credential class deliberately omits `/` so a stray `Bearer ` in front
     * of a URL path (`Bearer /api/v1/users/123`) does not consume the path
     * (M-4). JWT/opaque tokens use `A-Za-z0-9 . _ ~ + -` with optional `=`
     * base64 padding.
     */
    private const string BEARER = '/Bearer[ \t]+([A-Za-z0-9._~+-]+=*)/i';

    /**
     * BSN: a 9-digit citizen service number. Two shapes:
     *  - grouped with `.`, space, or `-` separators (`123.456.782`,
     *    `123 456 782`, `123-456-782`);
     *  - any run of 9-or-more consecutive digits, which also catches a 9-digit
     *    BSN embedded in a longer numeric string (`0612345678`).
     *
     * NOTE (tradeoff): widening to 9-or-more digits over-redacts legitimate
     * 9+-digit IDs. This is intentional for now — the elfproef (11-proef)
     * checksum validator that would distinguish a real BSN from an arbitrary
     * digit run (resolving BOTH the missed-detection and over-redaction
     * directions) is deferred to v1.5.
     */
    private const string BSN = '/\d{3}[.\s-]\d{3}[.\s-]\d{3}|\d{9,}/';

    /**
     * Email address. The `u` flag plus `\p{L}\p{N}` classes cover IDN domains
     * (`jan@müller.nl`) and unicode local parts; ASCII-only matching previously
     * leaked both.
     */
    private const string EMAIL = '/[\p{L}\p{N}._%+-]+@[\p{L}\p{N}.-]+\.[\p{L}]{2,}/u';

    public function scrub(string $value): string
    {
        // JWT and Bearer first: a JWT can contain an `@`, and a Bearer value
        // can contain a 9-digit run — redacting the structured secrets before
        // the looser email/BSN patterns avoids leaving a partial token behind.
        $value = (string) preg_replace(self::JWT, '[REDACTED:jwt]', $value);
        $value = $this->scrubBearer($value);
        $value = (string) preg_replace(self::EMAIL, '[REDACTED:email]', $value);

        return (string) preg_replace(self::BSN, '[REDACTED:bsn]', $value);
    }

    /**
     * Redact `Bearer <token>` values, then redact any bare reuse of the same
     * credential value elsewhere in the string (H-2): a credential that appears
     * once prefixed with `Bearer` and again unprefixed (`token=<same>`) would
     * otherwise leak on the second occurrence.
     */
    private function scrubBearer(string $value): string
    {
        $credentials = [];

        $value = (string) preg_replace_callback(
            self::BEARER,
            static function(array $matches) use (&$credentials): string {
                $credentials[] = $matches[1];

                return '[REDACTED:bearer]';
            },
            $value,
        );

        // The credential class requires at least one character, so each capture
        // is non-empty — a bare str_replace cannot blank-match the whole string.
        foreach ($credentials as $credential) {
            $value = str_replace($credential, '[REDACTED:bearer]', $value);
        }

        return $value;
    }
}
