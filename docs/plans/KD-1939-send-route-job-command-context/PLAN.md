# KD-1939 — send the route / job / command context

## Goal

The client sends a `context` saying where the exception ran: a route, a job or a command, with the field names of Kendo's `site/docs/api/error-events.md` "Context" section (KD-1935, kendo PR #2756).

## Scope

**In:** `ErrorTracker::buildPayload()` and its helpers, a `RunningContext` holder fed by job and command listeners in the service provider, tests, README "What gets sent", CHANGELOG `[Unreleased]`, the `CLAUDE.md` "Server contract" body line.

**Out:** host and user hash, the privacy text and the v0.2.0 tag (KD-1940).

## Approach

1. `src/RunningContext.php`: the jobs and commands in flight; a job that threw is kept against its exception in a `WeakMap`.
2. `src/ErrorTrackerServiceProvider.php`: bind `RunningContext`; listen for `JobProcessing`, `JobProcessed`, `JobExceptionOccurred`, `JobAttempted`, `CommandStarting` and `CommandFinished`.
3. `src/ErrorTracker.php`: `context()` picks one kind (job, then route, then command) and reads each field through `optional()`; `short()` scrubs and cuts a string that is not a path.
4. `composer.json`: require `illuminate/console` and `illuminate/routing`, whose classes the client now uses.
5. Tests: `tests/Feature/ContextTest.php` with fixtures in `tests/Fixtures/`.
6. README, CHANGELOG, CLAUDE.md.

## Acceptance criteria

- [ ] A request to `/orders/42?token=abc123&page=2` reports `{kind: route, name, method, pattern: /orders/{order}, action}`; the body holds neither the id nor the query string.
- [ ] An HTTP exception's status is sent as `response_status`; a status outside 100-599, or any other exception, sends none. A closure route sends no `action`.
- [ ] A queued job that fails under a real worker (failed, and released for a retry) reports `{kind: job, name, queue, attempt}`; a report made after it ended, in the same process, carries no context.
- [ ] A report made while a job runs carries the job; a sync-queue job that fails inside a request reports the job, not the route.
- [ ] A command's own exception, reported by the console kernel after `CommandFinished` fired, carries `{kind: command, name, class}`; after a nested `Artisan::call()` the outer command is sent.
- [ ] A report outside a request, job or command carries no context.
- [ ] A field that cannot be read is left out and the context is still sent; a context that cannot be read is left out and the report is still sent.
- [ ] Context strings are scrubbed and cut to 255 characters, and not path-normalized.
