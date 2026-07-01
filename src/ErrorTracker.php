<?php

declare(strict_types = 1);

namespace ScriptDevelopment\KendoErrorTracker;

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Client\Factory as HttpFactory;
use PDOException;
use ScriptDevelopment\KendoErrorTracker\Jobs\ReportErrorJob;
use Throwable;

use function array_filter;
use function error_log;
use function is_numeric;
use function is_scalar;
use function mb_rtrim;
use function sprintf;

/**
 * The public client surface: report a Throwable into kendo's error tracker.
 *
 * Every path is swallow-on-failure (KD-0772): report() never throws and never
 * blocks the caller. Scrubbing + path normalization happen synchronously inside
 * report() so the payload is already safe before it crosses the queue boundary;
 * the actual HTTP POST runs inline (sync mode) or on the queue (async, default).
 *
 * The bus is resolved lazily from the container inside report() rather than
 * injected, because report() is called from the consumer's exception handler:
 * if the Bus deferred provider is unresolvable in that container state, an
 * eager constructor dependency would throw a BindingResolutionException at
 * resolve() time — outside report()'s try/catch — defeating the never-throw
 * invariant and masking the original error. Resolving it inside the guard keeps
 * the failure swallowed.
 */
final readonly class ErrorTracker
{
    public function __construct(
        private HttpFactory $http,
        private Container $container,
        private Scrubber $scrubber,
        private PathNormalizer $pathNormalizer,
        private Config $config,
    ) {}

    /**
     * Report an exception. Idempotent, swallow-on-failure: never throws, never
     * blocks. Building the payload is wrapped too — a failure here (or in
     * dispatch) is logged to the local PHP error_log and the caller continues.
     */
    public function report(Throwable $throwable): void
    {
        try {
            $payload = $this->buildPayload($throwable);

            if ((bool) $this->config->get('error-tracker.sync', false)) {
                $this->send($payload);

                return;
            }

            $this->container->make(Dispatcher::class)->dispatch(new ReportErrorJob($payload));
        } catch (Throwable $e) {
            error_log(sprintf('[kendo-error-tracker] report failed: %s', $e->getMessage()));
        }
    }

    /**
     * Perform the HTTP POST. Called inline (sync mode) or from ReportErrorJob
     * (async mode). An explicit connect + total timeout (config-tunable, default
     * 2s / 5s) bounds the call so a hung kendo host never blocks the caller —
     * this is fire-and-forget telemetry. Every failure — timeout, 4xx, 5xx,
     * unreachable host — is caught and logged; only a 202 is treated as success.
     *
     * If any required key (kendo_url / project / token) is empty the call is
     * short-circuited with a distinct operator-facing log line and no POST is
     * attempted.
     *
     * @param array<string, mixed> $payload
     */
    public function send(array $payload): void
    {
        try {
            $kendoUrl = $this->configString('kendo_url');
            $project = $this->configString('project');
            $token = $this->configString('token');

            if ($kendoUrl === '' || $project === '' || $token === '') {
                error_log('[kendo-error-tracker] not configured: missing kendo_url/project/token; report dropped');

                return;
            }

            $url = sprintf(
                '%s/api/projects/%s/error-events',
                mb_rtrim($kendoUrl, '/'),
                $project,
            );

            $response = $this->http
                ->withToken($token)
                ->connectTimeout($this->configFloat('connect_timeout', 2.0))
                ->timeout($this->configFloat('timeout', 5.0))
                ->acceptJson()
                ->asJson()
                ->post($url, $payload);

            if ($response->status() !== 202) {
                error_log(sprintf(
                    '[kendo-error-tracker] send rejected: HTTP %d',
                    $response->status(),
                ));
            }
        } catch (Throwable $e) {
            error_log(sprintf('[kendo-error-tracker] send failed: %s', $e->getMessage()));
        }
    }

    /**
     * Build the scrubbed, path-normalized payload matching KD-0771's accepted
     * body: {environment, release?, exception_class, message, stack_trace}.
     *
     * @return array<string, mixed>
     */
    private function buildPayload(Throwable $throwable): array
    {
        $message = $this->scrubber->scrub($this->safeMessage($throwable));
        $stackTrace = $this->scrubber->scrub(
            $this->pathNormalizer->normalize($throwable->getTraceAsString()),
        );

        $release = $this->config->get('error-tracker.release');

        $payload = [
            'environment' => $this->configString('environment'),
            'release' => $release === null ? null : $this->configString('release'),
            'exception_class' => $throwable::class,
            'message' => $message,
            'stack_trace' => $stackTrace,
        ];

        // KD-0771 marks `release` nullable; drop it when unset so the payload
        // matches `{environment, release?, exception_class, message, stack_trace}`.
        return array_filter($payload, static fn(mixed $value): bool => $value !== null);
    }

    /**
     * Resolve the message to send: a database-carrier strip for any
     * `PDOException` (including Laravel's `QueryException`, which extends
     * it), otherwise the exception's own message unchanged (still passed
     * through the Scrubber afterwards either way).
     *
     * A `QueryException` message embeds the full SQL string with bound
     * parameter values interpolated in — free-text data (a name, an address,
     * a care-data note) that is not a regex-able secret shape and would
     * otherwise leak on the most common database-error path. The fingerprint
     * (exception class + SQLSTATE + driver error code) survives; the bound
     * values do not.
     */
    private function safeMessage(Throwable $throwable): string
    {
        return $throwable instanceof PDOException
            ? $this->databaseCarrierMessage($throwable)
            : $throwable->getMessage();
    }

    /**
     * Build the class + SQLSTATE + driver-error-code fingerprint that
     * replaces a database exception's message. `errorInfo` is PDO's
     * `[SQLSTATE, driver code, driver message]` triple; `QueryException`
     * copies it from its wrapped PDOException. Either piece may be absent
     * (mocked/manually-constructed exceptions, or a previous exception that
     * was not itself a PDOException), so both fall back to "unknown".
     */
    private function databaseCarrierMessage(PDOException $throwable): string
    {
        $errorInfo = $throwable->errorInfo;

        $sqlState = isset($errorInfo[0]) && is_scalar($errorInfo[0]) ? (string) $errorInfo[0] : 'unknown';
        $driverCode = isset($errorInfo[1]) && is_scalar($errorInfo[1]) ? (string) $errorInfo[1] : 'unknown';

        return sprintf('%s [SQLSTATE %s] [driver code %s]', $throwable::class, $sqlState, $driverCode);
    }

    /**
     * Read a string config value, narrowing the repository's mixed return.
     * Non-scalar / null values collapse to an empty string.
     */
    private function configString(string $key): string
    {
        $value = $this->config->get('error-tracker.' . $key);

        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * Read a float config value, narrowing the repository's mixed return.
     * Non-numeric / null values fall back to the supplied default.
     *
     * The coerced value is floored to positive-or-default (H-3): `is_numeric`
     * accepts `'0'` and negatives, but a non-positive Guzzle timeout means
     * "wait forever" — a hung kendo host would then block the caller in sync
     * mode, breaking the swallow-on-failure / never-block invariant. A
     * non-positive numeric therefore falls back to the supplied default.
     */
    private function configFloat(string $key, float $default): float
    {
        $value = $this->config->get('error-tracker.' . $key);

        if (!is_numeric($value)) {
            return $default;
        }

        $coerced = (float) $value;

        return $coerced > 0.0 ? $coerced : $default;
    }
}
