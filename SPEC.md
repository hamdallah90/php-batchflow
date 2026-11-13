# BatchFlow — The Fastest Way to Process CSV Inside PHP

**Spec version:** 1.0  
**Extension/module:** `batchflow`  
**Repository:** `php-batchflow`  
**Namespace:** `BatchFlow`  
**Implementation:** C11 + PHP Zend extension API. No third-party library, no bundled engine, no threads.  
**Platforms:** PHP 8.3, 8.4, 8.5 and 8.6 NTS on Linux x86-64 and Linux arm64. ZTS is provisional and is not a support claim.

> Where this file gives an exact string, limit or order, it is the contract. A technical claim that proves wrong is corrected here in the same change as the code.

---

## 1. Product

**Pitch:** the fastest way to process CSV inside PHP, with no database and no extra service.

```php
use BatchFlow\Csv;

$schema = ['id' => 'int64', 'status' => 'string', 'amount' => 'float64', 'region' => 'string'];

// Streaming: one pass over the file, no table built.
foreach (Csv::scan('/data/orders.csv', $schema)
    ->whereEq('status', 'paid')
    ->whereGt('amount', 100.0)
    ->groupSum('region', 'amount') as [$region, $count, $sum]) {
    printf("%s %d %.2f\n", $region ?? '(none)', $count, $sum);
}
```

```php
use BatchFlow\Table;

// Preloaded: repeated queries over typed columns.
$orders = Table::fromCsv('/data/orders.csv', $schema, ['dictionary' => ['region']]);
$paid   = $orders->whereEq('status', 'paid');
$n      = $paid->count();
$byArea = $paid->groupSum('region', 'amount');
```

```php
// ETL: walk a large filtered file at constant memory, one batch of PHP rows at a time.
$cursor = Csv::scan('/data/orders-eu.csv', $schema, ['delimiter' => ';'])
    ->whereIn('region', ['north', 'south'])
    ->whereGte('amount', 100.0)
    ->cursor();
while ($rows = $cursor->fetch(1000)) {
    foreach ($rows as $row) {
        // $row['id'], $row['status'], $row['amount'], $row['region']
    }
}
```

Pure native, light, dependency-free. DuckDB is a benchmark comparison and a last-resort recommendation in the docs; it is never a dependency.

### Claims policy

| Allowed once measured on the release build (`benchmarks/RESULTS.md`) | Never |
| --- | --- |
| Faster than the best JIT'd PHP loop on named CSV workloads, stating CPU, ISA and whether the process was warm or fresh | Beating DuckDB, unless that exact comparison was measured; "faster than DuckDB" in general is never written |
| Faster than single-threaded DuckDB on named cells, if measured | Faster numeric scans than JIT'd PHP on preloaded numeric columns |
| Streaming memory near the PHP floor | Any ratio without publishing the scalar ratio and the fresh-process ratio next to it |

## 2. Measurements

Measured results, the method and every loss are in `benchmarks/RESULTS.md`. Claims follow the policy in section 1.

## 3. Users and workloads

1. **Application engineer:** revenue by region from a CSV export, inside a request or a job, with no database.
2. **ETL worker:** filter and aggregate millions of rows under a `memory_limit`.
3. **Backend developer:** load a file once, run several reports against it.

Non-goals for v0.1: a SQL engine, general PHP array replacement, joins, sorting, multi-core execution.

## 4. Scope

**Required in v0.1:** streaming scan with no table built; fused `whereEq`, `whereNe`, `whereGt`, `whereGte`, `whereLt`, `whereLte` and `whereIn`; `count`, `sum`, `min`, `max`, `avg`, `aggregate`, `summary`; group-by on string and int64 keys with dictionary encoding, on one key (`groupCount`, `groupSum`) or one to four keys with zero to sixteen aggregates (`groupAgg`); CSV to real PHP rows bounded by `maxRows`, or unbounded at constant native memory through a `Cursor`; a `delimiter` option limited to `,` `;` tab `|`; typed preloaded `Table` for repeated queries; SIMD structural scanning with runtime AVX2 selection, a scalar fallback, and NEON on arm64; no `-march=native`; no AVX-512 dependency.

**Excluded from v0.1, later roadmap only:** threads and any parallel execution; NULL tokens other than the empty unquoted field; blank-line skipping; non-UTF-8 encodings; joins, order-by, projection, OR, arbitrary callbacks, SQL; spilling to disk; Arrow; persistence; a `Table` that outlives a request; a validate-all-columns streaming mode.

**Roadmap after v0.1, not committed to a release:** native CSV output (`writeCsv`: filter CSV to CSV with no PHP rows built); `date` and `timestamp` column types; `min` and `max` on `string` columns; multi-core execution; OR predicates.

## 5. Public API and exact semantics

```php
namespace BatchFlow;

/**
 * @strict-properties
 * @not-serializable
 */
final class Csv {
    private function __construct() {}
    public static function scan(string $path, array $schema, array $options = []): Query {}
    /** @param resource $stream */
    public static function scanStream($stream, array $schema, array $options = []): Query {}
}

/**
 * @strict-properties
 * @not-serializable
 */
final class Table {
    private function __construct() {}
    public static function fromCsv(string $path, array $schema, array $options = []): Table {}
    /** @param resource $stream */
    public static function fromStream($stream, array $schema, array $options = []): Table {}
    public function rowCount(): int {}
    /** @return list<string> schema order */
    public function columnNames(): array {}
    /** @return array<string,string> name to type, schema order */
    public function schema(): array {}
    /** Native bytes held by this table, by capacity. */
    public function memoryBytes(): int {}
    public function query(): Query {}
    public function whereEq(string $column, int|float|bool|string|null $value): Query {}
    public function whereGt(string $column, int|float $value): Query {}
    public function whereNe(string $column, int|float|bool|string|null $value): Query {}
    public function whereLt(string $column, int|float $value): Query {}
    public function whereGte(string $column, int|float $value): Query {}
    public function whereLte(string $column, int|float $value): Query {}
    /** @param list<int|float|bool|string|null> $values */
    public function whereIn(string $column, array $values): Query {}
}

/**
 * @strict-properties
 * @not-serializable
 */
final class Query {
    private function __construct() {}
    public function whereEq(string $column, int|float|bool|string|null $value): Query {}
    public function whereGt(string $column, int|float $value): Query {}
    public function whereNe(string $column, int|float|bool|string|null $value): Query {}
    public function whereLt(string $column, int|float $value): Query {}
    public function whereGte(string $column, int|float $value): Query {}
    public function whereLte(string $column, int|float $value): Query {}
    /** @param list<int|float|bool|string|null> $values */
    public function whereIn(string $column, array $values): Query {}
    public function count(): int {}
    public function sum(string $column): int|float {}
    public function min(string $column): int|float|null {}
    public function max(string $column): int|float|null {}
    public function avg(string $column): ?float {}
    /**
     * @param list<array{0:string,1:string}> $aggregates [op, column]
     * @return list<int|float|null> one result per pair, in order
     */
    public function aggregate(array $aggregates): array {}
    public function summary(?string $sumColumn = null): Summary {}
    /** @return list<array{0:string|int|null,1:int}> [key, count] */
    public function groupCount(string $key, int $maxGroups = 1000000): array {}
    /** @return list<array{0:string|int|null,1:int,2:int|float}> [key, count, sum] */
    public function groupSum(string $key, string $sum, int $maxGroups = 1000000): array {}
    /**
     * @param string|list<string> $keys
     * @param list<array{0:string,1:string}> $aggregates [op, column], 0 to 16 pairs; [] gives [key1, ..., keyN, count]
     * @return list<list<string|int|float|null>> [key1, ..., keyN, count, agg1, ..., aggM]
     */
    public function groupAgg(string|array $keys, array $aggregates, int $maxGroups = 1000000): array {}
    /** @return list<array<string|int,int|float|bool|string|null>> */
    public function toArray(int $maxRows = 10000): array {}
    public function cursor(): Cursor {}
}

/**
 * @strict-properties
 * @not-serializable
 */
final class Cursor {
    private function __construct() {}
    /** @return list<array<string|int,int|float|bool|string|null>> */
    public function fetch(int $maxRows = 1000): array {}
    public function close(): void {}
}

/** @strict-properties */
final class Summary {
    public readonly int $rowsScanned;
    public readonly int $rowsMatched;
    public readonly int|float|null $sum;
    private function __construct() {}
}

/**
 * @strict-properties
 * @not-serializable
 */
final class Runtime {
    private function __construct() {}
    /** @return array{version:string,simd:array{compiled:list<string>,auto:string}} */
    public static function info(): array {}
}

class BatchFlowException extends \RuntimeException {}
class SchemaException extends BatchFlowException {}
class CsvException extends BatchFlowException {}
class LimitException extends BatchFlowException {}
class ResultTooLargeException extends LimitException {}
class SumOverflowException extends BatchFlowException {}
class StreamException extends BatchFlowException {}
```

Each `@tag` sits on its own docblock line, or the stub generator silently drops the second one. Arginfo is generated from the stub by PHP 8.3's `gen_stub.php` and committed. `stream` parameters are untyped in the stub and validated with `php_stream_from_zval` semantics.

### 5.1 Source objects and laziness

