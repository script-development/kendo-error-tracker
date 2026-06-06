<?php

declare(strict_types = 1);

use ScriptDevelopment\KendoErrorTracker\ErrorTracker;

use function file_get_contents;
use function str_contains;

/*
|--------------------------------------------------------------------------
| External HTTP Timeout Architecture Test
|--------------------------------------------------------------------------
|
| Mirrors kendo's tests/Arch/ExternalHttpTimeoutTest.php (Doctrine Principle
| #8): every class that makes outbound HTTP calls to an external service must
| declare an explicit timeout — no relying on PHP/framework defaults.
|
| The package has a single external-HTTP class (ErrorTracker::send). When a new
| class makes outbound HTTP calls, add it to $externalServices and the test will
| fail until it declares a timeout.
|
 */

test('external HTTP classes must declare an explicit timeout', function(): void {
    $externalServices = [
        ErrorTracker::class,
    ];

    $violations = [];

    foreach ($externalServices as $externalService) {
        $source = (string) file_get_contents((new ReflectionClass($externalService))->getFileName());

        $hasTimeout = str_contains($source, '->timeout(')
            || str_contains($source, 'TIMEOUT')
            || str_contains($source, "'timeout'");

        if (!$hasTimeout) {
            $violations[] = \sprintf(
                '%s makes external HTTP calls but declares no explicit timeout (Doctrine Principle #8)',
                $externalService,
            );
        }
    }

    expect($violations)->toBeEmpty();
});
