<?php

declare(strict_types = 1);

use Illuminate\Foundation\Console\Kernel;
use Illuminate\Support\Facades\Http;
use ScriptDevelopment\KendoErrorTracker\ErrorTracker;
use ScriptDevelopment\KendoErrorTracker\PathNormalizer;
use ScriptDevelopment\KendoErrorTracker\RunningContext;

// A frame's class is an anonymous class's name when the exception passed
// through one of its methods: `class@anonymous\0/abs/file.php:LINE$N`. The
// trace must carry it in the form exception_class does, without the install
// path or the compile counter. Fixtures live under the system temp dir, outside
// `/home` and `/Users`, so the username fallback cannot hide a raw path.

beforeEach(function(): void {
    config()->set('error-tracker.kendo_url', 'https://kendo.test');
    config()->set('error-tracker.project', '7');
    config()->set('error-tracker.token', 'secret-token');
    config()->set('error-tracker.sync', true);

    $this->frameRoot = sys_get_temp_dir() . '/kendo-error-tracker-frame-' . bin2hex(random_bytes(6));
});

afterEach(function(): void {
    exec('rm -rf ' . escapeshellarg($this->frameRoot));
});

const ANONYMOUS_THROWER = <<<'PHP'
    <?php
    return new class {
        public function fail(): never
        {
            throw new RuntimeException('boom');
        }
    };
    PHP;

/**
 * Declare the anonymous thrower in `<root>/<file>` and return what its method
 * throws, so a trace frame names the anonymous class.
 */
function throwThroughAnonymousFrame(string $root, string $file): RuntimeException
{
    @mkdir(\dirname($root . '/' . $file), 0o777, true);
    file_put_contents($root . '/' . $file, ANONYMOUS_THROWER);

    $thrower = require $root . '/' . $file;

    try {
        $thrower->fail();
    } catch (RuntimeException $exception) {
        return $exception;
    }

    throw new LogicException('the thrower did not throw');
}

/**
 * @return array<string, mixed>
 */
function bodyReportedWithBasePath(string $basePath, Throwable $throwable): array
{
    app()->instance(PathNormalizer::class, new PathNormalizer($basePath));
    app()->forgetInstance(ErrorTracker::class);

    $sent = [];
    Http::fake(function($request) use (&$sent) {
        $sent[] = $request->data();

        return Http::response('', 202);
    });

    app(ErrorTracker::class)->report($throwable);

    expect($sent)->toHaveCount(1);

    return $sent[0];
}

/**
 * The trace lines that name an anonymous class.
 *
 * @return list<string>
 */
function anonymousFrameLines(string $trace): array
{
    return array_values(array_filter(explode("\n", $trace), static fn(string $line): bool => str_contains($line, '@anonymous')));
}

it('sends a frame of an anonymous class declared under the base path without the path or the counter', function(): void {
    $throwable = throwThroughAnonymousFrame($this->frameRoot, 'app/Thrower.php');

    expect($throwable->getTrace()[0]['class'] ?? '')->toContain($this->frameRoot)->toMatch('/\$[0-9a-f]+\z/');

    $trace = bodyReportedWithBasePath($this->frameRoot, $throwable)['stack_trace'];
    $lines = anonymousFrameLines($trace);

    expect($lines)->toHaveCount(1)
        ->and($lines[0])->toEndWith(": class@anonymous\0app/Thrower.php:2->fail()")
        ->and($trace)->not->toContain(sys_get_temp_dir())
        ->and(mb_strlen($trace))->toBeLessThanOrEqual(131_072);
});

it('sends a frame of an anonymous class declared outside the base path with its basename only', function(): void {
    $throwable = throwThroughAnonymousFrame($this->frameRoot . '/shared', 'lib/Thrower.php');

    $trace = bodyReportedWithBasePath($this->frameRoot . '/current', $throwable)['stack_trace'];
    $lines = anonymousFrameLines($trace);

    expect($lines)->toHaveCount(1)
        ->and($lines[0])->toEndWith(": class@anonymous\0[REDACTED:path]/Thrower.php:2->fail()")
        ->and($trace)->not->toContain($this->frameRoot);
});

it('sends the same anonymous frame under two install roots', function(): void {
    $frames = array_map(
        static fn(string $root): array => anonymousFrameLines(
            bodyReportedWithBasePath($root, throwThroughAnonymousFrame($root, 'app/Thrower.php'))['stack_trace'],
        ),
        [$this->frameRoot . '/releases/1', $this->frameRoot . '/releases/2'],
    );

    expect($frames[0])->toHaveCount(1)->toBe($frames[1]);
});

it('normalises an anonymous frame in every cause of the chain', function(): void {
    $cause = throwThroughAnonymousFrame($this->frameRoot . '/shared', 'lib/Thrower.php');

    $body = bodyReportedWithBasePath($this->frameRoot . '/current', new LogicException('outer', 0, $cause));
    $entry = $body['previous_exceptions'][0];
    $lines = anonymousFrameLines($entry['stack_trace']);

    expect($lines)->toHaveCount(1)
        ->and($lines[0])->toEndWith(": class@anonymous\0[REDACTED:path]/Thrower.php:2->fail()")
        ->and($entry['stack_trace'])->not->toContain($this->frameRoot)
        ->and(mb_strlen($entry['exception_class']))->toBeLessThanOrEqual(255)
        ->and(mb_strlen($entry['stack_trace']))->toBeLessThanOrEqual(131_072);
});

it('sends an anonymous console command class in the same form', function(): void {
    $root = $this->frameRoot . '/shared';
    @mkdir($root . '/lib', 0o777, true);
    file_put_contents($root . '/lib/AnonymousCommand.php', <<<'PHP'
        <?php
        return new class extends Illuminate\Console\Command {
            protected $signature = 'kendo:anonymous';

            public function handle(): int
            {
                return 0;
            }
        };
        PHP);

    $command = require $root . '/lib/AnonymousCommand.php';

    expect($command::class)->toContain($this->frameRoot);

    $kernel = new Kernel(app(), app('events'));
    app()->instance(Illuminate\Contracts\Console\Kernel::class, $kernel);
    $kernel->registerCommand($command);
    app(RunningContext::class)->commandStarted('kendo:anonymous');

    $context = bodyReportedWithBasePath($this->frameRoot . '/current', new RuntimeException('boom'))['context'];

    expect($context)->toBe([
        'kind' => 'command',
        'name' => 'kendo:anonymous',
        'class' => "Illuminate\\Console\\Command@anonymous\0[REDACTED:path]/AnonymousCommand.php:2",
    ]);
});
