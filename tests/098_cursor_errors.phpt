--TEST--
batchflow: a failing fetch keeps the earlier batches, returns nothing, raises what toArray raises for that row (row, line and offset continued across fetches), then the cursor is closed; fetch and close() while a fetch runs, from a wrapper callback and from a fiber
--EXTENSIONS--
batchflow
--FILE--
<?php
require __DIR__ . '/bf_helper.inc';

use BatchFlow\Csv;

/** Fetches batches of $k until one fails or the cursor is exhausted; prints each fetch, then one fetch after. */
function walk(BatchFlow\Cursor $c, int $k): array
{
    $kept = [];
    for ($n = 1; ; $n++) {
        try {
            $b = $c->fetch($k);
        } catch (Throwable $e) {
            echo "  fetch $n: ", bf_cls($e), ': ', $e->getMessage(), "\n";
            break;
        }
        echo "  fetch $n: ", count($b), " rows\n";
        array_push($kept, ...$b);
        if (count($b) < $k) {
            break;
        }
    }
    echo '  then: ', bf_try(fn() => $c->fetch($k)), "\n";
    return $kept;
}

$S = ['i' => 'int64', 's' => 'string'];
$csv = "i,s\n1,a\n2,\"b\nc\"\n3,c\n4,d\n5,e\nx,f\n7,g\n";
echo "-- a bad row in the third batch of fetch(2), a quoted LF before it\n";
$kept = walk(bf_q($csv, $S)->cursor(), 2);
echo '  kept: ', str_replace("\n", ' | ', bf_fmt_rows($kept)), "\n";
echo '  toArray: ', bf_try(fn() => bf_q($csv, $S)->toArray()), "\n";
echo "-- the same through a stream served 3 bytes per read\n";
walk(Csv::scanStream(bf_wrap_stream($csv, 3), $S)->cursor(), 2);
echo "-- a later bad row is not reached by a full batch\n";
walk(bf_q($csv, $S)->cursor(), 5);
echo "-- framing, field count, UTF-8, maxFieldBytes and maxRowBytes errors as toArray gives them\n";
foreach ([
    'bare quote' => ["i,s\n1,a\n2,b\n3,c\"d\n", [], 1],
    'field count' => ["i,s\n1,a\n2,b\n3\n", [], 2],
    'invalid UTF-8' => ["i,s\n1,a\n2,b\n3,\xFF\n", [], 2],
    'maxFieldBytes' => ["i,s\n1,a\n2,b\n3,cccc\n", ['maxFieldBytes' => 3], 2],
    'maxRowBytes' => ["i,s\n1,a\n2,b\n3,cccccc\n", ['maxRowBytes' => 5], 1],
    'unterminated quote at EOF' => ["i,s\n1,a\n2,\"b", [], 1],
] as $label => [$data, $o, $k]) {
    $c = bf_q($data, $S, $o)->cursor();
    $out = [];
    for ($n = 0; $n < 4; $n++) {
        $out[] = str_replace("\n", ' | ', bf_try(fn() => bf_fmt_rows($c->fetch($k))));
    }
    $t = bf_try(fn() => bf_q($data, $S, $o)->toArray());
    echo str_pad($label, 26), implode(' || ', $out), "\n", str_pad('', 26), 'toArray: ', $t, "\n";
}
echo "-- a predicate that matches no row still validates every row\n";
$c = bf_q("i,s\n1,a\n2,b\nx,c\n", $S)->whereEq('s', 'zz')->cursor();
echo '  ', bf_try(fn() => $c->fetch(1)), "\n";
echo "-- a stream error in a later fetch\n";
$f = bf_wrap_stream("i,s\n1,a\n2,b\n3,c\n4,d\n", 4, ['mode' => 'throw_read', 'after' => 12, 'exc' => new BfBoom('read boom')]);
$c = Csv::scanStream($f, $S)->cursor();
echo '  fetch(1): ', bf_try(fn() => count($c->fetch(1))), "\n";
echo '  fetch(9): ', bf_try(fn() => count($c->fetch(9))), "\n";
echo '  then: ', bf_try(fn() => $c->fetch(1)), "\n";
$f = bf_wrap_stream("i,s\n1,a\n2,b\n3,c\n4,d\n", 4, ['mode' => 'false_read', 'after' => 12]);
$c = Csv::scanStream($f, $S)->cursor();
echo '  fetch(1): ', bf_try(fn() => count($c->fetch(1))), ', fetch(9): ', bf_try(fn() => count($c->fetch(9))), ', then: ', bf_try(fn() => $c->fetch(1)), "\n";

