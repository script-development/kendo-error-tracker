# KD-1978 — scope the user hash to the app's tenant

## Goal

A hook an app registers once that returns its current tenant key; `user_hash` then covers the tenant key and the user id together, so the same id in two tenants gives two hashes. CHANGELOG `[0.2.1]`, ready for the tag.

## Scope

**In:** the hook in `src/`, `userHash()`, tests, README "Affected users", CHANGELOG `[0.2.1]`, the client `CLAUDE.md` line that describes `user_hash`.

**Out:** setting the hook in emmie (EMMIE-2134, emmie#2416) and Kendo (KD-1979); the v0.2.1 tag (the developer's go after the merge).

## Approach

1. `src/ErrorTracker.php`: `scopeUserHashUsing(Closure)` stores the resolver on the singleton. `userHash()` checks the id first, then asks `userHashKey()` for the key. `userHashKey()` returns v0.2.0's key without a tenant key, a key derived from the tenant key with one, and null (no hash) for a tenant key it cannot use.
2. `tests/Feature/HostAndUserHashTest.php`: the cases below.
3. README "Affected users" gains "Multi-tenant apps"; CHANGELOG `[Unreleased]` becomes `[0.2.1]`; `CLAUDE.md`'s `user_hash` sentence names the scoped derivation.

## Acceptance criteria

- The same user id under two tenant keys gives two different hashes (string and int keys).
- No resolver, and a resolver that returns null, give v0.2.0's hash for the same id and key (pinned as a literal computed with v0.2.0's formula).
- A throwing resolver leaves `user_hash` out, logs the class only, and the report is still sent.
- A resolver that returns an empty string, a float, a bool or an object sends no hash, never the unscoped one.
- The tenant key appears in no body sent.
- The resolver is not called for a guest request, a report outside a request, or an id that sends no hash.
- `composer test`, `composer phpstan` and `composer format:check` pass.
