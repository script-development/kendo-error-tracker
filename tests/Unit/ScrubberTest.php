<?php

declare(strict_types = 1);

use ScriptDevelopment\KendoErrorTracker\Scrubber;

beforeEach(function(): void {
    $this->scrubber = new Scrubber;
});

it('redacts a JWT', function(): void {
    $jwt = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0.SflKxwRJSMeKKF2QT4fwpMeJf36POk6yJV_adQssw5c';

    $out = $this->scrubber->scrub("token was {$jwt} and failed");

    expect($out)
        ->toContain('[REDACTED:jwt]')
        ->not->toContain($jwt);
});

it('redacts a Bearer token', function(): void {
    $out = $this->scrubber->scrub('Authorization: Bearer abc123DEF456ghi.jkl_mno-pqr=');

    expect($out)
        ->toContain('[REDACTED:bearer]')
        ->not->toContain('abc123DEF456ghi');
});

it('redacts a valid BSN', function(): void {
    // 111222333 passes the eleven-test checksum (a real BSN shape), unlike an
    // arbitrary 9-digit run.
    $out = $this->scrubber->scrub('citizen 111222333 reported');

    expect($out)
        ->toContain('[REDACTED:bsn]')
        ->not->toContain('111222333');
});

it('does not redact a 9-digit run that fails the eleven-test checksum', function(): void {
    // M-1: the KD-0885 fix over-redacted any 9+-digit run. 123456789 is
    // shaped like a BSN but fails the checksum, so it must now pass through
    // untouched — this is a legitimate order/invoice/timestamp-shaped ID.
    $clean = 'order 123456789 placed';

    expect($this->scrubber->scrub($clean))
        ->toBe($clean)
        ->not->toContain('[REDACTED:bsn]');
});

it('redacts a formatted BSN', function(string $formatted): void {
    // C-1: grouped forms with `.`, space, or `-` separators must redact.
    // 123.456.782 / 123 456 782 / 123-456-782 all pass the eleven-test checksum.
    $out = $this->scrubber->scrub("citizen {$formatted} reported");

    expect($out)
        ->toContain('[REDACTED:bsn]')
        ->not->toContain($formatted);
})->with([
    'dotted' => '123.456.782',
    'spaced' => '123 456 782',
    'dashed' => '123-456-782',
]);

it('does not redact a formatted 9-digit group that fails the checksum', function(): void {
    // M-1: the grouped form is validated too — a formatted-looking number that
    // fails the eleven-test (123.456.789) must not redact.
    $clean = 'ref 123.456.789 recorded';

    expect($this->scrubber->scrub($clean))
        ->toBe($clean)
        ->not->toContain('[REDACTED:bsn]');
});

it('redacts a valid BSN embedded in a longer numeric run', function(): void {
    // C-1: a real BSN hidden inside a longer digit string must not escape
    // because it borders other digits. 123456782 (valid) is embedded starting
    // at index 1 of the 10-digit run; the leading "9" is not part of the BSN
    // and is left untouched.
    $out = $this->scrubber->scrub('id 9123456782 here');

    expect($out)
        ->toBe('id 9[REDACTED:bsn] here')
        ->not->toContain('123456782');
});

it('does not redact a phone-number-shaped digit run with no valid BSN window', function(): void {
    // M-1: 0612345678 is a realistic Dutch mobile number. Neither of its two
    // possible 9-digit windows passes the eleven-test checksum, so — unlike
    // the KD-0885 bare-regex behavior — it must now pass through untouched.
    $clean = 'phone 0612345678 logged';

    expect($this->scrubber->scrub($clean))
        ->toBe($clean)
        ->not->toContain('[REDACTED:bsn]');
});

it('does not redact a 10-digit run containing no valid BSN window', function(): void {
    // M-1: neither 9-digit window of 1234567890 passes the eleven-test checksum.
    $clean = 'id 1234567890 here';

    expect($this->scrubber->scrub($clean))
        ->toBe($clean)
        ->not->toContain('[REDACTED:bsn]');
});

it('redacts an email address', function(): void {
    $out = $this->scrubber->scrub('contact jane.doe@example.com please');

    expect($out)
        ->toContain('[REDACTED:email]')
        ->not->toContain('jane.doe@example.com');
});

it('redacts an email with an IDN (unicode) domain', function(): void {
    // H-1: ASCII-only matching previously leaked unicode domains.
    $out = $this->scrubber->scrub('mail jan@müller.nl now');

    expect($out)
        ->toContain('[REDACTED:email]')
        ->not->toContain('jan@müller.nl');
});

it('redacts an email with a unicode local part', function(): void {
    // H-1: unicode local parts previously leaked.
    $out = $this->scrubber->scrub('from José@example.com today');

    expect($out)
        ->toContain('[REDACTED:email]')
        ->not->toContain('José@example.com');
});

