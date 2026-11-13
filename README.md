# BatchFlow

BatchFlow is a PHP extension written in C. It scans CSV files and streams without building PHP arrays first.
It filters rows, counts, sums, groups and aggregates in native code, and returns only the result to PHP.
Memory use stays flat, whatever the file size.

JSON input (NDJSON and top-level JSON arrays) with the same API is coming soon.

## Requirements

- PHP 8.3, 8.4 and 8.5, and 8.6 (pre-release builds until PHP 8.6.0 is released), non-thread-safe (NTS) builds. Thread-safe (ZTS) builds are not supported: CI runs a provisional ZTS lane that is allowed to fail, and nothing in this project claims ZTS works.
- Linux on x86-64 or arm64. It also builds and runs on macOS (checked on arm64 with PHP 8.3).
- A C compiler, `make`, `autoconf` and the PHP development files (`phpize`).
- SIMD: AVX2 on x86-64 is picked at run time. NEON is used on arm64. A scalar fallback is always built in.

## Install and build

With [PIE](https://github.com/php/pie):

```sh
pie install hamdallah90/batchflow
```

On minimal images such as `php:8.3-cli`, install `libtool` first, because PIE builds with `phpize`. The PIE metadata is in `composer.json`: Linux and macOS, NTS only, PHP 8.3 up to 8.6.

To build from source:

```sh
phpize
./configure --enable-batchflow
make
make test
sudo make install
```

Then add this line to your `php.ini`:

```ini
extension=batchflow
```

To try it without installing, load the built module for one run:

```sh
php -d extension=$PWD/modules/batchflow.so --ri batchflow
```

Check that it is loaded:

```php
<?php
var_dump(extension_loaded('batchflow'));
```

Output:

```text
bool(true)
```

```sh
php --ri batchflow
```

## Usage

The examples below use this file, `sales.csv`. Each example writes it to the system temp directory first.

```text
region,product,units,price
north,apple,10,1.5
south,pear,4,2.25
north,pear,7,2.25
east,apple,3,1.5
south,apple,8,1.5
north,fig,,4
```

A schema maps each column name to a type: `int64`, `float64`, `bool` or `string`. The first line of the file is the header.
Options go in an optional third array, for example `['delimiter' => ';', 'maxRowBytes' => 1048576]`.

### Totals

`Csv::scan()` reads the file as a stream. It builds no table in memory. Every call on the returned `Query` reads the file again. An empty field is `null` and is skipped by `sum`, `min`, `max` and `avg`.

```php
<?php
use BatchFlow\Csv;

$path = sys_get_temp_dir() . '/sales.csv';
file_put_contents($path, <<<CSV
region,product,units,price
north,apple,10,1.5
south,pear,4,2.25
north,pear,7,2.25
east,apple,3,1.5
south,apple,8,1.5
north,fig,,4
CSV);

$schema = ['region' => 'string', 'product' => 'string', 'units' => 'int64', 'price' => 'float64'];
$query = Csv::scan($path, $schema);

echo $query->count(), "\n";
echo $query->sum('units'), "\n";
echo $query->min('units'), "\n";
echo $query->max('price'), "\n";
echo $query->avg('units'), "\n";
echo json_encode($query->aggregate([['sum', 'units'], ['max', 'price']])), "\n";
```

Output:

```text
6
32
3
4
6.4
[32,4]
```

### Group by

`groupCount` and `groupSum` return a list of `[key, ...]` rows in first-seen order. `groupAgg` takes one to four keys and up to 16 `[op, column]` pairs. `op` is one of `count`, `sum`, `min`, `max`, `avg`; `count` counts non-null values. Each row is `[key1, ..., count, agg1, ...]`.

```php
<?php
use BatchFlow\Csv;

$path = sys_get_temp_dir() . '/sales.csv';
file_put_contents($path, <<<CSV
region,product,units,price
north,apple,10,1.5
south,pear,4,2.25
north,pear,7,2.25
east,apple,3,1.5
south,apple,8,1.5
north,fig,,4
CSV);

$schema = ['region' => 'string', 'product' => 'string', 'units' => 'int64', 'price' => 'float64'];
$query = Csv::scan($path, $schema);

echo json_encode($query->groupCount('region')), "\n";
echo json_encode($query->groupSum('region', 'units')), "\n";
echo json_encode($query->groupAgg(['region', 'product'], [['sum', 'units'], ['max', 'price']])), "\n";
```

Output:

```text
[["north",3],["south",2],["east",1]]
[["north",3,17],["south",2,12],["east",1,3]]
[["north","apple",1,10,1.5],["south","pear",1,4,2.25],["north","pear",1,7,2.25],["east","apple",1,3,1.5],["south","apple",1,8,1.5],["north","fig",1,0,4]]
```

### Filters, rows and summary

Filters return a new `Query`. Chain as many as you need. `toArray()` returns matching rows as arrays. `summary()` counts rows scanned and matched in one pass.

```php
<?php
use BatchFlow\Csv;

$path = sys_get_temp_dir() . '/sales.csv';
file_put_contents($path, <<<CSV
region,product,units,price
north,apple,10,1.5
south,pear,4,2.25
north,pear,7,2.25
east,apple,3,1.5
south,apple,8,1.5
north,fig,,4
CSV);

$schema = ['region' => 'string', 'product' => 'string', 'units' => 'int64', 'price' => 'float64'];
$apples = Csv::scan($path, $schema)->whereEq('product', 'apple')->whereGte('units', 5);

echo $apples->count(), "\n";
echo json_encode($apples->toArray()), "\n";

$summary = Csv::scan($path, $schema)->whereIn('region', ['north', 'south'])->summary('units');
echo "$summary->rowsScanned $summary->rowsMatched $summary->sum\n";
```

Output:

```text
2
[{"region":"north","product":"apple","units":10,"price":1.5},{"region":"south","product":"apple","units":8,"price":1.5}]
6 5 29
```

### Streams and cursors

`Csv::scanStream()` reads any PHP stream. A `Cursor` returns rows in batches, so memory stays flat on large files.

```php
<?php
use BatchFlow\Csv;

$path = sys_get_temp_dir() . '/sales.csv';
file_put_contents($path, "region,units\nnorth,10\nsouth,4\nnorth,7\neast,3\nsouth,8\n");

$stream = fopen($path, 'rb');
$total = Csv::scanStream($stream, ['region' => 'string', 'units' => 'int64'])->sum('units');
echo $total, "\n";

$cursor = Csv::scan($path, ['region' => 'string', 'units' => 'int64'])->cursor();
while ($rows = $cursor->fetch(2)) {
    echo json_encode($rows), "\n";
}
$cursor->close();
```

Output:

```text
32
[{"region":"north","units":10},{"region":"south","units":4}]
[{"region":"north","units":7},{"region":"east","units":3}]
[{"region":"south","units":8}]
```

### Tables

`Table::fromCsv()` and `Table::fromStream()` read the input once and keep it in memory as typed columns. `query()` and the table's `where*` calls return a `Query` that runs on the table, so repeated queries do not read the file again.

```php
<?php
use BatchFlow\Table;

$path = sys_get_temp_dir() . '/sales.csv';
file_put_contents($path, <<<CSV
region,product,units,price
north,apple,10,1.5
south,pear,4,2.25
north,pear,7,2.25
east,apple,3,1.5
south,apple,8,1.5
north,fig,,4
CSV);

$schema = ['region' => 'string', 'product' => 'string', 'units' => 'int64', 'price' => 'float64'];
$table = Table::fromCsv($path, $schema);

echo $table->rowCount(), "\n";
echo json_encode($table->columnNames()), "\n";
echo $table->query()->sum('units'), "\n";
echo json_encode($table->whereEq('product', 'apple')->groupSum('region', 'units')), "\n";
echo $table->whereGte('units', 7)->count(), "\n";
```

Output:

```text
6
["region","product","units","price"]
32
[["north",1,10],["east",1,3],["south",1,8]]
3
```

### Errors

Errors in the data, schema, limits and streams are `BatchFlow\BatchFlowException` (or a subclass). Bad arguments raise PHP's `ValueError` or `TypeError`. CSV errors (`CsvException`) name the row, line and column.

```php
<?php
use BatchFlow\Csv;
use BatchFlow\CsvException;
use BatchFlow\SchemaException;

$path = sys_get_temp_dir() . '/bad.csv';
file_put_contents($path, "region,units\nnorth,10\nsouth,many\n");

try {
    Csv::scan($path, ['region' => 'string', 'units' => 'int64'])->sum('units');
} catch (CsvException $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}

try {
    Csv::scan($path, ['units' => 'integer']);
} catch (SchemaException $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
```

Output:

```text
BatchFlow\CsvException: row 2 (line 3, offset 22): column "units": expected int64, got malformed value
BatchFlow\SchemaException: column "units": unknown type
```

## Memory limits

BatchFlow checks `memory_limit` before it allocates, and raises `LimitException` ("memory_limit would be exceeded") instead of letting PHP stop with a fatal error. Two effects of that check are visible:

- The check for grouping (`groupCount`, `groupSum`, `groupAgg`) is conservative. A run can be refused while some memory is still free under `memory_limit`. Measured with one million distinct string keys on a table (PHP 8.3, x86-64), `groupCount` needed 339 MB of memory beyond what was already in use and was refused below 369 MB; `groupSum` needed 347 MB and was refused below 377 MB. The refused margin was about 30 MB, or 9% of the need.
- When the check refuses a large block, it first tries the allocation once with the limit raised by one 2 MiB chunk, then frees the block and restores the limit. Each such try can raise `memory_get_peak_usage(true)` by up to one 2 MiB chunk for that moment. `memory_get_usage(true)` afterwards is no higher than before.

## What the extension adds at run time

Namespace: `BatchFlow`. There are no functions, constants or ini settings, and no interfaces or enums.

Functions: none.

Constants: none.

Ini settings: none.

Interfaces and enums: none.

### Classes

- `Csv`: final. Entry points for scanning a file or a stream.
  - `Csv::scan(string $path, array $schema, array $options = []): Query`: scan a CSV file.
  - `Csv::scanStream($stream, array $schema, array $options = []): Query`: scan a PHP stream.
- `Query`: final. An immutable query. Every `where*` call returns a new `Query`.
  - `whereEq`, `whereNe`, `whereGt`, `whereGte`, `whereLt`, `whereLte`, `whereIn`: add a filter on one column.
  - `count()`: number of matching rows.
  - `sum(string $column)`: sum of a numeric column.
  - `min(string $column)`, `max(string $column)`: smallest and largest value, or `null` if none.
  - `avg(string $column)`: mean of a numeric column, or `null` if none.
  - `aggregate(array $aggregates)`: up to 16 `[op, column]` pairs in one pass. `op` is one of `count`, `sum`, `min`, `max`, `avg`; `count` counts non-null values.
  - `summary(?string $sumColumn = null)`: rows scanned, rows matched and an optional sum, as a `Summary`.
  - `groupCount(string $key, int $maxGroups = 1000000)`: rows per key.
  - `groupSum(string $key, string $sum, int $maxGroups = 1000000)`: rows and sum per key.
  - `groupAgg(string|array $keys, array $aggregates, int $maxGroups = 1000000)`: one to four keys, up to 16 aggregates.
  - `toArray(int $maxRows = 10000)`: matching rows as arrays.
  - `cursor()`: a `Cursor` over matching rows.
- `Cursor`: final. Reads matching rows in batches.
  - `fetch(int $maxRows = 1000): array`: the next batch. An empty array means the end.
  - `close(): void`: release the cursor.
- `Summary`: final. Result of `Query::summary()`.
  - `int $rowsScanned`: rows read (read-only property).
  - `int $rowsMatched`: rows that passed the filters (read-only property).
  - `int|float|null $sum`: the sum, or `null` if no column was given (read-only property).
- `Table`: final. A typed in-memory table, loaded once.
  - `Table::fromCsv(string $path, array $schema, array $options = []): Table`: load a CSV file.
  - `Table::fromStream($stream, array $schema, array $options = []): Table`: load from a PHP stream.
  - `rowCount`, `columnNames`, `schema`, `memoryBytes`: table facts.
  - `query()`: a `Query` over the table.
  - `whereEq`, `whereNe`, `whereGt`, `whereGte`, `whereLt`, `whereLte`, `whereIn`: start a filtered `Query`.
- `Runtime`: final.
  - `Runtime::info(): array`: version and SIMD support (`version`, `simd.compiled`, `simd.auto`).

### Exceptions

- `BatchFlowException`: base class. Extends `RuntimeException`.
- `SchemaException`: bad schema, option, column name or filter value.
- `CsvException`: malformed CSV or a value that does not match its type.
- `LimitException`: a size or count limit was exceeded.
- `ResultTooLargeException`: extends `LimitException`. A result would be too large, for example too many groups.
- `SumOverflowException`: an integer sum overflowed, or a float sum is not finite.
- `StreamException`: the input could not be opened or read.

## Performance

All numbers are from one machine and one architecture: an Intel Xeon Platinum 8358 (Linux x86-64, AVX2), PHP 8.3.36 NTS,
BatchFlow built with the PHP's own compiler flags, files of 1,000,000 rows from the generator in `benchmarks/`, one core
per timed job with nothing else on it. `benchmarks/RESULTS.md` has every cell, every implementation, the method, the raw
medians and p95, the warm and the fresh-process numbers side by side, and every loss. Each ratio below is a range over
the named cells, for a warm process and for a fresh process, and comes with the ratio of the scalar build.

- **Group by** (`groupCount`, `groupSum` on a string or integer key, 10 to 100,000 distinct keys, 20 cells): 3.7x to 13.6x faster
  than the fastest JIT'd PHP loop over the same file, and 2.6x to 5.5x faster than single-threaded DuckDB (its CLI, one
  thread). At 100,000 rows the ratio is lower in one cell (`gs-mixnq-lo-c100k`, fresh process: 2.7x); the 3x claim is for 1,000,000 rows and up. The AVX2 build is 1.2x to 2.7x faster than `simd => scalar` on these cells (1.1x to 3.0x across all 43 cells of that size), so the vector scan carries
  part of the win.
