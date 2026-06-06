<?php

declare(strict_types = 1);

namespace ScriptDevelopment\KendoErrorTracker;

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory as HttpFactory;
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

        $this->app->singleton(ErrorTracker::class, fn(Application $app): ErrorTracker => new ErrorTracker(
            $app->make(HttpFactory::class),
            $app->make(Dispatcher::class),
            $app->make(Scrubber::class),
            $app->make(PathNormalizer::class),
            $app->make(Config::class),
        ));
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/error-tracker.php' => config_path('error-tracker.php'),
            ], 'error-tracker-config');
        }
    }
}
