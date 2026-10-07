# KD-1939 decisions

## D1 — Batch rulings this issue builds on (epic 156)

- **D1** (Jasper, 2026-10-07): "All three data tiers ship in this epic: today's data; the server-only per-day counts and the Regressed flag; and kendo-error-tracker v0.2's new fields with the server storing them."
- **D19** (Jasper, 2026-10-07): "The ingest API stays stack-neutral: an event carries `runtime {name, version}` and `framework {name, version}`, not `php_version` / `laravel_version`. Context fields (KD-1935) use neutral names for its route, job and command kinds."

## D2 — One kind per report: a job, then the route, then the command

**Chosen:** a job wins when it is running or when the reported exception is the one it failed with (a job under `queue:work`, or a sync-queue job inside a request). Otherwise the current route, then the running console command. No report carries two kinds.
**Why:** the innermost unit of work explains the error best. A sync job fails inside a request, and its exception bubbles up to the request's handler; the job is where it ran. A request under Octane or a job under `queue:work` runs inside a long-lived command, which says little.
**Rejected:** sending every kind that applies. The server takes one `kind` per context.

## D3 — A failed job is kept against its exception, not as running

**Chosen:** `JobProcessing` pushes the job; `JobProcessed`, `JobExceptionOccurred` and `JobAttempted` remove it. `JobExceptionOccurred` also stores the job in a `WeakMap` keyed by the exception. At report time the exception's own job comes first, then the innermost job still running.
**Why:** Laravel's worker reports a job's exception after `JobExceptionOccurred`, the release or the failure, and `JobAttempted` have fired (`Worker::runJob` and `handleJobException`). A context cleared on those events is gone when the job's own report runs; a context kept until the next job leaks onto every report in between. Keying on the exception gives the job's own report its job and any other report none. The `WeakMap` entry dies with the exception. `JobAttempted` fires in a `finally`, so a failer that throws before `JobExceptionOccurred` still clears the job.
**Rejected:** clearing on `JobProcessing` of the next job (leaks onto reports between jobs), and clearing on `JobFailed` (gone before the report).

## D4 — The outermost console command lives as long as the process

**Chosen:** `CommandStarting` pushes the command name; `CommandFinished` pops it, except the last one in a console process. A nested `Artisan::call()` is gone once it finishes. The class is looked up at report time with the console kernel's `findCommand()` (falling back to `all()` on a Laravel without it).
**Why:** Laravel 13 dispatches both events by rerouting Symfony's COMMAND and TERMINATE events, and Symfony fires TERMINATE before the command's exception reaches the console kernel's report. `CommandFinished` carries no exception, so the job's `WeakMap` cannot apply. The outermost command of a console process is that process. Tinker and `schedule:run` therefore send `kind: command` with their name (orchestrator ruling, 2026-10-07). Looking the class up at report time loads one command, and only when a console report is made.
**Rejected:** clearing on `CommandFinished` (the command's own exception loses its context); resolving every command with `all()` on each `CommandStarting`.

## D5 — `response_status` only when the exception carries one

**Chosen:** a route context sends the status of a Symfony `HttpExceptionInterface`, when it lies within 100-599. Any other exception sends no `response_status`.
**Why:** at report time no response exists yet; only an HTTP exception knows its status. A status outside the range gets the whole report a 422.
**Rejected:** sending 500 for every other exception. The handler may render it as another status (a `ModelNotFoundException` becomes 404), so the guess would be wrong.

## D6 — Context strings are scrubbed and cut, not path-normalized

**Chosen:** every context string goes through the Scrubber and is cut to 255 characters. The `PathNormalizer` is not applied.
**Why:** a context value is not a file path, and the normalizer's username pass would turn a route pattern such as `/home/{user}/settings` into `/home/[REDACTED:user]/settings`. The top-level message gets the Scrubber only for the same reason.
**Rejected:** reusing `clean()`, which path-normalizes.

## D7 — The route pattern is the route as declared

**Chosen:** `pattern` is `'/' . ltrim($route->uri(), '/')`, `method` the request's method, `action` the route's `controller` action. A closure route has no controller action, so `action` is left out.
**Why:** Kendo drops the whole context when `pattern` looks like a URL, and a request URL carries ids and query strings. `uri()` is the declared pattern, without host or query.
**Rejected:** `getActionName()`, which reads `Closure` for a closure route.
