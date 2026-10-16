<?php

declare(strict_types = 1);
use Illuminate\Support\Facades\Http;

/*
| Doctrine Principle #8: every class that makes outbound HTTP calls declares an
| explicit `->timeout()` — no relying on PHP/framework defaults.
|
| HTTP-calling files are discovered from their code tokens (comments are
| dropped, so prose cannot satisfy or trip the check), and the call must be a
| real `->timeout(` method call.
 */

const HTTP_CLIENT_NAMESPACES = [
    'Illuminate\Http\Client\\',
    Http::class,
    'GuzzleHttp\\',
    'Psr\Http\Client\\',
    'Symfony\Contracts\HttpClient\\',
    'Symfony\Component\HttpClient\\',
];

const HTTP_CLIENT_FUNCTIONS = ['curl_init', 'curl_exec', 'curl_multi_exec', 'fsockopen', 'stream_socket_client'];

/** Files that reference the HTTP client without sending a request, with the reason. */
const HTTP_TIMEOUT_EXEMPT = [
    'ErrorTrackerServiceProvider.php' => 'resolves the HTTP factory to construct ErrorTracker; sends nothing',
];

/**
 * @return list<array{0: int, 1: string}|string>
 */
function codeTokens(string $source): array
{
    return array_values(array_filter(
        token_get_all($source),
        static fn(array|string $token): bool => !\is_array($token) || !\in_array($token[0], [\T_COMMENT, \T_DOC_COMMENT, \T_WHITESPACE], true),
    ));
}

/**
 * @param list<array{0: int, 1: string}|string> $tokens
 */
function referencesHttpClient(array $tokens): bool
{
    foreach ($tokens as $token) {
        if (!\is_array($token)) {
            continue;
        }

        if (\in_array($token[0], [\T_NAME_QUALIFIED, \T_NAME_FULLY_QUALIFIED], true)) {
            $name = mb_ltrim($token[1], '\\');

            foreach (HTTP_CLIENT_NAMESPACES as $namespace) {
                if (str_starts_with($name, $namespace)) {
                    return true;
                }
            }
        }

        if ($token[0] === \T_STRING && \in_array(mb_strtolower($token[1]), HTTP_CLIENT_FUNCTIONS, true)) {
            return true;
        }
    }

    return false;
}

/**
 * @param list<array{0: int, 1: string}|string> $tokens
 */
function callsTimeout(array $tokens): bool
{
    foreach ($tokens as $i => $token) {
        if (!\is_array($token) || !\in_array($token[0], [\T_OBJECT_OPERATOR, \T_NULLSAFE_OBJECT_OPERATOR], true)) {
            continue;
        }

        $method = $tokens[$i + 1] ?? null;

        if (\is_array($method) && $method[0] === \T_STRING && $method[1] === 'timeout' && ($tokens[$i + 2] ?? null) === '(') {
            return true;
        }
    }

    return false;
}

/**
 * @return array{scanned: int, http: list<string>, exempt: list<string>, violations: list<string>}
 */
function scanHttpTimeouts(string $directory): array
{
    $result = ['scanned' => 0, 'http' => [], 'exempt' => [], 'violations' => []];

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));

    foreach ($files as $file) {
        if (!$file instanceof SplFileInfo || $file->getExtension() !== 'php') {
            continue;
        }

        $result['scanned']++;
        $tokens = codeTokens((string) file_get_contents($file->getPathname()));

        if (!referencesHttpClient($tokens)) {
            continue;
        }

        if (\array_key_exists($file->getFilename(), HTTP_TIMEOUT_EXEMPT)) {
            $result['exempt'][] = $file->getFilename();

            continue;
        }

        $result['http'][] = $file->getFilename();

        if (!callsTimeout($tokens)) {
            $result['violations'][] = \sprintf(
                '%s makes external HTTP calls but declares no explicit ->timeout() (Doctrine Principle #8)',
                $file->getPathname(),
            );
        }
    }

    sort($result['http']);
    sort($result['exempt']);

    return $result;
}

test('external HTTP classes must declare an explicit timeout', function(): void {
    $scan = scanHttpTimeouts(\dirname(__DIR__, 2) . '/src');

    expect($scan['scanned'])->toBeGreaterThan(0, 'the scan found no PHP files under src/')
        ->and($scan['http'])->not->toBeEmpty(\sprintf('scanned %d PHP file(s) but discovered 0 HTTP-calling classes', $scan['scanned']))
        ->and($scan['exempt'])->toBe(array_keys(HTTP_TIMEOUT_EXEMPT), 'an exemption names a file that no longer references the HTTP client')
        ->and($scan['violations'])->toBeEmpty();
});

it('discovers ErrorTracker as an HTTP-calling class', function(): void {
    expect(scanHttpTimeouts(\dirname(__DIR__, 2) . '/src')['http'])->toContain('ErrorTracker.php');
});

it('is not satisfied by a timeout that appears only in prose or a string', function(): void {
    $tokens = codeTokens(<<<'PHP'
        <?php
        use Illuminate\Http\Client\Factory;
        // ->timeout(5) TIMEOUT
        /** ->timeout(5) */
        final class Probe { public function go(Factory $http): void { $http->post('https://x.test', ['timeout' => 1, 'TIMEOUT' => '->timeout(']); } }
        PHP);

    expect(referencesHttpClient($tokens))->toBeTrue()
        ->and(callsTimeout($tokens))->toBeFalse();
});

it('is satisfied by a real timeout call', function(): void {
    $tokens = codeTokens(<<<'PHP'
        <?php
        final class Probe { public function go(\Illuminate\Http\Client\Factory $http): void { $http->timeout(3)->post('https://x.test'); } }
        PHP);

    expect(referencesHttpClient($tokens))->toBeTrue()
        ->and(callsTimeout($tokens))->toBeTrue();
});