- **Streaming count and sum** (8 cells): 5.2x to 15.4x the fastest JIT'd PHP loop, with peak memory at 0.84 times that of
  the streaming PHP loop, which is the PHP floor (the extension adds no table).
- **`toArray`** (6 cells): 2.8x to 7.5x the fastest PHP CSV loader.
- **Where it loses:** to four-thread DuckDB on four pinned cores, on group-by cells of 10,000,000 rows that keep most
  rows (for example `gi-numeric-lo-c1k` at 10M rows: DuckDB takes 0.75 of BatchFlow's time). BatchFlow has no threads. The full list
  is in `benchmarks/RESULTS.md`.
- **Not claimed:** anything on arm64 (the project's arm64 runs check results, not speed), anything against all-core
  DuckDB in general, and any speed of numeric scans over preloaded columns.

## Project documents

- `SPEC.md`: the contract for behaviour, exact messages and limits.
- `docs/DESIGN.md`: ownership, memory lifecycle, complexity and internal formats.
- `docs/THREAT_MODEL.md`: what BatchFlow defends against and its known limits.
- `benchmarks/RESULTS.md`: the benchmark results, the method and every loss.
- `CHANGELOG.md`, `LICENSE` (MIT).

CI runs PHPT on PHP 8.3, 8.4 and 8.5 on x86-64 and arm64, a sanitizer job (ASan, UBSan) with fuzz corpus replay, a
non-blocking PHP 8.6 lane and a provisional ZTS lane that may fail (`.github/workflows/ci.yml`).

## IDE autocomplete

`stubs/batchflow.stub.php` holds the full API with types, for IDE autocomplete.
