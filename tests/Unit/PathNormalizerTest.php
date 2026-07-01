<?php

declare(strict_types = 1);

use ScriptDevelopment\KendoErrorTracker\PathNormalizer;

it('strips the base path from a single frame', function(): void {
    $normalizer = new PathNormalizer('/var/www/html');

    $trace = '#0 /var/www/html/app/Foo.php(42): App\Services\Foo->bar()';

    expect($normalizer->normalize($trace))
        ->toBe('#0 app/Foo.php(42): App\Services\Foo->bar()');
});

it('strips the base path from every frame in the trace', function(): void {
    $normalizer = new PathNormalizer('/home/forge/app');

    $trace = implode("\n", [
        '#0 /home/forge/app/app/Services/Foo.php(10): doThing()',
        '#1 /home/forge/app/app/Http/Controllers/Bar.php(20): callThing()',
        '#2 {main}',
    ]);

    expect($normalizer->normalize($trace))->toBe(implode("\n", [
        '#0 app/Services/Foo.php(10): doThing()',
        '#1 app/Http/Controllers/Bar.php(20): callThing()',
        '#2 {main}',
    ]));
});

it('produces identical output for the same exception from different deploy roots', function(): void {
    $frame = '#0 %s/app/Foo.php(42): App\Services\Foo->bar()';

    $fromVarWww = (new PathNormalizer('/var/www/html'))
        ->normalize(\sprintf($frame, '/var/www/html'));

    $fromForge = (new PathNormalizer('/home/forge/app'))
        ->normalize(\sprintf($frame, '/home/forge/app'));

    expect($fromVarWww)
        ->toBe($fromForge)
        ->toBe('#0 app/Foo.php(42): App\Services\Foo->bar()');
});

it('leaves a non-matching path with no username shape unchanged', function(): void {
    $normalizer = new PathNormalizer('/var/www/html');

    $trace = '#0 /opt/other/lib/Thing.php(7): unrelated()';

    expect($normalizer->normalize($trace))->toBe($trace);
});

it('redacts the username from a non-matching /home/<user>/ path', function(): void {
    // M-3: a frame whose path does not start with base_path() previously
    // leaked the absolute path — including the OS username — verbatim.
    $normalizer = new PathNormalizer('/var/www/html');

    $trace = '#1 /home/forge/.composer/vendor/bin/tool.php(3): call()';

    expect($normalizer->normalize($trace))
        ->toBe('#1 /home/[REDACTED:user]/.composer/vendor/bin/tool.php(3): call()')
        ->not->toContain('forge');
});

it('redacts the username from a non-matching /Users/<user>/ path', function(): void {
    // M-3: the macOS-style home directory shape leaks the same way.
    $normalizer = new PathNormalizer('/var/www/html');

    $trace = '#1 /Users/janedoe/.composer/vendor/bin/tool.php(3): call()';

    expect($normalizer->normalize($trace))
        ->toBe('#1 /Users/[REDACTED:user]/.composer/vendor/bin/tool.php(3): call()')
        ->not->toContain('janedoe');
});

it('redacts usernames in every non-matching frame without double-processing a matching frame', function(): void {
    // The app-root frame is stripped via the exact base_path() prefix (no
    // /home/ segment survives to redact); the vendor frame under a different
    // user's home directory falls through to the username-redaction pass.
    $normalizer = new PathNormalizer('/home/forge/app');

    $trace = implode("\n", [
        '#0 /home/forge/app/app/Services/Foo.php(10): doThing()',
        '#1 /home/deploy/.composer/vendor/bin/tool.php(3): call()',
        '#2 {main}',
    ]);

    expect($normalizer->normalize($trace))->toBe(implode("\n", [
        '#0 app/Services/Foo.php(10): doThing()',
        '#1 /home/[REDACTED:user]/.composer/vendor/bin/tool.php(3): call()',
        '#2 {main}',
    ]));
});
