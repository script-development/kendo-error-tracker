<?php

declare(strict_types = 1);

use Illuminate\Auth\GenericUser;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use ScriptDevelopment\KendoErrorTracker\ErrorTracker;
use ScriptDevelopment\KendoErrorTracker\PathNormalizer;
use ScriptDevelopment\KendoErrorTracker\RunningContext;
use ScriptDevelopment\KendoErrorTracker\Scrubber;
use ScriptDevelopment\KendoErrorTracker\Tests\Fixtures\FailingJob;

const APP_KEY = 'base64:2fl+Ktvkfl+Fuz4Qp/A75G2RTiWVA/ZoKZvp6fiiM10=';

// No hex digit, so a hash cannot contain it by chance.
const USER_ID = 'usr-Jqz-wxy';

// What v0.2.0 (e3b37e5) sends for USER_ID under APP_KEY.
const V020_USER_HASH = 'd439989304547e57c486f21025a8bf35519728be0966f9afc3b69e78d12fd3a2';

const TENANT_KEY = 'tenant-Kqz-wxy';

beforeEach(function(): void {
    config()->set('error-tracker.kendo_url', 'https://kendo.test');
    config()->set('error-tracker.project', '7');
    config()->set('error-tracker.token', 'secret-token');
    config()->set('error-tracker.environment', 'production');
    config()->set('error-tracker.sync', true);
    config()->set('app.key', APP_KEY);

    Http::fake(['kendo.test/*' => Http::response('', 202)]);

    app(ExceptionHandler::class)->reportable(static function(Throwable $throwable): void {
        app(ErrorTracker::class)->report($throwable);
    });

    Route::get('/orders/{order}', static fn(): never => throw new RuntimeException('boom'));
});

/**
 * The bodies sent to kendo, keyed by message.
 *
 * @return array<string, array<string, mixed>>
 */
function bodiesSent(): array
{
    $bodies = [];

    foreach (Http::recorded() as [$request]) {
        /** @var Request $request */
        $body = $request->data();
        $bodies[(string) $body['message']] = $body;
    }

    return $bodies;
}

/**
 * The raw JSON bodies sent to kendo, joined.
 */
function rawBodiesSent(): string
{
    $raw = '';

    foreach (Http::recorded() as [$request]) {
        /** @var Request $request */
        $raw .= $request->body();
    }

    return $raw;
}

/**
 * What the callback wrote to PHP's error_log.
 */
function errorLogWrittenBy(callable $callback): string
{
    $log = (string) tempnam(sys_get_temp_dir(), 'kendo-error-tracker-log');
    $previousLog = ini_set('error_log', $log);

    try {
        $callback();

        return (string) file_get_contents($log);
    } finally {
        ini_set('error_log', (string) $previousLog);
        unlink($log);
    }
}

/**
 * The user_hash of each body sent to kendo, in order; null where none was sent.
 *
 * @return list<mixed>
 */
function userHashesSent(): array
{
    $hashes = [];

    foreach (Http::recorded() as [$request]) {
        /** @var Request $request */
        $hashes[] = $request->data()['user_hash'] ?? null;
    }

    return $hashes;
}

function scopedUserHash(int|string $id, int|string $tenant): string
{
    return hash_hmac('sha256', (string) $id, hash_hmac('sha256', "kendo-error-tracker:user_hash\0" . $tenant, APP_KEY, true));
}

function trackerWithHost(Closure $hostname): ErrorTracker
{
    return new ErrorTracker(
        app(HttpFactory::class),
        app(),
        new Scrubber,
        app(PathNormalizer::class),
        app(Config::class),
        app(RunningContext::class),
        $hostname,
    );
}

it('sends HMAC(user id, APP_KEY) for a signed-in user, and never the id', function(int|string $id): void {
    $this->actingAs(new GenericUser(['id' => $id]));

    $this->get('/orders/42')->assertStatus(500);

    expect(bodiesSent()['boom']['user_hash'])->toBe(hash_hmac('sha256', (string) $id, hash_hmac('sha256', 'kendo-error-tracker:user_hash', APP_KEY, true)))
        ->not->toBe(hash_hmac('sha256', (string) $id, APP_KEY))
        ->toMatch('/\A[0-9a-f]{64}\z/');
})->with([
    'string id' => [USER_ID],
    'int id' => [42],
]);

it('keeps the user id out of the whole payload', function(): void {
    $this->actingAs(new GenericUser(['id' => USER_ID]));

    $this->get('/orders/42')->assertStatus(500);

    expect(bodiesSent()['boom'])->toHaveKey('user_hash')
        ->and(rawBodiesSent())->not->toContain(USER_ID);
});

