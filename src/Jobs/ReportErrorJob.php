<?php

declare(strict_types = 1);

namespace ScriptDevelopment\KendoErrorTracker\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use ScriptDevelopment\KendoErrorTracker\ErrorTracker;
use Throwable;

use function error_log;
use function sprintf;

/**
 * Async carrier for an already-scrubbed error payload.
 *
 * Scrubbing + path normalization happened synchronously in ErrorTracker::report()
 * before this job was queued, so the serialized payload carries no raw PII.
 *
 * Failure policy (KD-0772): 0 retries. A failed POST must never requeue — error
 * tracking must not amplify load during an outage — so $tries = 1 and failed()
 * logs to the local PHP error_log instead. The send itself also swallows, so
 * failed() only fires on an infrastructure-level job failure.
 */
final class ReportErrorJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** One attempt, no retries — a failed report is dropped, never requeued. */
    public int $tries = 1;

    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public readonly array $payload,
    ) {}

    public function handle(ErrorTracker $tracker): void
    {
        $tracker->send($this->payload);
    }

    public function failed(?Throwable $exception): void
    {
        error_log(sprintf(
            '[kendo-error-tracker] ReportErrorJob failed: %s',
            $exception?->getMessage() ?? 'unknown',
        ));
    }
}
