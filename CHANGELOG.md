# Changelog

All notable changes to `script-development/kendo-error-tracker` are documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Fixed

- **BSN scrub gaps (KD-0885, C-1):** the bare 9-digit pattern missed grouped forms (`123.456.782`, `123 456 782`, `123-456-782`) and BSNs embedded in a longer digit run (`0612345678`). The pattern now matches grouped separators and any run of 9-or-more digits. Tradeoff: increased over-redaction of legit 9+-digit IDs until the elfproef (11-proef) validator lands (v1.5).
- **Email IDN / unicode leak (KD-0885, H-1):** the ASCII-only email pattern leaked IDN domains (`jan@müller.nl`) and unicode local parts. The pattern now uses the `u` flag and `\p{L}\p{N}` classes. Quoted local parts with an internal space remain out of scope (documented).
- **Bearer credential reuse leak (KD-0885, H-2):** a credential redacted in a `Bearer <token>` value leaked again where the same value reappeared unprefixed (`token=<same>`). The Bearer scrub now captures the credential and redacts every bare reuse across the string. The `eyJ`-header requirement for JWT detection is documented as an explicit scope boundary.
- **Timeout disabled by zero/negative config (KD-0885, H-3):** `ErrorTracker::configFloat()` accepted `0`/negative numeric timeouts (Guzzle-infinite), so a hung kendo host could block the caller in sync mode. A non-positive numeric now floors to the default for both `connect_timeout` and `timeout`.
- **Bearer over-redaction (KD-0885, M-4):** the Bearer credential class included `/` and matched across newlines, so `Bearer /api/v1/users/123` ate the whole path. The class now drops `/` and bounds whitespace to horizontal characters.

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
