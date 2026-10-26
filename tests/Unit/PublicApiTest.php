<?php

declare(strict_types = 1);

use ScriptDevelopment\KendoErrorTracker\ErrorTracker;
use ScriptDevelopment\KendoErrorTracker\Jobs\ReportErrorJob;
use ScriptDevelopment\KendoErrorTracker\PathNormalizer;
use ScriptDevelopment\KendoErrorTracker\Scrubber;

/*
 * The public API is what consumers build on: lokalekeuze's own reporter
 * constructs PathNormalizer, calls Scrubber::scrub() and dispatches
 * ReportErrorJob with a payload it built (WR-2511). Every class in src/ says
 * whether it is public (@api) or internal (@internal), and each public
 * signature is pinned here, so a patch that breaks a consumer reds this file.
 */

/**
 * @return list<class-string>
 */
function packageClasses(): array
{
    $root = \dirname(__DIR__, 2) . '/src';
    $classes = [];

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));

    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $relative = mb_substr($file->getPathname(), mb_strlen($root) + 1, -4);
        $classes[] = 'ScriptDevelopment\KendoErrorTracker\\' . str_replace('/', '\\', $relative);
    }

    sort($classes);

    return $classes;
}

/**
 * @param ReflectionClass<object>|ReflectionMethod $reflection
 */
function apiTag(ReflectionClass|ReflectionMethod $reflection): ?string
{
    $doc = (string) $reflection->getDocComment();
    $api = preg_match('/^\s*\*\s*@api\b/m', $doc) === 1;
    $internal = preg_match('/^\s*\*\s*@internal\b/m', $doc) === 1;

    return match (true) {
        $api && !$internal => 'api',
        $internal && !$api => 'internal',
        default => null,
    };
}

function signature(ReflectionMethod $method): string
{
    $parameters = array_map(
        static fn(ReflectionParameter $parameter): string => \sprintf('%s $%s', (string) $parameter->getType(), $parameter->getName()),
        $method->getParameters(),
    );

    return \sprintf('%s(%s): %s', $method->getName(), implode(', ', $parameters), (string) $method->getReturnType());
}

it('marks every class in src as either public or internal', function(): void {
    $classes = packageClasses();

    expect($classes)->toHaveCount(7);

    $tags = [];

    foreach ($classes as $class) {
        $tags[$class] = apiTag(new ReflectionClass($class));
    }

    expect($tags)->toBe([
        'ScriptDevelopment\KendoErrorTracker\ErrorTracker' => 'api',
        'ScriptDevelopment\KendoErrorTracker\ErrorTrackerServiceProvider' => 'internal',
        'ScriptDevelopment\KendoErrorTracker\Jobs\ReportErrorJob' => 'api',
        'ScriptDevelopment\KendoErrorTracker\MemoryLimit' => 'internal',
        'ScriptDevelopment\KendoErrorTracker\PathNormalizer' => 'api',
        'ScriptDevelopment\KendoErrorTracker\RunningContext' => 'internal',
        'ScriptDevelopment\KendoErrorTracker\Scrubber' => 'api',
    ]);
});

it('keeps the public methods of each public class', function(string $class, array $expected): void {
    $public = [];
    $reflection = new ReflectionClass($class);

    // Methods a trait brings in (the job's Queueable, Dispatchable) are the
    // framework's, not this package's: only the class's own file counts.
    foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        if ($method->getFileName() !== $reflection->getFileName() || apiTag($method) === 'internal') {
            continue;
        }

        $public[] = signature($method);
    }

    sort($public);
    sort($expected);

    expect($public)->toBe($expected);
})->with([
    'ErrorTracker' => [ErrorTracker::class, [
        'report(Throwable $throwable): void',
        'scopeUserHashUsing(Closure $resolver): void',
    ]],
    'Scrubber' => [Scrubber::class, [
        'scrub(string $value): string',
    ]],
    'PathNormalizer' => [PathNormalizer::class, [
        '__construct(string $basePath): ',
        'normalize(string $trace): string',
    ]],
    'ReportErrorJob' => [ReportErrorJob::class, [
        '__construct(array $payload): ',
        'failed(?Throwable $exception): void',
        'handle(ScriptDevelopment\KendoErrorTracker\ErrorTracker $tracker): void',
    ]],
]);

it('lets a consumer build a Scrubber without arguments and read a job\'s payload', function(): void {
    $job = new ReportErrorJob(['message' => (new Scrubber)->scrub('jan@example.com')]);

    expect($job->payload)->toBe(['message' => '[REDACTED:email]'])
        ->and((new ReflectionProperty(ReportErrorJob::class, 'payload'))->isPublic())->toBeTrue();
});
