# KD-1873 decisions

## D0 — Wave rules this issue builds on (error-location)

- The wire contract is an optional top-level `location: {file, line}`: `file` a string of at most 255 characters, `line` an integer of at least 1, both required when `location` is sent. Kendo answers 422 to a malformed location.
- Kendo uses it only when the stack trace holds no frame but `{main}` (or is empty), for the fingerprint and the "What happened" at-line. The fingerprint of such an event is the exception class plus the normalized location file, with no line and no message.

## D1 — A new `location` field

**Chosen:** a top-level `location: {file, line}` field.
**Why:** the page would otherwise still read `at {main}`, and a line in the trace would enter the fallback fingerprint.
**Rejected:** a `#- file(line)` line prepended to `stack_trace`.

## D2 — Sent for every exception

**Chosen:** the client sends `location` for every exception.
**Why:** only the server then owns the "no frame" rule; the client does not decide which traces count as empty.
**Rejected:** sending it only when the trace is empty.

## D3 — The file gets the trace's normalization

**Chosen:** `clean($file, self::MAX_CLASS)`: `PathNormalizer::normalize()`, then `Scrubber`, then a cut to 255 characters. A file under the base path is sent relative to it; a `/home/<user>/` or `/Users/<user>/` path outside it has its username redacted, exactly as in a trace frame.
**Why:** the fingerprint uses the normalized file, so it must match how the same file reads in a trace. `short()` skips the path normalization; it is meant for strings that are not paths.
**Rejected:** the anonymous-class rule (`relativize()`, else the basename).

## D4 — Left out on an empty file or a line below 1

**Chosen:** `location()` returns null when `getFile()` is empty or `getLine()` is below 1, so the key is left out.
**Why:** PHP reports some fatals as file `Unknown` and line 0, and kendo answers 422 to line 0, which would lose the whole report.
**Rejected:** sending it and letting kendo answer 422.
