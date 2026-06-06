<?php

declare(strict_types = 1);

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use ScriptDevelopment\KendoErrorTracker\ErrorTracker;
use ScriptDevelopment\KendoErrorTracker\Jobs\ReportErrorJob;

beforeEach(function(): void {
    config()->set('error-tracker.kendo_url', 'https://kendo.test');
    config()->set('error-tracker.project', '7');
    config()->set('error-tracker.token', 'secret-token');
    config()->set('error-tracker.environment', 'production');
    config()->set('error-tracker.release', 'v1.2.3');
    config()->set('error-tracker.sync', true);
});

it('posts to the kendo error-events endpoint with the expected body', function(): void {
    Http::fake([
        'kendo.test/*' => Http::response('', 202),
    ]);

    app(ErrorTracker::class)->report(new RuntimeException('boom'));

    Http::assertSent(function($request): bool {
        expect($request->url())->toBe('https://kendo.test/api/projects/7/error-events');
        expect($request->hasHeader('Authorization', 'Bearer secret-token'))->toBeTrue();

        $body = $request->data();
        expect($body)
            ->toHaveKeys(['environment', 'release', 'exception_class', 'message', 'stack_trace'])
            ->and($body['environment'])->toBe('production')
            ->and($body['release'])->toBe('v1.2.3')
            ->and($body['exception_class'])->toBe(RuntimeException::class)
            ->and($body['message'])->toBe('boom');

        return true;
    });
});

it('omits release when it is null', function(): void {
    config()->set('error-tracker.release', null);
    Http::fake(['kendo.test/*' => Http::response('', 202)]);

    app(ErrorTracker::class)->report(new RuntimeException('boom'));

    Http::assertSent(function($request): bool {
        expect($request->data())->not->toHaveKey('release');

        return true;
    });
});

it('treats a 202 response as success without throwing', function(): void {
    Http::fake(['kendo.test/*' => Http::response('', 202)]);

    app(ErrorTracker::class)->report(new RuntimeException('boom'));

    // Reaching the assertion at all proves report() did not throw.
    Http::assertSentCount(1);
});

it('dispatches a queued job in async mode and posts nothing inline', function(): void {
    config()->set('error-tracker.sync', false);
    Bus::fake();
    Http::fake();

    app(ErrorTracker::class)->report(new RuntimeException('boom'));

    Bus::assertDispatched(ReportErrorJob::class);
    Http::assertNothingSent();
});

it('posts inline in sync mode and dispatches no job', function(): void {
    Bus::fake();
    Http::fake(['kendo.test/*' => Http::response('', 202)]);

    app(ErrorTracker::class)->report(new RuntimeException('boom'));

    Http::assertSentCount(1);
    Bus::assertNotDispatched(ReportErrorJob::class);
});

it('scrubs the message before sending', function(): void {
    Http::fake(['kendo.test/*' => Http::response('', 202)]);

    app(ErrorTracker::class)->report(new RuntimeException('email leaked: secret.user@example.com'));

    Http::assertSent(function($request): bool {
        $body = $request->data();
        expect($body['message'])
            ->toContain('[REDACTED:email]')
            ->not->toContain('secret.user@example.com');

        return true;
    });
});

it('dispatches a job carrying the already-scrubbed payload in async mode', function(): void {
    config()->set('error-tracker.sync', false);
    Bus::fake();

    app(ErrorTracker::class)->report(new RuntimeException('mail user@example.com'));

    Bus::assertDispatched(ReportErrorJob::class, function(ReportErrorJob $job): bool {
        expect($job->payload['message'])
            ->toContain('[REDACTED:email]')
            ->not->toContain('user@example.com');

        return true;
    });
});
