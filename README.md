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
    "stack_trace": "<scrubbed, path-normalized stack trace>"
}
```

`environment` reflects the resolved value (your `ERROR_TRACKER_ENVIRONMENT`, else `APP_ENV`, else `production`); `release` is omitted from the body entirely when unset. No request, user, or context fields are sent — the server schema bans them.

## Scrubbing

Before send, the message and stack trace are scrubbed of the following patterns (each replaced with a `[REDACTED:<kind>]` marker):

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
