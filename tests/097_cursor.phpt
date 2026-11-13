--TEST--
batchflow: Cursor class shape; for every batch size the concatenated batches equal toArray; the final-batch and [] rules; maxInputRows and the BOM across fetches; fetch's ValueError before the state check; the state table of SPEC 5.13; cursor() does no I/O
--EXTENSIONS--
batchflow
--FILE--
<?php
require __DIR__ . '/bf_helper.inc';

use BatchFlow\{Csv, Cursor};

echo "-- class shape\n";
$r = new ReflectionClass(Cursor::class);
echo 'final=', var_export($r->isFinal(), true), ' internal=', var_export($r->isInternal(), true), ' cloneable=', var_export($r->isCloneable(), true),
    ' constructor private=', var_export($r->getConstructor()->isPrivate(), true), "\n";
foreach (['fetch', 'close'] as $n) {
    $m = $r->getMethod($n);
    $ps = [];
    foreach ($m->getParameters() as $p) {
        $ps[] = $p->getType() . ' $' . $p->getName() . ($p->isDefaultValueAvailable() ? ' = ' . var_export($p->getDefaultValue(), true) : '');
    }
    echo implode(' ', Reflection::getModifierNames($m->getModifiers())), ' ', $n, '(', implode(', ', $ps), '): ', $m->getReturnType(), "\n";
}
$m = new ReflectionMethod(BatchFlow\Query::class, 'cursor');
echo 'Query::cursor(): ', $m->getReturnType(), ', ', $m->getNumberOfParameters(), " parameters\n";
$c = bf_q("i\n1\n", ['i' => 'int64'])->cursor();
echo get_class($c), "\n";
foreach ([
    'new' => fn() => new Cursor(),
    'clone' => fn() => clone $c,
    'serialize' => fn() => serialize($c),
    'unserialize' => fn() => unserialize(sprintf('O:%d:"%s":0:{}', strlen(Cursor::class), Cursor::class)),
    'newInstanceWithoutConstructor' => fn() => $r->newInstanceWithoutConstructor(),
] as $what => $fn) {
    try {
        $fn();
        echo "$what: NOT REFUSED\n";
    } catch (Throwable $e) {
        echo "$what: ", get_class($e), ': ', $e->getMessage(), "\n";
    }
}

echo "-- every batch size from 1 to the row count plus one, against toArray on the 090 fixtures\n";
$ALL = ['i' => 'int64', 'f' => 'float64', 'b' => 'bool', 's' => 'string'];
$fixtures = [
    'several rows' => ["i,f,b,s\n3,0.5,false,c\n1,2.5,true,a\n2,-1.25,false,b\n3,0.5,false,c\n", $ALL, []],
    'NULLs and quoted empty' => ["i,f,b,s\n,,,\n1,1.0,true,\"\"\n2,2.0,false,\n", $ALL, []],
    'keys in schema order' => ["s,b,f,i\nx,true,0.25,7\ny,false,1e2,8\n", $ALL, []],
    'int boundaries' => ["i\n9223372036854775807\n-9223372036854775808\n0\n-1\n", ['i' => 'int64'], []],
    'floats' => ["f\n1.0\n1e2\n-0.0\n.5\n5.\n+2.5\n1e-400\n-1e-400\n12345678901234567890\n", ['f' => 'float64'], []],
    'strings with quotes and newlines' => ["s\né\n日本語\n\"a,b\"\n\"say \"\"hi\"\"\"\n\"l1\nl2\"\n\"c1\r\nc2\"\n\"c1\rc2\"\n\"n\0n\"\n😀\n", ['s' => 'string'], []],
    'extra columns' => ["x,i,x,,s\nbad\xFF,1,\"q\",z,a\n,2,,\"\"\"\",b\n", ['i' => 'int64', 's' => 'string'], ['allowExtraColumns' => true]],
    'CRLF, no final terminator' => ["i,s\r\n1,a\r\n2,b\r\n3,c", ['i' => 'int64', 's' => 'string'], []],
    'one column with a blank line' => ["s\na\n\nb\n", ['s' => 'string'], []],
    'header only' => ["i,s\n", ['i' => 'int64', 's' => 'string'], []],
    'a semicolon file' => ["i;s\n1;a,b\n2;\"c;d\"\n", ['i' => 'int64', 's' => 'string'], ['delimiter' => ';']],
];
foreach ($fixtures as $label => [$csv, $S, $o]) {
    $want = bf_q($csv, $S, $o)->toArray();
    $n = count($want);
    $bad = [];
    for ($k = 1; $k <= $n + 1; $k++) {
        $cur = bf_q($csv, $S, $o)->cursor();
        $got = $sizes = [];
        do {
            $batch = $cur->fetch($k);
            $sizes[] = count($batch);
            array_push($got, ...$batch);
        } while (count($batch) === $k);
        // A batch shorter than $k reached end of input: the cursor is exhausted and answers [] from now on.
        $after = $cur->fetch($k);
        $short = array_slice($sizes, 0, -1) === array_fill(0, count($sizes) - 1, $k) && end($sizes) === $n % $k;
        if ($got !== $want || $after !== [] || !$short || array_sum($sizes) !== $n) {
            $bad[] = "k=$k sizes " . implode(',', $sizes);
        }
    }
    echo str_pad($label, 34), "$n rows, batch sizes 1..", $n + 1, ': ', $bad ? 'DIFFERS ' . implode(' | ', $bad) : 'equal', "\n";
}
echo "-- predicates pick the rows of every batch\n";
$csv = "i,s\n" . implode('', array_map(fn($i) => "$i," . ($i % 3 ? 'a' : 'b') . "\n", range(1, 50)));
$q = bf_q($csv, ['i' => 'int64', 's' => 'string'])->whereEq('s', 'a')->whereGt('i', 10);
$c = $q->cursor();
$got = [];
while ($b = $c->fetch(7)) {
    array_push($got, ...$b);
}
echo count($got), ' rows, equal to toArray: ', $got === $q->toArray() ? 'yes' : 'NO', "\n";

