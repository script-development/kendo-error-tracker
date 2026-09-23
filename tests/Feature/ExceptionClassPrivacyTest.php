<?php

declare(strict_types = 1);

use Illuminate\Support\Facades\Http;
use ScriptDevelopment\KendoErrorTracker\ErrorTracker;
use ScriptDevelopment\KendoErrorTracker\PathNormalizer;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

// WR-0798: an anonymous class name is `Parent@anonymous\0/abs/path/file.php:LINE$N`.
// It carries the consumer's absolute install path, and `$N` is PHP's
// process-global compile counter, so neither is stable across deploys (KD-0771 D6).
// Fixtures are written under the system temp dir, outside `/home` and `/Users`,
// so the username-redaction fallback cannot mask an unnormalized path.

beforeEach(function(): void {
    config()->set('error-tracker.kendo_url', 'https://kendo.test');
    config()->set('error-tracker.project', '7');
    config()->set('error-tracker.token', 'secret-token');
    config()->set('error-tracker.sync', true);

    $this->installRoot = sys_get_temp_dir() . '/kendo-error-tracker-' . bin2hex(random_bytes(6));
});

afterEach(function(): void {
    exec('rm -rf ' . escapeshellarg($this->installRoot));
});

/**
 * Write `$source` to `<root>/app/Failure.php`, require it, and return the
 * Throwable it builds, so the anonymous class is declared under `$root`.
 */
function anonymousThrowableUnder(string $root, string $source): Throwable
{
    @mkdir($root . '/app', 0o777, true);
    file_put_contents($root . '/app/Failure.php', $source);

    return require $root . '/app/Failure.php';
}

/**
 * Report `$throwable` with the app's base path set to `$root`, returning the
 * body that reached the kendo endpoint.
 *
 * @return array<string, mixed>
 */
function reportedBodyUnder(string $root, Throwable $throwable): array
{
    app()->instance(PathNormalizer::class, new PathNormalizer($root));
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

const ANONYMOUS_RUNTIME = "<?php\nreturn new class('boom') extends RuntimeException {};\n";

it('strips the install path and compile counter from an anonymous exception class', function(): void {
    $throwable = anonymousThrowableUnder($this->installRoot, ANONYMOUS_RUNTIME);

    // Guard against a false-green: the raw class name carries the absolute root.
    expect($throwable::class)->toContain($this->installRoot);

    $body = reportedBodyUnder($this->installRoot, $throwable);

    expect($body['exception_class'])
        ->toBe("RuntimeException@anonymous\0app/Failure.php:2")
        ->not->toContain(sys_get_temp_dir());
});

it('reports the same anonymous exception class under two different install roots', function(): void {
    $classes = array_map(
        fn(string $root): mixed => reportedBodyUnder($root, anonymousThrowableUnder($root, ANONYMOUS_RUNTIME))['exception_class'],
        [$this->installRoot . '/releases/1', $this->installRoot . '/releases/2'],
    );

    expect($classes[0])->toBe($classes[1]);
});

it('keeps the install path out of a database carrier message built from an anonymous PDOException', function(): void {
    $throwable = anonymousThrowableUnder(
        $this->installRoot,
        "<?php\nreturn new class('insert into users (name) values (Jan)') extends PDOException {};\n",
    );

    $body = reportedBodyUnder($this->installRoot, $throwable);

    expect($body['message'])
        ->toBe("PDOException@anonymous\0app/Failure.php:2 [SQLSTATE unknown] [driver code unknown]")
        ->and($body['exception_class'])->toBe("PDOException@anonymous\0app/Failure.php:2");
});

it('scrubs a secret in an anonymous class path that sits outside the base path', function(): void {
    $throwable = anonymousThrowableUnder($this->installRoot . '/shared-jan@example.com', ANONYMOUS_RUNTIME);

    $body = reportedBodyUnder($this->installRoot . '/current', $throwable);

    expect($body['exception_class'])
        ->toContain('[REDACTED:email]')
        ->not->toContain('jan@example.com');
});

it('sends a named exception class byte-identical to the class name', function(Throwable $throwable): void {
    $body = reportedBodyUnder($this->installRoot, $throwable);

    expect($body['exception_class'])->toBe($throwable::class);
})->with([
    'global class' => fn(): Throwable => new RuntimeException('boom'),
    'namespaced class' => fn(): Throwable => new NotFoundHttpException('gone'),
]);