- `Csv::scan` and `Csv::scanStream` validate schema and options and return a `Query`. They perform no I/O: the file need not exist yet.
- Every terminal (`count`, `sum`, `min`, `max`, `avg`, `aggregate`, `summary`, `groupCount`, `groupSum`, `groupAgg`, `toArray`) opens or reads the source again. There is no result cache. `cursor()` is not a terminal: it performs no I/O, and the cursor's first `fetch` opens or reads the source as a terminal would (5.13).
- A path source reopens the path on each terminal.
- A stream source reads from the stream's current position to EOF on each terminal. The extension never seeks. A second terminal on a consumed stream therefore raises `CsvException` with reason `missing header`, unless the caller rewound the stream.
- `Table::fromCsv` and `Table::fromStream` read the whole source eagerly and produce a complete table or an exception. No partial table exists.
- `where*` returns a new `Query` and leaves the receiver unchanged.

### 5.2 Schema

`$schema` is a non-empty map of column name to type, at most 1024 entries.

- Types are exactly the lowercase strings `int64`, `float64`, `bool`, `string`. A value that is not a string is `unknown type`. Every column is nullable; there is no `?` syntax.
- Integer array keys are converted to their decimal string, because PHP normalizes canonical decimal strings to integers. `['2024' => 'float64']` means the header `2024`.
- A name is valid UTF-8 of 1 to 1024 bytes.
- Schema order is the order of `toArray` keys, of `columnNames()`, and of per-row value validation.

### 5.3 Options

| Key | Applies to | Type | Default | Range |
| --- | --- | --- | --- | --- |
| `allowExtraColumns` | all | bool | `false` | |
| `maxFieldBytes` | all | int | 1,048,576 | 1 to 67,108,864 |
| `maxRowBytes` | all | int | 16,777,216 | 1 to 268,435,456 |
| `maxInputRows` | all | int | 100,000,000 | 1 to `PHP_INT_MAX` |
| `maxGroupBytes` | all | int | 268,435,456 | 1,048,576 to 1,099,511,627,776 |
| `simd` | all | string | `'auto'` | `auto`, `scalar`, `avx2`, `neon` |
| `delimiter` | all | string | `','` | exactly one of `","`, `";"`, `"\t"` (byte 0x09), `"\|"` |
| `dictionary` | `Table` only | list of column names | `[]` | each a schema column of type `string` or `int64`, no repeats |
| `maxDictionarySize` | `Table` only | int | 16,777,216 | 1 to 4,294,967,294 |
| `maxTableBytes` | `Table` only | int | 4,294,967,296 | 1,048,576 to 1,099,511,627,776 |

Option values are checked with no coercion: `'1'`, `1.0` and `true` are not ints. An unknown key, including a `Table`-only key passed to `Csv::scan*`, raises `SchemaException`. `simd => 'avx2'` on a CPU without AVX2, or `neon` off arm64, raises `SchemaException`; `auto` never fails.

`maxRowBytes` may be smaller than `maxFieldBytes`; the smaller effective limit wins for any single field.

`maxInputRows` counts data rows and excludes the header. `maxDictionarySize` counts distinct non-NULL values; unlike `maxGroups`, NULL does not count. `simd` and `delimiter` affect CSV reading only, so on a `Table` they affect loading only. `delimiter` is compared as exact bytes: a non-string value is `option "delimiter": wrong type`; any other string, including `", "`, `"\x00"`, `'\t'` (two bytes) and `""`, is `option "delimiter": out of range`. `maxGroupBytes` passed to `Table::from*` is stored and bounds each later group-by on that table, both the run-local group table and the dictionary `count`, `sum` and `order` arrays. Dictionary construction during load counts only against `maxTableBytes`.

### 5.4 CSV dialect

Input is a byte stream of RFC-4180 CSV with these exact rules.

- Delimiter: the byte of the `delimiter` option, `,` by default. Everywhere in this specification "delimiter" means that configured byte, including the leftmost-byte positions and header checks below. The other three allowed delimiter bytes are ordinary data in that run: with `;` a comma is data, unquoted or quoted. Quote `"`. A quote inside a quoted field is written `""`.
- The value grammars of 5.6 do not depend on the delimiter: in a `;` file, `1,5` in a `float64` column is `malformed value`, never 1.5. With tab as delimiter, a space is still data and still invalid in a numeric field.
- A record ends at LF or CRLF, freely mixed within a file. The last record may have no terminator.
- CR outside quotes that is not immediately followed by LF is an error, including a lone CR as the final byte of the input.
- Inside quotes every byte is data, including delimiter, CR and LF. Newlines inside quoted fields are preserved byte for byte, never normalized.
- A quote may open a field only as its first byte. A quote elsewhere in an unquoted field is an error. After a closing quote the next byte must be delimiter, CR, LF, or end of input.
- No trimming: a space is data. In a numeric field it makes the value invalid.
- The file ends after the last record. A terminator at end of input does not create another record. A blank line is a record of one empty field: in a one-column file it is a row holding NULL, otherwise it fails the field count check.
- A single UTF-8 BOM (`EF BB BF`) at byte 0 of the input is skipped, once. It counts toward offsets. The reader fills until it has 3 bytes or EOF before testing, so a short first read cannot hide it. For a stream source, byte 0 means the stream's position when the run starts.
- NUL bytes are data. They are valid in a `string` value and invalid in any other type.

**Header.** The first record is the header and is required.

- Zero data rows is valid and gives `0`, `0`/`0.0`, `[]`. Zero bytes (after any BOM) is an error: `missing header`.
- Names are matched as exact bytes after quote removal, doubled quotes included. Case-sensitive.
- Column order in the file is free.
- `maxFieldBytes` does not apply to header fields; `maxRowBytes` does. A leading BOM is not part of the header's row length.
- Header checks, first failure reported: (1) framing and row size by the leftmost-byte rule below, where a 4097th field gives `too many columns (limit 4096)` at the delimiter opening it; (2) each field left to right: a schema name seen before is `duplicate column`; a field matching no schema name is `unexpected column` unless `allowExtraColumns` is true; (3) each schema column in schema order must be present, else `missing column`.
- With `allowExtraColumns`, non-schema columns get framing checks only, may repeat, may be empty-named, and never appear in any result.

**Row order of checks**, first failure reported: (1) framing, row size and field count, where the leftmost failing byte decides; (2) `maxInputRows`, raised on row limit+1 after that row's framing; (3) each referenced column in schema order: field size, then value.

Leftmost-byte positions: a quote or CR error at its own byte; `expected N fields, got more` at the delimiter opening field N+1; `expected N fields, got M` (M < N) at the row's terminator or end of input; `row exceeds maxRowBytes` at the first byte past the limit that is not the terminator. When a framing error falls on that same byte, the framing error is reported.

### 5.5 Which columns are validated

**Referenced columns** are those named by a predicate of the query, the sum, an aggregate (`min`, `max`, `avg`, or any pair of `aggregate` or `groupAgg`, `count` pairs included), or a group key. On every row, whether or not the row matches, a referenced column is fully validated.

| Check | Referenced | Unreferenced schema columns and extra columns |
| --- | --- | --- |
| Quote state, line structure, exact field count | yes | yes |
| `maxRowBytes` | yes | yes |
| `maxFieldBytes` | yes | no |
| Type grammar, overflow, non-finite | yes | no |
| UTF-8 | yes (`string` columns) | no |

`toArray` and `Cursor::fetch` reference every schema column. `Table` loading references every schema column. A `count()` with no predicate references none and still frames every row. Consequently a malformed value in an unreferenced column raises nothing on a streaming query but does raise on `toArray` and on `Table` load. This is documented behaviour and is what keeps streaming fast.

### 5.6 Value rules

**NULL.** An unquoted empty field is NULL for every type. A quoted empty field `""` is the empty string in a `string` column and invalid in every other type. There is no other NULL token.

**Quotes around non-strings.** `"5"`, `"1.5"`, `"true"` are accepted in their columns. A field that contains a doubled quote is invalid in `int64`, `float64` and `bool`.

| Type | Accepted grammar (the whole field) | Rejected |
| --- | --- | --- |
| `int64` | `-?(0\|[1-9][0-9]*)` within int64 range | `+5`, `007`, `-0`, `1.0`, `1e3`, spaces, out of range |
| `float64` | `[+-]?([0-9]+(\.[0-9]*)?\|\.[0-9]+)([eE][+-]?[0-9]+)?`; a finite result | `nan`, `inf`, hex, spaces, a result that overflows to infinity |
| `bool` | exactly `true` or `false` | `TRUE`, `1`, `0`, `t`, `yes` |
| `string` | valid UTF-8 after quote removal | overlong forms, surrogates, above U+10FFFF, truncated sequences |

Float values are correctly rounded to nearest binary64. Fast path: Clinger, when the significand fits 2^53 and the decimal exponent is within 22. Otherwise `zend_strtod`. Underflow to zero (`1e-400`, keeping the sign) is accepted. A value that overflows to infinity (`1e400`) fails as `out-of-range number`. A field of 19,683 bytes or more whose exponent magnitude exceeds 19999 also fails as `out-of-range number`, because `zend_strtod` clamps such an exponent and its result could be wrong.

`maxFieldBytes` counts bytes after quote removal.

### 5.7 Predicates

At most 64 predicates, AND only, the same column may repeat. Each `where*` call adds exactly one predicate, `whereIn` included, whatever its list length. They run in declaration order with short-circuit. Because all referenced columns are validated before predicates run, predicate order cannot change which error is raised.

`whereEq` and `whereNe` are equality predicates; `whereGt`, `whereGte`, `whereLt` and `whereLte` are range predicates meaning `>`, `>=`, `<` and `<=`; `whereIn` is set membership.

| Column type | `whereEq`, `whereNe` literal; each `whereIn` element | `whereGt`, `whereGte`, `whereLt`, `whereLte` literal |
| --- | --- | --- |
| `int64` | int or null | int |
| `float64` | finite float, int exactly representable as binary64, or null | finite float, int exactly representable as binary64 |
| `bool` | bool or null | not allowed |
| `string` | valid UTF-8 string up to 1,048,576 bytes, or null | not allowed |

