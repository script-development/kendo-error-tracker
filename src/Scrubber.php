<?php

declare(strict_types = 1);

namespace ScriptDevelopment\KendoErrorTracker;

use function preg_replace;

/**
 * Redacts PII and secrets from outbound error payloads.
 *
 * Scrubbing happens source-side, before send, so the kendo server never
 * receives the raw values. The v1 pattern set covers the leaks seen most
 * often in exception messages and stack traces:
 *
 * - JWTs (any `eyJ...` base64url triple)
 * - HTTP `Bearer <token>` authorization values
 * - Dutch BSN (9 consecutive digits, the citizen service number)
 * - email addresses
 *
 * Each redaction collapses the match to a fixed `[REDACTED:<kind>]` marker so
 * the scrubbed string stays readable while carrying no recoverable value.
 */
final class Scrubber
{
    /** JWT: three base64url segments separated by dots, leading `eyJ` header. */
    private const string JWT = '/eyJ[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+/';

    /** Bearer token: the `Bearer` keyword followed by the credential. */
    private const string BEARER = '/Bearer\s+[A-Za-z0-9._~+\/-]+=*/i';

    /** BSN: exactly 9 digits not bordered by another digit. */
    private const string BSN = '/(?<!\d)\d{9}(?!\d)/';

    /** Email address. */
    private const string EMAIL = '/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/';

    public function scrub(string $value): string
    {
        // JWT and Bearer first: a JWT can contain an `@`, and a Bearer value
        // can contain a 9-digit run — redacting the structured secrets before
        // the looser email/BSN patterns avoids leaving a partial token behind.
        $value = (string) preg_replace(self::JWT, '[REDACTED:jwt]', $value);
        $value = (string) preg_replace(self::BEARER, '[REDACTED:bearer]', $value);
        $value = (string) preg_replace(self::EMAIL, '[REDACTED:email]', $value);

        return (string) preg_replace(self::BSN, '[REDACTED:bsn]', $value);
    }
}
