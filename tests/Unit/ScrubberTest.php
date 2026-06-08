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

it('redacts a BSN', function(): void {
    $out = $this->scrubber->scrub('citizen 123456789 reported');

    expect($out)
        ->toContain('[REDACTED:bsn]')
        ->not->toContain('123456789');
});

it('redacts a formatted BSN', function(string $formatted): void {
    // C-1: grouped forms with `.`, space, or `-` separators must redact.
    $out = $this->scrubber->scrub("citizen {$formatted} reported");

    expect($out)
        ->toContain('[REDACTED:bsn]')
        ->not->toContain($formatted);
})->with([
    'dotted' => '123.456.782',
    'spaced' => '123 456 782',
    'dashed' => '123-456-782',
]);

it('redacts a 9-digit BSN embedded in a longer numeric run', function(): void {
    // C-1: a BSN hidden inside a longer digit string (e.g. a phone number)
    // must not escape because it borders other digits.
    $out = $this->scrubber->scrub('phone 0612345678 logged');

    expect($out)
        ->toContain('[REDACTED:bsn]')
        ->not->toContain('0612345678');
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

it('redacts multiple patterns in one string', function(): void {
    $input = 'user alice@corp.nl bsn 987654321 token Bearer xyz789';

    $out = $this->scrubber->scrub($input);

    expect($out)
        ->toContain('[REDACTED:email]')
        ->toContain('[REDACTED:bsn]')
        ->toContain('[REDACTED:bearer]')
        ->not->toContain('alice@corp.nl')
        ->not->toContain('987654321')
        ->not->toContain('xyz789');
});

it('leaves non-matching strings unchanged', function(): void {
    $clean = 'Undefined array key "name" in App\Services\Foo at line 42';

    expect($this->scrubber->scrub($clean))->toBe($clean);
});

it('now redacts a 10-digit run that contains a 9-digit BSN', function(): void {
    // C-1 behavior change: the widened BSN pattern matches any run of 9-or-more
    // digits so a BSN cannot hide inside a longer digit string. This accepts
    // increased over-redaction of legit 9+-digit IDs until the v1.5 elfproef
    // validator distinguishes a real BSN from an arbitrary digit run.
    $out = $this->scrubber->scrub('id 1234567890 here');

    expect($out)
        ->toContain('[REDACTED:bsn]')
        ->not->toContain('1234567890');
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
