# Changelog

All notable changes to `script-development/kendo-error-tracker` are documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.2.1] — 2026-10-08

### Fixed

- **Users who share an id across tenants counted as one (KD-1978).** In an app with one database per tenant, ids restart per tenant, so user 42 of two tenants sent the same `user_hash`: kendo counted them as one affected user and could link their reports. An app can now register a resolver once with `app(ErrorTracker::class)->scopeUserHashUsing(fn (): int|string|null => …)` that returns its current tenant key. The tenant key goes into the hash's key, so the same id in two tenants gives two hashes; the tenant key is never sent. Without a resolver, or when it returns `null`, the hash is exactly v0.2.0's, so a single-tenant app's hashes do not change. A resolver that throws, or returns anything but an integer or a non-empty string, sends no hash; the report is still sent. The README's "Multi-tenant apps" section shows the registration.

## [0.2.0] — 2026-10-08

Upgrade: a `^0.1` constraint does not admit 0.2.0. Require `^0.2`.

### Added

- **Caused-by chain, exception code, runtime and memory (KD-1938).** A report now carries `previous_exceptions` (the `getPrevious()` chain, outermost cause first, at most 10), `exception_code`, `runtime` (`php` and its version), `framework` (`laravel` and the app's version), `memory_peak_bytes` and `memory_limit_bytes`, with the field names of kendo's error-events API. Every cause is scrubbed, path-normalized and database carrier-stripped like the thrown exception, and cut to the server's limits so one oversized cause cannot get the whole report rejected. A field the client cannot read is left out; the report is still sent.
- **Where the exception ran (KD-1939).** A report now carries a `context` with kendo's field names: a `route` (name, method, the pattern as declared, the controller action, and the response status an HTTP exception carries), a `job` (class, queue, attempt) or a `command` (name, class). The request URL, query string and headers are never sent. A queued job's own exception carries its job although Laravel reports it after the job ended; a later report carries none. A running job wins over the route, and the route over the command. A field the client cannot read is left out; the report is still sent. The package now also requires `illuminate/console` and `illuminate/routing`, which every Laravel app already has.
- **Host and affected users (KD-1940).** A report now carries `host`, the machine's name (`gethostname()`), scrubbed and cut to 255 characters. A report from a request whose default guard already holds a signed-in user also carries `user_hash`: the HMAC-SHA256 of the user's id, keyed with a key derived from the app's `APP_KEY` for this field only, so kendo counts affected users without receiving their ids. The client puts the user id in no field; an id your code writes into an exception message is sent as written, because scrubbing does not catch ids. A guest request, a job, a command, an app without `APP_KEY`, or an id that is not an integer or a non-empty string sends no hash. The client never asks a guard to look up a user, so reporting runs no session read or token query. Changing `APP_KEY` changes every hash. The README's "Affected users" section says exactly what is sent.

## [0.1.2] — 2026-10-01

### Added

- **API-key prefix and IPv4 scrubbing (KD-0887).** The Scrubber now redacts Stripe-style `sk_live_...` keys, AWS `AKIA...` access key IDs, and IPv4 addresses, in addition to the existing JWT / Bearer / BSN / email coverage.
- **Database DSN password scrubbing (KD-0887).** Any `scheme://user:pass@host` credential — not just a fixed list of DB scheme names — has its password redacted (`scheme://user:[REDACTED:dsn-password]@host`), closing the most severe gap named in the KD-0885 audit debrief.
- **`QueryException` / `PDOException` carrier-strip (KD-0887).** A `QueryException` message embeds the full SQL string with bound parameter values interpolated in — free-text data (a name, an address, a care-data note) that is not a regex-able secret shape and previously reached the payload unredacted on the most common database-error path (surfaced by the emmie annexation, which carries NEN 7510 / AVG care data). `ErrorTracker` now replaces any `PDOException`-family message with `class [SQLSTATE x] [driver code y]` before the Scrubber even runs, dropping the SQL and bindings entirely while preserving the fingerprint.
- **`PathNormalizer` username redaction fallback (KD-0887, M-3).** A stack frame whose path does not start with `base_path()` (vendor installed outside the app root, a globally-installed tool) previously leaked the absolute path verbatim, including the OS username. A secondary pass now redacts the username segment of any `/home/<user>/` or `/Users/<user>/` shape in those frames.

### Fixed

- **DSN password leaked when the username is empty (WR-1498).** The DSN rule required at least one username character, so the canonical Redis form `redis://:secret@host:6379/0` did not match and its password was sent unredacted. An empty username now matches (`redis://:[REDACTED:dsn-password]@host`); a URL with no userinfo is left untouched.
- **Anonymous exception class names leaked the install path (WR-0798).** `exception_class` was sent as the raw `$throwable::class`, and an anonymous class's name is `Parent@anonymous\0/abs/path/file.php:LINE$N` — the consumer's absolute filesystem path plus PHP's process-global compile counter, so the same exception also fingerprinted differently on every deploy (KD-0771 D6). The counter is now dropped and the declaration file takes the `PathNormalizer` pass (`Parent@anonymous\0app/Foo.php:LINE`); any other declaration file — outside `base_path()`, or a stream-wrapper URI such as `phar://` — keeps only its basename (`Parent@anonymous\0[REDACTED:path]/Foo.php:LINE`), so no absolute path is sent and the value does not depend on the install location. The result then takes the `Scrubber` pass, and the `PDOException` carrier message uses the same form. Named classes are sent unchanged. Consumer-visible: error groups for anonymous exception classes re-fingerprint once on upgrade.
- **BSN eleven-test (Dutch: elfproef) validation (KD-0887, M-1).** The KD-0885 fix widened the BSN pattern to any run of 9-or-more digits, which resolved the missed-detection leak but over-redacted legitimate 9+-digit IDs (order numbers, invoice IDs, timestamps) as `[REDACTED:bsn]`. BSN candidates — both grouped (`123.456.782`) and bare digit runs — are now validated against the eleven-test checksum before redaction, so only a number that is actually shaped like a real BSN redacts. A digit run is scanned for any contiguous 9-digit window that passes, so a real BSN embedded in a longer number is still caught without redacting the surrounding digits.

## [0.1.1] — 2026-06-29

### Fixed

- **Eager `Dispatcher` injection broke the never-throw invariant.** `ErrorTracker` took `Illuminate\Contracts\Bus\Dispatcher` as a constructor dependency, so resolving the service threw a `BindingResolutionException` *before* `report()`'s try/catch whenever the Bus deferred provider was unresolvable. Because `report()` runs inside the consumer's exception handler, that throw escaped the reportable callback and replaced the original error with `Target [Illuminate\Contracts\Bus\Dispatcher] is not instantiable` (observed in a Laravel 12 app). The bus is now resolved lazily from the container inside `report()`'s guard, so the failure is swallowed and the original error is preserved.

## [0.1.0] — 2026-06-08

Inaugural public release. The KD-0885 scrubber hardening below landed before
first publish, so `0.1.0` is the first version available on packagist and the
pre-hardening code was never distributed.

### Added

- Initial release of the kendo error-tracking client library.
- Auto-discovered `ErrorTrackerServiceProvider` binding the `ErrorTracker` service.
- Publishable `config/error-tracker.php` with `kendo_url`, `project`, `token`, `environment`, `release`, `sync` keys (env defaults `ERROR_TRACKER_*`).
- `ErrorTracker::report(\Throwable): void` — idempotent, swallow-on-failure; never throws, never blocks the caller.
- Async dispatch via `ReportErrorJob` (0 retries; failed posts log to PHP `error_log`, never requeue) with opt-in sync mode (`error-tracker.sync`).
- Scrubbing layer redacting JWT, `Bearer` tokens, BSN, and email addresses from the message and stack trace before send.
- Exact `base_path()` path normalization of each stack frame (mirrors `laravel/nightwatch`'s `Location::normalizeFile()`) for host-stable fingerprints.
- POST to `{kendo_url}/api/projects/{project}/error-events` with Bearer auth and the `{environment, release?, exception_class, message, stack_trace}` body; treats `202` as success and swallows every failure (timeout, `401`/`403`/`422`, `5xx`, unreachable host).

### Fixed

- **BSN scrub gaps (KD-0885, C-1):** the bare 9-digit pattern missed grouped forms (`123.456.782`, `123 456 782`, `123-456-782`) and BSNs embedded in a longer digit run (`0612345678`). The pattern now matches grouped separators and any run of 9-or-more digits. Tradeoff: increased over-redaction of legit 9+-digit IDs until the elfproef (11-proef) validator lands (v1.5).
- **Email IDN / unicode leak (KD-0885, H-1):** the ASCII-only email pattern leaked IDN domains (`jan@müller.nl`) and unicode local parts. The pattern now uses the `u` flag and `\p{L}\p{N}` classes. Quoted local parts with an internal space remain out of scope (documented).
- **Bearer credential reuse leak (KD-0885, H-2):** a credential redacted in a `Bearer <token>` value leaked again where the same value reappeared unprefixed (`token=<same>`). The Bearer scrub now captures the credential and redacts every bare reuse across the string. The `eyJ`-header requirement for JWT detection is documented as an explicit scope boundary.
- **Timeout disabled by zero/negative config (KD-0885, H-3):** `ErrorTracker::configFloat()` accepted `0`/negative numeric timeouts (Guzzle-infinite), so a hung kendo host could block the caller in sync mode. A non-positive numeric now floors to the default for both `connect_timeout` and `timeout`.
- **Bearer over-redaction (KD-0885, M-4):** the Bearer credential class included `/` and matched across newlines, so `Bearer /api/v1/users/123` ate the whole path. The class now drops `/` and bounds whitespace to horizontal characters.
