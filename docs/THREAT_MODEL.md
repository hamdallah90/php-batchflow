# BatchFlow threat model

The memory and failure model, the stream rules and the safety model of `SPEC.md` are the contract. This file states what BatchFlow defends against, what it
leaves to the caller, and the limits it knows.

## What is trusted and what is not

- **Untrusted:** CSV bytes (file or stream), and the number, size and distinctness of the rows, fields and group
  keys in them.
- **Trusted:** the PHP code that calls BatchFlow: schema, options, literals, paths and streams. The extension
  validates them and raises `SchemaException` or `ValueError`, but it does not treat the caller as an attacker.
- **Not a sandbox:** the path is whatever the PHP process may read. `open_basedir`, stream wrappers and
  `allow_url_fopen` apply exactly as for `fopen`. A caller who passes a user-chosen path has a path-traversal
  problem that BatchFlow cannot see.

## Defences against hostile CSV

| Threat | Defence | Where |
| --- | --- | --- |
| Many distinct group keys to exhaust memory or force hash collisions (HashDoS) | SipHash-1-3 with a 128-bit key drawn from the CSPRNG for each run and each `Table`, never exposed; `maxGroups`, `maxGroupBytes`; `ResultTooLargeException` or `LimitException` | SPEC 5.9, 6.3 |
| One huge row or field | `maxRowBytes` (default 16 MiB) and `maxFieldBytes` (default 1 MiB), exact; the reader buffer is bounded by `maxRowBytes + 2 + 65536 + 64` | SPEC 5.3, 6.4 |
| Huge file loaded into a `Table` | `maxTableBytes`, `maxDictionarySize`, `maxInputRows`, all preflighted before each growth | SPEC 6.4, 6.6 |
| Huge result | `toArray` budget check at most every 1 MiB of estimated PHP allocation; group results preflighted before they are built | SPEC 6.4 |
| Memory fatal or `_exit(137)` from a native loop that keeps allocating past `memory_limit` | Every growth is preflighted against `memory_limit` (`mem_fits`) before it allocates; a refusal is a catchable `LimitException` | SPEC 6.4 |
| Integer overflow in sums, offsets, counts, lengths | Checked arithmetic on every count, length and allocation size; an `int64` sum overflow is `SumOverflowException` and a non-finite float sum raises | SPEC 5.8, 6.4 |
| Malformed framing, quotes, CR, BOM, NUL | One exact dialect (SPEC 5.4); the leftmost failing byte decides; no record bytes in any message | SPEC 5.4, 5.11 |
| Quadratic rescans with short reads | The reader doubles the buffered bytes before it rescans | SPEC 6.2 |
| Timeout bypass inside a native loop | `EG(timed_out)` is checked after every read and every 1,048,576 table rows; the run raises `BatchFlowException` and lets the engine raise its timeout fatal | SPEC 6.4 |
| Information leak through errors | Messages carry row, line, offset and column index, never record bytes | SPEC 8 |

Hostile input ends in a catchable exception within the preflight's best-effort limit, not in a dead process.

## Known limits

1. **The memory preflight is best-effort for small blocks (SPEC 6.4).** On a fragmented heap close to
   `memory_limit`, a small block whose bin has no free slot needs a free page run that the rule's "half the average
   free space" does not guarantee, and PHP's normal `Allowed memory size ... exhausted` fatal can still occur.
   Large and huge blocks are priced exactly or above their cost. The hard limit (`_exit(137)`) stays unreachable
   because nothing allocates more than one growth step past a check.
2. **A soft `memory_limit` crossing in PHP is a fatal, not an exception.** A result build whose preflight
   undercharges ends in `Fatal error: Allowed memory size ...` at the terminal call; no `catch` or `finally` runs.
3. **`memory_limit=-1`:** only `maxGroupBytes`, `maxTableBytes` and the per-row limits bound memory.
4. **Streaming runs have no timeout check after the last row.** A timer that fires while the last buffer is
   scanned reaches PHP when the call returns, as the engine's plain timeout fatal, without `BatchFlowException`.
5. **`pclose()` of a stream from its own callback is a php-src use-after-free**, reproducible with plain `fread`
   and not specific to BatchFlow. Because a run has one stream and all PHP code runs inside that stream's
   calls, the message `a stream was closed during processing` is a defensive branch with no safe PHP-level test.
6. **A cursor's stream can be closed between fetches.** `pclose()` ignores the in-use flag that makes `fclose()`
   refuse, so PHP code can close a caller's stream between two fetches. The next fetch raises `StreamException`
   (`a stream was closed during processing`) before it emits any buffered row, and the cursor is closed. PHP code
   that reads, writes or seeks a stream between fetches is not detected (SPEC 5.13).
7. **Fibers.** A stream held by a run must not be used from another fiber while the run is active. A bailout
   while a run is suspended in a fiber leaves `PHP_STREAM_FLAG_NO_FCLOSE` set on a persistent stream until the
   worker exits.
8. **Stream state after an exception is unspecified:** the reader reads ahead, so the caller's stream position
   does not mark the failing row.
9. **Per-version stream behaviour.** PHP 8.5 sets `NO_FCLOSE` around userspace wrapper callbacks, so a run on a
   wrapper's own stream from inside its callback is refused on 8.5 and allowed on 8.3 and 8.4 (SPEC 6.5). A path
   that names a directory opens on Linux and fails on read with an `E_NOTICE` that can reach an error handler
   before `StreamException`.
10. **Old PHP 8.3 patch releases.** Before 8.3.36, `zend_exception_set_previous` does not skip an exit being
    chained onto (GHSA-w8hw-w9fx-j79w); BatchFlow releases the held exception itself, and the suite passes on
    8.3.21, but a PHP security update is still the fix for that class of bug.
11. **ZTS is not supported.** `composer.json` sets `support-zts` to false, the module has not been reviewed or
    tested for threads, and the ZTS CI lane is provisional and allowed to fail. Do not load the extension in a
    ZTS build.
12. **Platform.** Linux x86-64 and arm64 are tested. macOS builds and runs (checked on arm64 with PHP 8.3); it
    has no CI lane. Windows is not supported (`os-families`).
13. **CSV formula injection.** Values are not altered. If you export BatchFlow's output to a spreadsheet, escape
    cells that begin with `=`, `+`, `-` or `@` yourself.
14. **No sandboxing of native code.** The extension is C. A memory-safety bug in it is a bug in the PHP process.
    The mitigations are the ones in the next section; they reduce the risk and do not remove it.

## How the native code is checked

- Native core (`src/bf_*.c`) has no `php.h` dependency and is fuzzed on its own (`fuzz/`): a libFuzzer harness
  under ASan and UBSan checks all compiled mask functions against each other and against a reference parser,
  every read-chunking pattern, the group table with colliding hashes, the column store and its dictionaries,
  and the memory rule on injected counters.
- PHPT suite under ASan, UBSan and the Zend allocator's own leak report (CI `sanitizer` job).
- No third-party code, no threads, no `mmap`, no persistent allocation, no global request state.
- `nm -D` shows `get_module` only: the build uses `-fvisibility=hidden`.
