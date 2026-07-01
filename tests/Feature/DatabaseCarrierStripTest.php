<?php

declare(strict_types = 1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use ScriptDevelopment\KendoErrorTracker\ErrorTracker;

beforeEach(function(): void {
    config()->set('error-tracker.kendo_url', 'https://kendo.test');
    config()->set('error-tracker.project', '7');
    config()->set('error-tracker.token', 'secret-token');
    config()->set('error-tracker.sync', true);
});

it('strips the SQL and bound values from a QueryException, keeping only the fingerprint', function(): void {
    // The emmie annexation gap (KD-0887 comment): a QueryException message
    // embeds the failing SQL WITH bound parameter values interpolated in, so
    // free-text care data (a client name) reaches the message string. That is
    // not a regex-able secret shape, so the Scrubber cannot catch it — the
    // carrier-strip must replace the whole message before the Scrubber runs.
    Http::fake(['kendo.test/*' => Http::response('', 202)]);

    $throwable = null;

    try {
        DB::insert('insert into missing_table (name) values (?)', ['Jan de Vries']);
    } catch (QueryException $exception) {
        $throwable = $exception;
    }

    expect($throwable)->toBeInstanceOf(QueryException::class);

    // Guard against a false-green: the care-data value and the SQL must be
    // present in the RAW message before the carrier-strip runs.
    expect($throwable->getMessage())
        ->toContain('Jan de Vries')
        ->toContain('missing_table');

    app(ErrorTracker::class)->report($throwable);

    Http::assertSent(function($request) use ($throwable): bool {
        $body = $request->data();

        expect($body['exception_class'])->toBe($throwable::class);
        expect($body['message'])
            ->toContain($throwable::class)
            ->not->toContain('Jan de Vries')
            ->not->toContain('missing_table')
            ->not->toContain('insert into');

        return true;
    });
});

it('strips a raw PDOException message the same way, not only Laravel\'s QueryException', function(): void {
    Http::fake(['kendo.test/*' => Http::response('', 202)]);

    $throwable = new \PDOException("Duplicate entry 'Jan de Vries' for key 'PRIMARY'");
    $throwable->errorInfo = ['23000', 1_062, "Duplicate entry 'Jan de Vries' for key 'PRIMARY'"];

    app(ErrorTracker::class)->report($throwable);

    Http::assertSent(function($request): bool {
        $body = $request->data();

        expect($body['message'])
            ->toBe(\PDOException::class . ' [SQLSTATE 23000] [driver code 1062]')
            ->not->toContain('Jan de Vries');

        return true;
    });
});

it('falls back to "unknown" when a PDOException carries no errorInfo', function(): void {
    Http::fake(['kendo.test/*' => Http::response('', 202)]);

    app(ErrorTracker::class)->report(new \PDOException('some pdo failure'));

    Http::assertSent(function($request): bool {
        expect($request->data()['message'])
            ->toBe(\PDOException::class . ' [SQLSTATE unknown] [driver code unknown]');

        return true;
    });
});

it('does not carrier-strip a non-database exception', function(): void {
    Http::fake(['kendo.test/*' => Http::response('', 202)]);

    app(ErrorTracker::class)->report(new \RuntimeException('plain failure, not a database error'));

    Http::assertSent(function($request): bool {
        expect($request->data()['message'])->toBe('plain failure, not a database error');

        return true;
    });
});
