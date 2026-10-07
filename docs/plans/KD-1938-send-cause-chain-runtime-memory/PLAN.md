# KD-1938 — send the cause chain, exception code, runtime and memory

## Goal

The client sends the caused-by chain, the exception code, the runtime and framework, and the memory peak and limit, with the field names of Kendo's `site/docs/api/error-events.md` (KD-1934, kendo PR #2753).

## Scope

**In:** `ErrorTracker::buildPayload()` and its helpers, a `MemoryLimit` parser, tests, README "What gets sent", CHANGELOG `[Unreleased]`, the `CLAUDE.md` "Server contract" body line.

**Out:** route / job / command context (KD-1939); host, user hash, the privacy text and the v0.2.0 tag (KD-1940).

## Approach

1. `src/ErrorTracker.php`: split today's message and trace pipeline into `message()` and `stackTrace()`; add `previousExceptions()`, `exceptionCode()`, `framework()`, `clean()`, and the per-field guard `optional()`.
2. `src/MemoryLimit.php`: parse `ini_get('memory_limit')`.
3. Tests: `tests/Feature/PreviousExceptionsTest.php`, `tests/Feature/RuntimeFactsTest.php`, `tests/Unit/MemoryLimitTest.php`.
4. README, CHANGELOG, CLAUDE.md.

## Acceptance criteria

- [ ] A report carries `previous_exceptions` (class, message, trace per cause), outermost first, scrubbed and path-normalized like the thrown exception; a `PDOException` in the chain is carrier-stripped.
- [ ] An exception wrapping a cause whose message holds a bearer token and an email sends the chain scrubbed.
- [ ] A cause with a 200,000-character trace is sent cut to 131,072, and a fake server that answers 422 over the limits answers 202.
- [ ] A report carries `exception_code`, `runtime`, `framework`, `memory_peak_bytes` and `memory_limit_bytes`; an unlimited memory limit is left out.
- [ ] A field that throws while being read is left out; the report is still sent.
- [ ] README and CHANGELOG list the new fields.