- No coercion, regardless of `strict_types`. Parse the literal with `Z_PARAM_ZVAL` and dispatch by type. A value of a type outside the declared union raises `TypeError` with PHP's standard argument wording.
- `null` means IS NULL in `whereEq` and IS NOT NULL in `whereNe`. A NULL row never matches a non-null `whereEq` or `whereNe` literal, never matches a range predicate, and matches `whereIn` only when its list holds `null`. Example, a column `a` holding `5`, NULL and `7` in three rows: `whereEq('a', 5)` matches the `5` row; `whereNe('a', 5)` matches the `7` row only, not the NULL row; `whereNe('a', null)` matches `5` and `7`; `whereLt('a', 6)` matches `5`; `whereGte('a', 5)` matches `5` and `7`; `whereIn('a', [7, null])` matches `7` and NULL.
- A float literal on an `int64` column is rejected even if integral.
- An int literal for a `float64` column must round-trip: `d = (double)v`, reject if `d >= 2^63` or `(int64_t)d != v`.
- Range predicates compare integers on `int64` and IEEE doubles on `float64`.
- Float comparison is IEEE: `-0.0` equals `0.0`. So `whereNe('x', 0.0)` does not match a `-0.0` row, `whereGte('x', 0.0)` and `whereLte('x', 0.0)` match it, and `whereLt('x', 0.0)` does not.
- String equality is exact bytes after quote removal.
- A range predicate on a `bool` or `string` column raises `<method> requires a numeric column` with its own method name (`whereGt`, `whereGte`, `whereLt`, `whereLte`), whatever the literal.
- Unknown columns and mismatches raise at the `where*` call, before any I/O. The `Table` shortcuts check exactly as `Query`'s methods, and their `TypeError` and `ValueError` messages name the method called, such as `BatchFlow\Table::whereGt()`.

**`whereIn`.** `$values` is a list of 1 to 1024 elements. Each element obeys the `whereEq` literal rule for the column: `null` matches NULL rows, any other element matches as `whereEq` with that element would. A row matches when it matches any element. Duplicates are allowed and collapse: `[1, 1]` behaves as `[1]`, and on `float64` `[0.0, -0.0]` is one value; a duplicate is never an error and never changes a result. Checks, first failure reported:

1. Shape, as `ValueError`, after parameter parsing: an empty array is `zend_argument_value_error(2, "must not be empty")`; an array that is not a list (`zend_array_is_list` false) is `zend_argument_value_error(2, "must be a list")`; more than 1024 elements is `zend_argument_value_error(2, "must contain at most 1024 values")`.
2. Each element in list order whose type is array, object or resource: `zend_argument_type_error(2, "element I must be of type string|int|float|bool|null, %s given")` with `zend_zval_value_name` of the element, the wording `whereEq` uses for its own literal.
3. Predicate count, then unknown column, as for every `where*`.
4. Each element in list order against the column by the `whereEq` literal rules, as `SchemaException` `column "<name>": whereIn element I: <reason>`, where `<reason>` is the reason `whereEq` would raise for that element without its `column "<name>": ` prefix. Example: `whereIn('n', [1, '2'])` on an `int64` column raises `column "n": whereIn element 1: value of type string cannot be compared with int64 column`.
5. Memory: string elements are owned copies (5.12). The sum of the byte lengths of the distinct string elements is checked with `mem_fits` (6.4) before copying; refused, it raises `LimitException` `memory_limit would be exceeded`, with no prefix.

`I` is the element's 0-based list index. Distinct elements are held sorted (`int64` numerically, `float64` in IEEE order with `-0.0` stored as `0.0`, strings by length and then by bytes, so a probe of another length is decided without comparing bytes) and a row tests membership by binary search, so no hash key is involved and results cannot depend on one.

### 5.8 Aggregates

- `count()` is rows passing all predicates (COUNT(*)).
- `sum(column)` requires `int64` or `float64`. NULL is ignored. No matching non-null value gives `0` or `0.0` by column type.
- `int64` sums use checked addition; overflow raises `SumOverflowException` at the row where it happens. Sums never wrap.
- `float64` sums are IEEE doubles added in row order from `+0.0`, with no compensated summation. A non-finite final sum raises `SumOverflowException`; the check runs once after the scan, not per row.
- `summary(?string $sumColumn)` returns all counts from one pass, so a streaming caller does not read the file twice. `rowsScanned` is data rows examined (`rowCount` for a table). `rowsMatched` equals `count()`. `sum` is `null` when no column was requested. `Summary` exists only after success.
- `min(column)` and `max(column)` require `int64` or `float64`, else `column "<name>": min requires a numeric column` (`max` likewise). NULL is ignored. The result is an int on an `int64` column and a float on a `float64` column; no matching non-null value gives `null`. Float values compare by IEEE; among equal values the first seen in row order is kept, so over `0.0` then `-0.0` both `min` and `max` return `0.0`, and over `-0.0` then `0.0` both return `-0.0`.
- `avg(column)` requires `int64` or `float64`, else `column "<name>": avg requires a numeric column`. It is the column's sum under exactly the `sum` rules, overflow included (an `int64` overflow raises at its row, a non-finite `float64` sum raises after the scan, both with the `sum` reasons), divided by the count `n` of matching non-null values, computed in C as `(double)sum / (double)n`. An `int64` sum above 2^53 is therefore rounded once to binary64 before the division. No matching non-null value gives `null`. A `float64` column whose sum overflows raises even when the mean would be finite.
- `aggregate(array $aggregates)` computes several aggregates in one pass and returns a packed list of their results in the order of `$aggregates`. `$aggregates` is a list of 1 to 16 pairs `[op, column]`; `op` is exactly one of `count`, `sum`, `min`, `max`, `avg` (lowercase). `count` is the number of matching rows whose value in that column is not NULL, accepts every column type, and gives an int; `sum`, `min`, `max` and `avg` follow the rules above and give the same results the single methods give. Pairs may repeat; each repeat yields its own result. With no matching row the results are `0`, `0` or `0.0`, `null`, `null`, `null` by op.
- Overflow among several aggregates: within a row, aggregates update in list order, so an `int64` overflow is reported for the first pair in list order that overflows on the earliest such row. Non-finite `float64` sums are checked after the scan in list order; the first failing pair is reported.
- `aggregate` checks, first failure reported: (1) shape, as `ValueError`: an empty array `zend_argument_value_error(1, "must not be empty")`; not a list `zend_argument_value_error(1, "must be a list")`; more than 16 pairs `zend_argument_value_error(1, "must contain at most 16 aggregates")`; then each pair in list order: anything but a list of exactly two strings `zend_argument_value_error(1, "element I must be a list of two strings")`, an `op` outside the five names `zend_argument_value_error(1, "element I: aggregate must be one of count, sum, min, max, avg")`; (2) each pair's column in list order: `unknown column "<name>"`, then for `sum`, `min`, `max` and `avg` on a non-numeric column `column "<name>": <op> requires a numeric column`. `I` is the pair's 0-based list index. All checks run before any I/O.

### 5.9 Group-by

`groupCount` and `groupSum` take a key column of type `string` or `int64`; anything else raises `SchemaException`.

- A group is one distinct key among **rows that passed the predicates**.
- **Order is first-seen order among those rows**, on every path, including when the key is dictionary-encoded and regardless of the hash key. The result is a deterministic function of the input, never of the hash seed or ISA.
- **NULL keys form one group**, in the position its first row was seen. Its key is PHP `null`. NULL and empty string are different groups.
- String keys compare by exact bytes after quote removal. `"abc"` and `abc` are the same group; `123` and `0123` are different groups. Keys stay PHP strings; a numeric-looking key is never converted.
- Int keys compare numerically and are PHP ints.
- `count` is rows in the group, not non-null values. A group whose sums are all NULL sums to `0` or `0.0`.
- Sum overflow is per group, and is `SumOverflowException` at the offending row. A non-finite float group sum is checked in one pass over groups after the scan.
- No matching row gives `[]`.
- Each element is a packed PHP list `[key, count]` or `[key, count, sum]`, chosen because that shape is what was measured and because `foreach (... as [$k, $c, $s])` reads well. A PHP map keyed by group value is impossible: it cannot hold NULL and would conflate `'123'` with `123`.

**Cardinality limit.** `maxGroups` is an int from 1 to 100,000,000, else `ValueError`. The NULL group counts. On the first distinct key that would make `maxGroups + 1` groups, the run stops and raises `ResultTooLargeException`. No partial result is returned, nothing is truncated, nothing spills. Native group state is further limited by `maxGroupBytes`, counted by capacity over slot table, per-group arrays and key arena. On a group-by over a dictionary column, `maxGroups` applies to groups actually produced.

**`groupAgg`.** `groupAgg(string|array $keys, array $aggregates, int $maxGroups = 1000000)` groups by one to four key columns and computes the aggregates of 5.8 per group. Every rule of this section above applies, with a group being one distinct tuple of key values:

