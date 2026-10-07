<?php

declare(strict_types = 1);

namespace ScriptDevelopment\KendoErrorTracker;

use Illuminate\Contracts\Queue\Job;
use Throwable;
use WeakMap;

use function array_filter;
use function array_pop;
use function array_values;
use function count;
use function end;

/**
 * What the process is running between the framework's events: the queued
 * jobs and console commands in flight, innermost last.
 *
 * Laravel's queue worker reports a job's exception after JobExceptionOccurred
 * has fired and the job was released or failed. A job that throws is therefore
 * kept against its exception, not as running: its own report carries the job,
 * and a report made after it ended carries none. The entry dies with the
 * exception.
 *
 * Symfony fires TERMINATE (Laravel's CommandFinished) before a command's
 * exception reaches the console kernel's report, and that event carries no
 * exception. The outermost command of a console process is that process, so
 * it is kept for the process's life; a nested `Artisan::call()` is dropped
 * when it finishes.
 */
final class RunningContext
{
    /** @var list<Job> */
    private array $jobs = [];

    /** @var WeakMap<Throwable, Job> */
    private WeakMap $failedJobs;

    /** @var list<string> */
    private array $commands = [];

    public function __construct(
        private readonly bool $console = false,
    ) {
        $this->failedJobs = new WeakMap;
    }

    public function jobStarted(Job $job): void
    {
        $this->jobs[] = $job;
    }

    public function jobEnded(Job $job, ?Throwable $exception = null): void
    {
        // The innermost job keeps it: a sync job's exception bubbles up through the outer job's JobExceptionOccurred.
        if ($exception !== null) {
            $this->failedJobs[$exception] ??= $job;
        }

        $this->jobs = array_values(array_filter($this->jobs, static fn(Job $running): bool => $running !== $job));
    }

    /**
     * The job the throwable failed, else the innermost job still running.
     */
    public function job(Throwable $throwable): ?Job
    {
        $jobs = $this->jobs;

        return $this->failedJobs[$throwable] ?? (end($jobs) ?: null);
    }

    public function commandStarted(string $name): void
    {
        $this->commands[] = $name;
    }

    public function commandFinished(): void
    {
        if (count($this->commands) > ($this->console ? 1 : 0)) {
            array_pop($this->commands);
        }
    }

    public function command(): ?string
    {
        $commands = $this->commands;
        $command = end($commands);

        return $command === false || $command === '' ? null : $command;
    }
}
