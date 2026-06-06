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

Set the environment variables (the config reads `ERROR_TRACKER_*`):

| Env var | Config key | Description |
|---|---|---|
| `ERROR_TRACKER_KENDO_URL` | `kendo_url` | Base URL of your kendo tenant — always `https://{tenant}.kendo.dev` (e.g. `https://script.kendo.dev`). |
| `ERROR_TRACKER_PROJECT` | `project` | The kendo **project id** that owns the errors (the `{project}` route-key; kendo binds it by id). |
| `ERROR_TRACKER_TOKEN` | `token` | A kendo project token carrying the `error-events:write` ability (Bearer). |
| `ERROR_TRACKER_ENVIRONMENT` | `environment` | Deploy environment label (defaults to `APP_ENV`). |
| `ERROR_TRACKER_RELEASE` | `release` | Optional release identifier (git sha / version tag). |
| `ERROR_TRACKER_SYNC` | `sync` | `false` (default) queues the report; `true` POSTs inline. |

```dotenv
ERROR_TRACKER_KENDO_URL=https://script.kendo.dev
ERROR_TRACKER_PROJECT=7
ERROR_TRACKER_TOKEN=your-project-token
ERROR_TRACKER_ENVIRONMENT=production
ERROR_TRACKER_RELEASE=v1.2.3
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

`release` is omitted when unset. No request, user, or context fields are sent — the server schema bans them.

## Scrubbing

Before send, the message and stack trace are scrubbed of the following patterns (each replaced with a `[REDACTED:<kind>]` marker):

| Pattern | Example |
|---|---|
| JWT | `eyJhbGc...` (three base64url segments) |
| Bearer token | `Bearer <credential>` |
| BSN (Dutch citizen service number) | a 9-digit run |
| Email address | `user@example.com` |

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
