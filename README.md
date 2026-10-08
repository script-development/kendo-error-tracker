# kendo-error-tracker

[![Packagist Version](https://img.shields.io/packagist/v/script-development/kendo-error-tracker.svg)](https://packagist.org/packages/script-development/kendo-error-tracker)
[![PHP Version](https://img.shields.io/packagist/dependency-v/script-development/kendo-error-tracker/php.svg)](https://packagist.org/packages/script-development/kendo-error-tracker)
[![CI](https://github.com/script-development/kendo-error-tracker/actions/workflows/ci.yml/badge.svg)](https://github.com/script-development/kendo-error-tracker/actions/workflows/ci.yml)
[![License](https://img.shields.io/packagist/l/script-development/kendo-error-tracker.svg)](LICENSE)

Canonical Laravel client library for reporting errors into kendo's error-tracking endpoint — scrubbing + auth + swallow-on-failure, installable via Composer across Script Development Laravel territories.

## Why

kendo ships the server endpoint, but allied projects must not POST raw HTTP: PII scrubbing has to happen source-side and consistently. This library is the gate — install it, call `ErrorTracker::report($exception)` from your exception handler, and inherit scrubbing, Bearer auth, path normalization, and swallow-on-failure for free. Without it, every consuming project reinvents the wheel and the scrubbing contract drifts.

## Installation

```bash
composer require script-development/kendo-error-tracker
```

The `ErrorTrackerServiceProvider` is auto-discovered via Laravel package discovery — no manual registration. Publish the config if you want to tune it:

```bash
php artisan vendor:publish --tag=error-tracker-config
```

## Configuration

Set the environment variables (the config reads `ERROR_TRACKER_*`). Only the first **three are required** — without them a report is silently dropped. Everything below them is **optional** and has a sane default.

| Env var | Config key | Required? | Description |
|---|---|---|---|
| `ERROR_TRACKER_KENDO_URL` | `kendo_url` | **Required** | Base URL of your kendo tenant — always `https://{tenant}.kendo.dev` (e.g. `https://script.kendo.dev`). |
| `ERROR_TRACKER_PROJECT` | `project` | **Required** | The kendo **project id** that owns the errors (the `{project}` route-key; kendo binds it by id). |
| `ERROR_TRACKER_TOKEN` | `token` | **Required** | A kendo project token carrying the `error-events:write` ability (Bearer). |
| `ERROR_TRACKER_ENVIRONMENT` | `environment` | Optional | Deploy environment label. May be omitted — falls back to `APP_ENV`, then `production`. Only set it to override that derived default. |
| `ERROR_TRACKER_RELEASE` | `release` | Optional | Release identifier (git sha / version tag). May be omitted — when unset it is dropped from the payload entirely. |
| `ERROR_TRACKER_SYNC` | `sync` | Optional | `false` (default) queues the report; `true` POSTs inline. |
| `ERROR_TRACKER_CONNECT_TIMEOUT` | `connect_timeout` | Optional | Seconds to wait while connecting to the kendo host (default `2`). |
| `ERROR_TRACKER_TIMEOUT` | `timeout` | Optional | Total seconds to wait for the POST (default `5`); bounds the call so a hung host never blocks the caller. |

Minimal working config — just the three required vars:

```dotenv
ERROR_TRACKER_KENDO_URL=https://script.kendo.dev
ERROR_TRACKER_PROJECT=7
ERROR_TRACKER_TOKEN=your-project-token
```

The optional knobs below are shown with their defaults; leave them commented out unless you need to override:

```dotenv
# ERROR_TRACKER_ENVIRONMENT=          # defaults to APP_ENV, then "production"
# ERROR_TRACKER_RELEASE=              # omitted from the payload when unset (e.g. v1.2.3)
# ERROR_TRACKER_SYNC=false           # true POSTs inline instead of queueing
# ERROR_TRACKER_CONNECT_TIMEOUT=2    # seconds to wait while connecting
# ERROR_TRACKER_TIMEOUT=5            # total seconds to wait for the POST
```

## Minting a project token

The token is a kendo **project token** carrying the `error-events:write` ability:

1. Open the kendo project's **API token** settings.
2. Create a token scoped to the project and grant it the `error-events:write` ability.
3. Copy the token into `ERROR_TRACKER_TOKEN`.

The token is bound to the project it was minted under. A token used against a different project's route is rejected by the server (`422`) — and like every failure, the client swallows it.

## Integration

Report exceptions from your application's exception handler. In Laravel 11+ (`bootstrap/app.php`):

```php
use ScriptDevelopment\KendoErrorTracker\ErrorTracker;
use Throwable;

->withExceptions(function (Exceptions $exceptions): void {
    $exceptions->report(function (Throwable $e): void {
        app(ErrorTracker::class)->report($e);
    });
})
```

Or from a classic `App\Exceptions\Handler::report()`:

```php
public function report(Throwable $e): void
{
    app(\ScriptDevelopment\KendoErrorTracker\ErrorTracker::class)->report($e);

    parent::report($e);
}
```

That single call is the whole integration. `report()` is **swallow-on-failure**: it never throws and never blocks the request, so it is safe to call from inside your own exception handler.

## What gets sent

`report()` builds and POSTs this body to `{kendo_url}/api/projects/{project}/error-events`:

```json
{
    "environment": "production",
    "release": "v1.2.3",
    "exception_class": "RuntimeException",
    "message": "<scrubbed exception message>",
    "stack_trace": "<scrubbed, path-normalized stack trace>",
    "previous_exceptions": [
        {
            "exception_class": "PDOException",
            "message": "<scrubbed message of the cause>",
            "stack_trace": "<scrubbed, path-normalized stack trace of the cause>"
        }
    ],
    "exception_code": "0",
    "runtime": {"name": "php", "version": "8.5.1"},
    "framework": {"name": "laravel", "version": "13.1.0"},
    "memory_peak_bytes": 44040192,
    "memory_limit_bytes": 268435456,
    "context": {
        "kind": "route",
        "name": "orders.show",
        "method": "GET",
        "pattern": "/orders/{order}",
        "action": "App\\Http\\Controllers\\OrderController@show"
    },
    "host": "web-1",
    "user_hash": "5f0e8a3c9b1d7e2f4a6c8b0d1e3f5a7c9b2d4e6f8a0c1e3f5b7d9a2c4e6f8b0d"
}
```

- `environment` reflects the resolved value (your `ERROR_TRACKER_ENVIRONMENT`, else `APP_ENV`, else `production`); `release` is omitted from the body entirely when unset.
- `previous_exceptions` is the caused-by chain (`getPrevious()`), outermost cause first, at most 10 entries. Each cause goes through the same scrubbing, path normalization and database carrier-strip as the thrown exception. Each cause's message is cut to 65,535 characters and its stack trace to 131,072, the server's limits. The key is left out when the exception has no cause.
- `exception_code` is the exception's code as a string (`"0"` when none was set, a SQLSTATE such as `"42S02"` for a `PDOException`), scrubbed.
- `runtime` is PHP and its version; `framework` is Laravel and your app's version.
- `memory_peak_bytes` is the process's peak memory (`memory_get_peak_usage(true)`); `memory_limit_bytes` is your `memory_limit` in bytes, left out when it is unlimited (`-1`).

- `context` says where the exception ran, as one of three kinds:
  - `route`: the route's `name`, the HTTP `method`, the `pattern` as declared (`/orders/{order}`), the controller `action`, and the `response_status` when the exception carries one (an HTTP exception's status code). The request URL, its query string, its headers and its body are never sent.
  - `job`: the job class as `name`, its `queue`, and the `attempt` that failed (the first is `1`). A queued job's own exception carries its job, even though Laravel reports it after the job was released or failed; a report made after the job ended carries none.
  - `command`: the command `name` and the `class` that runs it. A command run from inside another one through `Artisan::call()` counts only while it runs.

  When more than one applies, a running job wins (including a sync-queue job inside a request), then the current route, then the console command. A report from anywhere else carries no `context`. Every context string is scrubbed and cut to 255 characters. It is not path-normalized: a route pattern is not a file path.

- `host` is the name of the machine that ran the code (`gethostname()`), scrubbed and cut to 255 characters. It is left out when PHP cannot read it.
- `user_hash` stands for the signed-in user. See [Affected users](#affected-users).

A field the client cannot read is left out, and the rest of the report is still sent.

### Affected users

`user_hash` lets kendo count how many different users an error hit, without sending who they are.

- `user_hash` is the HMAC-SHA256 of the signed-in user's id (`getAuthIdentifier()`), keyed with your app's `APP_KEY`, written as 64 lowercase hex characters.
- The user id is never sent. Your `APP_KEY` never leaves your app, so kendo cannot turn a hash back into an id.
- The same user gives the same hash on every report, so kendo counts each user once.
- The hash is sent only for a request whose default guard already holds a signed-in user. A guest request, a job (a sync job inside a request too), a console command, or a report from anywhere else carries no hash.
- The client never asks a guard to look up a user. Reporting therefore runs no session read and no token query.
- No hash is sent when `APP_KEY` is not set, or when the user's id is not an integer or a non-empty string.
- kendo keeps each hash for 90 days after it last saw it, then deletes it.
- Changing `APP_KEY` changes every hash. A user who hits the error before and after the change counts twice until the old hash expires.

No other user data is added: not the user's name, not their email address, and no request body, header, cookie or URL. A message or stack trace that names a user is scrubbed as [Scrubbing](#scrubbing) describes. That catches an email address, but not a name.

## Scrubbing

Before send, the message, the stack trace, the causes, the exception code, the runtime and framework versions, the context and the host are scrubbed of the following patterns (each replaced with a `[REDACTED:<kind>]` marker):

| Pattern | Example |
|---|---|
| JWT | `eyJhbGc...` (three base64url segments) |
| Bearer token | `Bearer <credential>` |
| Database DSN password | `mysql://user:pass@host` — only the password is redacted |
| API-key prefix | `sk_live_...`, `AKIA...` |
| IPv4 address | `192.168.1.42` |
| BSN (Dutch citizen service number) | a 9-digit run passing the eleven-test (Dutch: elfproef) checksum |
| Email address | `user@example.com` |

BSN candidates are checksum-validated (the eleven-test) before redaction, so an arbitrary 9+-digit ID (an order number, invoice ID, or timestamp) is not falsely redacted, and a real BSN embedded in a longer digit run (e.g. a phone number) is still caught.

Free-text PII that isn't a fixed secret shape — a name, address, or care-data value embedded in a database error message — is not covered by pattern matching. `QueryException` and `PDOException` are instead handled by a per-exception-type carrier-strip: the message is replaced with just the exception class, SQLSTATE, and driver error code, dropping the SQL string and bound parameter values entirely.

## Path normalization

Each stack frame's absolute path has the app's own `base_path()` stripped (an **exact** prefix removal, mirroring `laravel/nightwatch`'s `Location::normalizeFile()`). The same exception thrown from `/var/www/html/app/Foo.php` and `/home/forge/app/Foo.php` normalizes to the identical `app/Foo.php`, so kendo fingerprints it once regardless of deploy root.

An anonymous exception class is named after the file that declares it (`Parent@anonymous\0/abs/path/Foo.php:LINE$N`). `exception_class` drops the `$N` compile counter and strips `base_path()` from that file; any other declaration file (outside `base_path()` — vendor code on a shared mount, a symlinked release directory — or a stream-wrapper URI such as `phar://`) is reduced to its basename, `Parent@anonymous\0[REDACTED:path]/Foo.php:LINE`. Two anonymous classes with the same file name and line outside the app root therefore share a fingerprint. Stack frames outside `base_path()` are not reduced this way: they keep their absolute path, with only the `/home/<user>/` and `/Users/<user>/` username redacted.

## Dispatch modes

- **Async (default):** `report()` dispatches `ReportErrorJob` to the queue. The job has **0 retries** — a failed POST logs to the local PHP `error_log` and is never requeued, so error tracking never amplifies load during an outage.
- **Sync:** set `error-tracker.sync` (`ERROR_TRACKER_SYNC=true`) to POST inline.

Both modes swallow every failure.

## Failure handling

A `202` response is success. Every failure — HTTP timeout, `401` (no/invalid token), `403` (token lacks `error-events:write`), `422` (token not linked to the project, or revoked), `5xx`, or an unreachable host — is written to the local `error_log` and never thrown.

## Development

```bash
composer test          # Pest
composer phpstan       # PHPStan (level max, self-analysis)
composer format:check  # Pint --test
composer format        # Pint write
```
