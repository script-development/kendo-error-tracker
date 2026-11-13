<?php

declare(strict_types = 1);

use Illuminate\Auth\GenericUser;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use ScriptDevelopment\KendoErrorTracker\ErrorTracker;
use ScriptDevelopment\KendoErrorTracker\Jobs\ReportErrorJob;

// The three switches a consumer sets when its data posture allows less than
// the defaults send: no exception message, no user_hash, and the queued report
// written through a connection of its own.

beforeEach(function(): void {
    config()->set('error-tracker.kendo_url', 'https://kendo.test');
    config()->set('error-tracker.project', '7');
    config()->set('error-tracker.token', 'secret-token');
    config()->set('error-tracker.environment', 'production');
    config()->set('error-tracker.sync', true);
    config()->set('app.key', 'base64:2fl+Ktvkfl+Fuz4Qp/A75G2RTiWVA/ZoKZvp6fiiM10=');

    Http::fake(['kendo.test/*' => Http::response('', 202)]);
});

/**
 * The one body sent to kendo.
 *
 * @return array<string, mixed>
 */
function theBodySent(): array
{
    $bodies = array_map(static fn(array $pair): array => $pair[0]->data(), Http::recorded()->all());

    expect($bodies)->toHaveCount(1);

    return array_values($bodies)[0];
}

function rawBodySent(): string
{
    $requests = Http::recorded()->all();

    expect($requests)->toHaveCount(1);

    /** @var Request $request */
    $request = array_values($requests)[0][0];

    return $request->body();
}

/**
 * Report a 500 from a route a signed-in user requested.
 */
function reportFromSignedInRoute(): void
{
    app(ExceptionHandler::class)->reportable(static function(Throwable $throwable): void {
        app(ErrorTracker::class)->report($throwable);
    });

    Route::get('/orders/{order}', static fn(): never => throw new RuntimeException('boom'));

    test()->actingAs(new GenericUser(['id' => 'usr-Jqz-wxy']));
    test()->get('/orders/42')->assertStatus(500);
}

describe('send_messages', function(): void {
    it('sends the message by default', function(): void {
        expect(config('error-tracker.send_messages'))->toBeTrue();

        app(ErrorTracker::class)->report(new RuntimeException('order 42 for Jan de Vries'));

        expect(theBodySent()['message'])->toBe('order 42 for Jan de Vries');
    });

    it('sends the message for a value that turns it on', function(mixed $value): void {
        config()->set('error-tracker.send_messages', $value);

        app(ErrorTracker::class)->report(new RuntimeException('order 42 for Jan de Vries'));

        expect(theBodySent()['message'])->toBe('order 42 for Jan de Vries');
    })->with([true, 'true', 'on', 'yes', '1']);

    it('withholds the message and every cause message for any other value', function(mixed $value): void {
        config()->set('error-tracker.send_messages', $value);

        $root = new InvalidArgumentException('lives at Dorpsstraat 1');
        $middle = new LogicException('called Jan de Vries', 0, $root);

        app(ErrorTracker::class)->report(new RuntimeException('order 42 for Jan de Vries', 0, $middle));

        $body = theBodySent();

        expect($body['message'])->toBe('[REDACTED:message]')
            ->and(array_column($body['previous_exceptions'], 'message'))->toBe(['[REDACTED:message]', '[REDACTED:message]'])
            ->and(array_column($body['previous_exceptions'], 'exception_class'))->toBe([LogicException::class, InvalidArgumentException::class])
            ->and($body['exception_class'])->toBe(RuntimeException::class)
            ->and(rawBodySent())->not->toContain('Jan de Vries')->not->toContain('Dorpsstraat');
    })->with([false, 'false', 'off', 'no', '0', '', 'nope', null]);

    it('withholds a database exception message, fingerprint included', function(): void {
        config()->set('error-tracker.send_messages', false);

        $throwable = null;

        try {
            DB::insert('insert into missing_table (name) values (?)', ['Jan de Vries']);
        } catch (QueryException $exception) {
            $throwable = $exception;
        }

        expect($throwable)->toBeInstanceOf(QueryException::class);

        app(ErrorTracker::class)->report($throwable);

        $body = theBodySent();

        expect($body['message'])->toBe('[REDACTED:message]')
            ->and($body['exception_code'])->toBe((string) $throwable->getCode())
            ->and(rawBodySent())->not->toContain('missing_table')->not->toContain('SQLSTATE');
    });
});

