# KD-1940 decisions

## D1 — Batch rulings this issue builds on (epic 156)

- **D5** (Jasper, 2026-10-07): "Affected users: the client sends HMAC(user id, the app's APP_KEY); Kendo never receives the user id. Kendo keeps the distinct hashes per group for 90 days, the group retention. Jasper reviews the client README's privacy wording before v0.2.0 is tagged."
- **D19** (Jasper, 2026-10-07): "The ingest API stays stack-neutral: an event carries `runtime {name, version}` and `framework {name, version}`, not `php_version` / `laravel_version`. Context fields (KD-1935) use neutral names for its route, job and command kinds."

## D2 — Read only a user the app already resolved

**Chosen:** the hash reads the default guard only when the container has resolved `auth` and the auth manager has resolved a guard. It then asks `hasUser()`, and calls `user()` only when that is true.
**Why:** `user()` on a guard that holds no user runs a session read or a token lookup. Inside exception reporting that is a database query while the database may be what failed. A user the app resolved is already on the guard.
**Rejected:** `Auth::user()` or `Auth::id()`, which run the lookup.

## D3 — A hash only beside a route context

**Chosen:** `buildPayload()` asks for `user_hash` only when the report's `context` is a `route`. A job (a sync job inside a signed-in request too), a command, or a report from anywhere else carries none.
**Why:** the brief's AC 1 reading says a job and a command carry no hash, and Kendo's docs say to leave it out outside a request. A queue worker's guard keeps a user an earlier job set with `Auth::setUser()`, so `hasUser()` alone would attach that user to later jobs' errors. The route check is the client's one existing answer to "is this a request" (`context()`, KD-1939).
**Rejected:** `hasUser()` alone (the worker leak above); `runningInConsole()` (true under Octane's and the test runner's requests too).

## D4 — The key and rotation

**Chosen:** a key for this field only, derived from the configured `app.key` string (including a `base64:` prefix): `$k = hash_hmac('sha256', 'kendo-error-tracker:user_hash', $appKey, true)`, then `hash_hmac('sha256', (string) $id, $k)` (orchestrator ruling, 2026-10-08). No key, or an empty one, sends no hash. The id must be an int or a non-empty string; any other value sends none.
**Why:** `APP_KEY` is a secret every Laravel app already holds and never sends (D5); the derived key depends on it alone, so D5 holds. Laravel signs URLs with `hash_hmac('sha256', $url, config('app.key'))`, the same string. Hashing the id with that key directly would make a hash equal the signature of a URL whose text is the id. The derived key ends that overlap. The change is free only before v0.2.0 ships: a later key change makes every user count twice once. A float, a bool or an object id is not a user id the client can render stably.
**Rejected:** `hash_hmac('sha256', (string) $id, $appKey)`, as first built. The overlap needs a user id that is a valid signed URL of the app, so it is not exploitable in practice, but the derived key removes it for two lines.
**Note:** rotating `APP_KEY` changes every hash, so a user counts twice within kendo's 90-day window. The README says so.

## D5 — The host source

**Chosen:** `gethostname()`, scrubbed and cut to 255 characters through `short()` (KD-1939). A `false` or empty result, or a read that throws, leaves `host` out.
**Why:** it names the machine (or container) on every PHP SAPI with no configuration. `short()` keeps the scrubbing pipeline every string field takes; it is not a path, so it is not path-normalized.
**Rejected:** `$_SERVER['SERVER_NAME']` or the request host: that is the site the user visited, not the machine, and it exists only in a request.

## D6 — The hostname reader is injectable

**Chosen:** `ErrorTracker` takes an optional `?Closure $hostname` after `RunningContext`; `null` means `gethostname()`. The service provider passes none.
**Why:** a `false` from `gethostname()` and an overlong name cannot be produced on a test machine otherwise. The package imports its functions (`use function`), so a namespace override does not work.
