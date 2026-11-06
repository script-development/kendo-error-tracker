<?php

declare(strict_types = 1);

namespace ScriptDevelopment\KendoErrorTracker\Console;

use Illuminate\Console\Command;
use ScriptDevelopment\KendoErrorTracker\ErrorTracker;

use function sprintf;

/**
 * The release check an app opts into from its release step: exit 1 when a
 * setting send() cannot go without is blank, so a release fails rather than
 * dropping every report. It reads settings and sends nothing, so a wrong token
 * or URL still passes, and it never prints a value.
 *
 * @internal Internal: not for use outside this package. Its command name and exit codes are public; the class is not.
 */
final class CheckCommand extends Command
{
    private const array ENV_VARS = [
        'kendo_url' => 'ERROR_TRACKER_KENDO_URL',
        'project' => 'ERROR_TRACKER_PROJECT',
        'token' => 'ERROR_TRACKER_TOKEN',
    ];

    protected $signature = 'error-tracker:check';

    protected $description = 'Fail when a setting the error tracker needs is blank (exit 1). Sends nothing, so a wrong value passes.';

    public function handle(ErrorTracker $tracker): int
    {
        $missing = $tracker->missingSettings();

        foreach ($missing as $setting) {
            $this->error(sprintf('%s (error-tracker.%s) is blank or missing: every report would be dropped.', self::ENV_VARS[$setting], $setting));
        }

        if ($missing !== []) {
            return self::FAILURE;
        }

        $this->info('The error tracker\'s URL, project and token are set. Nothing was sent, so a wrong value passes here.');

        return self::SUCCESS;
    }
}
