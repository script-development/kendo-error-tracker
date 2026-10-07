# KD-1938 decisions

## D1 — Batch rulings this issue builds on (epic 156)

- **D1** (Jasper, 2026-10-07): "All three data tiers ship in this epic: today's data; the server-only per-day counts and the Regressed flag; and kendo-error-tracker v0.2's new fields with the server storing them."
- **D19** (Jasper, 2026-10-07): "The ingest API stays stack-neutral: an event carries `runtime {name, version}` and `framework {name, version}`, not `php_version` / `laravel_version`."

## D2 — The chain stops at ten causes

**Chosen:** walk `getPrevious()` and keep the first ten causes, outermost first; drop the rest.
**Why:** the server answers 422 to an 11th entry, and a 422 loses the whole report. The outermost causes sit next to the code that caught them, so they explain the report best; the cap also ends the walk on a pathological chain.
**Rejected:** keeping the innermost ten (the root cause plus its nine wrappers). It reads the chain from the wrong end and needs the whole chain in memory first.

## D3 — Each cause is cut to the server's limits after scrubbing

**Chosen:** a cause's class, message and trace are cut to 255, 65,535 and 131,072 characters, after the scrubber ran. The thrown exception's own message and trace keep today's behaviour (no cut).
**Why:** one oversized entry answers 422 and the whole report is lost. Cutting after the scrubber means no secret is halved before the patterns look at it; a `[REDACTED:…]` marker cut at the end is harmless. The class name is cut too: an anonymous class name carries a file path and can pass 255.
**Rejected:** cutting before scrubbing. A JWT or a BSN cut in half no longer matches its pattern and would be sent.

## D4 — `memory_limit` is parsed with a strict pattern

**Chosen:** `MemoryLimit::toBytes()` accepts a whole number with an optional K, M or G suffix (any case, surrounding space). `-1`, any other negative, anything else, and a value at or past `PHP_INT_MAX` give null (checked on a float, because an int cast saturates), so the field is left out.
**Why:** these are the forms `memory_limit` takes in practice, and the parser stays a pure function that emits no warning.
**Rejected:** PHP's `ini_parse_quantity()`. It also reads hex and octal forms, but it raises `E_WARNING` on a malformed value, which Laravel turns into an `ErrorException` inside the consumer's exception handler.

## D5 — Unlimited memory and an unreadable field are both left out

**Chosen:** `buildPayload()` keeps dropping null keys, so an unlimited `memory_limit` is left out rather than sent as `null`. A field whose read throws is logged to `error_log` and left out (`optional()`).
**Why:** the server docs say "Send `null` or leave it out when the limit is unlimited", and the server stores `null` for a field the event leaves out. Both forms store the same row.

## D6 — The memory peak is the real allocation

**Chosen:** `memory_get_peak_usage(true)`.
**Why:** PHP checks `memory_limit` against the memory it allocated from the system, so the real figure is the one comparable to `memory_limit_bytes`.
**Rejected:** `memory_get_peak_usage()` (the emalloc'd figure), which reads lower than what the limit is checked against.

## D7 — Short string fields go through the same pipeline

**Chosen:** `exception_code` and both versions pass `PathNormalizer`, then `Scrubber`, then a 255-character cut. The names `php` and `laravel` are literals and pass nothing.
**Why:** the wave rule sends every string through the pipeline. A version or an int code never matches a pattern, so the pipeline costs nothing there; a custom exception code could carry anything.
