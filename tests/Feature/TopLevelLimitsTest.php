<?php

declare(strict_types = 1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use ScriptDevelopment\KendoErrorTracker\ErrorTracker;

beforeEach(function(): void {
    config()->set('error-tracker.kendo_url', 'https://kendo.test');
    config()->set('error-tracker.project', '7');
    config()->set('error-tracker.token', 'secret-token');
    config()->set('error-tracker.environment', 'production');
    config()->set('error-tracker.sync', true);

    // A fake server that answers 422 like kendo does when exception_class or
    // stack_trace is over its limit, and 202 otherwise.
    Http::fake(function(Request $request) {
        $body = $request->data();

        if (mb_strlen($body['exception_class']) > 255 || mb_strlen($body['stack_trace']) > 131_072) {
            return Http::response('', 422);
        }

        return Http::response('', 202);
    });
});

function throwFromTheTopOfARecursionWhoseFunctionNameIsLongOnPurposeToMakeEveryTraceLineLongerThanTheLimitNeedsXXX(int $depth): never
{
    if ($depth === 0) {
        throw new LogicException('deep top-level exception');
    }

    throwFromTheTopOfARecursionWhoseFunctionNameIsLongOnPurposeToMakeEveryTraceLineLongerThanTheLimitNeedsXXX($depth - 1);
}

/**
 * @return array{0: array<string, mixed>, 1: int}
 */
function reportedBodyAndStatus(Throwable $throwable): array
{
    app(ErrorTracker::class)->report($throwable);

    $recorded = Http::recorded();
    expect($recorded)->toHaveCount(1);

    [$request, $response] = $recorded[0];

    return [$request->data(), $response->status()];
}

it('cuts an oversized top-level stack trace to 131,072 characters so the report is still accepted', function(): void {
    try {
        throwFromTheTopOfARecursionWhoseFunctionNameIsLongOnPurposeToMakeEveryTraceLineLongerThanTheLimitNeedsXXX(1_500);
    } catch (LogicException $deep) {
        $thrown = $deep;
    }

    expect(mb_strlen($thrown->getTraceAsString()))->toBeGreaterThanOrEqual(200_000);

    [$body, $status] = reportedBodyAndStatus($thrown);

    expect($status)->toBe(202)
        ->and(mb_strlen($body['stack_trace']))->toBe(131_072)
        ->and($body['stack_trace'])->toStartWith('#0 ');
});

it('cuts an oversized top-level exception class to 255 characters so the report is still accepted', function(): void {
    require_once __DIR__ . '/../Fixtures/long-class-name.php';
    $longClass = 'ExceptionWithAClassNameLongerThanTheServerLimit' . str_repeat('X', 260);
    expect(mb_strlen($longClass))->toBeGreaterThan(255);

    [$body, $status] = reportedBodyAndStatus(new $longClass('boom'));

    expect($status)->toBe(202)
        ->and($body['exception_class'])->toBe(mb_substr($longClass, 0, 255));
});

it('leaves a class and trace within the limits uncut', function(): void {
    $thrown = new RuntimeException('small');

    [$body, $status] = reportedBodyAndStatus($thrown);

    expect($status)->toBe(202)
        ->and($body['exception_class'])->toBe(RuntimeException::class)
        ->and($body['stack_trace'])->toEndWith('{main}');
});
