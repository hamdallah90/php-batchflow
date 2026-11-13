# Changelog

All notable changes to BatchFlow are recorded here. Dates are UTC.

## 1.0.0 - 2026-11-13

First release.

### Features

- `BatchFlow\Csv::scan` and `Csv::scanStream`: lazy, immutable `Query` objects over a CSV file or PHP stream, with a
  typed schema (`int64`, `float64`, `bool`, `string`), RFC 4180 dialect rules, an optional `delimiter` (`,`, `;`, tab
  or `|`) and a leading UTF-8 BOM.
- Filters `whereEq`, `whereNe`, `whereGt`, `whereGte`, `whereLt`, `whereLte` and `whereIn`; terminals `count`, `sum`,
  `min`, `max`, `avg`, `aggregate`, `summary`, `groupCount`, `groupSum`, `groupAgg` (one to four keys, up to 16
  aggregates), `toArray` and `cursor()` (`Cursor::fetch`, `Cursor::close`).
- `BatchFlow\Table`: a preloaded typed column store with optional dictionary columns, the same query methods as a
  streaming `Query`, byte-identical results, and `rowCount`, `columnNames`, `schema` and `memoryBytes`.
- `Runtime::info()` and `php --ri batchflow` report the version and the compiled and selected SIMD function.
- Exceptions with exact messages that carry row, line, offset and column but never record bytes: `SchemaException`,
  `CsvException`, `LimitException`, `ResultTooLargeException`, `SumOverflowException`, `StreamException`, all
  extending `BatchFlowException`.
- Bounded memory: every growth is preflighted against `memory_limit`, so a limit is a `LimitException`, not a PHP
  fatal (best-effort on a fragmented heap, see `docs/THREAT_MODEL.md`). Limits `maxRowBytes`, `maxFieldBytes`,
  `maxInputRows`, `maxGroups`, `maxGroupBytes`, `maxTableBytes` and `maxDictionarySize`.
- Group keys are hashed with SipHash-1-3 under a per-run random key, so hostile keys cannot aim collisions.
- Structural scan with AVX2 (selected at run time on x86-64), NEON (arm64) and a scalar fallback, all
  byte-identical.
- Platforms: PHP 8.3, 8.4 and 8.5 NTS on Linux x86-64 and arm64; PHP 8.6 (pre-release) NTS, non-blocking
  until PHP 8.6.0 is released. macOS arm64 builds and runs but has no CI lane. ZTS is not supported.
- PIE metadata in `composer.json` (`php-ext`, NTS only, Linux and macOS). The source package
  (`git archive`) leaves out `tests/`, `fuzz/`, `benchmarks/` and `.github/`.

### Performance

Measured on one x86-64 machine with AVX2 (see `benchmarks/RESULTS.md`): group-by 3.7x to 13.6x the fastest JIT'd PHP loop and 2.6x to
5.5x single-threaded DuckDB; streaming count and sum 5.2x to 15.4x the PHP loop at 0.84 times its peak memory; `toArray` 2.8x to
7.5x the fastest PHP loader. Rows with many unreferenced trailing columns are framed by popcount, and group-by on tables of
16,384 or more slots probes in batches of up to 16 rows with the slots prefetched. No arm64 speed claim.

### Hardening

- CI (`.github/workflows/ci.yml`, actions pinned by commit SHA): PHPT on PHP 8.3, 8.4 and 8.5 on x86-64 and arm64;
  PHP 8.6 from source, non-blocking; ASan, UBSan and Zend-allocator PHPT runs with fuzz corpus replay on both
  architectures; a provisional ZTS lane that does not fail the workflow.
- A libFuzzer harness over the native core (all compiled mask functions, the reference parser, chunked reads,
  group table, column store), with a committed seed and regression corpus.
- `docs/THREAT_MODEL.md`, `docs/DESIGN.md`.

### Known limitations

See `docs/THREAT_MODEL.md`. In short: the memory preflight is best-effort for small blocks on a fragmented heap,
ZTS is not supported, and a stream's position after an exception is unspecified.
