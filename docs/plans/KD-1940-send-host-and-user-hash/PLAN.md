# KD-1940 — send the host and a keyed user hash, ready v0.2.0

## Goal

The client sends `host` and `user_hash` with the field names of Kendo's `site/docs/api/error-events.md` ("Request Fields" and "Affected users", KD-1936, kendo PR #2757). The README says exactly what is sent, and CHANGELOG `[Unreleased]` becomes `[0.2.0]`, ready for the tag.

## Scope

**In:** `ErrorTracker::buildPayload()` and its helpers, tests, README "What gets sent" and "Scrubbing", CHANGELOG, the `CLAUDE.md` "Server contract" body line.

**Out:** the v0.2.0 tag (the developer's go, after the merge and after the privacy wording is approved); Kendo's install hint and docs.

## Approach

1. `src/ErrorTracker.php`: `host()` reads `gethostname()` through `short()`; `userHash()` reads the default guard's user only when `hasUser()`, and hashes its auth identifier with a key derived from `config('app.key')` (DECISIONS D4). `buildPayload()` asks for the hash only beside a `route` context. Both read through `optional()`.
2. `tests/Feature/HostAndUserHashTest.php`.
3. README "What gets sent" (new "Affected users" subsection) and "Scrubbing"; CHANGELOG `[0.2.0]`; `CLAUDE.md`.

## Acceptance criteria

- [ ] A signed-in request reports `user_hash` = HMAC-SHA256(id, the key derived from APP_KEY) as 64 lowercase hex; the raw id appears nowhere in the body.
- [ ] A guard that holds no user is never asked for one (`user()` is not called), and the report carries no hash.
- [ ] A guest request, a sync job inside a signed-in request, and a report outside a request carry no hash.
- [ ] No `APP_KEY`, or an id that is not an int or a non-empty string, sends no hash; an id read that throws leaves the hash out, logs the class only, and the report is still sent.
- [ ] `host` is the scrubbed `gethostname()`, cut to 255; a `false`, empty or throwing read leaves it out.
- [ ] README states what is sent about a user; CHANGELOG has `[0.2.0]` covering KD-1938, KD-1939 and KD-1940.
