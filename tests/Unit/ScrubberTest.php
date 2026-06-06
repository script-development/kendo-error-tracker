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

it('redacts an email address', function(): void {
    $out = $this->scrubber->scrub('contact jane.doe@example.com please');

    expect($out)
        ->toContain('[REDACTED:email]')
        ->not->toContain('jane.doe@example.com');
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

it('does not redact a 9-digit run that borders another digit', function(): void {
    // A 10+ digit run is not a BSN; the negative look-around must not fire.
    $out = $this->scrubber->scrub('id 1234567890 here');

    expect($out)
        ->toBe('id 1234567890 here')
        ->not->toContain('[REDACTED:bsn]');
});