- `$keys` is one column name, or a list of 1 to 4 names. Each key column is `string` or `int64`; no name may appear twice.
- `$aggregates` is a list of 0 to 16 pairs as for `aggregate` (5.8). An empty list is valid: each result row is then `[key1, ..., keyN, rowCount]`, the multi-key group count. `aggregate` itself still requires 1 to 16 pairs.
- Each key position follows the single-key rules: string keys compare by exact bytes, int keys numerically; NULL equals NULL and differs from every non-NULL value in the same position, so NULL and the empty string are different in every position. `(NULL, 'a')` twice is one group; `(NULL, 'a')` and `('', 'a')` are two.
- Each result row is a packed list `[key1, ..., keyN, rowCount, agg1, ..., aggM]`: the key values in `$keys` order (NULL as PHP `null`), the number of matching rows in the group, then one result per pair of `$aggregates` in order, with the per-group values `aggregate` would give over that group's rows. A group whose values for an aggregate are all NULL gives `0` for `count`, `0` or `0.0` for `sum`, and `null` for `min`, `max` and `avg`.
- Order is first-seen order of the tuple among matching rows. `maxGroups` (the group of the all-NULL tuple counts), its `ValueError`, `ResultTooLargeException` and `maxGroupBytes` apply exactly as for `groupCount`, over the per-group state of 6.3; the result preflight uses the `groupAgg` estimate of 6.3. Sum overflow is per group and per pair, at the offending row; non-finite `float64` sums are checked after the scan, groups in result order and, within a group, pairs in list order; the first failure is reported.
- With one key, `groupAgg('k', [])` returns exactly what `groupCount('k')` returns, `groupAgg('k', [['sum', 'v']])` returns exactly what `groupSum('k', 'v')` returns, and `groupAgg('k', [['count', 'k']])` returns `[key, count, c]` where `c` is `count` for every group except the NULL group, whose `c` is `0`.
- `groupCount` and `groupSum` are unchanged and keep their own single-key fast path (6.3); `groupAgg` never replaces them.

`groupAgg` checks, first failure reported: (1) `$keys` as an array, as `ValueError`: empty `zend_argument_value_error(1, "must not be empty")`; not a list `zend_argument_value_error(1, "must be a list")`; more than four `zend_argument_value_error(1, "must contain at most 4 key columns")`; then element by element in list order, a non-string element `zend_argument_value_error(1, "element I must be a string")`, then a name equal in bytes to an earlier one `zend_argument_value_error(1, "must not contain a column twice")`; (2) `$aggregates` exactly as `aggregate`'s shape checks, as argument 2, except that an empty array is valid and raises nothing (there is no `must not be empty` for this argument); (3) `maxGroups` range, argument 3; (4) each key column in order: `unknown column "<name>"`, then `column "<name>": group key must be string or int64`; (5) each pair's column in list order as for `aggregate`. All checks run before any I/O.

### 5.10 toArray

Returns a list of associative rows in input order. Each row has every schema column in schema order; extra columns are absent. Values are `null`, int, float, bool or string. Integer-like column names use PHP symtable semantics, so `$row[2024]` and `$row['2024']` both work.

`$maxRows` is an int from 1 to 100,000,000, else `ValueError`. When the matching-row count would exceed `$maxRows`, the run stops at that row and raises `ResultTooLargeException`. Nothing partial is returned. Rows after that point are not read. A caller that needs more matching rows than it can hold at once uses a cursor (5.13).

### 5.11 Errors

Class by cause: `TypeError` and `ValueError` for bad PHP arguments; `SchemaException` for schema, options, column references and predicate literals; `CsvException` for malformed input; `LimitException` for a configured limit or memory preflight; `ResultTooLargeException` for `maxRows` and `maxGroups`; `SumOverflowException`; `StreamException` for I/O. An exception raised by a PHP callback during a read (wrapper, filter, error handler) propagates unchanged, never wrapped.

**Argument failure order.** Parameter parsing and `ValueError` first, in argument order; then schema (empty, count, entries in array order, name before type); then options in array order. For `where*`: predicate count, unknown column, literal type against column type, literal value. `whereIn`, `aggregate` and `groupAgg` follow the complete orders given in 5.7, 5.8 and 5.9. Terminals check their columns before any I/O. After parameter parsing and `ValueError`, column and literal checks follow argument order: every check on an earlier argument comes before any check on a later one, so `groupSum` with a bad key column and a bad sum column reports the key column, and every `where*`, `min`, `max`, `avg`, `aggregate` and `groupAgg` call obeys the same rule within its own order.

`ValueError` uses `zend_argument_value_error(n, "must be between 1 and 100000000")` for `maxGroups` and `maxRows`, `zend_argument_value_error(1, "must be between 1 and 1000000")` for `Cursor::fetch`'s `$maxRows`, the array-shape messages of 5.7, 5.8 and 5.9, and `zend_argument_value_error(1, "must not be empty")` for an empty `$path`. A path containing NUL raises `ValueError` through `Z_PARAM_PATH_STR`.

