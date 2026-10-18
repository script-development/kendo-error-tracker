# KD-1873 — send the throwable's location

## Goal

A `FatalError` (or any throwable whose trace holds only `{main}`) arrives in kendo with its normalized `file:line`; kendo groups it by class + file and shows `at app/…:N`.

## Scope

**In:** send `location: {file, line}` for every exception, from `getFile()`/`getLine()`, read through `optional()`; the file goes through the same path normalization and scrubbing as trace frames and is cut to 255 characters; the README contract, the `CLAUDE.md` "Server contract" body line, and a CHANGELOG entry under `[Unreleased]`.

**Out:** a location on each `previous_exceptions` entry; the release tag and the emmie/kendo consumer bumps; anything on the kendo side (KD-2205).

## Approach

1. `src/ErrorTracker.php`: `location()`, read through `optional()` in `buildPayload()`; the file through `clean($file, self::MAX_CLASS)`.
2. Tests: `tests/Feature/LocationTest.php`, with a synthetic `Symfony\Component\ErrorHandler\Error\FatalError` built with an empty trace.
3. README "What gets sent", `CLAUDE.md` "Server contract", CHANGELOG.

## Acceptance criteria

- [ ] A `FatalError` with an empty trace and a file under `base_path()` sends a relative `location.file` and the error's line.
- [ ] A `FatalError` at a `/home/<user>/…` file outside the base path sends no username.
- [ ] An ordinary exception sends its location too.
- [ ] A file longer than 255 characters is cut to 255.
- [ ] An empty file or a line below 1 leaves `location` out; the report is still sent.
- [ ] README, `CLAUDE.md` and CHANGELOG describe the field.
