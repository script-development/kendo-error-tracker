<?php

declare(strict_types = 1);

use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Console\Kernel;
use Illuminate\Http\Client\Request;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use ScriptDevelopment\KendoErrorTracker\ErrorTracker;
use ScriptDevelopment\KendoErrorTracker\RunningContext;
use ScriptDevelopment\KendoErrorTracker\Tests\Fixtures\CallingCommand;
use ScriptDevelopment\KendoErrorTracker\Tests\Fixtures\FailingCommand;
use ScriptDevelopment\KendoErrorTracker\Tests\Fixtures\FailingJob;
use ScriptDevelopment\KendoErrorTracker\Tests\Fixtures\NoopCommand;
use ScriptDevelopment\KendoErrorTracker\Tests\Fixtures\OrderController;
use ScriptDevelopment\KendoErrorTracker\Tests\Fixtures\ReportingJob;
use ScriptDevelopment\KendoErrorTracker\Tests\Fixtures\SyncDispatchingJob;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function(): void {
    config()->set('error-tracker.kendo_url', 'https://kendo.test');
    config()->set('error-tracker.project', '7');
    config()->set('error-tracker.token', 'secret-token');
    config()->set('error-tracker.environment', 'production');
    config()->set('error-tracker.sync', true);

    Http::fake(['kendo.test/*' => Http::response('', 202)]);

    // The integration the README documents: the app's handler hands every
    // reported exception to the tracker.
    app(ExceptionHandler::class)->reportable(static function(Throwable $throwable): void {
        app(ErrorTracker::class)->report($throwable);
    });
});

/**
 * Every body sent to kendo, in order, keyed by message.
 *
 * @return array<string, array<string, mixed>>
 */
function sentBodies(): array
{
    $bodies = [];

    foreach (Http::recorded() as [$request]) {
        /** @var Request $request */
        $body = $request->data();
        $bodies[(string) $body['message']] = $body;
    }

    return $bodies;
}

/**
 * What the callback wrote to PHP's error_log.
 */
function errorLogOf(callable $callback): string
{
    $log = (string) tempnam(sys_get_temp_dir(), 'kendo-error-tracker-log');
    $previousLog = ini_set('error_log', $log);

    try {
        $callback();

        return (string) file_get_contents($log);
    } finally {
        ini_set('error_log', (string) $previousLog);
        unlink($log);
    }
}

/**
 * The database queue a real worker reads from.
 */
