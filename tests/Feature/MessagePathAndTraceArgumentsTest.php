<?php

declare(strict_types = 1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use ScriptDevelopment\KendoErrorTracker\ErrorTracker;
use ScriptDevelopment\KendoErrorTracker\PathNormalizer;
use ScriptDevelopment\KendoErrorTracker\Scrubber;
use ScriptDevelopment\KendoErrorTracker\Tests\Fixtures\TraceArgumentsFixture;

beforeEach(function(): void {
    config()->set('error-tracker.kendo_url', 'https://kendo.test');
    config()->set('error-tracker.project', '7');
    config()->set('error-tracker.token', 'secret-token');
    config()->set('error-tracker.environment', 'production');
    config()->set('error-tracker.sync', true);

    // PHP cuts a string argument to this many characters when it renders a
    // trace. php.ini-production sets 0, which would hide the name below
    // whatever the client does; 15 is PHP's compiled-in default.
    $this->previousMaxLen = \ini_get('zend.exception_string_param_max_len');
    ini_set('zend.exception_string_param_max_len', '15');
});

afterEach(function(): void {
    ini_set('zend.exception_string_param_max_len', (string) $this->previousMaxLen);
});

/**
 * Throw through a function taking a name, under the given
 * zend.exception_ignore_args. The ini is read when the exception is built, so
 * it is set before the throw and restored after.
 */
function throwableCarryingAName(string $ignoreArgs): Throwable
{
    $previous = \ini_get('zend.exception_ignore_args');
    ini_set('zend.exception_ignore_args', $ignoreArgs);

    try {
        new TraceArgumentsFixture()->viaInternalCallback('Jan de Vries');
    } catch (Throwable $throwable) {
        return $throwable;
    } finally {
        ini_set('zend.exception_ignore_args', (string) $previous);
    }
}

function sentBody(Request $request): array
{
    return $request->data();
}

it('path-normalizes the message like the stack trace', function(): void {
    Http::fake(['kendo.test/*' => Http::response('', 202)]);

    app(ErrorTracker::class)->report(new ErrorException(
        'file_put_contents(/home/forge/app/storage/logs/laravel.log): Failed to open stream: Permission denied',
    ));

    Http::assertSent(function(Request $request): bool {
        expect(sentBody($request)['message'])
            ->toBe('file_put_contents(/home/[REDACTED:user]/app/storage/logs/laravel.log): Failed to open stream: Permission denied');

        return true;
    });
});

it('strips the base path from the message', function(): void {
    Http::fake(['kendo.test/*' => Http::response('', 202)]);

    app(ErrorTracker::class)->report(new RuntimeException(
        \sprintf('include(%s/storage/framework/views/abc.php): Failed to open stream', base_path()),
    ));

    Http::assertSent(function(Request $request): bool {
        expect(sentBody($request)['message'])
            ->toBe('include(storage/framework/views/abc.php): Failed to open stream');

        return true;
    });
});

it('path-normalizes a cause\'s message', function(): void {
    Http::fake(['kendo.test/*' => Http::response('', 202)]);

    $cause = new RuntimeException('fopen(/Users/jan/app/x.log): failed');

    app(ErrorTracker::class)->report(new LogicException('wrapper', 0, $cause));

    Http::assertSent(function(Request $request): bool {
        expect(sentBody($request)['previous_exceptions'][0]['message'])
            ->toBe('fopen(/Users/[REDACTED:user]/app/x.log): failed');

        return true;
    });
});

it('sends no argument values in the stack trace', function(string $ignoreArgs): void {
    Http::fake(['kendo.test/*' => Http::response('', 202)]);

    $throwable = throwableCarryingAName($ignoreArgs);

    // Positive control: with arguments switched on, PHP's own trace string
    // does carry the name, so the assertion below can fail.
    if ($ignoreArgs === '0') {
        expect($throwable->getTraceAsString())->toContain('Jan de Vries');
    }

    app(ErrorTracker::class)->report(new LogicException('wrapper', 0, $throwable));

    Http::assertSent(function(Request $request): bool {
        $body = sentBody($request);

        expect($body['stack_trace'])->not->toContain('Jan de Vries')
            ->and($body['previous_exceptions'][0]['stack_trace'])
            ->not->toContain('Jan de Vries')
            ->toContain('TraceArgumentsFixture::throwFor()')
            ->toContain('TraceArgumentsFixture::lookUp()')
            ->toContain('TraceArgumentsFixture->viaInternalCallback()')
            ->toContain('[internal function]: ');

        return true;
    });
})->with(['arguments on' => '0', 'arguments off' => '1']);

it('renders the trace as PHP does when arguments are off, minus the included file names', function(): void {
    Http::fake(['kendo.test/*' => Http::response('', 202)]);

    $throwable = throwableCarryingAName('1');

    // PHP keeps an include or require frame's file name even with arguments
    // off, cut to zend.exception_string_param_max_len characters; the client
    // sends that frame as `include()`.
    $phpTrace = (string) preg_replace('/^(#\d+ .+: (?:include|require)(?:_once)?)\(.*\)$/m', '$1()', $throwable->getTraceAsString());
    expect($phpTrace)->not->toBe($throwable->getTraceAsString());

    $expected = app(Scrubber::class)->scrub(app(PathNormalizer::class)->normalize($phpTrace));

    app(ErrorTracker::class)->report($throwable);

    Http::assertSent(function(Request $request) use ($expected): bool {
        expect(sentBody($request)['stack_trace'])->toBe($expected);

        return true;
    });
});