echo "-- fetch and close() from the running fetch's own wrapper callback\n";
$c = null;
$msgs = [];
$f = bf_wrap_stream("i\n1\n2\n3\n", 2, ['on_read' => function () use (&$c, &$msgs) {
    if ($c === null || $msgs) {
        return;
    }
    foreach (['fetch(0)' => fn() => $c->fetch(0), 'fetch()' => fn() => $c->fetch(), 'close()' => fn() => $c->close()] as $n => $fn) {
        try {
            $fn();
            $msgs[] = "$n: NO EXCEPTION";
        } catch (Throwable $e) {
            $msgs[] = "$n: " . bf_cls($e) . ': ' . $e->getMessage();
        }
    }
}]);
$c = Csv::scanStream($f, ['i' => 'int64'])->cursor();
echo '  running fetch(2): ', str_replace("\n", ' | ', bf_fmt_rows($c->fetch(2))), "\n";
echo '  ', implode("\n  ", $msgs), "\n";
echo '  the cursor is unchanged: ', bf_fmt_rows($c->fetch()), ', then ', bf_fmt_rows($c->fetch()), "\n";

echo "-- fetch and close() from another fiber while a fetch is suspended in a read\n";
$f = bf_wrap_stream("i\n1\n2\n3\n", 2, ['on_read' => function () {
    if (Fiber::getCurrent() !== null) {
        Fiber::suspend('suspended in a read');
    }
}]);
$c = Csv::scanStream($f, ['i' => 'int64'])->cursor();
$fiber = new Fiber(fn() => $c->fetch(10));
echo '  ', $fiber->start(), "\n";
echo '  fetch(): ', bf_try(fn() => $c->fetch()), "\n";
echo '  close(): ', bf_try(fn() => $c->close()), "\n";
echo '  fetch(0): ', bf_try(fn() => $c->fetch(0)), "\n";
while (!$fiber->isTerminated()) {
    $fiber->resume();
}
echo '  the suspended fetch completes: ', str_replace("\n", ' | ', bf_fmt_rows($fiber->getReturn())), "\n";
echo '  then exhausted: ', bf_fmt_rows($c->fetch()), "\n";
echo "-- two cursors in two fibers interleave on two streams\n";
$mk = fn(string $d) => Csv::scanStream(bf_wrap_stream($d, 2, ['on_read' => function () {
    if (Fiber::getCurrent() !== null) {
        Fiber::suspend();
    }
}]), ['i' => 'int64'])->cursor();
$a = $mk("i\n1\n2\n3\n");
$b = $mk("i\n7\n8\n");
$fa = new Fiber(fn() => [$a->fetch(2), $a->fetch(2)]);
$fb = new Fiber(fn() => [$b->fetch(1), $b->fetch(5)]);
$fa->start();
$fb->start();
while (!$fa->isTerminated() || !$fb->isTerminated()) {
    if (!$fa->isTerminated()) {
        $fa->resume();
    }
    if (!$fb->isTerminated()) {
        $fb->resume();
    }
}
echo '  ', json_encode($fa->getReturn()), ' ', json_encode($fb->getReturn()), "\n";
?>
--EXPECT--
-- a bad row in the third batch of fetch(2), a quoted LF before it
  fetch 1: 2 rows
  fetch 2: 2 rows
  fetch 3: CsvException: row 6 (line 8, offset 28): column "i": expected int64, got malformed value
  then: BatchFlowException: cursor is closed
  kept: {i=int(1), s=s"a"} | {i=int(2), s=s"b\nc"} | {i=int(3), s=s"c"} | {i=int(4), s=s"d"}
  toArray: CsvException: row 6 (line 8, offset 28): column "i": expected int64, got malformed value
