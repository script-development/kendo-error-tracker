<?php

declare(strict_types = 1);

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use ScriptDevelopment\KendoErrorTracker\ErrorTracker;
use ScriptDevelopment\KendoErrorTracker\PathNormalizer;
use ScriptDevelopment\KendoErrorTracker\Scrubber;

beforeEach(function(): void {
    config()->set('error-tracker.kendo_url', 'https://kendo.test');
    config()->set('error-tracker.project', '7');
    config()->set('error-tracker.token', 'secret-token');
    config()->set('error-tracker.environment', 'production');
    config()->set('error-tracker.sync', true);

    $this->memoryLimit = \ini_get('memory_limit');
});

afterEach(function(): void {
    ini_set('memory_limit', $this->memoryLimit);
});

/**
 * @return array<string, mixed>
 */
function reportAndCaptureBody(Throwable $throwable, ?ErrorTracker $tracker = null): array
{
    Http::fake(['kendo.test/*' => Http::response('', 202)]);

    ($tracker ?? app(ErrorTracker::class))->report($throwable);

    $body = [];
    Http::assertSent(function(Request $request) use (&$body): bool {
        $body = $request->data();

        return true;
    });

    return $body;
}

it('sends the runtime, framework and memory facts', function(): void {
    ini_set('memory_limit', '2G');

    $body = reportAndCaptureBody(new RuntimeException('boom'));

    expect($body['runtime'])->toBe(['name' => 'php', 'version' => \PHP_VERSION])
        ->and($body['framework'])->toBe(['name' => 'laravel', 'version' => app()->version()])
        ->and($body['memory_peak_bytes'])->toBeInt()->toBeGreaterThan(0)
        ->and($body['memory_limit_bytes'])->toBe(2_147_483_648);
});

it('leaves the memory limit out when it is unlimited', function(): void {
    ini_set('memory_limit', '-1');

    expect(reportAndCaptureBody(new RuntimeException('boom')))->not->toHaveKey('memory_limit_bytes');
});

it('sends the exception code as a string', function(Throwable $throwable, string $code): void {
    expect(reportAndCaptureBody($throwable)['exception_code'])->toBe($code);
})->with([
    'default code' => [new RuntimeException('boom'), '0'],
    'int code' => [new RuntimeException('boom', 404), '404'],
    'SQLSTATE code' => [(static function(): PDOException {
        $exception = new PDOException('missing table');
        (new ReflectionProperty(Exception::class, 'code'))->setValue($exception, '42S02');

        return $exception;
    })(), '42S02'],
]);

it('leaves the framework out when its version cannot be read, and still sends the report', function(): void {
    $application = Mockery::mock(Application::class);
    $application->allows('version')->andThrow(new RuntimeException('no version for jan@example.com'));

    $tracker = new ErrorTracker(app(HttpFactory::class), $application, new Scrubber, app(PathNormalizer::class), app(Config::class));

    $log = tempnam(sys_get_temp_dir(), 'kendo-error-tracker-log');
    $previousLog = ini_set('error_log', $log);

    try {
        $body = reportAndCaptureBody(new RuntimeException('boom'), $tracker);
        $logged = (string) file_get_contents($log);
    } finally {
        ini_set('error_log', (string) $previousLog);
        unlink($log);
    }

    expect($logged)->toContain('[kendo-error-tracker] framework left out: ' . RuntimeException::class)
        ->not->toContain('jan@example.com');

    expect($body)->not->toHaveKey('framework')
        ->and($body['message'])->toBe('boom')
        ->and($body['runtime'])->toBe(['name' => 'php', 'version' => \PHP_VERSION]);
});