**Record bytes never appear in a message.** Only schema names and numbers do. `<name>` is written through one escape: bytes outside 0x20 to 0x7E, plus `"` and `\`, become `\xHH` with uppercase hex. Output stops after 64 source bytes, and a trailing `...` appears only when bytes were cut.

**Prefix.** A reason carries a prefix only when a record is current. `SchemaException`, `StreamException`, the timeout reason and the two cursor-state reasons never carry one; a `LimitException` from `whereIn`'s literal preflight carries none either. `LimitException`, `ResultTooLargeException` and `int64` overflow carry the row or header prefix when a record is current and none after the scan (the group-result preflight). A `memory_limit` refusal while reading a record uses that record's context, so a header too large to buffer is `header (line 1, offset 0): memory_limit would be exceeded`. Data-row errors from a CSV source, `Table` loading included, start with `row R (line L, offset O): `. `R` is 1-based and excludes the header. `L` is the 1-based physical line of the row's first byte, counting each LF once, including LFs inside quoted fields. `O` is the byte offset of the row's first byte from the start of this run's reading. Header errors start with `header (line 1, offset O): `. Errors during a `Table` query have the prefix `row R: `, where `R` is the 1-based index of the table row, whatever the predicates; a cursor's row-budget refusal (6.4) on a table names a row of the table without fixing which one.

Reasons, exactly:

| Class | Reason |
| --- | --- |
| `CsvException` | `missing header` · `too many columns (limit 4096)` · `duplicate column "<name>"` · `unexpected column at position P` (1-based) · `missing column "<name>"` |
| `CsvException` | `bare quote in unquoted field` · `unterminated quoted field` · `text after closing quote` · `bare CR` · `expected N fields, got M` · `expected N fields, got more` |
| `CsvException` | `column "<name>": expected <type>, got <kind>`, where `<kind>` is `malformed value`, `quoted empty field` or `out-of-range number` · `column "<name>": invalid UTF-8` |
| `LimitException` | `row exceeds maxRowBytes` · `column "<name>": field exceeds maxFieldBytes` · `input exceeds maxInputRows` · `table exceeds maxTableBytes` · `dictionary for column "<name>" exceeds maxDictionarySize` · `group state exceeds maxGroupBytes` · `memory_limit would be exceeded` |
| `ResultTooLargeException` | `result exceeds maxRows (N)` · `result exceeds maxGroups (N)` |
| `SumOverflowException` | `int64 sum overflow in column "<name>"` · `float64 sum of column "<name>" is not finite` (no row prefix) |
| `StreamException` | `cannot open input` · `reading the input stream failed` · `input stream returned no data before EOF` · `a stream was closed during processing` · `a stream was closed before the run started` · `a stream is already in use by another BatchFlow run or cannot be closed from PHP` |
| `BatchFlowException` | `stopped by execution timeout` · `cursor is closed` · `a fetch on this cursor is already running` |

`SchemaException` reasons have no prefix: `schema must not be empty` · `too many columns (limit 1024)` · `column "<name>": unknown type` · `column "<name>": invalid name` · `option "<name>": unknown option` · `option "<name>": out of range` · `option "<name>": wrong type` · `option "simd": <value> is not available on this CPU` (where `<value>` is one of the four allowed names) · `unknown column "<name>"` · `too many predicates (limit 64)` · `column "<name>": value of type <php type> cannot be compared with <type> column` · `column "<name>": whereGt requires a numeric column` · `column "<name>": whereGte requires a numeric column` · `column "<name>": whereLt requires a numeric column` · `column "<name>": whereLte requires a numeric column` · `column "<name>": whereIn element I: <reason>` (5.7) · `column "<name>": integer literal is not exactly representable as float64` · `column "<name>": float literal must be finite` · `column "<name>": string literal is not valid UTF-8` · `column "<name>": string literal exceeds 1048576 bytes` · `column "<name>": sum requires a numeric column` · `column "<name>": min requires a numeric column` · `column "<name>": max requires a numeric column` · `column "<name>": avg requires a numeric column` · `column "<name>": group key must be string or int64` · `option "dictionary": column "<name>" must be string or int64`.

For `dictionary`, elements are checked in array order: a non-list value or non-string element is `option "dictionary": wrong type`, a repeated name is `option "dictionary": out of range`, and a name absent from the schema is `unknown column "<name>"`. An unrecognized `simd` string is `option "simd": out of range`; a `delimiter` outside its four values is `option "delimiter": out of range` and a non-string one `option "delimiter": wrong type`. `<php type>` comes from `zend_zval_type_name` and is one of `int`, `float`, `bool`, `string`.

### 5.12 Object lifetime, clone, serialization

- `Table`, `Query`, `Cursor`, `Csv` and `Runtime` have `clone_obj = NULL` and `ZEND_ACC_NOT_SERIALIZABLE`. `Summary` holds no native state and allows clone and serialization.
- `Table`, `Query` and `Cursor` have `create_object` and `free_obj`. A `Cursor` holds a strong reference to the `Query` it came from, and so to that query's `Table` or stream; nothing references a `Cursor` natively, so no cycle passes through it either. Being final internal classes with a custom `create_object`, reflection's `newInstanceWithoutConstructor` is refused by PHP. `free_obj` handles a partially built object.
- A `Query` from a table holds a strong reference to its `Table` object: `Table::fromCsv(...)->whereEq(...)` keeps the table alive after the temporary is dropped. A `Query` from a stream holds a strong reference to the stream resource. No `get_gc` is needed: a `Table` references nothing, and resources cannot be cycle roots, so no object cycle can pass through these edges.
- Query predicates are a copied vector, immutable after construction. String literals are owned copies, never borrowed from PHP arrays. A `whereIn` set is built once at the call and shared by reference count with every query derived from it, like a single string literal.
- `Table` is immutable after construction and holds no per-run state, so nested and fiber-interleaved queries on one table are safe. Terminal scratch arrays live in the run, not on the table.
- No per-request global state and no module globals. The only module-level write is the chosen SIMD function pointer, set once in `MINIT` and read-only afterwards. Per-run state lives in one heap struct, owned by the terminal call, or by the `Cursor` for a cursor run (6.8). Nothing persists across requests; there is no `pemalloc`.

### 5.13 Cursor

`Query::cursor()` returns a new `Cursor` over that query's rows. It performs no I/O and runs no checks: like `toArray`, a cursor references every schema column, so there is no column to reject. It works on a streaming query and on a `Table` query alike.

**`fetch(int $maxRows = 1000): array`** returns the next batch of matching rows, in the shape and order of `toArray` (5.10), at most `$maxRows` of them. `$maxRows` is an int from 1 to 1,000,000, else `ValueError` (5.11), raised before any state check. A fetch returns fewer than `$maxRows` rows only when it reached end of input. After the `$maxRows`-th matching row it stops without framing any later row, so an error in a later row is raised by a later fetch. A fetch never raises `ResultTooLargeException`.

**States.** A cursor is *new* until its first fetch, *open* while its run holds the source, *exhausted* after end of input, and *closed* after `close()` or a failed fetch.

| State | `fetch` | `close()` |
| --- | --- | --- |
| new | opens or reads the source as a terminal would (5.1); becomes open, or exhausted if it reached end of input | becomes closed, no I/O |
| open | continues the run | releases the run (below), becomes closed |
| exhausted | returns `[]`, no I/O | becomes closed |
| closed | `BatchFlowException` `cursor is closed` | no effect |

The fetch that reaches end of input releases the run before it returns its rows, which may be a non-empty final batch, and leaves the cursor exhausted; if that release raises, the fetch has failed (below). `fetch` and `close()` called while a fetch on the same cursor is running, from a stream callback or from another fiber, raise `BatchFlowException` `a fetch on this cursor is already running` and leave the running fetch and the cursor unchanged. Order for `fetch`: `$maxRows` range, then the running check, then the state.

**Continuity.** One cursor is one run: `maxInputRows` counts data rows across all fetches; the BOM is tested once, at the start of the first fetch; and `R`, `L` and `O` in error prefixes continue across fetches, `O` counting from the source position at the first fetch.

**Failure.** If a row fails, or a stream, memory or timeout error occurs, that fetch raises the exception a terminal would raise and returns nothing; rows returned by earlier fetches stay delivered. The run is released and the cursor is closed, so every later `fetch` raises `cursor is closed`. A `LimitException` `memory_limit would be exceeded` from the row budget (6.4) is such a failure; a retry with a smaller `$maxRows` needs a new cursor.

**Releasing the run** clears the stream's in-use flag, frees the reader state, and closes a path-opened stream exactly as a terminal does on a non-bailout exit (6.5): a wrapper's `stream_close` runs, and an exception it throws propagates unchanged from that `fetch` or `close()`, discarding that fetch's rows. A caller's stream is never closed.

**Streams in use.** For a stream source, the stream carries the in-use flag of 6.5 from the start of the first fetch until the run is released: by the fetch that reaches end of input, by a failed fetch, by `close()`, or by freeing the cursor. Meanwhile, between fetches as well as during them:

- Any other run that starts on that stream, a terminal or another cursor's first fetch, from the same `Query` or any other, raises `StreamException` `a stream is already in use by another BatchFlow run or cannot be closed from PHP` at its entry, as in 6.5; the open cursor is unaffected and continues.
- `fclose()` of that stream is refused by PHP with its `E_WARNING`, as in 6.5.
- PHP code that reads, writes or seeks that stream between fetches is not detected. The reader reads ahead, so later fetches then return unspecified rows or raise `CsvException`; every returned row still obeys the schema, and memory safety is unaffected.

A path source opens its own stream at the first fetch, so other runs on the same path are unaffected. Until a path cursor is released, the file stays open.

**Freeing a cursor.** Dropping the last reference to an open cursor releases its run as `close()` would; an exception from a path stream's `stream_close` then propagates from the statement that dropped the reference. At request shutdown a cursor can be freed before or after PHP closes the request's resource list: before, it is released as above; after, its stream is already closed and is not touched, and PHP runs no user callback by then. The known limit of 6.5 extends to cursors: a cursor still open on a persistent stream when the request ends after the resource list was closed leaves the in-use flag set on that stream until the worker exits.

## 6. Native architecture

```text
Csv::scan / Table::fromCsv
   |  schema + options compiled, no I/O
   v
Query (immutable)        Table (immutable typed columns)
   |  terminal
   v
PHP stream glue  ----->  bf_core (no Zend headers)
  re-fetch, EG(exception),   reader + structural masks (AVX2 | NEON | scalar)
  EG(timed_out), NO_FCLOSE   framing -> value decode -> fused predicates
                             count / sum / group table / column store
   ^                                  |
   +--- host callbacks (alloc, mem_fits, read, strtod) ----+
   v
scalar PHP result, aggregate lists, group rows, bounded rows,
or one batch of rows per Cursor::fetch (run state kept in the Cursor between fetches)
```

### 6.1 Core and glue split

`src/bf_*.c` is the core and includes only `bf_core.h`, standard C headers and intrinsics headers. It has no `php.h`. It reaches PHP only through a `bf_host` struct of function pointers: `alloc`, `realloc`, `free`, `mem_fits(size)`, `read(buf, want)`, `strtod(s, n, &d)`, `interrupted()`, `emit_row(values, n)`, passed explicitly in the run. `emit_row` receives one matched row's decoded values; the glue builds the PHP row there, and the `toArray` budget check of 6.4 lives there. `emit_row` returns continue or pause. On pause the core returns to the glue with its whole position kept (buffer, masks, cursor into the buffer, row, line and offset) and the next call continues at the next row; `toArray` never pauses, a cursor fetch pauses after its `$maxRows`-th row (6.8). It reports errors as a `bf_error` struct (class, reason code, row, line, offset, column index, two numbers) and returns status codes. `src/php_*.c` is the Zend glue: it implements the host, formats messages and owns objects. Nothing in the core formats text from record bytes. Allocation through the host is by growth only, so an indirect call never reaches the row loop.

The libFuzzer harness links the core alone with a host using `malloc`, a scripted `read`, `strtod_l` in the C locale on a NUL-terminated copy, a scripted `interrupted`, and a recording `emit_row`.

### 6.2 Structural scan and SIMD

Reader, framing and bit iteration:

- Read in `BF_CHUNK` = 65,536 byte reads into one contiguous buffer. A row is never split: when a row runs off the buffer it is compacted, refilled and rescanned from its start.
- Rescans stay linear even with short reads. After compaction the reader loops, with `want = min(max(fill, BF_CHUNK), B - BF_BLOCK - fill)` (`B` from 6.4), and computes masks and rescans only once the buffered bytes have at least doubled since the last scan, or EOF arrived, or the buffer reached `B`. The checks of 6.5 and `interrupted()` run after each read of the loop. (Recomputing masks on every refill would be quadratic when a long row arrives in small reads.) Results appear only at the end of a terminal, so waiting for more bytes cannot change them.
- The buffer is followed by `BF_BLOCK` = 64 zeroed bytes after every fill, so a block straddling the fill cannot produce a false structural bit.
- `bf_masks_fn(const char *buf, size_t nblocks, unsigned char delim, uint64_t *out)` turns each 64-byte block into one 64-bit mask of the delimiter byte, `LF`, `CR` and `"`, bit i for byte i. **It is the only code that differs by ISA.** AVX2 and NEON broadcast `delim` once per call. The delimiter is part of the compiled plan.
- *Instantiation.* Framing and the row loop are each written once, as an always-inline body that takes the delimiter and the plan class as compile-time arguments, and compiled into a fixed set of instantiations of that one body; none is a hand-written copy. Framing has two: the delimiter the constant `,`, and the plan's byte (`;`, tab, `|`, and every header row). The row loop has one per framing instantiation and plan class. `toArray` and a cursor are a plan class of their own, *rows*, with a loop of their own per framing instantiation: it decodes every schema column, evaluates every predicate kind and passes each matched row to `emit_row`; the scan loops hold no code for it. A *basic* plan holds only the basic predicate kinds (`whereEq`, `whereGt`, `whereEq` with null) and ends in `count`, `sum`, `summary`, `groupCount` or `groupSum`; its loop holds no code for the other predicate kinds or for aggregate states. Every other plan is *full*. A run picks its instantiation once, after the header, never per row. The fuzz harness and the differential reach all four scan loops and both rows loops. The extension is compiled with `-falign-functions=64 -falign-loops=32`, each flag only where `config.m4` finds that the compiler accepts it without a warning: with the compiler's default alignment the basic comma loop, measured 3% to 8% slower on the wide shape than the same instructions in a smaller build, a difference that moved with the size of unrelated code.
- *Popcount tail.* After the row's last referenced file column (the first column if none is referenced), a row with at least 8 fields left (`BF_TAIL_MIN`) takes the remaining delimiters in one call of a per-ISA function (`bf_tail_avx2`, `bf_tail_neon`, which is built with `popcnt`): from the scan position it walks the blocks to the first LF, CR or quote byte, counts the structural bits before it by popcount, and returns that byte. The row uses the result only if that byte is LF and the field count is exactly the file's; anything else (a quote or CR, a count above or below, the end of the data) takes the per-bit walk from the same position, which reports every error where it always has. The scalar masks have no tail function. Plans with fewer than 8 fields left run framing instantiations without the tail, so their code is the code without it: the framing and row loops have a second set of instantiations for the tail, chosen with the rest after the header.
- Selection: `__builtin_cpu_supports("avx2")` once in `MINIT` on x86-64, with `__attribute__((target("avx2")))` on the one function. On aarch64 NEON is always used (baseline ISA). Otherwise scalar. No `-march=native`, no `-mavx2` on any other file, no AVX-512, no ifunc.
- Scalar uses a 256-entry table, one static table per allowed delimiter.