echo "-- the final batch and [] rules\n";
$q = bf_q("i\n1\n2\n3\n4\n", ['i' => 'int64']);
$c = $q->cursor();
foreach ([2, 2, 2, 2] as $k) {
    echo "fetch($k): ", bf_fmt_rows($c->fetch($k)), "\n";
}
$c = $q->cursor();
foreach ([3, 3, 3] as $k) {
    echo "fetch($k): ", bf_fmt_rows($c->fetch($k)), "\n";
}
echo "-- a fetch stops after its maxRows-th row: a later bad row waits for the next fetch\n";
$c = bf_q("i\n1\n2\nx\n", ['i' => 'int64'])->cursor();
echo 'fetch(2): ', bf_fmt_rows($c->fetch(2)), "\n";
echo 'fetch(2): ', bf_try(fn() => $c->fetch(2)), "\n";

echo "-- maxInputRows counts data rows across fetches\n";
$q = bf_q("i\n1\n2\n3\n4\n5\n6\n", ['i' => 'int64'], ['maxInputRows' => 5]);
echo 'toArray: ', bf_try(fn() => $q->toArray()), "\n";
$c = $q->cursor();
for ($k = 0; $k < 4; $k++) {
    echo 'fetch(2): ', str_replace("\n", ' | ', bf_try(fn() => bf_fmt_rows($c->fetch(2)))), "\n";
}
$c = bf_q("i\n1\n2\n3\n4\n5\n", ['i' => 'int64'], ['maxInputRows' => 5])->cursor();
echo 'exactly maxInputRows over fetch(2): ';
$n = 0;
while ($b = $c->fetch(2)) {
    $n += count($b);
}
echo "$n rows\n";

echo "-- the BOM is tested once, at the start of the first fetch; offsets continue\n";
$csv = "\xEF\xBB\xBFs\nab\n\xEF\xBB\xBFx\nz\n";
$want = bf_q($csv, ['s' => 'string'])->toArray();
$c = bf_q($csv, ['s' => 'string'])->cursor();
$got = [];
while ($b = $c->fetch(1)) {
    $got[] = $b[0];
}
echo bf_fmt_rows($got), "\n", 'equal to toArray: ', $got === $want ? 'yes' : 'NO', "\n";
$c = Csv::scanStream(bf_wrap_stream("\xEF\xBB\xBFs\nab\n\xEF\xBB\xBFx\n", 1), ['s' => 'string'])->cursor();
echo 'one byte per read: ', bf_fmt_rows([...$c->fetch(1), ...$c->fetch(1), ...$c->fetch(1)]), "\n";
$c = bf_q("\xEF\xBB\xBFi\n1\n2\nx\n", ['i' => 'int64'])->cursor();
$c->fetch(1);
$c->fetch(1);
echo 'error offset after the BOM: ', bf_try(fn() => $c->fetch(1)), "\n";
echo 'toArray gives the same: ', bf_try(fn() => bf_q("\xEF\xBB\xBFi\n1\n2\nx\n", ['i' => 'int64'])->toArray()), "\n";

