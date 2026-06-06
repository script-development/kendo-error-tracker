# Changelog

All notable changes to `script-development/kendo-error-tracker` are documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.1.0] — 2026-06-06

### Added

- Initial release of the kendo error-tracking client library.
- Auto-discovered `ErrorTrackerServiceProvider` binding the `ErrorTracker` service.
- Publishable `config/error-tracker.php` with `kendo_url`, `project`, `token`, `environment`, `release`, `sync` keys (env defaults `ERROR_TRACKER_*`).
- `ErrorTracker::report(\Throwable): void` — idempotent, swallow-on-failure; never throws, never blocks the caller.
- Async dispatch via `ReportErrorJob` (0 retries; failed posts log to PHP `error_log`, never requeue) with opt-in sync mode (`error-tracker.sync`).
- Scrubbing layer redacting JWT, `Bearer` tokens, BSN, and email addresses from the message and stack trace before send.
- Exact `base_path()` path normalization of each stack frame (mirrors `laravel/nightwatch`'s `Location::normalizeFile()`) for host-stable fingerprints.
- POST to `{kendo_url}/api/projects/{project}/error-events` with Bearer auth and the `{environment, release?, exception_class, message, stack_trace}` body; treats `202` as success and swallows every failure (timeout, `401`/`403`/`422`, `5xx`, unreachable host).
