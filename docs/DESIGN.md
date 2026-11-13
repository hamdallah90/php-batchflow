# BatchFlow design notes

`SPEC.md` is the authority for behaviour. This file records design decisions that the spec leaves to the
implementation: the core and glue split, ownership, the memory lifecycle, complexity, the internal formats, and the
Table. Section numbers (5.x, 6.x) refer to `SPEC.md`.

## Core and glue

`src/bf_*.c` (the core) has no `php.h`. It reaches PHP through the `bf_host` function table (alloc, realloc, free,
`mem_fits`, read, strtod, `interrupted`, `emit_row`), so the fuzz harness can link it with a `malloc` host and a
scripted reader. `src/php_*.c` (the glue) implements the host, builds messages and owns every PHP object. The core
never formats text from record bytes, and nothing in a loop calls PHP: PHP code can run only inside a stream
read or EOF call, where the glue re-checks `EG(exception)` and re-fetches the resource (6.5).

## Ownership

| Object | Owns | Lifetime |
| --- | --- | --- |
| `Query` | copied predicate vector, owned string literals, shared `whereIn` sets (reference counted), a compiled plan | immutable; a derived `Query` shares sets and plans by reference count |
| `Query` over a `Table` | a strong reference to the `Table` object | keeps the table alive after the temporary is dropped |
| `Query` over a stream | a strong reference to the stream resource | the stream is never closed by a run (a path-opened stream is) |
| `Table` | column arrays, the string arena, dictionaries, its SipHash key | immutable after load; no per-run state, so nested and fiber-interleaved queries are safe |
| `Cursor` | a strong reference to its `Query`, the run struct (reader buffer, masks, field table) while open | released at end of input, on error, on `close()`, or in `free_obj` |
| a terminal call | one run struct on the heap, address fixed before the `zend_try` | freed on every exit, bailout included |

Nothing native references a `Cursor`, and a `Table` references nothing, so no object cycle passes through these
edges and no `get_gc` handler is needed. There is no module-global state except the SIMD function pointer, set once
in `MINIT`, and no persistent allocation.

## Memory lifecycle

All per-run and table memory is Zend memory (`emalloc` through the host); the core never calls `malloc`. Every growth
is preflighted (`mem_fits`) before it allocates, so a limit is a catchable exception and the hard limit
(`_exit(137)`) is unreachable. In order, a streaming run holds: the reader buffer (starts at 256 KiB, doubles to at
most `B = maxRowBytes + 2 + 65536 + 64`), the mask array (`ceil(B / 64) * 8` bytes), the field table (file columns
x 32 bytes, at most 4096 columns), and group state when grouping (slots, arrays and key arena, bounded by
`maxGroupBytes` and `maxGroups`). A cursor keeps exactly the run struct between fetches, constant in the number of
rows read. `toArray` and each fetch add PHP rows, bounded by `$maxRows` and a budget check at most every 1 MiB of
estimated allocation. A `Table` holds its capacity, reported exactly by `memoryBytes()`, and is freed with its
object. Bailout (a fatal or timeout inside a run) is caught by `zend_catch`, which clears the stream's in-use
flag, frees the per-run buffers and re-bails; it never closes a stream, because that could run a wrapper callback
during a fatal.

## Complexity

n bytes of input, r rows, c file columns, g groups, k predicates.

- Scan: O(n) time. The structural masks are one pass over each 64-byte block; the row loop visits each set bit once.
  A row that runs off the buffer is rescanned from its start, but the reader waits until the buffered bytes at
  least double, so total rescan work is linear (6.2).
- Value work: only the columns a query references are decoded and validated; unreferenced columns are framed and
  counted only. A query costs O(r * (referenced columns + k)).
- Group-by: expected O(1) per matching row (open addressing, load at most 3/4, SipHash-1-3 per key), O(g) memory,
  and growth by doubling, so O(r + g) amortised. SipHash makes the expected bound hold against chosen keys.
- `Table`: load is O(r * c) with the `toArray` decoding path; a query is O(r * k); dictionary predicates compare
  `uint32` ids; a dictionary group-by uses arrays of dictionary size and costs O(r + dictionary size).
- Memory: O(B) for streaming without groups, O(B + group state) with groups, O(row capacity * column widths +
  arenas) for a `Table`.

**Popcount tail.** Past the last referenced column, the delimiters of a row are counted by popcount over the structural
bits up to the first LF, CR or quote byte, in one call of a per-ISA function. The row uses the count only when that byte
is an LF and the count equals the file's field count, so every error is reported by the per-bit walk, from the same
position, exactly as before. Plans with fewer than eight fields left run framing instantiations that contain none of this.

**Batched group probes.** When the single-key group table has outgrown the cache (16,384 slots), up to 16 matched rows
are queued with their keys hashed and their slots prefetched, then probed in row order. Order, limits and the first error
are those of one probe per row; a string key above 48 bytes is not queued.

## Formats

- **Structural masks:** one `uint64_t` per 64-byte block; bit i is set when byte i is the delimiter, LF, CR or `"`.
  One function per ISA (scalar, AVX2, NEON) computes it, and all produce identical masks. Every buffer fill is
  followed by 64 zero bytes so a block at the edge cannot create a false structural bit.
- **Group table:** 64-bit slots, `(tag << 32) | (group id + 1)`, `tag` the high 32 bits of the hash, the index the low
  bits; dense group ids in first-seen order index per-group arrays (hash, key or offset and length, count, sum).
- **`groupAgg` key:** per key position one tag byte (0 NULL, 1 int64, 2 string, 3 dictionary id), then nothing, 8
  bytes little-endian, a 4-byte length and the bytes, or a 4-byte id. The encoding is injective.
