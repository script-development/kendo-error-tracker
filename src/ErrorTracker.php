<?php

declare(strict_types = 1);

namespace ScriptDevelopment\KendoErrorTracker;

use const PHP_VERSION;

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use PDOException;
use ScriptDevelopment\KendoErrorTracker\Jobs\ReportErrorJob;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

use function array_filter;
use function count;
use function error_log;
use function ini_get;
use function is_int;
use function is_numeric;
use function is_object;
use function is_scalar;
use function is_string;
use function mb_ltrim;
use function mb_rtrim;
use function mb_strlen;
use function mb_strpos;
use function mb_substr;
use function memory_get_peak_usage;
use function method_exists;
use function preg_match;
use function preg_replace;
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
    private const string ANONYMOUS = "@anonymous\0";

    /** The server answers 422 to an 11th `previous_exceptions` entry. */
    private const int MAX_PREVIOUS = 10;

    /** The server's limits on a string field, in characters. */
    private const int MAX_CLASS = 255;

    private const int MAX_MESSAGE = 65_535;

    private const int MAX_STACK_TRACE = 131_072;

    public function __construct(
        private HttpFactory $http,
        private Container $container,
        private Scrubber $scrubber,
        private PathNormalizer $pathNormalizer,
        private Config $config,
        private RunningContext $running = new RunningContext,
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
     * Build the scrubbed, path-normalized payload in the shape of kendo's
     * error-events body. The required four keys come from the thrown
     * exception; every optional key is read through optional(), so a key that
     * cannot be read is left out and the rest is still sent. Nulls are dropped.
     *
     * @return array<string, mixed>
     */
    private function buildPayload(Throwable $throwable): array
    {
        $release = $this->config->get('error-tracker.release');

        $payload = [
            'environment' => $this->configString('environment'),
            'release' => $release === null ? null : $this->configString('release'),
            'exception_class' => $this->exceptionClass($throwable),
            'message' => $this->message($throwable),
            'stack_trace' => $this->stackTrace($throwable),
            'previous_exceptions' => $this->optional('previous_exceptions', fn(): ?array => $this->previousExceptions($throwable)),
            'exception_code' => $this->optional('exception_code', fn(): string => $this->exceptionCode($throwable)),
            'runtime' => $this->optional('runtime', fn(): array => ['name' => 'php', 'version' => $this->clean(PHP_VERSION, self::MAX_CLASS)]),
            'framework' => $this->optional('framework', fn(): ?array => $this->framework()),
            'memory_peak_bytes' => $this->optional('memory_peak_bytes', static fn(): int => memory_get_peak_usage(true)),
            'memory_limit_bytes' => $this->optional('memory_limit_bytes', static fn(): ?int => MemoryLimit::toBytes(ini_get('memory_limit'))),
            'context' => $this->optional('context', fn(): ?array => $this->context($throwable)),
        ];

        return array_filter($payload, static fn(mixed $value): bool => $value !== null);
    }

    /**
     * Read one optional field. A read that throws leaves the field out
     * (null) instead of losing the whole report. The log line names the
     * exception class only: its message is unscrubbed free text.
     *
     * @template T
     *
     * @param callable(): T $read
     *
     * @return T|null
     */
    private function optional(string $field, callable $read): mixed
    {
        try {
            return $read();
        } catch (Throwable $e) {
            error_log(sprintf('[kendo-error-tracker] %s left out: %s', $field, $e::class));

            return null;
        }
    }

    private function message(Throwable $throwable): string
    {
        return $this->scrubber->scrub($this->safeMessage($throwable));
    }

    private function stackTrace(Throwable $throwable): string
    {
        return $this->scrubber->scrub($this->pathNormalizer->normalize($throwable->getTraceAsString()));
    }

    /**
     * The caused-by chain, outermost cause first, through the same pipeline as
     * the thrown exception. Causes past the tenth are dropped. Each string is
     * cut to the server's limit after scrubbing: one entry over a limit gets
     * the whole report a 422, and cutting before scrubbing could halve a
     * secret so that no pattern matches it.
     *
     * @return list<array{exception_class: string, message: string, stack_trace: string}>|null
     */
    private function previousExceptions(Throwable $throwable): ?array
    {
        $chain = [];

        for ($previous = $throwable->getPrevious(); $previous !== null && count($chain) < self::MAX_PREVIOUS; $previous = $previous->getPrevious()) {
            $chain[] = [
                'exception_class' => mb_substr($this->exceptionClass($previous), 0, self::MAX_CLASS),
                'message' => mb_substr($this->message($previous), 0, self::MAX_MESSAGE),
                'stack_trace' => mb_substr($this->stackTrace($previous), 0, self::MAX_STACK_TRACE),
            ];
        }

        return $chain === [] ? null : $chain;
    }

    /**
     * The code as a string: an int for most exceptions, a SQLSTATE string
     * such as `42S02` for a PDOException.
     */
    private function exceptionCode(Throwable $throwable): string
    {
        return $this->clean((string) $throwable->getCode(), self::MAX_CLASS);
    }

    /**
     * @return array{name: string, version: string}|null
     */
    private function framework(): ?array
    {
        return $this->container instanceof Application
            ? ['name' => 'laravel', 'version' => $this->clean($this->container->version(), self::MAX_CLASS)]
            : null;
    }

    /**
     * Where the exception ran, as one kind: the job it failed or the job still
     * running, else the current route, else the running console command.
     * Null outside all three.
     *
     * @return array<string, int|string>|null
     */
    private function context(Throwable $throwable): ?array
    {
        $job = $this->running->job($throwable);

        if ($job instanceof Job) {
            return $this->contextOf('job', [
                'name' => static fn(): mixed => $job->resolveName(),
                'queue' => static fn(): mixed => $job->getQueue(),
                'attempt' => static fn(): mixed => $job->attempts() >= 1 ? $job->attempts() : null,
            ]);
        }

        $request = $this->container->make('request');
        $route = $request instanceof Request ? $request->route() : null;

        if ($request instanceof Request && $route instanceof Route) {
            return $this->contextOf('route', [
                'name' => static fn(): mixed => $route->getName(),
                'method' => static fn(): mixed => $request->getMethod(),
                'pattern' => static fn(): mixed => '/' . mb_ltrim($route->uri(), '/'),
                'action' => static fn(): mixed => $route->getAction('controller'),
                'response_status' => static fn(): mixed => self::responseStatus($throwable),
            ]);
        }

        $command = $this->running->command();

        if ($command !== null) {
            return $this->contextOf('command', [
                'name' => static fn(): string => $command,
                'class' => fn(): ?string => $this->commandClass($command),
            ]);
        }

        return null;
    }

    /**
     * Read each field of one context kind through optional(). A field that
     * cannot be read, or reads as anything but a non-empty string or an int,
     * is left out; the context is still sent. A string is scrubbed and cut,
     * never path-normalized: a route pattern such as `/home/{user}/` is not a
     * path.
     *
     * @param array<string, callable(): mixed> $reads
     *
     * @return array<string, int|string>
     */
    private function contextOf(string $kind, array $reads): array
    {
        $context = ['kind' => $kind];

        foreach ($reads as $field => $read) {
            $value = $this->optional('context.' . $field, $read);

            if (is_string($value) && $value !== '') {
                $context[$field] = $this->short($value);
            } elseif (is_int($value)) {
                $context[$field] = $value;
            }
        }

        return $context;
    }

    /**
     * The status an HTTP exception carries. Any other throwable has none, and
     * none is guessed: a status outside 100-599 would get the report a 422.
     */
    private static function responseStatus(Throwable $throwable): ?int
    {
        if (!$throwable instanceof HttpExceptionInterface) {
            return null;
        }

        $status = $throwable->getStatusCode();

        return $status >= 100 && $status <= 599 ? $status : null;
    }

    /**
     * The class registered under the command name, loading that one command.
     */
    private function commandClass(string $name): ?string
    {
        $kernel = $this->container->make(ConsoleKernel::class);
        $command = method_exists($kernel, 'findCommand') ? $kernel->findCommand($name) : ($kernel->all()[$name] ?? null);

        return is_object($command) ? $command::class : null;
    }

    /**
     * Path-normalize, scrub and cut a short string field.
     */
    private function clean(string $value, int $limit): string
    {
        return mb_substr($this->scrubber->scrub($this->pathNormalizer->normalize($value)), 0, $limit);
    }

    /**
     * Scrub and cut a short string that is not a path.
     */
    private function short(string $value): string
    {
        return mb_substr($this->scrubber->scrub($value), 0, self::MAX_CLASS);
    }

    /**
     * The reported class name. A named class is sent verbatim. An anonymous
     * class name (`Parent@anonymous\0/abs/path/file.php:LINE$N`) embeds the
     * consumer's absolute install path and `$N`, PHP's process-global compile
     * counter; both would leak the path and split one fingerprint per deploy
     * (KD-0771 D6, WR-0798). The counter is dropped. A declaration file under
     * the base path is sent relative to it; any other file (outside the base
     * path, or a stream-wrapper URI such as `phar://`) keeps only its basename,
     * so no install path leaves and the value is install-location independent.
     * A name that does not parse is cut to `Parent@anonymous`.
     */
    private function exceptionClass(Throwable $throwable): string
    {
        $class = $throwable::class;
        $marker = mb_strpos($class, self::ANONYMOUS);

        if ($marker === false) {
            return $class;
        }

        $parent = mb_substr($class, 0, $marker) . '@anonymous';

        if (preg_match('/\A(.*):(\d+)(?:\$[0-9a-f]+)?\z/s', mb_substr($class, $marker + mb_strlen(self::ANONYMOUS)), $location) !== 1) {
            return $parent;
        }

        $file = $this->pathNormalizer->relativize($location[1])
            ?? '[REDACTED:path]/' . (string) preg_replace('#\A.*[/\\\]#s', '', $location[1]);

        return $this->scrubber->scrub(sprintf("%s\0%s:%s", $parent, $file, $location[2]));
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

        return sprintf('%s [SQLSTATE %s] [driver code %s]', $this->exceptionClass($throwable), $sqlState, $driverCode);
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
