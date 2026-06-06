<?php

declare(strict_types = 1);

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use ScriptDevelopment\KendoErrorTracker\ErrorTracker;

beforeEach(function(): void {
    config()->set('error-tracker.kendo_url', 'https://kendo.test');
    config()->set('error-tracker.project', '7');
    config()->set('error-tracker.token', 'secret-token');
    config()->set('error-tracker.environment', 'production');
    config()->set('error-tracker.sync', true);
});

it('swallows an HTTP error-status response', function(int $status): void {
    Http::fake(['kendo.test/*' => Http::response('', $status)]);

    app(ErrorTracker::class)->report(new RuntimeException('boom'));

    // The request was attempted and report() returned without throwing.
    Http::assertSentCount(1);
})
    ->with([
        '401 unauthorized' => 401,
        '403 forbidden' => 403,
        '422 unprocessable (cross-project / revoked token)' => 422,
        '500 server error' => 500,
        '503 unavailable' => 503,
    ]);

it('swallows a connection timeout / unreachable host', function(): void {
    Http::fake(function(): void {
        throw new ConnectionException('Connection timed out');
    });

    app(ErrorTracker::class)->report(new RuntimeException('boom'));
})->throwsNoExceptions();

it('returns promptly and swallows when the host hangs past the timeout', function(): void {
    // A hung host surfaces as a timeout ConnectionException once the explicit
    // ->timeout() bound is hit. The call must return without throwing and the
    // caller is never blocked beyond the configured bound.
    config()->set('error-tracker.connect_timeout', 1);
    config()->set('error-tracker.timeout', 1);

    Http::fake(function(): void {
        throw new ConnectionException('cURL error 28: Operation timed out after 1000 milliseconds');
    });

    $start = microtime(true);
    app(ErrorTracker::class)->report(new RuntimeException('boom'));
    $elapsed = microtime(true) - $start;

    // The fake throws immediately; the point is the path returns cleanly and
    // does not hang. Reaching this assertion proves report() did not throw.
    // Generous bound guards against an accidental real sleep.
    expect($elapsed)->toBeLessThan(5.0);
});

it('swallows a queue dispatch failure in async mode', function(): void {
    config()->set('error-tracker.sync', false);

    // Simulate an unavailable queue: the bus dispatcher throws on dispatch.
    $this->app->bind(Dispatcher::class, function(): Dispatcher {
        $dispatcher = Mockery::mock(Dispatcher::class);
        $dispatcher->shouldReceive('dispatch')->andThrow(new RuntimeException('queue unavailable'));

        return $dispatcher;
    });

    app(ErrorTracker::class)->report(new RuntimeException('boom'));
})->throwsNoExceptions();

it('drops the report without an HTTP call when configuration is missing', function(): void {
    config()->set('error-tracker.kendo_url', null);
    config()->set('error-tracker.project', null);
    config()->set('error-tracker.token', null);
    Http::fake();

    app(ErrorTracker::class)->report(new RuntimeException('boom'));

    // The send path short-circuits on the missing-config signal: no POST is
    // attempted, and report() returned without throwing.
    Http::assertNothingSent();
});

it('drops the report when only one required key is missing', function(string $key): void {
    config()->set("error-tracker.{$key}", null);
    Http::fake();

    app(ErrorTracker::class)->report(new RuntimeException('boom'));

    Http::assertNothingSent();
})->with(['kendo_url', 'project', 'token']);