-- the same through a stream served 3 bytes per read
  fetch 1: 2 rows
  fetch 2: 2 rows
  fetch 3: CsvException: row 6 (line 8, offset 28): column "i": expected int64, got malformed value
  then: BatchFlowException: cursor is closed
-- a later bad row is not reached by a full batch
  fetch 1: 5 rows
  fetch 2: CsvException: row 6 (line 8, offset 28): column "i": expected int64, got malformed value
  then: BatchFlowException: cursor is closed
-- framing, field count, UTF-8, maxFieldBytes and maxRowBytes errors as toArray gives them
bare quote                {i=int(1), s=s"a"} || {i=int(2), s=s"b"} || CsvException: row 3 (line 4, offset 12): bare quote in unquoted field || BatchFlowException: cursor is closed
                          toArray: CsvException: row 3 (line 4, offset 12): bare quote in unquoted field
field count               {i=int(1), s=s"a"} | {i=int(2), s=s"b"} || CsvException: row 3 (line 4, offset 12): expected 2 fields, got 1 || BatchFlowException: cursor is closed || BatchFlowException: cursor is closed
                          toArray: CsvException: row 3 (line 4, offset 12): expected 2 fields, got 1
invalid UTF-8             {i=int(1), s=s"a"} | {i=int(2), s=s"b"} || CsvException: row 3 (line 4, offset 12): column "s": invalid UTF-8 || BatchFlowException: cursor is closed || BatchFlowException: cursor is closed
                          toArray: CsvException: row 3 (line 4, offset 12): column "s": invalid UTF-8
maxFieldBytes             {i=int(1), s=s"a"} | {i=int(2), s=s"b"} || LimitException: row 3 (line 4, offset 12): column "s": field exceeds maxFieldBytes || BatchFlowException: cursor is closed || BatchFlowException: cursor is closed
                          toArray: LimitException: row 3 (line 4, offset 12): column "s": field exceeds maxFieldBytes
maxRowBytes               {i=int(1), s=s"a"} || {i=int(2), s=s"b"} || LimitException: row 3 (line 4, offset 12): row exceeds maxRowBytes || BatchFlowException: cursor is closed
                          toArray: LimitException: row 3 (line 4, offset 12): row exceeds maxRowBytes
unterminated quote at EOF {i=int(1), s=s"a"} || CsvException: row 2 (line 3, offset 8): unterminated quoted field || BatchFlowException: cursor is closed || BatchFlowException: cursor is closed
                          toArray: CsvException: row 2 (line 3, offset 8): unterminated quoted field
-- a predicate that matches no row still validates every row
  CsvException: row 3 (line 4, offset 12): column "i": expected int64, got malformed value
-- a stream error in a later fetch
  fetch(1): int(1)
  fetch(9): BfBoom: read boom
  then: BatchFlowException: cursor is closed
  fetch(1): int(1), fetch(9): StreamException: reading the input stream failed, then: BatchFlowException: cursor is closed
-- fetch and close() from the running fetch's own wrapper callback
  running fetch(2): {i=int(1)} | {i=int(2)}
  fetch(0): ValueError: BatchFlow\Cursor::fetch(): Argument #1 ($maxRows) must be between 1 and 1000000
  fetch(): BatchFlowException: a fetch on this cursor is already running
  close(): BatchFlowException: a fetch on this cursor is already running
  the cursor is unchanged: {i=int(3)}, then []
-- fetch and close() from another fiber while a fetch is suspended in a read
  suspended in a read
  fetch(): BatchFlowException: a fetch on this cursor is already running
  close(): BatchFlowException: a fetch on this cursor is already running
  fetch(0): ValueError: BatchFlow\Cursor::fetch(): Argument #1 ($maxRows) must be between 1 and 1000000
  the suspended fetch completes: {i=int(1)} | {i=int(2)} | {i=int(3)}
  then exhausted: []
-- two cursors in two fibers interleave on two streams
  [[{"i":1},{"i":2}],[{"i":3}]] [[{"i":7}],[{"i":8}]]
