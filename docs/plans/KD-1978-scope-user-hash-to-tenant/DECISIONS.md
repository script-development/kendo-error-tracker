# KD-1978 decisions

## D1 — Rulings this issue builds on

- **Batch D5** (Jasper, 2026-10-07, epic 156): "Affected users: the client sends HMAC(user id, the app's APP_KEY); Kendo never receives the user id. Kendo keeps the distinct hashes per group for 90 days, the group retention. Jasper reviews the client README's privacy wording before v0.2.0 is tagged."
- **Developer ruling** (Jasper, 2026-10-08) on crit's finding `1f42282d6cf5` on emmie#2416, "user_hash merges distinct users who share an ID across customer databases": hold emmie's upgrade for a client fix.

## D2 — The tenant key goes into the key derivation

**Chosen:** with a tenant key, the HMAC key is `hash_hmac('sha256', 'kendo-error-tracker:user_hash' . "\0" . $tenantKey, APP_KEY, true)`; without one it stays v0.2.0's `hash_hmac('sha256', 'kendo-error-tracker:user_hash', APP_KEY, true)`. The id stays the HMAC message, as in v0.2.0. An int tenant key is used as its decimal string.
**Why:** the orchestrator's alignment. The id is hashed exactly as before, so no tenant key gives byte-for-byte v0.2.0's hash (a single-tenant app's hashes and Kendo's stored counts do not change). The NUL separator keeps every scoped derivation message apart from the unscoped one, which has none, and two different tenant keys give two different messages.
**Rejected:** the tenant key in the message (`$tenant . "\0" . $id`): it works, but changes the message for every scoped hash while the key derivation already exists to carry a field-specific input.

## D3 — No fallback to the unscoped hash

**Chosen:** a resolver that returns `null` gives the unscoped hash. A resolver that throws, or returns anything but `null`, an int or a non-empty string, sends no `user_hash`. A throw is caught by `optional('user_hash')`, which logs the exception class only, so the rest of the report is sent.
**Why:** `null` means "no tenant here" (a central route in a tenant app, or a single-tenant app), where ids do not collide. A tenant app whose resolver fails would get the collision of D1 back if it fell back to the unscoped hash. Leaving one count out is cheaper than merging users across tenants.
**Rejected:** the unscoped hash on a throw or an unusable key.

## D4 — The hook's form: an instance method on the singleton

**Chosen:** `app(ErrorTracker::class)->scopeUserHashUsing(fn (): int|string|null => …)`, registered once in a service provider's `boot()`. The resolver is stored on the `ErrorTracker` singleton and called at report time, only after the id check passed, so a guest request, a job, a command or an unusable id never calls it. `ErrorTracker` therefore stops being a `readonly` class: every constructor property stays `readonly`, and the resolver is its one mutable property.
**Why:** the brief's preference. An instance property dies with the container, so Testbench's fresh app per test needs no reset, and a test can build its own `ErrorTracker` without global state. The tenant key is read when the report is built, which is when the tenant is known.
**Rejected:** a static setter (`ErrorTracker::scopeUserHashUsing()`): its state outlives the container, so every test and every long-lived worker would need a reset. A config key holding the closure: `php artisan config:cache` cannot serialize a closure. A mutable holder class injected into a still-`readonly` `ErrorTracker`: one more class and one more binding for one nullable property.