it('redacts a database DSN password', function(): void {
    $out = $this->scrubber->scrub('connection failed: mysql://root:s3cr3tPass@127.0.0.1:3306/app');

    expect($out)
        ->toContain('mysql://root:[REDACTED:dsn-password]@')
        ->toContain('[REDACTED:ip]')
        ->not->toContain('s3cr3tPass');
});

it('redacts a DSN password regardless of scheme', function(): void {
    $out = $this->scrubber->scrub('redis://default:hunter2@cache.internal:6379/0');

    expect($out)
        ->toContain('redis://default:[REDACTED:dsn-password]@')
        ->not->toContain('hunter2');
});

it('redacts a Stripe-style live API key', function(): void {
    // Deliberately low-entropy placeholder (not a real key shape) so this
    // fixture doesn't trip secret-scanning push protection on the repo.
    $out = $this->scrubber->scrub('stripe call failed with key sk_live_FAKEFAKEFAKEFAKEFAKE01');

    expect($out)
        ->toContain('[REDACTED:api-key]')
        ->not->toContain('sk_live_FAKEFAKEFAKEFAKEFAKE01');
});

it('redacts an AWS access key id', function(): void {
    $out = $this->scrubber->scrub('credentials AKIAIOSFODNN7EXAMPLE rejected');

    expect($out)
        ->toContain('[REDACTED:api-key]')
        ->not->toContain('AKIAIOSFODNN7EXAMPLE');
});

it('redacts an IPv4 address', function(): void {
    $out = $this->scrubber->scrub('connection refused from 192.168.1.42');

    expect($out)
        ->toContain('[REDACTED:ip]')
        ->not->toContain('192.168.1.42');
});

it('does not redact an out-of-range octet as an IPv4 address', function(): void {
    $clean = 'value 999.999.999.999 is not an ip';

    expect($this->scrubber->scrub($clean))
        ->toBe($clean)
        ->not->toContain('[REDACTED:ip]');
});

it('redacts multiple patterns in one string', function(): void {
    $input = 'user alice@corp.nl bsn 456789017 token Bearer xyz789';

    $out = $this->scrubber->scrub($input);

    expect($out)
        ->toContain('[REDACTED:email]')
        ->toContain('[REDACTED:bsn]')
        ->toContain('[REDACTED:bearer]')
        ->not->toContain('alice@corp.nl')
        ->not->toContain('456789017')
        ->not->toContain('xyz789');
});

it('leaves non-matching strings unchanged', function(): void {
    $clean = 'Undefined array key "name" in App\Services\Foo at line 42';

    expect($this->scrubber->scrub($clean))->toBe($clean);
});

it('does not redact a digit run shorter than 9', function(): void {
    $out = $this->scrubber->scrub('order 12345678 placed');

    expect($out)
        ->toBe('order 12345678 placed')
        ->not->toContain('[REDACTED:bsn]');
});

it('documents the eyJ-header JWT scope boundary', function(): void {
    // H-2: JWT detection is intentionally scoped to the conventional `eyJ`
    // base64url header. A token without it is OUT of scope here (deferred), so
    // it is NOT redacted as a JWT. This test pins the documented boundary so a
    // future widening is a deliberate, test-visible change.
    $nonEyJ = 'abcdef.ghijkl.mnopqr';

    $out = $this->scrubber->scrub("token {$nonEyJ} present");

    expect($out)->not->toContain('[REDACTED:jwt]');
});

it('redacts bare reuse of a Bearer credential elsewhere in the string', function(): void {
    // H-2: the same credential appearing once prefixed with `Bearer` and again
    // unprefixed must be redacted in BOTH places.
    $out = $this->scrubber->scrub('Bearer abc123DEF456 then token=abc123DEF456 reused');

    expect($out)
        ->not->toContain('abc123DEF456')
        ->and(mb_substr_count($out, '[REDACTED:bearer]'))->toBe(2);
});

it('does not consume a URL path after a stray Bearer keyword', function(): void {
    // M-4: dropping `/` from the credential class and bounding whitespace keeps
    // `Bearer ` in front of a path from eating the path.
    $out = $this->scrubber->scrub('Bearer /api/v1/users/123 next');

    expect($out)
        ->toBe('Bearer /api/v1/users/123 next')
        ->not->toContain('[REDACTED:bearer]');
});

it('does not let a Bearer match span a newline', function(): void {
    // M-4: bounded horizontal whitespace ([ \t]+) must not cross a line break.
    $input = "header Bearer\nnot-a-token here";

    $out = $this->scrubber->scrub($input);

    expect($out)
        ->toBe($input)
        ->not->toContain('[REDACTED:bearer]');
});
