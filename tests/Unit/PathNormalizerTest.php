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

it('leaves paths that do not start with the base path unchanged', function(): void {
    $normalizer = new PathNormalizer('/var/www/html');

    $trace = '#0 /opt/other/lib/Thing.php(7): unrelated()';

    expect($normalizer->normalize($trace))->toBe($trace);
});