- **Column store:** see Table below.
- **Errors:** a `bf_error` struct (class, reason code, row, line, offset, column index, two numbers) crosses the
  core boundary; the glue turns it into the exact message of 5.11.

## Table

**Load.** `Table::fromCsv` and `Table::fromStream` run the toArray rows loop (`bf_run_rows`) unchanged, with an
`emit_row` that appends each decoded row to the column store (`bf_table_append`, `src/bf_table.c`). Every schema
column is therefore validated exactly as `toArray` validates it, with the same CSV row prefixes. A refused append
(`maxTableBytes`, `maxDictionarySize`, `memory_limit`) records its error at the committed row and pauses the loop;
the glue reads a pause with an error as a failed load and frees everything, so no partial table exists. No CSV scan
or rows loop body changed for the Table.

**Column store.** Per column: a validity byte per row, then `int64_t[]`, `double[]`, `uint8_t[]`, or for a plain
string an offset and a length into one append-only arena shared by all string columns. A dictionary column holds
`uint32_t` ids (0 for NULL); its distinct values live in a single-key group table (6.3) keyed with the table's own
SipHash key. Every growth checks the total capacity against `maxTableBytes`, then `mem_fits` on each array right
before its own realloc; `memoryBytes()` is that same total.

**Dictionary predicates before value predicates.** At the start of each table run (a cursor: its first fetch),
`whereEq`, `whereNe` and `whereIn` on a dictionary column are resolved to ids and moved out of the value predicate list;
a row is tested against the id predicates first, then against the remaining predicates by the shared `bf_match`. This
reordering cannot change a result or an error:

- Predicates are a conjunction without side effects, so any evaluation order selects the same rows.
- No predicate raises during a table run. Every value was validated when the table was loaded, and the literals were
  checked when the predicate was added (5.7, 5.11). The errors a table run can raise (sum overflow, `maxGroups`,
  `maxGroupBytes`, `maxRows`, memory, timeout) depend only on which rows match and in what order, and rows are always
  visited in table order.
- An id predicate decides exactly what the value predicate would: two values of one dictionary column are equal
  exactly when their ids are equal, NULL has id 0 and no value has id 0, and a literal that is absent from the
  dictionary gets the id `UINT32_MAX`, which no row holds (so `whereEq` matches nothing and `whereNe` matches every
  non-NULL row, as 6.6 requires). `whereIn` keeps the ids it finds, sorted, plus whether its list held `null`.

The fuzz harness checks this: each input is also loaded as a table (plain, every eligible column a dictionary, a mixed
choice, and the all-dictionary table under the identical-hash shim). The same query, with its mix of dictionary and
plain predicates, must give the streaming result whenever the load succeeds.

**Group-by on a table.** `groupCount` and `groupSum` keyed on a dictionary column use arrays indexed by id, sized
by the whole dictionary and checked before the scan (6.6). Any other key uses the run-local group table, as when
streaming. `groupAgg` always uses the run-local tuple table. A dictionary key enters the tuple as tag 3 and its 4-byte
id, and the result converts it back to the value. The table run uses its own copies of the tuple functions,
instantiated with a table flag. The CSV `bf_tuple_of` keeps its body. Its growth helpers are forced inline, because
GCC had inlined them while they had one caller.

**The preflight's try of a large block (6.4).** `bf_host_mem_retry` (`src/php_run.c`) runs only when the rule
refuses. It raises the heap limit by one chunk with `zend_set_memory_limit`, allocates and frees the block, and
restores the limit. Its guarantees:

- The limit is restored on every path. On return it is set back to `memory_limit`; `zend_set_memory_limit`
  recomputes the hard limit (8.3.36 and later) from the limit and `memory_hard_limit_percent`, so both are as before.
  The `memory_limit` INI value is never written. A bailout inside the try is caught (`zend_try`), the limit restored,
  and the bailout continues. No limit can bail out there (next point); on 8.3.36 and later an mmap failure exits the
  process (`zend_out_of_memory`), and before that it bailed out ("Out of memory"), which the catch covers. `128`
  checks after a fitting and a refused try that a 1.9 MB string ends in "Allowed memory size of L bytes", L the limit
  the script set, and that a 4 L string, past the default hard limit, ends in the same fatal as in a child that made
  no run. On the builds tested (8.3.21, 8.3.36, 8.6.0RC4) that fatal is the soft one even at 10 L, so the hard limit
  is not observable there; its restore rests on `zend_set_memory_limit` recomputing it.
- The try cannot fatal or queue a delayed OOM. Only a large block (3,073 bytes to a chunk minus a page) is tried,
  only when real memory is within the limit; such a block needs at most one fresh chunk, which the raised limit
  holds. Huge blocks are priced in their own chunks by the rule and never tried.
- After the try the heap holds no more real memory than before. The block is freed; a chunk it mapped is then empty
  and ZendMM either unmaps it or keeps it in its cache, where it still counts in `memory_get_usage(true)`. If real
  memory is above its value before the try, the limit is set to that value first, which unmaps cached chunks down
  to it, then to `memory_limit`. Over the local suite (8.3.21, 18,654 tries) 341 tries left a cached chunk, all
  refused ones, and every try ended at its starting real memory.
- Several arrays: each array is preflighted right before its own realloc, so a try sees the heap as that realloc
  will. A refusal at array 2 of 3 leaves array 1 reallocated, larger than `cap`, and arrays 2 and 3 as they were;
  each pointer is stored right after its realloc, `cap` and the row or group count are unchanged, so every array
  still holds its first `cap` entries. The run records `memory_limit` and stops; teardown frees each array once.
  `fuzz/test_table.c` T4 and `fuzz/test_groups.c` refuse each array in turn under ASan and LeakSanitizer.

**Timeout.** A table scan checks `EG(timed_out)` every 1,048,576 rows and once when it reaches the last row (6.4).
