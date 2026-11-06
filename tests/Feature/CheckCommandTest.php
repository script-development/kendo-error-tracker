<?php

declare(strict_types = 1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use ScriptDevelopment\KendoErrorTracker\ErrorTracker;

beforeEach(function(): void {
    config()->set('error-tracker.kendo_url', 'https://planted-url.test');
    config()->set('error-tracker.project', 'planted-project');
    config()->set('error-tracker.token', 'planted-token');
});

function checkErrorLogDuring(callable $callback): string
{
    $log = (string) tempnam(sys_get_temp_dir(), 'kendo-error-tracker-check-log');
    $previousLog = ini_set('error_log', $log);

    try {
        $callback();

        return (string) file_get_contents($log);
    } finally {
        ini_set('error_log', (string) $previousLog);
        unlink($log);
    }
}

it('exits 0 when every setting is there, saying nothing was sent', function(): void {
    expect(Artisan::call('error-tracker:check'))->toBe(0)
        ->and(Artisan::output())->toContain('Nothing was sent');
});

it('exits 1 on a blank setting, and send drops a report on the same value', function(string $setting, mixed $value): void {
    config()->set('error-tracker.' . $setting, $value);
    Http::fake();

    expect(Artisan::call('error-tracker:check'))->toBe(1);

    $log = checkErrorLogDuring(static fn() => app(ErrorTracker::class)->send(['message' => 'boom']));

    Http::assertNothingSent();
    expect($log)->toContain('[kendo-error-tracker] not configured');
})->with(['kendo_url', 'project', 'token'])->with([
    'missing' => [null],
    'empty' => [''],
    'spaces' => ['   '],
    'a newline' => ["\n"],
    'a non-breaking space' => ["\u{00A0}"],
    'false' => [false],
    'not a scalar' => [['planted']],
]);

it('exits 0 on a set value, and send posts the same value', function(string $setting, mixed $value): void {
    config()->set('error-tracker.' . $setting, $value);
    Http::fake(['*' => Http::response('', 202)]);

    expect(Artisan::call('error-tracker:check'))->toBe(0);

    app(ErrorTracker::class)->send(['message' => 'boom']);

    Http::assertSentCount(1);
})->with(['kendo_url', 'project', 'token'])->with([
    'zero' => [0],
    'a zero string' => ['0'],
    'wrong but not blank' => ['wrong'],
    'true' => [true],
]);

it('names only the blank setting, and prints no value', function(string $blank, string $named, array $notNamed): void {
    config()->set('error-tracker.' . $blank, '');

    expect(Artisan::call('error-tracker:check'))->toBe(1);

    expect(Artisan::output())
        ->toContain($named)
        ->not->toContain('planted-url')
        ->not->toContain('planted-project')
        ->not->toContain('planted-token')
        ->not->toContain($notNamed[0])
        ->not->toContain($notNamed[1]);
})->with([
    'url' => ['kendo_url', 'ERROR_TRACKER_KENDO_URL', ['ERROR_TRACKER_PROJECT', 'ERROR_TRACKER_TOKEN']],
    'project' => ['project', 'ERROR_TRACKER_PROJECT', ['ERROR_TRACKER_KENDO_URL', 'ERROR_TRACKER_TOKEN']],
    'token' => ['token', 'ERROR_TRACKER_TOKEN', ['ERROR_TRACKER_KENDO_URL', 'ERROR_TRACKER_PROJECT']],
]);

it('prints no value when all is well', function(): void {
    Artisan::call('error-tracker:check');

    expect(Artisan::output())
        ->not->toContain('planted-url')
        ->not->toContain('planted-project')
        ->not->toContain('planted-token');
});

it('names every blank setting at once', function(): void {
    config()->set('error-tracker.kendo_url', null);
    config()->set('error-tracker.project', '');
    config()->set('error-tracker.token', ' ');

    expect(Artisan::call('error-tracker:check'))->toBe(1);

    expect(Artisan::output())
        ->toContain('ERROR_TRACKER_KENDO_URL')
        ->toContain('ERROR_TRACKER_PROJECT')
        ->toContain('ERROR_TRACKER_TOKEN');
});

it('sends and reports nothing, red or green', function(?string $token, int $exitCode): void {
    config()->set('error-tracker.token', $token);
    Http::fake();
    Bus::fake();

    $log = checkErrorLogDuring(static fn() => expect(Artisan::call('error-tracker:check'))->toBe($exitCode));

    Http::assertNothingSent();
    Bus::assertNothingDispatched();
    expect($log)->toBe('');
})->with([
    'red' => [null, 1],
    'green' => ['planted-token', 0],
]);