echo "-- fetch maxRows range is a ValueError, raised before the state check\n";
$c = Csv::scan('/nonexistent/batchflow/none.csv', ['i' => 'int64'])->cursor();
foreach ([0, -1, 1000001, PHP_INT_MIN, PHP_INT_MAX] as $n) {
    echo str_pad("fetch($n)", 28), bf_try(fn() => $c->fetch($n)), "\n";
}
$c->close();
echo str_pad('closed, fetch(0)', 28), bf_try(fn() => $c->fetch(0)), "\n";
echo str_pad('closed, fetch(1)', 28), bf_try(fn() => $c->fetch(1)), "\n";
echo str_pad('fetch() default is 1000', 28), count(bf_q("i\n" . implode("\n", range(1, 1500)) . "\n", ['i' => 'int64'])->cursor()->fetch()), "\n";
echo str_pad('fetch(1000000) accepted', 28), count(bf_q("i\n1\n", ['i' => 'int64'])->cursor()->fetch(1000000)), "\n";
echo str_pad('named argument', 28), count(bf_q("i\n1\n2\n", ['i' => 'int64'])->cursor()->fetch(maxRows: 1)), "\n";

echo "-- the state table\n";
$path = bf_wrap_path(['segments' => [["i\n", 1], ["1\n", 5]], 'max' => 4]);
$q = Csv::scan($path, ['i' => 'int64']);
$c = $q->cursor();
echo 'cursor(): opens ', BfWrap::$opens, "\n";
$c->close();
echo 'new, close(): opens ', BfWrap::$opens, ', closes ', BfWrap::$closes, "\n";
echo 'closed, fetch(): ', bf_try(fn() => $c->fetch()), "\n";
$c->close();
echo "closed, close(): no effect\n";
$c = $q->cursor();
echo 'new, fetch(2): ', count($c->fetch(2)), ' rows, opens ', BfWrap::$opens, ', closes ', BfWrap::$closes, "\n";
echo 'open, fetch(2): ', count($c->fetch(2)), ' rows, opens ', BfWrap::$opens, "\n";
$c->close();
echo 'open, close(): closes ', BfWrap::$closes, "\n";
echo 'closed, fetch(): ', bf_try(fn() => $c->fetch()), "\n";
bf_wrap_path(BfWrap::$cfg);
$c = $q->cursor();
echo 'new, fetch(10): ', count($c->fetch(10)), ' rows, the run released: closes ', BfWrap::$closes, "\n";
$reads = BfWrap::$last->reads;
echo 'exhausted, fetch(): ', bf_fmt_rows($c->fetch()), ', reads unchanged: ', BfWrap::$last->reads === $reads ? 'yes' : 'NO', ', opens ', BfWrap::$opens, "\n";
$c->close();
echo 'exhausted, close(): ', bf_try(fn() => $c->fetch()), "\n";
bf_wrap_path(BfWrap::$cfg);
$c = $q->cursor();
echo 'new, fetch(5), exactly the rows left: ', count($c->fetch(5)), ' rows, closes ', BfWrap::$closes, "\n";
echo 'open, fetch(5) reaches end of input: ', bf_fmt_rows($c->fetch(5)), ', closes ', BfWrap::$closes, "\n";
echo 'exhausted, fetch(5): ', bf_fmt_rows($c->fetch(5)), "\n";
echo "-- two cursors of one Query are independent runs\n";
$q = bf_q("i\n1\n2\n3\n", ['i' => 'int64']);
$a = $q->cursor();
$b = $q->cursor();
echo bf_fmt_rows($a->fetch(1)), ' ', bf_fmt_rows($b->fetch(2)), ' ', bf_fmt_rows($a->fetch(1)), ' ', bf_fmt_rows($b->fetch(2)), "\n";