**Identical results.** Every ISA must produce a byte-identical mask array for every input and every allowed delimiter, proven by the fuzz harness. Framing, value decode, hashing, grouping and result construction are shared C, so results and error messages are identical by construction. Hashing is scalar on every path.

### 6.3 Hash and group table

An unseeded hash is a HashDoS risk, and adding a seed to the initial state of a `mum`-based hash would not fix it: its block step is `h = mum(read64(s) ^ K2, read64(s+8) ^ h)`, and a block whose first word equals the public `K2` zeroes the multiplicand and forces `h = 0` regardless of seed.

**Decision: SipHash-1-3, 128-bit key, one hash for string and int keys.** It is a keyed PRF, so an attacker who does not know the key cannot aim collisions. Cost measured in section 2: 5.6% to 10.7% on the worst case of hashing every row.

- Key: 16 bytes from `php_random_bytes_throw`, one per terminal run and one per `Table`, stored in the run or table struct and never exposed. Include the CSPRNG header through `ext/random/php_random.h`; if a PHP version moved the symbol, guard with `PHP_VERSION_ID`.
- A CSPRNG failure propagates `\Random\RandomException` unchanged.
- Strings hash their bytes; int keys hash their 8-byte little-endian two's complement value. Words are read little-endian.
- The core takes the key as an argument. Only the glue draws randomness, so tests inject fixed keys.
- `bf_siphash13` is unit-tested against an independent reference: Rust `core`'s `test_siphash_1_3` vector table, or another published SipHash-1-3 vector set.

Group table: open addressing, linear probing, 64-bit slots `(tag << 32) | (gid + 1)` where `tag` is the high 32 hash bits and the index is the low bits masked. Full key comparison on tag match. Dense group ids in first-seen order index per-group arrays (`hash`, `ikey` or `off`+`len`, `count`, `sum`) and a key arena. NULL is never in the slot table. Initial 1024 slots; grow at load 3/4; rehash from stored full hashes.

This single-key table is what `groupCount` and `groupSum` use, unchanged by `groupAgg`.

*Batched probes.* Once the single-key table has 16,384 slots or more, matched rows are queued (at most 16, in the run, nothing allocated): the key is hashed and its slot prefetched at the row, and the queue is probed and counted in row order when it is full, at the end of input, or before a framing or decode error is reported. Group ids, first-seen order, `maxGroups`, the sum overflow and the first error are those of probing each row at once: an error at a later row is kept aside while the queue is flushed, and a queued row's error is reported with its own row, line and offset. A string key longer than 48 bytes flushes the queue and is handled at once. Smaller tables, which stay in cache, are probed at once.

**`groupAgg` table.** Same slots, probing, growth, tag and first-seen group ids, with three differences, used for every `groupAgg` call including one key:

- *Key encoding.* A group's key is the tuple encoded as, per key position in `$keys` order, one tag byte (`0` NULL, `1` int64, `2` string, `3` dictionary id) followed by nothing for NULL, the 8-byte little-endian value for int64, a 4-byte little-endian length then the bytes for a string, or the 4-byte little-endian id for a non-NULL value of a dictionary column (6.6); a NULL is tag `0` in every column. The encoding is injective, so two tuples are equal exactly when their encodings are equal bytes; NULL and the empty string differ by their tag.
- *Hash.* SipHash-1-3 with the run's key over the encoded tuple, computed incrementally over the parts, so no per-row copy of the tuple is built. Tuples containing NULL, the all-NULL tuple included, are ordinary entries in the slot table; there is no separate NULL group.
- *Arena and arrays.* The key arena holds each group's encoded tuple once, appended at first sight; per-group arrays are `hash` (8 bytes), `off` (8), `len` (4) of the encoded tuple, `count` (8), and per aggregate pair an 8-byte value (the `int64` or `float64` sum, minimum or maximum) and an 8-byte non-NULL count. Full key comparison on tag match compares the row's parts against the stored encoding without building it.

Growth, preflight and `maxGroupBytes` accounting follow 6.4 over these arrays. An encoded tuple is never empty and every tuple enters the slots, so a new group's slots, arrays and arena are all allocated at the first group, in that order. The result preflight is `groups * (row + 88 + 32 * N) + key allocation sizes`, where `N` is the number of keys, `row` the allocation sizes (6.4) of a row's `zend_array` and of its packed data `HT_PACKED_SIZE_EX(P, HT_MIN_MASK)`, `P` the packed capacity PHP allocates for a list of `N + 1 + M` elements (8, 16 or 32), and key allocation sizes the sum of the allocation sizes of the string keys of all groups. On a release build `row` is 216, 376 or 696; the 0.3 term `144 + 16 * P` counted the packed data at its length, which at `P = 32` (520 bytes in the 640-byte bin) was below the cost. `M` is the number of pairs and may be `0`, in which case there are no per-pair arrays and `P` is 8.

### 6.4 Memory and failure model

All per-run and table memory uses Zend allocation through the host. Never `malloc`, `mmap` or persistent allocation.

PHP's failure model, verified in `Zend/zend_alloc.c` and on 8.3.36, 8.4.27, 8.5.12:

- Crossing the soft `memory_limit` in a non-fallible `emalloc` succeeds and queues a delayed OOM. Native code keeps running and keeps allocating; nothing in it sees the OOM. At the engine's next interrupt check, at the latest when the terminal returns to the VM, PHP prints `Fatal error: Allowed memory size of N bytes exhausted (tried to allocate M bytes)` and unwinds with `UnwindExit`: no `catch` or `finally` block runs, shutdown functions do. For the caller this is a fatal at the terminal call, not a catchable exception; a result build whose preflight undercharges ends there (the 4 KiB-key case of `079`, verified on 8.3.36 and 8.5.12).
- The hard limit (200% of `memory_limit` by default, or +1G above 1G) calls `_exit(137)`: no longjmp, no shutdown functions, no `zend_catch`. A native loop that keeps allocating past the soft limit reaches it.
- Genuine fatals (compile errors, timeouts) longjmp through `zend_catch`, and C cleanup after the failing point never runs.

