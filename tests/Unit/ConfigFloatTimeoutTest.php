<?php

declare(strict_types = 1);

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use ScriptDevelopment\KendoErrorTracker\ErrorTracker;
use ScriptDevelopment\KendoErrorTracker\PathNormalizer;
use ScriptDevelopment\KendoErrorTracker\Scrubber;

/*
|--------------------------------------------------------------------------
| H-3 — configFloat must floor a non-positive timeout to the default.
|--------------------------------------------------------------------------
|
| is_numeric('0') === true and is_numeric('-1') === true, so a configured 0 or
| negative timeout would previously pass straight through to Guzzle as an
| "infinite" timeout — a hung kendo host would then block the caller in sync
| mode, breaking the never-block invariant. configFloat() now floors a
| non-positive numeric to the supplied default.
|
| This is a BEHAVIORAL test: it drives the real send() path and captures the
| connectTimeout()/timeout() values actually applied to the request, rather
| than asserting on a string in the source.
|
 */

/**
 * Build an ErrorTracker whose HTTP factory records the connect/total timeout
 * values applied to the outbound request, with config sourced from $overrides.
 *
 * @param array<string, mixed> $overrides
 *
 * @return array{tracker: ErrorTracker, captured: object}
 */
function trackerCapturingTimeouts(array $overrides): array
{
    $captured = new class {
        public ?float $connectTimeout = null;

        public ?float $timeout = null;
    };

    $config = [
        'error-tracker.kendo_url' => 'https://kendo.test',
        'error-tracker.project' => '7',
        'error-tracker.token' => 'secret-token',
        'error-tracker.environment' => 'production',
        'error-tracker.release' => null,
        'error-tracker.sync' => true,
        ...$overrides,
    ];

    $configRepo = Mockery::mock(Config::class);
    $configRepo->shouldReceive('get')->andReturnUsing(
        static fn(string $key, mixed $default = null): mixed => $config[$key] ?? $default,
    );

    $pending = Mockery::mock(PendingRequest::class);
    $pending->shouldReceive('withToken')->andReturnSelf();
    $pending->shouldReceive('connectTimeout')->andReturnUsing(
        static function(float $seconds) use ($pending, $captured): PendingRequest {
            $captured->connectTimeout = $seconds;

            return $pending;
        },
    );
    $pending->shouldReceive('timeout')->andReturnUsing(
        static function(float $seconds) use ($pending, $captured): PendingRequest {
            $captured->timeout = $seconds;

            return $pending;
        },
    );
    $pending->shouldReceive('acceptJson')->andReturnSelf();
    $pending->shouldReceive('asJson')->andReturnSelf();

    $response = Mockery::mock(Response::class);
    $response->shouldReceive('status')->andReturn(202);
    $pending->shouldReceive('post')->andReturn($response);

    $http = Mockery::mock(HttpFactory::class);
    $http->shouldReceive('withToken')->andReturn($pending);

    $tracker = new ErrorTracker(
        $http,
        Mockery::mock(Container::class),
        new Scrubber,
        new PathNormalizer('/app'),
        $configRepo,
    );

    return ['tracker' => $tracker, 'captured' => $captured];
}

it('applies a positive configured timeout unchanged', function(): void {
    ['tracker' => $tracker, 'captured' => $captured] = trackerCapturingTimeouts([
        'error-tracker.connect_timeout' => 3,
        'error-tracker.timeout' => 7,
    ]);

    $tracker->report(new RuntimeException('boom'));

    expect($captured->connectTimeout)->toBe(3.0)
        ->and($captured->timeout)->toBe(7.0);
});

it('floors a zero timeout to the default', function(): void {
    ['tracker' => $tracker, 'captured' => $captured] = trackerCapturingTimeouts([
        'error-tracker.connect_timeout' => 0,
        'error-tracker.timeout' => 0,
    ]);

    $tracker->report(new RuntimeException('boom'));

    // Defaults baked into send(): connect 2.0s, total 5.0s.
    expect($captured->connectTimeout)->toBe(2.0)
        ->and($captured->timeout)->toBe(5.0);
});

it('floors a negative timeout to the default', function(): void {
    ['tracker' => $tracker, 'captured' => $captured] = trackerCapturingTimeouts([
        'error-tracker.connect_timeout' => -1,
        'error-tracker.timeout' => -10,
    ]);

    $tracker->report(new RuntimeException('boom'));

    expect($captured->connectTimeout)->toBe(2.0)
        ->and($captured->timeout)->toBe(5.0);
});

it('falls back to the default for a non-numeric timeout', function(): void {
    ['tracker' => $tracker, 'captured' => $captured] = trackerCapturingTimeouts([
        'error-tracker.connect_timeout' => 'nonsense',
        'error-tracker.timeout' => null,
    ]);

    $tracker->report(new RuntimeException('boom'));

    expect($captured->connectTimeout)->toBe(2.0)
        ->and($captured->timeout)->toBe(5.0);
});