echo "-- cursor() on a nonexistent path does no I/O\n";
$c = Csv::scan('/nonexistent/batchflow/none.csv', ['i' => 'int64'])->cursor();
echo "cursor(): no exception\n";
echo 'first fetch: ', bf_try(fn() => $c->fetch()), "\n";
echo 'then: ', bf_try(fn() => $c->fetch()), "\n";
echo 'a cursor of a query with a bad literal cannot exist: ', bf_try(fn() => Csv::scan('/nonexistent', ['i' => 'int64'])->whereEq('i', 'x')->cursor()), "\n";
?>
--EXPECT--
-- class shape
final=true internal=true cloneable=false constructor private=true
public fetch(int $maxRows = 1000): array
public close(): void
Query::cursor(): BatchFlow\Cursor, 0 parameters
BatchFlow\Cursor
new: Error: Call to private BatchFlow\Cursor::__construct() from global scope
clone: Error: Trying to clone an uncloneable object of class BatchFlow\Cursor
serialize: Exception: Serialization of 'BatchFlow\Cursor' is not allowed
unserialize: Exception: Unserialization of 'BatchFlow\Cursor' is not allowed
newInstanceWithoutConstructor: ReflectionException: Class BatchFlow\Cursor is an internal class marked as final that cannot be instantiated without invoking its constructor
-- every batch size from 1 to the row count plus one, against toArray on the 090 fixtures
several rows                      4 rows, batch sizes 1..5: equal
NULLs and quoted empty            3 rows, batch sizes 1..4: equal
keys in schema order              2 rows, batch sizes 1..3: equal
int boundaries                    4 rows, batch sizes 1..5: equal
floats                            9 rows, batch sizes 1..10: equal
strings with quotes and newlines  9 rows, batch sizes 1..10: equal
extra columns                     2 rows, batch sizes 1..3: equal
CRLF, no final terminator         3 rows, batch sizes 1..4: equal
one column with a blank line      3 rows, batch sizes 1..4: equal
header only                       0 rows, batch sizes 1..1: equal
a semicolon file                  2 rows, batch sizes 1..3: equal
-- predicates pick the rows of every batch
27 rows, equal to toArray: yes
-- the final batch and [] rules
fetch(2): {i=int(1)}
{i=int(2)}
fetch(2): {i=int(3)}
{i=int(4)}
fetch(2): []
fetch(2): []
fetch(3): {i=int(1)}
{i=int(2)}
{i=int(3)}
fetch(3): {i=int(4)}
fetch(3): []
-- a fetch stops after its maxRows-th row: a later bad row waits for the next fetch
fetch(2): {i=int(1)}
{i=int(2)}
fetch(2): CsvException: row 3 (line 4, offset 6): column "i": expected int64, got malformed value
-- maxInputRows counts data rows across fetches
toArray: LimitException: row 6 (line 7, offset 12): input exceeds maxInputRows
fetch(2): {i=int(1)} | {i=int(2)}
fetch(2): {i=int(3)} | {i=int(4)}
fetch(2): LimitException: row 6 (line 7, offset 12): input exceeds maxInputRows
fetch(2): BatchFlowException: cursor is closed
exactly maxInputRows over fetch(2): 5 rows
-- the BOM is tested once, at the start of the first fetch; offsets continue
{s=s"ab"}
{s=s"﻿x"}
{s=s"z"}
equal to toArray: yes
one byte per read: {s=s"ab"}
{s=s"﻿x"}
error offset after the BOM: CsvException: row 3 (line 4, offset 9): column "i": expected int64, got malformed value
toArray gives the same: CsvException: row 3 (line 4, offset 9): column "i": expected int64, got malformed value
-- fetch maxRows range is a ValueError, raised before the state check
fetch(0)                    ValueError: BatchFlow\Cursor::fetch(): Argument #1 ($maxRows) must be between 1 and 1000000
fetch(-1)                   ValueError: BatchFlow\Cursor::fetch(): Argument #1 ($maxRows) must be between 1 and 1000000
fetch(1000001)              ValueError: BatchFlow\Cursor::fetch(): Argument #1 ($maxRows) must be between 1 and 1000000
fetch(-9223372036854775808) ValueError: BatchFlow\Cursor::fetch(): Argument #1 ($maxRows) must be between 1 and 1000000
fetch(9223372036854775807)  ValueError: BatchFlow\Cursor::fetch(): Argument #1 ($maxRows) must be between 1 and 1000000
closed, fetch(0)            ValueError: BatchFlow\Cursor::fetch(): Argument #1 ($maxRows) must be between 1 and 1000000
closed, fetch(1)            BatchFlowException: cursor is closed
fetch() default is 1000     1000
fetch(1000000) accepted     1
named argument              1
-- the state table
cursor(): opens 0
new, close(): opens 0, closes 0
closed, fetch(): BatchFlowException: cursor is closed
closed, close(): no effect
new, fetch(2): 2 rows, opens 1, closes 0
open, fetch(2): 2 rows, opens 1
open, close(): closes 1
closed, fetch(): BatchFlowException: cursor is closed
new, fetch(10): 5 rows, the run released: closes 1
exhausted, fetch(): [], reads unchanged: yes, opens 1
exhausted, close(): BatchFlowException: cursor is closed
new, fetch(5), exactly the rows left: 5 rows, closes 0
open, fetch(5) reaches end of input: [], closes 1
exhausted, fetch(5): []
-- two cursors of one Query are independent runs
{i=int(1)} {i=int(1)}
{i=int(2)} {i=int(2)} {i=int(3)}
-- cursor() on a nonexistent path does no I/O
cursor(): no exception
first fetch: StreamException: cannot open input
then: BatchFlowException: cursor is closed
a cursor of a query with a bad literal cannot exist: SchemaException: column "i": value of type string cannot be compared with int64 column
