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
});

/**
 * Recurse $depth frames deep under a long function name, then throw, so the
 * trace string is far longer than the server's 131,072-character limit.
 */
function throwFromDeepInsideARecursionWhoseFunctionNameIsLongOnPurposeToMakeEveryTraceLineLongerThanTheLimitNeedsXX(int $depth): never
{
    if ($depth === 0) {
        throw new LogicException('deep cause');
    }

    throwFromDeepInsideARecursionWhoseFunctionNameIsLongOnPurposeToMakeEveryTraceLineLongerThanTheLimitNeedsXX($depth - 1);
}

/**
 * @return list<array<string, mixed>>
 */
function sentChain(Request $request): array
{
    /** @var list<array<string, mixed>> $chain */
    $chain = $request->data()['previous_exceptions'];

    return $chain;
}

it('sends the caused-by chain outermost first, scrubbed like the thrown exception', function(): void {
    Http::fake(['kendo.test/*' => Http::response('', 202)]);

    $root = new InvalidArgumentException('token Bearer abc123DEFtoken for jan@example.com');
    $middle = new LogicException('middle', 0, $root);

    app(ErrorTracker::class)->report(new RuntimeException('top', 0, $middle));

    Http::assertSent(function(Request $request): bool {
        $chain = sentChain($request);

        expect($chain)->toHaveCount(2)
            ->and($chain[0]['exception_class'])->toBe(LogicException::class)
            ->and($chain[0]['message'])->toBe('middle')
            ->and($chain[0]['stack_trace'])->toContain('#0 ')
            ->and($chain[1]['exception_class'])->toBe(InvalidArgumentException::class)
            ->and($chain[1]['message'])->toBe('token [REDACTED:bearer] for [REDACTED:email]');

        return true;
    });
});

it('scrubs a secret in a cause\'s stack trace', function(): void {
    Http::fake(['kendo.test/*' => Http::response('', 202)]);

    $cause = captureThrowableFromFixture('trace-secret-jan@example.com');
    expect($cause->getTraceAsString())->toContain('jan@example.com');

    app(ErrorTracker::class)->report(new RuntimeException('wrapper', 0, $cause));

    Http::assertSent(function(Request $request): bool {
        expect(sentChain($request)[0]['stack_trace'])
            ->toContain('[REDACTED:email]')
            ->not->toContain('jan@example.com');

        return true;
    });
});

it('carrier-strips a PDOException in the chain', function(): void {
    Http::fake(['kendo.test/*' => Http::response('', 202)]);

    $cause = new PDOException("Duplicate entry 'Jan de Vries' for key 'PRIMARY'");
    $cause->errorInfo = ['23000', 1_062, "Duplicate entry 'Jan de Vries' for key 'PRIMARY'"];

    app(ErrorTracker::class)->report(new RuntimeException('save failed', 0, $cause));

    Http::assertSent(function(Request $request): bool {
        expect(sentChain($request)[0]['message'])->toBe(PDOException::class . ' [SQLSTATE 23000] [driver code 1062]');

        return true;
    });
});

it('drops the causes past the tenth', function(): void {
    Http::fake(['kendo.test/*' => Http::response('', 202)]);

    $previous = null;

    for ($i = 12; $i >= 1; $i--) {
        $previous = new RuntimeException('cause ' . $i, 0, $previous);
    }

    app(ErrorTracker::class)->report(new RuntimeException('top', 0, $previous));

    Http::assertSent(function(Request $request): bool {
        $chain = sentChain($request);

        expect($chain)->toHaveCount(10)
            ->and($chain[0]['message'])->toBe('cause 1')
            ->and($chain[9]['message'])->toBe('cause 10');

        return true;
    });
});

it('cuts an oversized cause to the server\'s limits so the report is still accepted', function(): void {
    // A fake server that answers 422 like kendo does when one chain entry is
    // over a limit, and 202 otherwise.
    Http::fake(function(Request $request) {
        foreach (sentChain($request) as $entry) {
            if (mb_strlen($entry['message']) > 65_535 || mb_strlen($entry['stack_trace']) > 131_072) {
                return Http::response('', 422);
            }
        }

        return Http::response('', 202);
    });

    try {
        throwFromDeepInsideARecursionWhoseFunctionNameIsLongOnPurposeToMakeEveryTraceLineLongerThanTheLimitNeedsXX(1_500);
    } catch (LogicException $deep) {
        $cause = $deep;
    }

    expect(mb_strlen($cause->getTraceAsString()))->toBeGreaterThanOrEqual(200_000);

    app(ErrorTracker::class)->report(new RuntimeException('top', 0, new RuntimeException(str_repeat('x', 100_000), 0, $cause)));

    $recorded = Http::recorded();
    expect($recorded)->toHaveCount(1);

    [$request, $response] = $recorded[0];
    $chain = sentChain($request);

    expect($response->status())->toBe(202)
        ->and(mb_strlen($chain[0]['message']))->toBe(65_535)
        ->and(mb_strlen($chain[1]['stack_trace']))->toBe(131_072);
});

it('leaves previous_exceptions out when the exception has no cause', function(): void {
    Http::fake(['kendo.test/*' => Http::response('', 202)]);

    app(ErrorTracker::class)->report(new RuntimeException('alone'));

    Http::assertSent(function(Request $request): bool {
        expect($request->data())->not->toHaveKey('previous_exceptions');

        return true;
    });
});