it('reads no user from a guard that holds none', function(): void {
    $guard = new class implements Guard {
        public int $userCalls = 0;

        public function check(): bool
        {
            return false;
        }

        public function guest(): bool
        {
            return true;
        }

        public function user(): ?Authenticatable
        {
            $this->userCalls++;

            return null;
        }

        public function id(): null
        {
            return null;
        }

        /**
         * @param array<string, mixed> $credentials
         */
        public function validate(array $credentials = []): bool
        {
            return false;
        }

        public function hasUser(): bool
        {
            return false;
        }

        public function setUser(Authenticatable $user): void {}
    };

    Auth::extend('spy', static fn(): Guard => $guard);
    config()->set('auth.guards.spy', ['driver' => 'spy']);
    config()->set('auth.defaults.guard', 'spy');

    Route::get('/guest', static function(): never {
        auth()->guard();

        throw new RuntimeException('guest');
    });

    $this->get('/guest')->assertStatus(500);

    expect(bodiesSent()['guest'])->not->toHaveKey('user_hash')
        ->and(bodiesSent()['guest']['context']['kind'])->toBe('route')
        ->and($guard->userCalls)->toBe(0);
});

it('sends no hash for a guest request', function(): void {
    $this->get('/orders/42')->assertStatus(500);

    expect(bodiesSent()['boom'])->not->toHaveKey('user_hash')
        ->toHaveKey('context');
});

it('sends no hash for a sync job inside a signed-in request', function(): void {
    $this->actingAs(new GenericUser(['id' => USER_ID]));

    Route::get('/sync-job', static function(): string {
        Queue::connection('sync')->push(new FailingJob);

        return 'ok';
    });

    $this->get('/sync-job')->assertStatus(500);

    expect(bodiesSent()['job failed']['context']['kind'])->toBe('job')
        ->and(bodiesSent()['job failed'])->not->toHaveKey('user_hash');
});

it('sends no hash outside a request, even with a user on the guard', function(): void {
    $this->actingAs(new GenericUser(['id' => USER_ID]));

    app(ErrorTracker::class)->report(new RuntimeException('outside'));

    expect(bodiesSent()['outside'])->not->toHaveKey('user_hash');
});

it('sends no hash without an app key', function(mixed $key): void {
    config()->set('app.key', $key);
    $this->actingAs(new GenericUser(['id' => USER_ID]));

    $this->get('/orders/42')->assertStatus(500);

    expect(bodiesSent()['boom'])->not->toHaveKey('user_hash');
})->with([
    'unset' => [null],
    'empty' => [''],
]);

it('sends no hash for an id that is not an int or a non-empty string', function(mixed $id): void {
    $this->actingAs(new GenericUser(['id' => $id]));

    $this->get('/orders/42')->assertStatus(500);

    expect(bodiesSent()['boom'])->not->toHaveKey('user_hash');
})->with([
    'object' => [new stdClass],
    'empty string' => [''],
    'null' => [null],
]);

it('leaves the hash out when the id cannot be read, and still sends the report', function(): void {
    $this->actingAs(new class(['id' => USER_ID]) extends GenericUser {
        public function getAuthIdentifier(): never
        {
            throw new RuntimeException('no id for ' . USER_ID);
        }
    });

    $logged = errorLogWrittenBy(fn() => $this->get('/orders/42')->assertStatus(500));

    expect($logged)->toContain('[kendo-error-tracker] user_hash left out: ' . RuntimeException::class)
        ->not->toContain(USER_ID)
        ->and(bodiesSent()['boom'])->not->toHaveKey('user_hash')
        ->and(bodiesSent()['boom']['context']['kind'])->toBe('route')
        ->and(rawBodiesSent())->not->toContain(USER_ID);
});

it('sends the name of the machine as host', function(): void {
    app(ErrorTracker::class)->report(new RuntimeException('boom'));

    expect(bodiesSent()['boom']['host'])->toBe(mb_substr((new Scrubber)->scrub((string) gethostname()), 0, 255))
        ->not->toBe('');
});

it('scrubs the host and cuts it to 255 characters', function(): void {
    trackerWithHost(static fn(): string => 'jan@example.com ' . str_repeat('a', 300))->report(new RuntimeException('boom'));

    expect(bodiesSent()['boom']['host'])->toStartWith('[REDACTED:email]')
        ->toHaveLength(255);
});

it('leaves the host out when it cannot be read, and still sends the report', function(Closure $hostname): void {
    trackerWithHost($hostname)->report(new RuntimeException('boom'));

    expect(bodiesSent()['boom'])->not->toHaveKey('host')
        ->toHaveKey('stack_trace');
})->with([
    'gethostname() fails' => [static fn(): false => false],
    'empty' => [static fn(): string => ''],
    'throws' => [static fn(): never => throw new RuntimeException('no host')],
]);

