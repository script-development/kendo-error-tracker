<?php

declare(strict_types = 1);

namespace ScriptDevelopment\KendoErrorTracker;

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Queue\Events\JobAttempted;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\ServiceProvider;

use function config_path;

/**
 * Auto-discovered ServiceProvider (registered via composer extra.laravel.providers).
 *
 * Merges the package config, publishes it to config/error-tracker.php, and binds
 * the ErrorTracker singleton — wiring its PathNormalizer to the consuming app's
 * own base_path() so the exact-prefix strip targets the right deploy root.
 */
final class ErrorTrackerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/error-tracker.php', 'error-tracker');

        $this->app->singleton(PathNormalizer::class, fn(): PathNormalizer => new PathNormalizer($this->app->basePath()));

        $this->app->singleton(RunningContext::class, fn(): RunningContext => new RunningContext($this->app->runningInConsole()));

        $this->app->singleton(ErrorTracker::class, fn(Application $app): ErrorTracker => new ErrorTracker(
            $app->make(HttpFactory::class),
            $app,
            $app->make(Scrubber::class),
            $app->make(PathNormalizer::class),
            $app->make(Config::class),
            $app->make(RunningContext::class),
        ));
    }

    public function boot(): void
    {
        $running = $this->app->make(RunningContext::class);
        $events = $this->app->make(Dispatcher::class);

        $events->listen(JobProcessing::class, static fn(JobProcessing $event) => $running->jobStarted($event->job));
        $events->listen(JobProcessed::class, static fn(JobProcessed $event) => $running->jobEnded($event->job));
        $events->listen(JobExceptionOccurred::class, static fn(JobExceptionOccurred $event) => $running->jobEnded($event->job, $event->exception));
        // A throwing failer skips JobExceptionOccurred; JobAttempted fires in a finally where Laravel has it.
        $events->listen(JobAttempted::class, static fn(JobAttempted $event) => $running->jobEnded($event->job));
        $events->listen(CommandStarting::class, static fn(CommandStarting $event) => $running->commandStarted((string) $event->command));
        $events->listen(CommandFinished::class, static fn() => $running->commandFinished());

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/error-tracker.php' => config_path('error-tracker.php'),
            ], 'error-tracker-config');
        }
    }
}
