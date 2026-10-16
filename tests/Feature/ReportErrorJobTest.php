<?php

declare(strict_types = 1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use ScriptDevelopment\KendoErrorTracker\ErrorTracker;
use ScriptDevelopment\KendoErrorTracker\Jobs\ReportErrorJob;

beforeEach(function(): void {
    config()->set('error-tracker.kendo_url', 'https://kendo.test');
    config()->set('error-tracker.project', '7');
    config()->set('error-tracker.token', 'secret-token');
    config()->set('error-tracker.environment', 'production');
    config()->set('error-tracker.release', 'v1.2.3');
    config()->set('error-tracker.sync', false);
});

/**
 * Point the default queue at a database table a real worker reads from, so the
 * job crosses a genuine serialisation boundary.
 */
function queueReportsInTheDatabase(): void
{
    config()->set('queue.connections.database', [
        'driver' => 'database',
        'table' => 'jobs',
        'queue' => 'default',
        'retry_after' => 90,
    ]);
    config()->set('queue.default', 'database');
    config()->set('queue.failed.driver', 'null');

    Schema::create('jobs', static function(Blueprint $table): void {
        $table->id();
        $table->string('queue')->index();
        $table->longText('payload');
        $table->unsignedTinyInteger('attempts');
        $table->unsignedInteger('reserved_at')->nullable();
        $table->unsignedInteger('available_at');
        $table->unsignedInteger('created_at');
    });
}

/**
 * Every request body sent to kendo, in order.
 *
 * @return list<array<string, mixed>>
 */
function bodiesPosted(): array
{
    return array_values(array_map(
        static fn(array $pair): array => $pair[0]->data(),
        Http::recorded()->all(),
    ));
}

/**
 * What the callback wrote to PHP's error_log.
 */
function errorLogDuring(callable $callback): string
{
    $log = (string) tempnam(sys_get_temp_dir(), 'kendo-error-tracker-job-log');
    $previousLog = ini_set('error_log', $log);

    try {
        $callback();

        return (string) file_get_contents($log);
    } finally {
        ini_set('error_log', (string) $previousLog);
        unlink($log);
    }
}

function queuedJob(Throwable $throwable): ReportErrorJob
{
    Bus::fake();
    app(ErrorTracker::class)->report($throwable);

    $job = null;
    Bus::assertDispatched(ReportErrorJob::class, static function(ReportErrorJob $dispatched) use (&$job): bool {
        $job = $dispatched;

        return true;
    });

    if (!$job instanceof ReportErrorJob) {
        throw new RuntimeException('no ReportErrorJob was dispatched');
    }

    return $job;
}

it('posts the queued report to kendo when a worker runs the job', function(): void {
    queueReportsInTheDatabase();
    Http::fake(['kendo.test/*' => Http::response('', 202)]);

    app(ErrorTracker::class)->report(new RuntimeException('boom'));

    Http::assertNothingSent();
    expect(DB::table('jobs')->count())->toBe(1);

    app('queue.worker')->runNextJob('database', 'default', new WorkerOptions);

    expect(DB::table('jobs')->count())->toBe(0);
    Http::assertSentCount(1);
    Http::assertSent(static function(Request $request): bool {
        expect($request->method())->toBe('POST')
            ->and($request->url())->toBe('https://kendo.test/api/projects/7/error-events')
            ->and($request->hasHeader('Authorization', 'Bearer secret-token'))->toBeTrue()
            ->and($request->hasHeader('Content-Type', 'application/json'))->toBeTrue()
            ->and($request->data())->toMatchArray([
                'environment' => 'production',
                'release' => 'v1.2.3',
                'exception_class' => RuntimeException::class,
                'message' => 'boom',
            ]);

        return true;
    });
});

it('posts the same body through the queue as it posts inline', function(): void {
    queueReportsInTheDatabase();
    Http::fake(['kendo.test/*' => Http::response('', 202)]);
    $throwable = captureThrowableFromFixture('trace-secret-jan@example.com');

    config()->set('error-tracker.sync', true);
    app(ErrorTracker::class)->report($throwable);

    config()->set('error-tracker.sync', false);
    app(ErrorTracker::class)->report($throwable);
    app('queue.worker')->runNextJob('database', 'default', new WorkerOptions);

    [$inline, $queued] = bodiesPosted();
    unset($inline['memory_peak_bytes'], $queued['memory_peak_bytes']);

    expect($queued)->toBe($inline);
});

it('carries no raw secret across the queue boundary, and posts none', function(): void {
    queueReportsInTheDatabase();
    Http::fake(['kendo.test/*' => Http::response('', 202)]);
    $raw = ['jan@example.com', 'abc123DEFtoken', '123456782'];

    app(ErrorTracker::class)->report(new RuntimeException(
        'mail jan@example.com with Bearer abc123DEFtoken for BSN 123456782',
        0,
        captureThrowableFromFixture('trace-secret-jan@example.com'),
    ));

    $stored = (string) DB::table('jobs')->value('payload');
    expect($stored)
        ->toContain('ReportErrorJob')
        ->toContain('[REDACTED:email]')
        ->toContain('[REDACTED:bearer]')
        ->toContain('[REDACTED:bsn]');
    foreach ($raw as $secret) {
        expect($stored)->not->toContain($secret);
    }

    app('queue.worker')->runNextJob('database', 'default', new WorkerOptions);

    $posted = (string) json_encode(bodiesPosted());
    expect($posted)
        ->toContain('[REDACTED:email]')
        ->toContain('[REDACTED:bearer]')
        ->toContain('[REDACTED:bsn]');
    foreach ($raw as $secret) {
        expect($posted)->not->toContain($secret);
    }
});

it('swallows a rejected response when the job runs, and logs the status', function(int $status): void {
    $job = queuedJob(new RuntimeException('boom'));
    Http::fake(['kendo.test/*' => Http::response('', $status)]);

    $log = errorLogDuring(static fn() => $job->handle(app(ErrorTracker::class)));

    Http::assertSentCount(1);
    expect($log)->toContain("[kendo-error-tracker] send rejected: HTTP {$status}");
})->with([
    '401 unauthorized' => 401,
    '422 unprocessable' => 422,
    '500 server error' => 500,
    '503 unavailable' => 503,
]);

it('swallows an unreachable host when the job runs, and logs the failure', function(): void {
    $job = queuedJob(new RuntimeException('boom'));
    Http::fake(static function(): void {
        throw new ConnectionException('Connection timed out');
    });

    $log = errorLogDuring(static fn() => $job->handle(app(ErrorTracker::class)));

    expect($log)->toContain('[kendo-error-tracker] send failed: Connection timed out');
});

it('drops the report without a request when the job runs unconfigured', function(): void {
    $job = queuedJob(new RuntimeException('boom'));
    config()->set('error-tracker.token', null);
    Http::fake();

    $log = errorLogDuring(static fn() => $job->handle(app(ErrorTracker::class)));

    Http::assertNothingSent();
    expect($log)->toContain('[kendo-error-tracker] not configured');
});

it('makes one attempt and never retries', function(): void {
    expect(new ReportErrorJob([])->tries)->toBe(1);
});

it('logs the infrastructure failure from failed() without throwing', function(?Throwable $exception, string $expected): void {
    $log = errorLogDuring(static fn() => new ReportErrorJob([])->failed($exception));

    expect($log)->toContain("[kendo-error-tracker] ReportErrorJob failed: {$expected}");
})->with([
    'with an exception' => [new RuntimeException('worker lost'), 'worker lost'],
    'without one' => [null, 'unknown'],
]);