describe('send_user_hash', function(): void {
    it('sends the hash by default', function(): void {
        expect(config('error-tracker.send_user_hash'))->toBeTrue();

        reportFromSignedInRoute();

        expect(theBodySent())->toHaveKey('user_hash');
    });

    it('sends no user_hash key and never asks the tenant resolver when off', function(mixed $value): void {
        config()->set('error-tracker.send_user_hash', $value);

        $resolverCalls = 0;
        app(ErrorTracker::class)->scopeUserHashUsing(static function() use (&$resolverCalls): string {
            $resolverCalls++;

            return 'tenant-1';
        });

        reportFromSignedInRoute();

        expect(theBodySent())->not->toHaveKey('user_hash')
            ->toHaveKey('context')
            ->and(theBodySent()['context']['kind'])->toBe('route')
            ->and($resolverCalls)->toBe(0);
    })->with([false, 'false', 'off', 'no', '0', '', 'nope', null]);
});

describe('queue_connection', function(): void {
    it('dispatches on the default connection by default', function(): void {
        config()->set('error-tracker.sync', false);
        Bus::fake();

        expect(config('error-tracker.queue_connection'))->toBeNull();

        app(ErrorTracker::class)->report(new RuntimeException('boom'));

        Bus::assertDispatched(ReportErrorJob::class, static fn(ReportErrorJob $job): bool => $job->connection === null);
    });

    it('dispatches on the default connection for a blank or non-string name', function(mixed $value): void {
        config()->set('error-tracker.sync', false);
        config()->set('error-tracker.queue_connection', $value);
        Bus::fake();

        app(ErrorTracker::class)->report(new RuntimeException('boom'));

        Bus::assertDispatched(ReportErrorJob::class, static fn(ReportErrorJob $job): bool => $job->connection === null);
    })->with(['', '   ', [['reports']]]);

    it('dispatches on the configured connection', function(): void {
        config()->set('error-tracker.sync', false);
        config()->set('error-tracker.queue_connection', 'error-reports');
        Bus::fake();

        app(ErrorTracker::class)->report(new RuntimeException('boom'));

        Bus::assertDispatched(ReportErrorJob::class, static fn(ReportErrorJob $job): bool => $job->connection === 'error-reports');
    });

    it('posts inline and queues nothing in sync mode, whatever the connection', function(): void {
        config()->set('error-tracker.queue_connection', 'error-reports');
        Bus::fake();

        app(ErrorTracker::class)->report(new RuntimeException('boom'));

        Bus::assertNotDispatched(ReportErrorJob::class);
        expect(theBodySent()['message'])->toBe('boom');
    });

    // LK-1662: a report raised inside a transaction that then rolls back. Two
    // connections onto one SQLite file stand in for the consumer's two PDO
    // connections onto one database. The transaction writes nothing itself,
    // because SQLite allows one writer at a time where MySQL locks rows.
    it('keeps the job row of a report whose transaction rolls back only on a connection of its own', function(?string $queueConnection, int $rowsLeft): void {
        $file = (string) tempnam(sys_get_temp_dir(), 'kendo-error-tracker-rollback');

        try {
            foreach (['app_db', 'reports_db'] as $connection) {
                config()->set('database.connections.' . $connection, ['driver' => 'sqlite', 'database' => $file, 'prefix' => '', 'foreign_key_constraints' => false]);
            }

            config()->set('database.default', 'app_db');
            config()->set('queue.connections.database', ['driver' => 'database', 'connection' => 'app_db', 'table' => 'jobs', 'queue' => 'default', 'retry_after' => 90]);
            config()->set('queue.connections.error-reports', ['driver' => 'database', 'connection' => 'reports_db', 'table' => 'jobs', 'queue' => 'default', 'retry_after' => 90]);
            config()->set('queue.default', 'database');
            config()->set('error-tracker.sync', false);
            config()->set('error-tracker.queue_connection', $queueConnection);

            Schema::connection('app_db')->create('jobs', static function(Blueprint $table): void {
                $table->id();
                $table->string('queue')->index();
                $table->longText('payload');
                $table->unsignedTinyInteger('attempts');
                $table->unsignedInteger('reserved_at')->nullable();
                $table->unsignedInteger('available_at');
                $table->unsignedInteger('created_at');
            });

            DB::connection('app_db')->beginTransaction();
            app(ErrorTracker::class)->report(new RuntimeException('boom'));
            DB::connection('app_db')->rollBack();

            expect(DB::connection('reports_db')->table('jobs')->count())->toBe($rowsLeft);
        } finally {
            DB::purge('app_db');
            DB::purge('reports_db');
            unlink($file);
        }
    })->with([
        'its own connection' => ['error-reports', 1],
        'the default connection' => [null, 0],
    ]);
});