**Preflight rule.** Every growth is preceded by `mem_fits(request)` for each block it allocates, which applies the JsonFuse rule with two changes. It compares against `PG(memory_limit)`, ignoring `-1`. A small request, at most `ZEND_MM_MAX_SMALL_SIZE` (3,072 bytes, a bin slot), is compared unrounded with the limit minus `zend_memory_usage(0)`; when `zend_memory_usage(1) + ZEND_MM_CHUNK_SIZE` exceeds the limit, so that the request must come from free pages of the chunks already mapped, it is also refused above half the average free space per chunk, `(zend_memory_usage(1) - zend_memory_usage(0)) / 2 / chunks` with `chunks` the mapped bytes in whole chunks, rounded up. A larger request is rounded up to whole 2 MiB chunks and compared with the limit minus `zend_memory_usage(1)`: a huge block (above `ZEND_MM_MAX_LARGE_SIZE`) is mapped on its own, and a large block (a run of pages) needs a run of free pages long enough in one chunk, which ZendMM's private free-page map does not show, so it is priced as a fresh chunk. (Half the average free space, applied to large blocks too, let a 1 MiB slot or arena growth pass at a 6M limit against 2.7 MB of free space spread over three chunks with no 256 free pages in a row, a 128 KiB slot growth of a second dictionary pass with no 32, and 256 KiB growths pass on a heap whose free pages lay in runs of 3 or 7; each mapped a chunk past the limit and ended in PHP's fatal. `079_group_growth_limit_sweep`, `126_table_dict2_load_no_fatal`, `127_stream_group_fragmented_heap`.) Any request is refused once `zend_memory_usage(1)` exceeds the limit, and a request above the limit itself is refused before any rounding, so rounding cannot wrap near `SIZE_MAX`. The rule is the pure function `bf_mem_rule` in `src/bf_core.h`, unit-tested by `fuzz/test_mem_rule.c` on given counters. Before it refuses, `mem_fits` calls `zend_mm_gc()` once, which returns ZendMM's cached free pages and chunks as `zend_mm_alloc_pages` does before it reports the limit, and refuses only if the rule still refuses on the new counters; two large results in a row (`106_table_group`) would otherwise be refused where plain PHP allocations succeed. A large request the rule still refuses only for want of room for a chunk is then tried: `mem_fits` raises the heap limit by one chunk with `zend_set_memory_limit`, allocates and frees the block, restores the limit, and accepts if `zend_memory_usage(1)` stayed within the limit, that is, if ZendMM placed the block in a free run or a cached chunk. Restoring the limit releases a chunk the try left cached (`128_mem_try_restores_limit`). The try runs on the refusal path only, once per growth; without it a 2M or 3M limit would refuse the 288 KiB reader buffers that fit in the one mapped chunk (`060`, `063`). A refused request raises `LimitException` with reason `memory_limit would be exceeded`, with row context when one exists. A growth of several arrays together (group arrays of a streaming run or of a `Table` dictionary, a `Table` load's row arrays, a table query's `groupAgg` arrays, a dictionary group-by's arrays) checks `maxGroupBytes` or `maxTableBytes` on the total first, then preflights each array right before it is allocated: ZendMM may place each array below 2 MiB in a chunk of its own, which a sum rounded to whole chunks does not price (a dictionary's hash, offset, length and count arrays at 131,072 entries, priced as one sum of 4 MiB, took 6 MiB, `125_table_dict_load_no_fatal`; three 1 MiB arrays likewise, `123_table_group_agg_arrays_memory`, `124_table_row_arrays_memory`). An `erealloc` may hold old and new at once; the old block is in the counters when the new one is preflighted. A refusal leaves the arrays allocated so far, larger than the capacity, which stays; the failed run, load or query releases them.

**Allocation size.** An estimate that sums many PHP strings charges each at what ZendMM takes for it, never its byte length: a block of `n` bytes costs the smallest bin of `ZEND_MM_BINS_INFO` (`Zend/zend_alloc_sizes.h`) that holds `ZEND_MM_ALIGNED_SIZE(n) + ZEND_MM_OVERHEAD` when that is at most `ZEND_MM_MAX_SMALL_SIZE` (3,072), else that size rounded up to whole `ZEND_MM_PAGE_SIZE` pages up to `ZEND_MM_MAX_LARGE_SIZE`, and to whole `ZEND_MM_CHUNK_SIZE` chunks above (huge blocks are rounded to the OS page size, which the glue does not know). A string of `len` bytes is the block `_ZSTR_STRUCT_SIZE(len)`, and costs nothing at 0 or 1 byte, which `zend_string_init_fast` returns interned. `ZEND_MM_OVERHEAD` is the debug build's block header and 0 on release builds. The glue expands these macros from the headers it is compiled against, so the classes follow the PHP version; the 30 bins are the same on 8.3 to 8.6. Charged at its length, a key of 4,072 bytes (a 4,097-byte block, two pages) counted half its cost.

The preflight prices large and huge blocks exactly or above their cost, and is best-effort for small ones. On a fragmented heap close to the limit, a small block whose bin has no free slot needs a free page run (up to 7 pages for some bins) that half the average free space does not guarantee, and PHP's normal memory fatal can still occur there. If a soft crossing happens anyway, usage then exceeds the limit, the next `mem_fits` refuses, and the run stops. That is why the hard limit stays unreachable: nothing allocates more than one growth step past a check. Sweeps of `memory_limit` end in `LimitException` or a result at every limit: on release builds from 2M to 16M in 16 KiB steps over 16 table shapes (int and string keys of 0 to 1,000 bytes, `groupCount`, `groupSum`, `groupAgg` with up to 16 pairs) and from 16M to 80M in 64 KiB steps over 12, on the debug build from 2M to 48M, and in `079_group_growth_limit_sweep`.

**Bounds:**

| Component | Bound | Notes |
| --- | --- | --- |
| Reader buffer | `B = maxRowBytes + 2 + 65536 + 64` | Starts at 256 KiB or `B` if smaller; doubles, clamped to `B`. A row is rejected once more than `maxRowBytes + 2` bytes are buffered with no terminator found; a known row length counts bytes excluding its terminator |
| Structural masks | `ceil(B / 64) * 8` | |
| Field table | file columns x 32 bytes, at most 4096 columns | |
| Group state | `maxGroupBytes`, and `maxGroups` | Slots start at 1024, group arrays at 256 entries, the key arena at 65,536 bytes; each doubles until the request fits. Group arrays clamp to `maxGroups`. Preflighted each growth. Nothing is allocated before the first group: the slots at the first non-NULL key, the arrays at the first group, the arena at the first non-empty string key. For a new group, after the `maxGroups` check, the slots grow first, then the arrays, then the arena; each growth is checked against `maxGroupBytes`, then preflighted, with the row's prefix |
| Group result | preflight `groups * (row + 56) + key allocation sizes` before building | Refused gives `LimitException`. `row` is the allocation sizes of a row's `zend_array` and of its packed data for 8 elements: 272 per group in all on a release build, more on a debug build, whose blocks carry `ZEND_MM_OVERHEAD`. Each string key at its allocation size (above). Debug builds (`ZEND_DEBUG`) also check after the build, with `ZEND_ASSERT`, that it allocated no more than the estimate plus the result list's own `zend_array`, its packed data for 8 elements and one `ZEND_MM_PAGE_SIZE`; release builds do not check |
| `toArray`, each `Cursor::fetch` | budget check at most every 1 MiB of estimated PHP allocation | See below; `pending` starts at 0 in each fetch |
| `Cursor` between fetches | reader buffer, masks and field table above, plus the run struct | No PHP value and no row is held natively; a `Table` cursor holds only its row position |
| `whereIn` literals | sum of distinct string element bytes, preflighted at the call | 5.7 |
| `Table` | `maxTableBytes`, by total capacity, and `maxDictionarySize` | No column, arena or dictionary capacity is allocated before the first data row: the load starts with only its column descriptors and, with a dictionary, the dictionary's scratch run (both preflighted, neither counted by `memoryBytes()`, so a 0-row table reports 0); the first row allocates 4096 rows, and rows double from there, clamped to `maxInputRows`. String and dictionary arenas start at 65,536 bytes and double until the request fits. Preflighted each growth. The total capacity, the sum compared with `maxTableBytes`, is exactly what `memoryBytes()` returns: per row of capacity, a validity byte per column plus 8 bytes for `int64` and `float64`, 1 for `bool`, 12 (offset and length) for a plain `string`, 4 (the id) for a dictionary column and 8 more for a dictionary `int64`; plus the string arena and each dictionary's slots, arrays and arena (6.3). A table with no rows reports 0 |

A configured byte limit is refused when the sum of all capacities after the growth would exceed it. Apart from the two clamps in this table, a growth is never shrunk to fit the remaining budget.

`toArray` cannot preflight a total it does not know. In `emit_row`, before building each matched row, it adds the row's estimate (the allocation sizes, as above, of the `zend_array`, of its hash data for the column count and of each string, and, for the row whose insert makes the result list grow, of the list's new packed data at twice its capacity) to `pending`. When `pending` reaches 1 MiB, or a single row's estimate does, it calls `mem_fits(pending + 2 MiB)`, then resets. Without this a 64M limit ended in `_exit(137)`. Without the list term, a list growing from 4 MiB to 8 MiB at 262,144 rows passed the last check with 2 to 3 MiB to spare and ended in PHP's fatal at 104M (8.3.36); `094_toarray_limit_sweep` sweeps that growth.

**Hostile input summary.** A hostile file ends in a catchable exception, not a dead process, within the preflight's best-effort limit above: on a fragmented heap close to the limit, PHP's normal memory fatal can still occur. Too many distinct keys is `ResultTooLargeException` or `LimitException`; a huge row or field is `LimitException`; a huge file through `Table` is `LimitException`. With `memory_limit=-1`, `maxGroupBytes` and `maxTableBytes` are the bound.

**Bailout.** The terminal body, each cursor fetch (6.8) and the table load run in one `zend_try` each. Per-run state lives in one struct whose address is fixed before the try. `zend_catch` clears `PHP_STREAM_FLAG_NO_FCLOSE`, frees per-run buffers, and re-bails. It does not call `php_stream_close`: that can run a wrapper callback during a fatal; the request's resource list closes the stream. Only Zend-allocated memory is held across a bailout.

`EG(timed_out)` is checked through the host's `interrupted()`, which the glue implements with `zend_atomic_bool_load_ex(&EG(timed_out))`, at every read of a refill and, in table scans, every 1,048,576 rows and once more when the scan reaches the last row, so a timer that fires anywhere inside a table scan is seen by it; if set the run raises `BatchFlowException` and lets the engine raise its timeout fatal rather than be hard-killed. A streaming run has no check after its last row: it checks after each read, the read that returns end of input included, so the rows of the buffer that read completes are scanned unchecked, and a timer that fires there reaches PHP only when the call returns, as the engine's plain timeout fatal.

### 6.5 Streams

These rules are the JsonFuse stream contract applied to one input stream.

**Opening a path.** `php_stream_open_wrapper(path, "rb", 0, NULL)`. Options are `0`, not `REPORT_ERRORS`: measured, this suppresses the `Failed to open stream` warning, so failure is a clean `StreamException` (`cannot open input`). If the open returns NULL with `EG(exception)` set (a throwing `stream_open`), that exception propagates unchanged and no `StreamException` is created. A warning a wrapper emits itself, such as `open_basedir`, is not suppressed. On a path-opened stream the run sets `stream->flags |= PHP_STREAM_FLAG_NO_BUFFER` and makes no `php_stream_set_option` call: that call runs a user wrapper's `stream_set_option`, and a wrapper without one raises an `E_WARNING` and keeps its buffer (measured on 8.3.36 and 8.5.12). On a caller's stream it changes nothing, so data the caller had buffered is still consumed.

