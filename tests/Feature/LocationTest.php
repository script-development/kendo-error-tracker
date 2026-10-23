<?php

declare(strict_types = 1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use ScriptDevelopment\KendoErrorTracker\ErrorTracker;
use Symfony\Component\ErrorHandler\Error\FatalError;

beforeEach(function(): void {
    config()->set('error-tracker.kendo_url', 'https://kendo.test');
    config()->set('error-tracker.project', '7');
    config()->set('error-tracker.token', 'secret-token');
    config()->set('error-tracker.environment', 'production');
    config()->set('error-tracker.sync', true);
});

/**
 * A fatal error as Symfony's handler builds it at shutdown. The trace is
 * passed empty: without one, FatalError asks xdebug for the function stack.
 */
function fatalErrorAt(string $file, int $line): FatalError
{
    return new FatalError('Allowed memory size exhausted', 0, ['type' => \E_ERROR, 'message' => 'Allowed memory size exhausted', 'file' => $file, 'line' => $line], null, true, []);
}

/**
 * @return array<string, mixed>
 */
function bodyReportedFor(Throwable $throwable): array
{
    Http::fake(['kendo.test/*' => Http::response('', 202)]);

    app(ErrorTracker::class)->report($throwable);

    $body = [];
    Http::assertSent(function(Request $request) use (&$body): bool {
        $body = $request->data();

        return true;
    });

    return $body;
}

it('sends a fatal error\'s file relative to the base path, with its line', function(): void {
    $body = bodyReportedFor(fatalErrorAt(base_path('app/Services/Importer.php'), 42));

    expect($body['stack_trace'])->toBe('#0 {main}')
        ->and($body['location'])->toBe(['file' => 'app/Services/Importer.php', 'line' => 42]);
});

it('sends no username for a fatal error outside the base path', function(): void {
    $body = bodyReportedFor(fatalErrorAt('/home/jan/tools/vendor/acme/Loader.php', 7));

    expect($body['stack_trace'])->toBe('#0 {main}')
        ->and($body['location'])->toBe(['file' => '/home/[REDACTED:user]/tools/vendor/acme/Loader.php', 'line' => 7]);
});

it('sends the location of an ordinary exception too', function(): void {
    $line = __LINE__ + 1;
    $body = bodyReportedFor(new RuntimeException('boom'));

    expect($body['location']['line'])->toBe($line)
        ->and($body['location']['file'])->toEndWith('tests/Feature/LocationTest.php');
});

it('scrubs a secret in the file path', function(): void {
    $thrown = captureThrowableFromFixture('trace-secret-jan@example.com');
    expect($thrown->getFile())->toContain('jan@example.com');

    $body = bodyReportedFor($thrown);

    expect($body['location']['file'])
        ->toContain('[REDACTED:email]/throw_with_token_in_path.php')
        ->not->toContain('jan@example.com');
});

it('cuts the file to 255 characters', function(): void {
    $body = bodyReportedFor(fatalErrorAt(base_path(str_repeat('a', 300) . '.php'), 3));

    expect($body['location']['file'])->toBe(str_repeat('a', 255));
});

it('leaves the location out when PHP gives no file or no line, and still sends the report', function(string $file, int $line): void {
    $body = bodyReportedFor(fatalErrorAt($file, $line));

    expect($body)->not->toHaveKey('location')
        ->and($body['exception_class'])->toBe(FatalError::class);
})->with([
    'line 0' => ['Unknown', 0],
    'empty file' => ['', 12],
]);