function useDatabaseQueue(): void
{
    config()->set('queue.connections.database', [
        'driver' => 'database',
        'table' => 'jobs',
        'queue' => 'default',
        'retry_after' => 90,
    ]);
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
 * Run one artisan command the way `php artisan` does: through Laravel's own
 * console kernel's handle(), which reports the command's exception (Testbench's
 * kernel rethrows it instead), with the command events rerouted from Symfony's
 * as outside unit tests.
 */
function runArtisan(string $command): int
{
    $kernel = new Kernel(app(), app('events'));
    app()->instance(ConsoleKernel::class, $kernel);
    $kernel->rerouteSymfonyCommandEvents();

    foreach ([new FailingCommand, new CallingCommand, new NoopCommand] as $registered) {
        $kernel->registerCommand($registered);
    }

    return $kernel->handle(new ArrayInput(['command' => $command]), new BufferedOutput);
}

it('sends the route pattern of a request, never its URL or query string', function(): void {
    Route::get('/orders/{order}', [OrderController::class, 'show'])->name('orders.show');

    $this->get('/orders/42?token=abc123&page=2')->assertStatus(500);

    $body = sentBodies()['order not shown'];

    expect($body['context'])->toBe([
        'kind' => 'route',
        'name' => 'orders.show',
        'method' => 'GET',
        'pattern' => '/orders/{order}',
        'action' => OrderController::class . '@show',
    ]);

    expect(json_encode($body, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES))
        ->not->toContain('/orders/42')
        ->not->toContain('abc123')
        ->not->toContain('page=2');
});

it('sends the status an HTTP exception carries, and leaves out the action of a closure route', function(): void {
    Route::get('/teapot', static function(ErrorTracker $tracker): string {
        $tracker->report(new HttpException(418, 'teapot'));
        $tracker->report(new HttpException(999, 'off the scale'));

        return 'ok';
    });

    $this->get('/teapot')->assertOk();

    expect(sentBodies()['teapot']['context'])->toBe([
        'kind' => 'route',
        'method' => 'GET',
        'pattern' => '/teapot',
        'response_status' => 418,
    ])->and(sentBodies()['off the scale']['context'])->not->toHaveKey('response_status');
});

it('scrubs and cuts a context string, and does not path-normalize it', function(): void {
    Route::get('/home/{user}/settings', static function(ErrorTracker $tracker): string {
        $tracker->report(new RuntimeException('boom'));

        return 'ok';
    })->name('notify jan@example.com ' . str_repeat('x', 300));

    $this->get('/home/jan/settings')->assertOk();

    $context = sentBodies()['boom']['context'];

    expect($context['pattern'])->toBe('/home/{user}/settings')
        ->and($context['name'])->toStartWith('notify [REDACTED:email] x')
        ->not->toContain('jan@example.com')
        ->and(mb_strlen($context['name']))->toBe(255);
});

it('sends the job context with the exception of a queued job, and none after the job ended', function(int $maxTries): void {
    useDatabaseQueue();
    Queue::connection('database')->pushOn('reports', new FailingJob);

    app('queue.worker')->runNextJob('database', 'reports', new WorkerOptions(maxTries: $maxTries));
    app(ErrorTracker::class)->report(new RuntimeException('after the job'));

    $bodies = sentBodies();

    expect($bodies['job failed']['context'])->toBe([
        'kind' => 'job',
        'name' => FailingJob::class,
        'queue' => 'reports',
        'attempt' => 1,
    ])->and($bodies['after the job'])->not->toHaveKey('context');
})->with([
    'failed on its last try' => [1],
    'released for a retry' => [3],
]);

it('sends the inner sync job, not the queued job its exception bubbled through', function(): void {
    useDatabaseQueue();
    Queue::connection('database')->pushOn('reports', new SyncDispatchingJob);

    app('queue.worker')->runNextJob('database', 'reports', new WorkerOptions);

    expect(sentBodies()['job failed']['context'])->toMatchArray([
        'kind' => 'job',
        'name' => FailingJob::class,
        'attempt' => 1,
    ]);
});

it('sends the job context for a report made while the job runs', function(): void {
    useDatabaseQueue();
    Queue::connection('database')->pushOn('reports', new ReportingJob);

    app('queue.worker')->runNextJob('database', 'reports', new WorkerOptions);
    app(ErrorTracker::class)->report(new RuntimeException('after the job'));

    $bodies = sentBodies();

    expect($bodies['caught inside the job']['context'])->toBe([
        'kind' => 'job',
        'name' => ReportingJob::class,
        'queue' => 'reports',
        'attempt' => 1,
    ])->and($bodies['after the job'])->not->toHaveKey('context');
});

it('sends the job context, not the route, for a sync-queue job that fails inside a request', function(): void {
    Route::get('/sync-job', static function(): string {
        Queue::connection('sync')->push(new FailingJob);

        return 'ok';
    });

    $this->get('/sync-job')->assertStatus(500);

    expect(sentBodies()['job failed']['context'])->toMatchArray([
        'kind' => 'job',
        'name' => FailingJob::class,
        'attempt' => 1,
    ]);
});

it('sends the route, not the running command, for a request', function(): void {
    app(RunningContext::class)->commandStarted('octane:start');
    Route::get('/orders/{order}', [OrderController::class, 'show']);

    $this->get('/orders/42')->assertStatus(500);

    expect(sentBodies()['order not shown']['context']['kind'])->toBe('route');
});

it('sends the command context with the exception of a command, though its finish fired first', function(): void {
    expect(runArtisan('kendo:fail'))->toBe(1);

    expect(sentBodies()['command failed']['context'])->toBe([
        'kind' => 'command',
        'name' => 'kendo:fail',
        'class' => FailingCommand::class,
    ]);
});

it('sends the outer command after a nested Artisan::call has finished', function(): void {
    expect(runArtisan('kendo:call'))->toBe(0);

    expect(sentBodies()['after the nested command']['context'])->toBe([
        'kind' => 'command',
        'name' => 'kendo:call',
        'class' => CallingCommand::class,
    ]);
});

it('sends no context outside a request, job or command', function(): void {
    app(ErrorTracker::class)->report(new RuntimeException('nowhere'));

    expect(sentBodies()['nowhere'])->not->toHaveKey('context');
});

it('leaves out a context field that cannot be read, and still sends the context', function(): void {
    $job = Mockery::mock(Job::class);
    $job->allows('resolveName')->andThrow(new RuntimeException('no name for jan@example.com'));
    $job->allows('getQueue')->andReturn('reports');
    $job->allows('attempts')->andReturn(2);
    app(RunningContext::class)->jobStarted($job);

    $logged = errorLogOf(static fn() => app(ErrorTracker::class)->report(new RuntimeException('boom')));

    expect($logged)->toContain('[kendo-error-tracker] context.name left out: ' . RuntimeException::class)
        ->not->toContain('jan@example.com');

    expect(sentBodies()['boom']['context'])->toBe(['kind' => 'job', 'queue' => 'reports', 'attempt' => 2]);
});

it('leaves the context out when it cannot be read, and still sends the report', function(): void {
    app()->bind('request', static fn() => throw new RuntimeException('no request'));

    $logged = errorLogOf(static fn() => app(ErrorTracker::class)->report(new RuntimeException('boom')));

    expect($logged)->toContain('[kendo-error-tracker] context left out: ' . RuntimeException::class);

    expect(sentBodies()['boom'])->not->toHaveKey('context')
        ->and(sentBodies()['boom']['exception_class'])->toBe(RuntimeException::class);
});
