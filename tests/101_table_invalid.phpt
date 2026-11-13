--TEST--
batchflow: every load error leaves no table and carries the message the streaming reader gives for the same bytes; a load validates every schema column; stream and argument failures
--EXTENSIONS--
batchflow
--FILE--
<?php
require __DIR__ . '/bf_table.inc';

use BatchFlow\{Csv, Table};

$S = ['a' => 'int64', 'b' => 'string', 'c' => 'float64'];

/**
 * SPEC 5.1: no partial table exists, so a failed load assigns nothing. SPEC 5.11: the load errors are the CSV errors of 5.4 to
 * 5.6 with the row, line and offset prefix, i.e. the text toArray (which references every column) gives on the same bytes.
 */
function load_vs_stream(string $label, string $csv, array $schema, array $opts = []): void
{
    $t = 'sentinel';
    $a = tb_try(function () use (&$t, $csv, $schema, $opts) {
        $t = tb_load($csv, $schema, $opts);
        return 'LOADED';
    });
    $b = tb_try(fn() => (bf_q($csv, $schema, tb_stream_opts($opts))->toArray(100000000) || true) ? 'LOADED' : '');
    echo $label, ': ', $a, "\n";
    if ($t !== 'sentinel' && $a !== 'LOADED') {
        echo "  A TABLE EXISTS AFTER A FAILED LOAD\n";
    }
    if ($a !== $b) {
        echo "  DIFFERS FROM toArray ON THE SAME BYTES: $b\n";
    }
}

echo "-- values, SPEC 5.6; the row prefix counts rows, lines and offsets from the start of the source\n";
$h = "a,b,c\n";
load_vs_stream('malformed int', "{$h}1,x,1.5\nx1,y,2\n", $S);
load_vs_stream('int with plus', "{$h}+5,x,1.5\n", $S);
load_vs_stream('int out of range', "{$h}1,x,1.5\n1,x,1.5\n9223372036854775808,y,2\n", $S);
load_vs_stream('quoted empty float', "{$h}1,x,1.5\n2,y,\"\"\n", $S);
load_vs_stream('float overflow', "{$h}1,x,1e400\n", $S);
load_vs_stream('float malformed', "{$h}1,x,nan\n", $S);
load_vs_stream('bool malformed', "d\ntrue\nTRUE\n", ['d' => 'bool']);
load_vs_stream('invalid UTF-8 in row 3', "{$h}1,x,1\n2,y,2\n3,\xff,3\n", $S);
load_vs_stream('overlong UTF-8', "{$h}1,\xC0\x80,1\n", $S);
load_vs_stream('doubled quote in an int', "{$h}\"1\"\"\",x,1\n", $S);

echo "-- framing, SPEC 5.4\n";
load_vs_stream('bare quote', "{$h}1,x\"y,1.5\n", $S);
load_vs_stream('unterminated quote', "{$h}1,\"x,1.5\n2,y,2\n", $S);
load_vs_stream('text after closing quote', "{$h}1,\"x\"y,1.5\n", $S);
load_vs_stream('bare CR', "{$h}1,x,1.5\r2,y,2\n", $S);
load_vs_stream('lone CR at the end', "{$h}1,x,1.5\r", $S);
load_vs_stream('too few fields', "{$h}1,x,1.5\n2,y\n", $S);
load_vs_stream('too many fields', "{$h}1,x,1.5\n2,y,2,3\n", $S);
load_vs_stream('blank line, three columns', "{$h}1,x,1.5\n\n", $S);
load_vs_stream('quoted newline, then bad row', "{$h}1,\"l1\nl2\",1.5\n2,y,bad\n", $S);

echo "-- header, SPEC 5.4\n";
load_vs_stream('empty source', '', $S);
load_vs_stream('BOM only', "\xEF\xBB\xBF", $S);
load_vs_stream('duplicate column', "a,b,a,c\n", $S);
load_vs_stream('unexpected column', "a,b,c,d\n", $S);
load_vs_stream('missing column', "a,b\n1,x\n", $S);
load_vs_stream('extra column allowed, then bad row', "a,x,b,c\n1,z,y,2\n2,z,y,bad\n", $S, ['allowExtraColumns' => true]);

