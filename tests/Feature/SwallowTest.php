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

it('does not throw when configuration is entirely missing', function(): void {
    config()->set('error-tracker.kendo_url', null);
    config()->set('error-tracker.project', null);
    config()->set('error-tracker.token', null);
    Http::fake();

    app(ErrorTracker::class)->report(new RuntimeException('boom'));
})->throwsNoExceptions();
