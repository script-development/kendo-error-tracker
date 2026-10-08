# kendo-error-tracker — kendo error-tracking client library

Distributable Laravel client library that reports scrubbed exceptions into kendo's error-tracking endpoint (KD-0771). Sister to `phpstan-warroom-rules` on the artifact side (standalone Composer package, public packagist, MIT, Pest/PHPStan/Pint) — but a **runtime library** (`type: library`, ServiceProvider auto-discovery), not a static-analysis extension.

## Stack

- **Language:** PHP 8.4+ (`private const string`, `readonly` classes, first-class callable syntax).
- **Framework:** Laravel package (`illuminate/* ^11–13`), ServiceProvider auto-registered via `extra.laravel.providers`.
- **Test:** Pest 3 over Orchestra Testbench (Feature tests boot a Laravel app; Unit tests are pure).
- **Static analysis:** PHPStan 2.x, level max, self-analysis on `src/`.
- **Format:** Pint (canonical war-room config).
- **Publish:** Auto-sync to public packagist.org via repository webhook (push-trigger; versioned releases on tag push via `release.yml`). First-time packagist submission is a manual user step.

## Server contract (KD-0771)

The client targets the kendo error-events ingestion endpoint shipped by KD-0771:

- **Route:** `POST {kendo_url}/api/projects/{project}/error-events`.
- **`{project}` route-key:** the project **id** (no `getRouteKeyName` override on the kendo `Project` model).
- **Auth:** Bearer — a kendo project token carrying the `error-events:write` ability.
- **Body:** `{environment, release?, exception_class, message, stack_trace, previous_exceptions?, exception_code?, runtime?, framework?, memory_peak_bytes?, memory_limit_bytes?, context?, host?, user_hash?}` (KD-1934's, KD-1935's and KD-1936's fields, documented in kendo's `site/docs/api/error-events.md`). `previous_exceptions` is a list of `{exception_class, message, stack_trace}`, at most 10, each entry within 255 / 65,535 / 131,072 characters, or the whole event gets 422. `context` is `{kind: route|job|command, name?, …}` with the fields of its kind; kendo drops the whole `context` when a route `pattern` looks like a URL. `host` is at most 255 characters. `user_hash` is HMAC-SHA256 of the user's id keyed with `APP_KEY`, as 64 lowercase hex characters; kendo silently drops any other shape and still answers 202. Unknown keys are stripped server-side; no request body, header, URL or user id.
- **Success:** `202 Accepted`, empty body. The client treats only `202` as success.
- **Failures:** `401` (no/invalid token), `403` (token lacks the ability), `422` (token not linked to the route's project, or revoked), `5xx`, timeout, unreachable — all swallowed.

## Architecture

| Class | Responsibility |
|---|---|
| `ErrorTracker` | Public surface. `report(\Throwable)`: builds the scrubbed/normalized payload synchronously, then sends inline (sync) or dispatches `ReportErrorJob` (async). Any `\PDOException` (including Laravel's `QueryException`, which extends it) gets a carrier-strip first — the message becomes `class [SQLSTATE x] [driver code y]`, dropping the SQL string and bound parameter values before the Scrubber even runs. `send(array)`: the HTTP POST, swallow-on-failure. Reads config live from the `Config` repository. |
| `Scrubber` | Redacts JWT / Bearer / DSN password / API-key prefix / IPv4 / BSN (eleven-test validated) / email from a string. |
| `MemoryLimit` | Parses a `memory_limit` ini value (`128M`, `2G`, plain bytes) into bytes; `-1` and anything unparsable give null. |
| `PathNormalizer` | Exact `base_path()` prefix strip of every frame (mirrors `laravel/nightwatch`'s `Location::normalizeFile()`). |
| `Jobs\ReportErrorJob` | Async carrier for the already-scrubbed payload. `$tries = 1` (0 retries); `failed()` logs to `error_log`, never requeues. |
| `RunningContext` | The jobs and console commands in flight, fed by the provider's queue and command listeners. A job that threw is kept against its exception (Laravel reports it after the job ended); the outermost console command lives as long as the process. |
| `ErrorTrackerServiceProvider` | Auto-discovered. Merges + publishes config; binds `ErrorTracker`, `PathNormalizer` (wired to the app's `base_path()`) and `RunningContext`, and listens for the job and command events. |

**Swallow-on-failure is the load-bearing invariant.** `report()` is called from inside the consumer's exception handler — it must never throw and never block. Every path (payload build, dispatch, HTTP send) is wrapped; failures go to `error_log`.

**Scrubbing happens synchronously, before the queue boundary.** The serialized `ReportErrorJob` payload carries no raw PII even in async mode.

## Conventions

- **Namespace:** `ScriptDevelopment\KendoErrorTracker\` (PSR-4, `src/`).
- **Config keys live in `config/error-tracker.php`** with `ERROR_TRACKER_*` env defaults. No `territory` key — the destination project is named in the route and bound to the token (KD-0771 D1/D2).

## Commands

| Command | Purpose |
|---|---|
| `composer test` | Run the Pest suite |
| `composer phpstan` | Self-analysis (level max) on `src/` |
| `composer format` | Pint write |
| `composer format:check` | Pint check |

## Versioning

SemVer. Pre-1.0 (`0.x`): minor bumps are treated as breaking (Composer's `^0.x` caret locks at minor). `main` is always release-ready; PRs update `CHANGELOG.md` under `[Unreleased]`; a release PR moves it to a versioned heading and tags the merge commit (`v0.x.y`). Packagist's webhook picks up the tag; `release.yml` re-runs CI and creates the GitHub release.

## Out of scope

Client-side coalescing/debounce (server owns dedup + rate limit), per-project custom scrub rules (v1.5), a framework-agnostic core (Laravel-only), a JS/TS client (v1.5+), Sentry shim, phone numbers / session IDs as scrub patterns (no shape distinct enough to redact without a high false-positive rate), a `RequestException` message-body carrier-strip (raised as a "consider" in the KD-0887 discussion, referencing the war-room Nightwatch-egress recon — no concrete shape/threshold was specified, so it's deferred pending that follow-up rather than guessed at here).
