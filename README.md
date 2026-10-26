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
| `ERROR_TRACKER_TOKEN` | `token` | **Required** | A kendo project token with the **Read & write** access level (Bearer). See [Minting a project token](#minting-a-project-token). |
| `ERROR_TRACKER_ENVIRONMENT` | `environment` | Optional | Deploy environment label. May be omitted — falls back to `APP_ENV`, then `production`. Only set it to override that derived default. |
| `ERROR_TRACKER_RELEASE` | `release` | Optional | Release identifier (git sha / version tag). May be omitted — when unset it is dropped from the payload entirely. |
| `ERROR_TRACKER_SYNC` | `sync` | Optional | `false` (default) queues the report; `true` POSTs inline. `on`, `yes` and `1` also POST inline; any other value, `off` and `no` included, queues. |
| `ERROR_TRACKER_CONNECT_TIMEOUT` | `connect_timeout` | Optional | Seconds to wait while connecting to the kendo host (default `2`). A value that is not above 0 and at most 30 falls back to the default. |
| `ERROR_TRACKER_TIMEOUT` | `timeout` | Optional | Total seconds to wait for the POST (default `5`); bounds the call so a hung host never blocks the caller. A value that is not above 0 and at most 30 falls back to the default. |

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

The token is a kendo **project token** with the **Read & write** access level. Kendo replaced token abilities with access levels on 2026-10-05; the old `error-events:write` ability no longer exists.

1. Sign in to kendo as a **dedicated account** made for this app, not as a person (see below).
2. Open the project's **Settings** and go to **API Tokens**.
3. Create a token, name it after the app, and choose the access **Read & write**. A Read token gets `403` on every write, error events included.
4. Copy the token into `ERROR_TRACKER_TOKEN`. Kendo shows it only once.

**Why a dedicated account.** A project token acts as the account that created it, at its access level. A Read & write token can therefore do in the project whatever its creator may do there, reading reports, issues and error groups included, not only send error events. The token sits in the app's environment, so anyone who can read that environment gets that access. Make the creating account a member of this project only, with no more permission than creating error groups (`Error Groups: Create`). Kendo has no send-only access level yet (KD-1908). A token converted from the old `error-events:write` ability is Read & write too: replace it with one minted this way.

The token is bound to the project it was minted under. A token used against a different project's route is rejected by the server (`403`) — and like every failure, the client swallows it.

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
    "location": {"file": "app/Services/OrderService.php", "line": 42},
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
- `location` is where the exception was raised: `getFile()` and `getLine()`. The file goes through the same path normalization and scrubbing as the trace and is cut to 255 characters. Kendo reads it only when the stack trace holds no frame, as a fatal error's does, and then groups the event by exception class and this file. The key is left out when PHP gives no file or a line below 1.
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

- `user_hash` is the HMAC-SHA256 of the signed-in user's id (`getAuthIdentifier()`), written as 64 lowercase hex characters. Its key is derived from your app's `APP_KEY` and used for this field only, so a hash never equals one of Laravel's URL signatures, which use `APP_KEY` itself.
- The client puts the user id in no field of the report. Your `APP_KEY` never leaves your app, so kendo cannot turn a hash back into an id.
- The same user gives the same hash on every report, so kendo counts each user once.
- The hash is sent only for a request whose default guard already holds a signed-in user. A guest request, a job (a sync job inside a request too), a console command, or a report from anywhere else carries no hash.
- The client never asks a guard to look up a user. Reporting therefore runs no session read and no token query.
- No hash is sent when `APP_KEY` is not set, or when the user's id is not an integer or a non-empty string.
- kendo keeps each hash for 90 days after it last saw it, then deletes it.
- Changing `APP_KEY` changes every hash. A user who hits the error before and after the change counts twice until the old hash expires.

#### Multi-tenant apps

In an app with one database per tenant, user ids restart in each tenant, so user 42 of two tenants would give one hash. kendo would count them as one user and could link their reports. Register a resolver that returns the current tenant key, once, in a service provider's `boot()` (here with stancl/tenancy):

```php
app(ErrorTracker::class)->scopeUserHashUsing(fn (): int|string|null => tenant()?->getTenantKey());
```

- The hash then covers the tenant key and the user id together: the same id in two tenants gives two hashes.
- The tenant key goes into the hash's key and is never sent.
- The resolver runs only when a hash is sent, at the moment of the report, inside your exception handler. Return the tenant your app already resolved for the request. Do not look it up in a database or a cache: that lookup can be what failed, and a lookup that hangs keeps the request in the handler.
- When it returns `null`, the hash is the same as without a resolver.
- When it throws, or returns anything but an integer or a non-empty string, no hash is sent. The report is still sent.
- Changing a tenant's key changes its users' hashes, as changing `APP_KEY` does.

No other user data is added: not the user's name, not their email address, and no request body, header, cookie or URL. The exception's message and stack trace are sent as your code wrote them, after [Scrubbing](#scrubbing). Scrubbing catches an email address, but not a user id or a name: if your code puts one into an exception message, kendo receives it.

## Scrubbing

Before send, the message, the stack trace, the location's file, the causes, the exception code, the runtime and framework versions, the context and the host are scrubbed of the following patterns (each replaced with a `[REDACTED:<kind>]` marker):

| Pattern | Example |
|---|---|
| JWT | `eyJhbGc...` (three base64url segments) |
| Bearer token | `Bearer <credential>`, including a Sanctum `id\|secret` token |
| Database DSN password | `mysql://user:pass@host` — only the password is redacted |
| Query-string or `name=value` credential | `?authKey=…`, `&key=…`, `DB_PASSWORD=…`, `oauth_token=…` — only the value is redacted, as `[REDACTED:secret]` |
| API-key prefix | `sk_live_...`, `AKIA...` |
| IPv4 address | `192.168.1.42` |
| BSN (Dutch citizen service number) | a 9-digit run passing the eleven-test (Dutch: elfproef) checksum |
| Email address | `user@example.com` |

BSN candidates are checksum-validated (the eleven-test) before redaction, so an arbitrary 9+-digit ID (an order number, invoice ID, or timestamp) is not falsely redacted, and a real BSN embedded in a longer digit run (e.g. a phone number) is still caught.

A query-string credential is redacted when the parameter name ends in `password`, `passwd`, `pwd`, `secret`, `token`, `api_key`, `apikey`, `auth_key`, `authkey`, `private_key` or `signature` (any case, with `-` or `_` or no separator inside the name, and any prefix), or when it is a bare `key` parameter after `?`, `&` or `;`. The value runs to the next `&`, whitespace, `#` or quote. Prose such as `foreign key=` or `Undefined array key=` is left alone.

Free-text PII that isn't a fixed secret shape — a name, address, or care-data value embedded in a database error message — is not covered by pattern matching. `QueryException` and `PDOException` are instead handled by a per-exception-type carrier-strip: the message is replaced with just the exception class, SQLSTATE, and driver error code, dropping the SQL string and bound parameter values entirely.

The stack trace carries each frame's file, line, class and function, and no argument values, whatever `zend.exception_ignore_args` is set to. An `include` or `require` frame is sent without its file name.

### Not scrubbed

- IPv6 addresses.
- A DSN password with an unencoded `?` or line break, or one that opens with digits followed by a reserved character or a space (`mysql://u:12/ab@db`), which reads as a port, is not fully redacted.
- JWTs that do not start with the conventional `eyJ` header, and truncated tokens.
- Email addresses with a quoted local part containing a space (`"jan de vries"@example.com`), and URL-encoded email addresses (`jan%40example.com`).
- Credentials outside the `name=value` form: a JSON pair (`"api_key":"…"`), a header or colon form (`X-Api-Key: …`, `password: …`), a URL-encoded pair (`api_key%3D…`), a name the list above does not end in (`APP_KEY=…`, `sig=…`, `access=…`), and a secret in a URL path (`/api/<token>/sets`).
- Other query-string values: a postcode, a house number or an address in `?address=…` is sent as written.
- IBANs. The account number of an IBAN can pass the BSN checksum, so `NL91ABNA0417164300` may go out as `NL91ABNA0[REDACTED:bsn]`, with the bank code kept and the wrong label.
- A BSN written in a grouping other than 3-3-3 (`1234 56 782`), and an 8-digit BSN whose leading zero was dropped.
- Dates, including dates of birth.
- Phone numbers. Part of a Dutch mobile number in international form (`+316…`) can pass the BSN checksum and be redacted as `[REDACTED:bsn]`; the rest of the number is sent.
- Session IDs.
- Free-text PII in a message other than a database exception's: a name, an address, a care-data value.

## Path normalization

Each stack frame's absolute path, and every absolute path in the message, has the app's own `base_path()` stripped (an **exact** prefix removal, mirroring `laravel/nightwatch`'s `Location::normalizeFile()`). The same exception thrown from `/var/www/html/app/Foo.php` and `/home/forge/app/Foo.php` normalizes to the identical `app/Foo.php`, so kendo fingerprints it once regardless of deploy root.

An anonymous exception class is named after the file that declares it (`Parent@anonymous\0/abs/path/Foo.php:LINE$N`). `exception_class` drops the `$N` compile counter and strips `base_path()` from that file; any other declaration file (outside `base_path()` — vendor code on a shared mount, a symlinked release directory — or a stream-wrapper URI such as `phar://`) is reduced to its basename, `Parent@anonymous\0[REDACTED:path]/Foo.php:LINE`. Two anonymous classes with the same file name and line outside the app root therefore share a fingerprint. Stack frames outside `base_path()` are not reduced this way: they keep their absolute path, with only the `/home/<user>/` and `/Users/<user>/` username redacted.

## Dispatch modes

- **Async (default):** `report()` dispatches `ReportErrorJob` to the queue. The job has **0 retries** — a failed POST logs to the local PHP `error_log` and is never requeued, so error tracking never amplifies load during an outage.
- **Sync:** set `error-tracker.sync` (`ERROR_TRACKER_SYNC=true`) to POST inline.

Both modes swallow every failure.

## Failure handling

A `202` response is success. Every failure — HTTP timeout, `401` (missing, invalid, revoked or expired token), `403` (a Read token, a token linked to another project, or the Error Tracking feature switched off), `422` (a field kendo refuses), `429` (rate limit), `5xx`, or an unreachable host — is written to the local `error_log` and never thrown.

## Public API

These classes and methods are the public API, marked `@api` in the source. Within a `0.x` minor they keep their signatures, so a patch release does not break code that uses them:

- `ErrorTracker`: resolve it from the container; `report(Throwable)` and `scopeUserHashUsing(Closure)`.
- `Scrubber`: `new Scrubber` and `scrub(string): string`.
- `PathNormalizer`: `new PathNormalizer(string $basePath)` and `normalize(string): string`.
- `Jobs\ReportErrorJob`: `new ReportErrorJob(array $payload)`, its public `payload`, and `dispatch` through the bus. A consumer that builds its own payload is responsible for scrubbing it.

What `Scrubber` and `PathNormalizer` redact may widen in a patch release: a new pattern is a fix. Everything else is internal, marked `@internal`, and may change in any release: `ErrorTracker`'s constructor and `send()`, `PathNormalizer::relativize()`, `RunningContext`, `MemoryLimit` and `ErrorTrackerServiceProvider`. `tests/Unit/PublicApiTest.php` pins the public signatures.

## Development

```bash
composer test          # Pest
composer test:coverage # Pest with coverage, failing below 98 % (the gate CI runs; needs pcov or Xdebug)
composer phpstan       # PHPStan (level max, self-analysis)
composer format:check  # Pint --test
composer format        # Pint write
```