A path naming a directory opens on Linux and fails on read: measured, PHP raises an `E_NOTICE` ("Read of 65536 bytes failed with errno=21") and returns `n < 0`, giving `StreamException`. The notice can reach an error handler, so the checks below apply.

**Stream source at run start**, before the entry check: re-fetch the retained resource with `zend_fetch_resource2`; if closed since `scanStream` or `fromStream`, raise `a stream was closed before the run started`.

**Entry.** If the stream already has `PHP_STREAM_FLAG_NO_FCLOSE`, raise `StreamException` (`a stream is already in use ...`). Known cases: another run on that stream; directory handles; streams held by SPL or libxml; a stream's own filter or OpenSSL callback; and on PHP 8.5, a wrapper's own callback, because 8.5 sets the flag around userspace read, write, seek and cast. 8.3 and 8.4 allow a run on a wrapper's own stream from inside its callback; 8.5 refuses. Otherwise set the flag, and clear it on every exit including bailout. A callback's `fclose()` is then refused by PHP with an `E_WARNING`. This applies to path-opened streams too, since `get_resources()` can reach them.

**After every `php_stream_read` and `php_stream_eof`**, which can run PHP, in this order: (1) if `EG(exception)` is set, release per-run state and return with that exception unchanged; (2) re-fetch with `zend_fetch_resource2(res, NULL, php_file_le_stream(), php_file_le_pstream())`, and if NULL raise `a stream was closed during processing`. No `php_stream*` is held across such a call.

**Reads.** `n < 0` is `reading the input stream failed`. `n == 0` with EOF is end of input. `n == 0` without EOF is `input stream returned no data before EOF`; non-blocking streams are not supported. Short reads are normal.

The run never seeks and never closes a caller's stream. A path-opened stream is closed on every non-bailout exit: re-fetch it, clear `PHP_STREAM_FLAG_NO_FCLOSE`, close, then, because close can run a wrapper's `stream_close`, if `EG(exception)` is set, discard any built result and return with that exception unchanged. After a throwing read or eof, `stream_close` still runs, exactly once: the pending exception is held aside as PHP does around a destructor. If close completes, the original exception surfaces unchanged, whatever close threw and caught internally; if close throws, the close exception surfaces with the original as its previous (PHP's rule for destructors and `finally`). An exit or a fiber's graceful exit pending from the read is not held: `stream_close` does not run, the stream is closed natively and the exit continues. An exit inside `stream_close` wins over the held exception. An exception thrown by a destructor during the native close replaces a pending exit, as in PHP. The original exception becomes the close exception's previous unless that would form a cycle, per PHP's rule (`zend_exception_set_previous`); a close exception whose previous chain already contains the original, as with `throw new X('C', 0, $original)`, keeps the chain it was built with. The caller's stream position after an exception is unspecified, because the reader reads ahead.

Known limits, documented in the threat model: `pclose()` of a stream from its own callback is a php-src use-after-free reproducible with plain `fread`; because a run has one stream and all PHP during a run executes inside that stream's calls, `a stream was closed during processing` is a defensive branch with no safe PHP-level test. A cursor is the exception: `pclose()` also ignores the in-use flag that refuses `fclose()`, so PHP code can close a caller's stream between two fetches; the next fetch raises `StreamException` `a stream was closed during processing` before it emits any row still buffered, and the cursor is closed. A stream held by a run must not be used from another fiber while the run is active. A bailout while a run is suspended in a fiber leaves the flag set on a persistent stream until the worker exits.

### 6.6 Table storage

- Fixed-width columns: `int64_t[]`, `double[]`, `uint8_t[]` for bool. Validity: one byte per row (1 present, 0 NULL). NULL slots hold zero.
- `string` columns: `uint64_t off[]`, `uint32_t len[]`, one append-only arena. Offsets, never pointers, because the arena moves.
- `dictionary` columns: `uint32_t ids[]` with 0 for NULL and `gid + 1` otherwise, built during load with the group table above and the table's own key. A dictionary `string` column has no per-row strings. A dictionary `int64` column keeps `int64_t[]` too, for predicates and sums.
- A predicate on a dictionary column resolves its literal to an id at the start of each terminal; an absent literal matches nothing. Accordingly, `whereNe` with an absent literal matches every non-NULL row, and `whereIn` resolves each element and keeps the ids found (an all-absent list without `null` matches nothing). Range predicates on a dictionary `int64` column read its `int64_t[]`. A cursor resolves at its first fetch.
- A group-by on a dictionary column uses arrays indexed by id (`count`, `sum`, `order`), preflighted per terminal, and appends to `order` when a group's count first becomes non-zero, so first-seen order holds among matching rows. The arrays are sized by the whole dictionary (entries + 1, the NULL group's id 0 included) and checked against the stored `maxGroupBytes`, then `mem_fits`, before the scan; a refusal is `group state exceeds maxGroupBytes` or `memory_limit would be exceeded` with no row prefix. `maxGroups` counts groups actually produced and is raised with the `row R: ` prefix at the row of the first group past it.
- A group-by on a non-dictionary column builds a run-local group table and behaves as in streaming.
- `groupAgg` on a table always builds the run-local `groupAgg` table of 6.3; a dictionary key column contributes its id (tag `3`), which the result converts back to the value. Results equal streaming `groupAgg` on the same data.
- Per-terminal scratch is allocated and freed by the terminal.

### 6.7 Result construction

`toArray` builds one template row and `zend_array_dup`s it per row. The template must be a real-initialized non-packed hash (`zend_hash_real_init_mixed`), with keys inserted through symtable semantics. A schema of names `0,1,2` would otherwise yield a packed array, whose storage differs from `arData`. Values are written by position into the duplicate's buckets. Strings are always copied into new `zend_string`s; no borrowed pointer outlives the buffer. Group rows are packed arrays built with `ZEND_HASH_FILL_*`.

### 6.8 Cursor runs

- The `Cursor` object holds its `Query` reference, its state (5.13), a `fetching` flag, and a pointer to the run struct, NULL unless the cursor is open.
- The first fetch allocates the same per-run struct a terminal uses and performs the run-start steps of 6.5 (re-fetch, entry check, flag, path open) or, for a table, binds the table and resolves dictionary literals. The struct's address is fixed from then until release.
- Each fetch, the first included, sets `fetching`, then runs the core in its own `zend_try` until `emit_row` pauses (6.1), end of input or an error, and clears `fetching` on every exit. No `php_stream*` is held between fetches: each fetch re-fetches the resource first, and a stream found closed raises `a stream was closed during processing`, the defensive branch of 6.5 (with the flag set PHP refuses `fclose()`, so no PHP-level test reaches it).
- Release, on any non-bailout exit that ends the run: clear the flag, close a path-opened stream per 6.5, free the reader buffer, masks and field table, free the struct, set the pointer to NULL. `close()` and `free_obj` mark the cursor closed before releasing, and a fetch releases with `fetching` still set, so a callback run by the close (a wrapper's `stream_close`) cannot re-enter the release.
- Bailout during a fetch: `zend_catch` clears the flag, frees the per-run buffers and struct, marks the cursor closed and re-bails, without `php_stream_close` (6.4). Between fetches no native code runs, so a timeout or fatal raised there bails out through PHP alone; `EG(timed_out)` is next seen by `interrupted()` if a fetch runs, which then raises `stopped by execution timeout` and closes the cursor.
- `free_obj` performs release if the cursor is open, re-fetching the stream first; if the request's resource list already closed it, only native memory is freed. A cursor run holds its own reference on a caller's stream resource from its first fetch to its release, because at request shutdown PHP may free the `Query` before the `Cursor`. A cursor freed while one of its fetches is suspended in a fiber (only at request shutdown) leaves that run to the request's memory release.
- Memory held between fetches is exactly the run struct, reader buffer (at most `B`), masks and field table of 6.4, and the template row of 6.7, whose size the schema fixes: constant in the number of rows read. A fetch's PHP rows belong to the returned array, bounded by `$maxRows` and the row budget.

## 7. Repository layout

```text
php-batchflow/
  README.md  SPEC.md  CHANGELOG.md  LICENSE  composer.json  config.m4
  src/bf_core.h       # host, error, run types; no Zend
  src/bf_csv.c        # reader, framing
  src/bf_masks.c      # scalar, AVX2, NEON, selection
  src/bf_value.c      # int, float, bool, UTF-8
  src/bf_hash.c       # SipHash-1-3
  src/bf_groups.c     # group table
  src/bf_table.c      # column store, dictionary
  src/bf_exec.c       # fused scan loops
  src/php_batchflow.c # module, classes, Runtime
  src/php_query.c     # Query, Csv
  src/php_table.c     # Table
  src/php_cursor.c    # Cursor
  src/php_run.c       # host, stream glue, preflight, bailout
  src/php_errors.c    # exceptions and message formatting
  stubs/batchflow.stub.php  stubs/batchflow_arginfo.h
  tests/*.phpt  tests/bf_helper.inc  tests/bf_reference.inc
  fuzz/  benchmarks/  examples/  docs/DESIGN.md  docs/THREAT_MODEL.md
  .github/workflows/ci.yml
```

## 8. Safety model

- CSV is untrusted. All counts, lengths and allocations are checked before use.
- Group keys are attacker-controlled; the keyed hash and the limits in 5.9 and 6.4 exist for that reason.
- Messages never carry record bytes. CSV formula injection matters only if data is exported to a spreadsheet; documented, values are not altered.
- The path is whatever the PHP process may read; `open_basedir` and wrappers apply as for `fopen`. The extension does not sandbox.
- No callbacks in native loops. PHP code can run only through the stream layer, handled in 6.5.
- ZTS is not advertised until proven.

