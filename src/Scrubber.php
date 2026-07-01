<?php

declare(strict_types = 1);

namespace ScriptDevelopment\KendoErrorTracker;

use function mb_strlen;
use function mb_substr;
use function preg_match;
use function preg_replace;
use function preg_replace_callback;
use function str_replace;

/**
 * Redacts PII and secrets from outbound error payloads.
 *
 * Scrubbing happens source-side, before send, so the kendo server never
 * receives the raw values. The pattern set covers the leaks seen most often
 * in exception messages and stack traces:
 *
 * - JWTs (any `eyJ...` base64url triple)
 * - HTTP `Bearer <token>` authorization values (and bare reuse of the same
 *   credential value elsewhere in the string)
 * - Database DSN passwords (`scheme://user:pass@host`)
 * - API-key prefixes (`sk_live_...`, `AKIA...`)
 * - IPv4 addresses
 * - Dutch BSN (9-digit citizen service number, including grouped forms and
 *   runs embedded in a longer numeric string), validated with the
 *   eleven-test (Dutch: elfproef) checksum so an arbitrary 9+-digit ID is
 *   not falsely redacted
 * - email addresses (including IDN / unicode local parts)
 *
 * Each redaction collapses the match to a fixed `[REDACTED:<kind>]` marker so
 * the scrubbed string stays readable while carrying no recoverable value.
 *
 * Scope boundaries (deferred beyond v1.5):
 * - JWT detection requires the conventional `eyJ` base64url header (the encoded
 *   `{"` JSON object start). Non-`eyJ` / truncated tokens are out of scope here;
 *   widening to every JWT shape risks redacting innocuous dotted identifiers.
 * - Quoted-local-part emails (`"weird name"@example.com`) carrying an internal
 *   space are not fully covered — the unicode class deliberately excludes the
 *   space to avoid swallowing surrounding prose. IDN domains and unicode (but
 *   unquoted) local parts ARE covered.
 * - Free-text PII (a name, address, or care-data value embedded in a message —
 *   e.g. a `QueryException`'s bound parameters) is not a regex-able secret
 *   shape and is out of scope for this scrubber; see the per-exception-type
 *   carrier-strip in `ErrorTracker::buildPayload()` for that class of leak.
 * - Phone numbers and session IDs remain out of scope (no shape distinct
 *   enough from other numeric/opaque identifiers to redact without a high
 *   false-positive rate).
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
     * DSN userinfo: `scheme://user:pass@`. Only the password (group 2) is
     * redacted — the scheme and username carry little on their own, and
     * keeping them intact keeps the scrubbed string legible. This is the
     * general URI-userinfo shape, not a fixed list of DB scheme names, so it
     * also catches non-DB DSNs that embed the same credential shape.
     */
    private const string DSN_PASSWORD = '/([a-zA-Z][a-zA-Z0-9+.-]*:\/\/[^:\/?#\s@]+:)([^@\/?#\s]+)(@)/';

    /** Stripe-style live secret key prefix. */
    private const string API_KEY_STRIPE = '/\bsk_live_[A-Za-z0-9]{10,}\b/';

    /** AWS access key ID: `AKIA` followed by exactly 16 uppercase alnum chars. */
    private const string API_KEY_AWS = '/\bAKIA[0-9A-Z]{16}\b/';

    /** IPv4 address, each octet bounded to 0-255. */
    private const string IPV4 = '/\b(?:(?:25[0-5]|2[0-4]\d|1\d\d|[1-9]?\d)\.){3}(?:25[0-5]|2[0-4]\d|1\d\d|[1-9]?\d)\b/';

    /**
     * Email address. The `u` flag plus `\p{L}\p{N}` classes cover IDN domains
     * (`jan@müller.nl`) and unicode local parts; ASCII-only matching previously
     * leaked both.
     */
    private const string EMAIL = '/[\p{L}\p{N}._%+-]+@[\p{L}\p{N}.-]+\.[\p{L}]{2,}/u';

    /** BSN candidate: 3-3-3 digits grouped with `.`, space, or `-` separators. */
    private const string BSN_GROUPED = '/\d{3}[.\s-]\d{3}[.\s-]\d{3}/';

    /** BSN candidate: any run of 9-or-more consecutive digits. */
    private const string BSN_RUN = '/\d{9,}/';

    public function scrub(string $value): string
    {
        // Structured secrets first, loosest patterns last: a JWT can contain
        // an `@`, and a digit run can border a credential — redacting the
        // narrower shapes first avoids leaving a partial secret behind or
        // having a looser pattern consume part of a stricter match.
        $value = (string) preg_replace(self::JWT, '[REDACTED:jwt]', $value);
        $value = $this->scrubBearer($value);
        $value = (string) preg_replace(self::DSN_PASSWORD, '$1[REDACTED:dsn-password]$3', $value);
        $value = (string) preg_replace(self::API_KEY_STRIPE, '[REDACTED:api-key]', $value);
        $value = (string) preg_replace(self::API_KEY_AWS, '[REDACTED:api-key]', $value);
        $value = (string) preg_replace(self::IPV4, '[REDACTED:ip]', $value);
        $value = (string) preg_replace(self::EMAIL, '[REDACTED:email]', $value);

        return $this->scrubBsn($value);
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

    /**
     * Redact BSN candidates that pass the eleven-test (Dutch: elfproef)
     * checksum.
     *
     * Grouped forms are validated whole (all 9 digits must pass); bare digit
     * runs are scanned for any contiguous 9-digit window that passes, so a
     * real BSN embedded in a longer number (`0612345678`-shaped) is caught
     * without redacting the surrounding digits that are not part of it. A
     * candidate that fails the checksum is left untouched — resolving the
     * KD-0885 M-1 over-redaction of legitimate 9+-digit IDs.
     */
    private function scrubBsn(string $value): string
    {
        $value = (string) preg_replace_callback(
            self::BSN_GROUPED,
            fn(array $matches): string => $this->elevenTest((string) preg_replace('/\D/', '', $matches[0]))
                ? '[REDACTED:bsn]'
                : $matches[0],
            $value,
        );

        return (string) preg_replace_callback(
            self::BSN_RUN,
            fn(array $matches): string => $this->redactValidBsnWindows($matches[0]),
            $value,
        );
    }

    /**
     * Scan a run of consecutive digits left to right, redacting each
     * non-overlapping 9-digit window that passes the eleven-test checksum and
     * leaving every other digit untouched.
     */
    private function redactValidBsnWindows(string $run): string
    {
        $length = mb_strlen($run);
        $result = '';
        $i = 0;

        while ($i < $length) {
            $window = $length - $i >= 9 ? mb_substr($run, $i, 9) : null;

            if ($window !== null && $this->elevenTest($window)) {
                $result .= '[REDACTED:bsn]';
                $i += 9;

                continue;
            }

            $result .= $run[$i];
            $i++;
        }

        return $result;
    }

    /**
     * The eleven-test (Dutch: elfproef) checksum: weight digits 9..2 for
     * positions 1-8 and -1 for position 9, sum, and require the total to be a
     * non-zero multiple of 11. `000000000` satisfies the modulo trivially but
     * is not a real BSN, hence the non-zero guard.
     */
    private function elevenTest(string $digits): bool
    {
        if (preg_match('/^\d{9}$/', $digits) !== 1) {
            return false;
        }

        $sum = 0;

        for ($position = 0; $position < 9; $position++) {
            $weight = $position === 8 ? -1 : 9 - $position;
            $sum += ((int) $digits[$position]) * $weight;
        }

        return $sum !== 0 && $sum % 11 === 0;
    }
}