echo "-- limits, SPEC 5.3 and 5.4: exact and +1\n";
load_vs_stream('maxInputRows 2, two rows', "{$h}1,x,1\n2,y,2\n", $S, ['maxInputRows' => 2]);
load_vs_stream('maxInputRows 2, three rows', "{$h}1,x,1\n2,y,2\n3,z,3\n", $S, ['maxInputRows' => 2]);
load_vs_stream('maxFieldBytes 3, 3 bytes', "{$h}1,abc,1\n", $S, ['maxFieldBytes' => 3]);
load_vs_stream('maxFieldBytes 3, 4 bytes', "{$h}1,x,1\n2,abcd,1\n", $S, ['maxFieldBytes' => 3]);
load_vs_stream('maxRowBytes exact', "a\n12345\n", ['a' => 'int64'], ['maxRowBytes' => 5]);
load_vs_stream('maxRowBytes +1', "a\n1\n123456\n", ['a' => 'int64'], ['maxRowBytes' => 5]);

// SPEC 5.5: a load references every schema column, a streaming count references none, so the same bytes are fine streamed
echo "-- load validates every column, streaming does not (SPEC 5.5)\n";
$bad = "{$h}1,x,1.5\n2,y,oops\n";
echo 'streaming count: ', tb_try(fn() => bf_fmt(bf_q($bad, $S)->count())), "\n";
echo 'streaming sum(a): ', tb_try(fn() => bf_fmt(bf_q($bad, $S)->sum('a'))), "\n";
echo 'table load: ', tb_try(fn() => bf_fmt(tb_load($bad, $S)->rowCount())), "\n";
$bad = "{$h}1,\xff,1.5\n";
echo 'streaming count, invalid UTF-8: ', tb_try(fn() => bf_fmt(bf_q($bad, $S)->count())), "\n";
echo 'table load, invalid UTF-8: ', tb_try(fn() => bf_fmt(tb_load($bad, $S)->rowCount())), "\n";

// SPEC 6.5: an exception from a callback during the load propagates unchanged, the path-opened stream is closed once
echo "-- callbacks and streams\n";
$boom = new BfBoom('boom');
$path = bf_wrap_path(['segments' => [["{$h}", 1], ["1,x,1\n", 5000]], 'max' => 4096, 'mode' => 'throw_read', 'after' => 20000, 'exc' => $boom]);
try {
    $t = Table::fromCsv($path, $S);
    echo "no exception\n";
} catch (Throwable $e) {
    echo 'same object: ', $e === $boom ? 'yes' : 'NO (' . get_class($e) . ')', ', closes: ', BfWrap::$closes, "\n";
}

$path = bf_wrap_path(['segments' => [["{$h}", 1], ["1,x,1\n", 3]], 'throw_open' => $boom]);
try {
    Table::fromCsv($path, $S);
    echo "no exception\n";
} catch (Throwable $e) {
    echo 'throwing stream_open, same object: ', $e === $boom ? 'yes' : 'NO', ', opens: ', BfWrap::$opens, "\n";
}

// SPEC 5.13, 6.5: a stream held by an open cursor refuses a second run, a table load included; the cursor is unaffected
$f = bf_mem("{$h}1,x,1\n2,y,2\n3,z,3\n");
$c = Csv::scanStream($f, $S)->cursor();
echo 'cursor first fetch: ', bf_fmt_rows($c->fetch(1)), "\n";
echo 'Table::fromStream on the same stream: ', tb_try(fn() => bf_fmt(Table::fromStream($f, $S)->rowCount())), "\n";
echo 'cursor continues: ', bf_fmt_rows($c->fetch(5)), "\n";
$c->close();

echo 'missing file: ', tb_try(fn() => bf_fmt(Table::fromCsv('/nonexistent/bf_m4_101.csv', $S)->rowCount())), "\n";
echo 'same, streaming: ', tb_try(fn() => bf_fmt(Csv::scan('/nonexistent/bf_m4_101.csv', $S)->count())), "\n";