it('sends the v0.2.0 hash without a resolver, or with one that returns null', function(?Closure $resolver): void {
    if ($resolver instanceof Closure) {
        app(ErrorTracker::class)->scopeUserHashUsing($resolver);
    }

    $this->actingAs(new GenericUser(['id' => USER_ID]));

    $this->get('/orders/42')->assertStatus(500);

    expect(bodiesSent()['boom']['user_hash'])->toBe(V020_USER_HASH);
})->with([
    'no resolver' => [null],
    'resolver returns null' => [static fn(): null => null],
]);

it('gives the same user id two hashes under two tenant keys', function(int|string $first, int|string $second): void {
    $tenant = $first;
    app(ErrorTracker::class)->scopeUserHashUsing(static function() use (&$tenant): int|string {
        return $tenant;
    });
    $this->actingAs(new GenericUser(['id' => USER_ID]));

    $this->get('/orders/42')->assertStatus(500);
    $tenant = $second;
    $this->get('/orders/42')->assertStatus(500);

    [$hashA, $hashB] = userHashesSent();

    expect($hashA)->toBe(scopedUserHash(USER_ID, $first))
        ->toMatch('/\A[0-9a-f]{64}\z/')
        ->not->toBe(V020_USER_HASH)
        ->and($hashB)->toBe(scopedUserHash(USER_ID, $second))
        ->not->toBe($hashA)
        ->not->toBe(V020_USER_HASH);
})->with([
    'string keys' => ['customer-a', 'customer-b'],
    'int keys' => [1, 2],
]);

it('gives the same user and tenant key the same hash on every report', function(): void {
    app(ErrorTracker::class)->scopeUserHashUsing(static fn(): string => TENANT_KEY);
    $this->actingAs(new GenericUser(['id' => 42]));

    $this->get('/orders/42')->assertStatus(500);
    $this->get('/orders/42')->assertStatus(500);

    expect(userHashesSent())->toBe([scopedUserHash(42, TENANT_KEY), scopedUserHash(42, TENANT_KEY)]);
});

it('keeps the tenant key out of the whole payload', function(): void {
    app(ErrorTracker::class)->scopeUserHashUsing(static fn(): string => TENANT_KEY);
    $this->actingAs(new GenericUser(['id' => USER_ID]));

    $this->get('/orders/42')->assertStatus(500);

    expect(bodiesSent()['boom'])->toHaveKey('user_hash')
        ->and(rawBodiesSent())->not->toContain(TENANT_KEY)
        ->not->toContain(USER_ID);
});

it('leaves the hash out when the resolver throws, and still sends the report', function(): void {
    app(ErrorTracker::class)->scopeUserHashUsing(static fn(): never => throw new RuntimeException('no tenant ' . TENANT_KEY));
    $this->actingAs(new GenericUser(['id' => USER_ID]));

    $logged = errorLogWrittenBy(fn() => $this->get('/orders/42')->assertStatus(500));

    expect($logged)->toContain('[kendo-error-tracker] user_hash left out: ' . RuntimeException::class)
        ->not->toContain(TENANT_KEY)
        ->and(bodiesSent()['boom'])->not->toHaveKey('user_hash')
        ->toHaveKey('stack_trace')
        ->and(bodiesSent()['boom']['context']['kind'])->toBe('route');
});

it('sends no hash, and never the unscoped one, for a tenant key that is not an int or a non-empty string', function(Closure $resolver): void {
    app(ErrorTracker::class)->scopeUserHashUsing($resolver);
    $this->actingAs(new GenericUser(['id' => USER_ID]));

    $this->get('/orders/42')->assertStatus(500);

    expect(bodiesSent()['boom'])->not->toHaveKey('user_hash')
        ->toHaveKey('context');
})->with([
    'empty string' => [static fn(): string => ''],
    'float' => [static fn(): float => 1.5],
    'bool' => [static fn(): bool => true],
    'object' => [static fn(): stdClass => new stdClass],
]);

it('calls the resolver only where a hash is sent', function(): void {
    $calls = 0;
    app(ErrorTracker::class)->scopeUserHashUsing(static function() use (&$calls): string {
        $calls++;

        return TENANT_KEY;
    });

    $this->get('/orders/42')->assertStatus(500);
    app(ErrorTracker::class)->report(new RuntimeException('outside'));
    $this->actingAs(new GenericUser(['id' => '']));
    $this->get('/orders/42')->assertStatus(500);

    expect($calls)->toBe(0);

    $this->actingAs(new GenericUser(['id' => USER_ID]));
    $this->get('/orders/42')->assertStatus(500);

    expect($calls)->toBe(1);
});