// SPEC 5.11: arguments are checked before any I/O, so the missing file is never reached
echo "-- arguments before I/O\n";
echo 'empty path: ', tb_try(fn() => bf_fmt(Table::fromCsv('', $S)->rowCount())), "\n";
echo 'NUL in path: ', tb_try(fn() => bf_fmt(Table::fromCsv("/tmp/a\0b", $S)->rowCount())), "\n";
echo 'empty schema: ', tb_try(fn() => bf_fmt(Table::fromCsv('/nonexistent/bf_m4_101.csv', [])->rowCount())), "\n";
echo 'bad type: ', tb_try(fn() => bf_fmt(Table::fromCsv('/nonexistent/bf_m4_101.csv', ['a' => 'int'])->rowCount())), "\n";
echo 'bad option: ', tb_try(fn() => bf_fmt(Table::fromCsv('/nonexistent/bf_m4_101.csv', $S, ['maxTableBytes' => 1])->rowCount())), "\n";
echo 'bad dictionary: ', tb_try(fn() => bf_fmt(Table::fromCsv('/nonexistent/bf_m4_101.csv', $S, ['dictionary' => ['c']])->rowCount())), "\n";
?>
--EXPECT--
-- values, SPEC 5.6; the row prefix counts rows, lines and offsets from the start of the source
malformed int: CsvException: row 2 (line 3, offset 14): column "a": expected int64, got malformed value
int with plus: CsvException: row 1 (line 2, offset 6): column "a": expected int64, got malformed value
int out of range: CsvException: row 3 (line 4, offset 22): column "a": expected int64, got out-of-range number
quoted empty float: CsvException: row 2 (line 3, offset 14): column "c": expected float64, got quoted empty field
float overflow: CsvException: row 1 (line 2, offset 6): column "c": expected float64, got out-of-range number
float malformed: CsvException: row 1 (line 2, offset 6): column "c": expected float64, got malformed value
bool malformed: CsvException: row 2 (line 3, offset 7): column "d": expected bool, got malformed value
invalid UTF-8 in row 3: CsvException: row 3 (line 4, offset 18): column "b": invalid UTF-8
overlong UTF-8: CsvException: row 1 (line 2, offset 6): column "b": invalid UTF-8
doubled quote in an int: CsvException: row 1 (line 2, offset 6): column "a": expected int64, got malformed value
-- framing, SPEC 5.4
bare quote: CsvException: row 1 (line 2, offset 6): bare quote in unquoted field
unterminated quote: CsvException: row 1 (line 2, offset 6): unterminated quoted field
text after closing quote: CsvException: row 1 (line 2, offset 6): text after closing quote
bare CR: CsvException: row 1 (line 2, offset 6): bare CR
lone CR at the end: CsvException: row 1 (line 2, offset 6): bare CR
too few fields: CsvException: row 2 (line 3, offset 14): expected 3 fields, got 2
too many fields: CsvException: row 2 (line 3, offset 14): expected 3 fields, got more
blank line, three columns: CsvException: row 2 (line 3, offset 14): expected 3 fields, got 1
quoted newline, then bad row: CsvException: row 2 (line 4, offset 20): column "c": expected float64, got malformed value
-- header, SPEC 5.4
empty source: CsvException: missing header
BOM only: CsvException: missing header
duplicate column: CsvException: header (line 1, offset 0): duplicate column "a"
unexpected column: CsvException: header (line 1, offset 0): unexpected column at position 4
missing column: CsvException: header (line 1, offset 0): missing column "c"
extra column allowed, then bad row: CsvException: row 2 (line 3, offset 16): column "c": expected float64, got malformed value
-- limits, SPEC 5.3 and 5.4: exact and +1
maxInputRows 2, two rows: LOADED
maxInputRows 2, three rows: LimitException: row 3 (line 4, offset 18): input exceeds maxInputRows
maxFieldBytes 3, 3 bytes: LOADED
maxFieldBytes 3, 4 bytes: LimitException: row 2 (line 3, offset 12): column "b": field exceeds maxFieldBytes
maxRowBytes exact: LOADED
maxRowBytes +1: LimitException: row 2 (line 3, offset 4): row exceeds maxRowBytes
-- load validates every column, streaming does not (SPEC 5.5)
streaming count: int(2)
streaming sum(a): int(3)
table load: CsvException: row 2 (line 3, offset 14): column "c": expected float64, got malformed value
streaming count, invalid UTF-8: int(1)
table load, invalid UTF-8: CsvException: row 1 (line 2, offset 6): column "b": invalid UTF-8
-- callbacks and streams
same object: yes, closes: 1
throwing stream_open, same object: yes, opens: 0
cursor first fetch: {a=int(1), b=s"x", c=float(1.0)}
Table::fromStream on the same stream: StreamException: a stream is already in use by another BatchFlow run or cannot be closed from PHP
cursor continues: {a=int(2), b=s"y", c=float(2.0)}
{a=int(3), b=s"z", c=float(3.0)}
missing file: StreamException: cannot open input
same, streaming: StreamException: cannot open input
-- arguments before I/O
empty path: ValueError: BatchFlow\Table::fromCsv(): Argument #1 ($path) must not be empty
NUL in path: ValueError: BatchFlow\Table::fromCsv(): Argument #1 ($path) must not contain any null bytes
empty schema: SchemaException: schema must not be empty
bad type: SchemaException: column "a": unknown type
bad option: SchemaException: option "maxTableBytes": out of range
bad dictionary: SchemaException: option "dictionary": column "c" must be string or int64
