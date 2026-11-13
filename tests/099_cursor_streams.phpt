--TEST--
batchflow: a stream cursor holds the in-use flag between fetches (other runs refused, fclose refused) and clears it on every release; a path cursor's stream_close runs on every release and its exception propagates; path cursors leave other runs alone; 10,000 mixed cursor lifecycles return memory to baseline
--EXTENSIONS--
batchflow
--SKIPIF--
<?php
// run-tests --asan disables the Zend allocator but sets USE_TRACKED_ALLOC=1, whose allocator counts memory_get_usage();
// only the untracked system allocator leaves it at 0.
if (getenv('USE_ZEND_ALLOC') === '0' && getenv('USE_TRACKED_ALLOC') !== '1') die('skip memory_get_usage() needs the Zend or the tracked allocator');
?>
--FILE--
<?php
require __DIR__ . '/bf_helper.inc';

use BatchFlow\Csv;

$S = ['i' => 'int64', 's' => 'string'];
$data = "i,s\n1,a\n2,b\n3,c\n";
function fclose_quiet($f): string
{
    $w = [];
    set_error_handler(function ($no, $msg) use (&$w) {
        $w[] = $msg;
        return true;
    });
    $ok = fclose($f);
    restore_error_handler();
    return ($ok ? 'closed' : 'refused') . ($w ? ', warning: ' . implode(' / ', $w) : ', no warning');
}
/** Rewinds the caller's stream and runs count() on it: works once the flag is clear. */
function rerun($f): string
{
    rewind($f);
    return bf_try(fn() => Csv::scanStream($f, ['i' => 'int64', 's' => 'string'])->count());
}

echo "-- between fetches a stream cursor holds the stream\n";
$f = bf_mem($data);
$q = Csv::scanStream($f, $S);
$c = $q->cursor();
echo 'fetch(1): ', bf_fmt_rows($c->fetch(1)), "\n";
echo 'a terminal of the same Query: ', bf_try(fn() => $q->count()), "\n";
echo 'another Query on the stream: ', bf_try(fn() => Csv::scanStream($f, $S)->toArray()), "\n";
echo "another cursor's first fetch: ", bf_try(fn() => $q->cursor()->fetch()), "\n";
echo "another cursor that never fetches: no error\n";
$q->cursor();
echo 'fclose(): ', fclose_quiet($f), "\n";
echo 'the open cursor continues: ', str_replace("\n", ' | ', bf_fmt_rows($c->fetch(5))), "\n";
echo 'after end of input: ', rerun($f), "\n";
rewind($f);
$c = Csv::scanStream($f, $S)->cursor();
$c->fetch(1);
$c->close();
echo 'after close(): ', rerun($f), "\n";
$g = bf_mem("i,s\n1,a\nx,b\n");
$c = Csv::scanStream($g, $S)->cursor();
$c->fetch(1);
echo 'failed fetch: ', bf_try(fn() => $c->fetch(1)), "\n";
echo 'after a failed fetch: ', rerun($g), "\n";
rewind($f);
$c = Csv::scanStream($f, $S)->cursor();
$c->fetch(1);
unset($c);
echo 'after unset of an open cursor: ', rerun($f), "\n";
echo 'fclose() now: ', fclose_quiet($f), "\n";
echo "-- a fetch finding its stream closed\n";
$f = bf_mem($data);
$c = Csv::scanStream($f, $S)->cursor();
fclose($f);
echo 'closed before the first fetch: ', bf_try(fn() => $c->fetch()), ', then ', bf_try(fn() => $c->fetch()), "\n";

echo "-- a path cursor's stream_close runs on every release\n";
$cfg = ['segments' => [[$data, 1]], 'max' => 4];
$path = bf_wrap_path($cfg);
$c = Csv::scan($path, $S)->cursor();
$c->fetch(1);
echo 'open: closes ', BfWrap::$closes, "\n";
$c->fetch(5);
echo 'end of input: closes ', BfWrap::$closes, "\n";
bf_wrap_path($cfg);
$c = Csv::scan($path, $S)->cursor();
$c->fetch(1);
$c->close();
echo 'close(): closes ', BfWrap::$closes, "\n";
bf_wrap_path(['segments' => [["i,s\n1,a\nx,b\n", 1]], 'max' => 4]);
$c = Csv::scan($path, $S)->cursor();
$c->fetch(1);
bf_try(fn() => $c->fetch(1));
echo 'failed fetch: closes ', BfWrap::$closes, "\n";
bf_wrap_path($cfg);
$c = Csv::scan($path, $S)->cursor();
$c->fetch(1);
unset($c);
echo 'unset: closes ', BfWrap::$closes, "\n";
bf_wrap_path($cfg);
$c = Csv::scan($path, $S)->cursor();
$c->close();
echo 'close() of a new cursor: opens ', BfWrap::$opens, ', closes ', BfWrap::$closes, "\n";

echo "-- its exception propagates, discarding that fetch's rows\n";
$boom = ['segments' => [[$data, 1]], 'max' => 4, 'throw_close' => new BfBoom('close boom')];
bf_wrap_path($boom);
$c = Csv::scan($path, $S)->cursor();
echo 'fetch(1): ', count($c->fetch(1)), "\n";
echo 'the fetch reaching end of input: ', bf_try(fn() => $c->fetch(5)), "\n";
echo 'then: ', bf_try(fn() => $c->fetch()), "\n";
bf_wrap_path($boom);
$c = Csv::scan($path, $S)->cursor();
$c->fetch(1);
echo 'close(): ', bf_try(fn() => $c->close()), "\n";
echo 'then: ', bf_try(fn() => $c->fetch()), ', close() again: ', bf_try(fn() => $c->close() ?? 'no effect'), "\n";
bf_wrap_path(['segments' => [["i,s\n1,a\nx,b\n", 1]], 'max' => 4, 'throw_close' => new BfBoom('close boom')]);
$c = Csv::scan($path, $S)->cursor();
$c->fetch(1);
try {
    $c->fetch(1);
} catch (Throwable $e) {
    echo 'a failed fetch: ', bf_cls($e), ': ', $e->getMessage(), ', previous ', $e->getPrevious() === null ? 'none' : get_class($e->getPrevious()), "\n";
}
bf_wrap_path($boom);
$c = Csv::scan($path, $S)->cursor();
$c->fetch(1);
try {
    unset($c);
    echo "unset: NO EXCEPTION\n";
} catch (Throwable $e) {
    echo 'unset: ', bf_cls($e), ': ', $e->getMessage(), "\n";
}
echo "-- stream_close's own calls cannot re-enter the release\n";
$c = null;
$seen = [];
$probe = function () use (&$c, &$seen) {
    foreach (['fetch()' => fn() => $c->fetch(), 'close()' => fn() => $c->close()] as $n => $fn) {
        try {
            $fn();
            $seen[] = "$n: returned";
        } catch (Throwable $e) {
            $seen[] = "$n: " . bf_cls($e) . ': ' . $e->getMessage();
        }
    }
};
bf_wrap_path(['segments' => [[$data, 1]], 'max' => 4, 'on_close' => $probe]);
$c = Csv::scan($path, $S)->cursor();
$c->fetch(1);
$c->close();
echo 'during close(): ', implode('; ', $seen), "\n";
$seen = [];
bf_wrap_path(['segments' => [[$data, 1]], 'max' => 4, 'on_close' => $probe]);
$c = Csv::scan($path, $S)->cursor();
$c->fetch(9);
echo 'during the fetch reaching end of input: ', implode('; ', $seen), "\n";

echo "-- a path cursor leaves other runs on the same path alone\n";
$p = bf_file($data);
$c = Csv::scan($p, $S)->cursor();
$c->fetch(1);
echo 'count(): ', Csv::scan($p, $S)->count(), ', another cursor: ', count(Csv::scan($p, $S)->cursor()->fetch(9)), ', the first continues: ',
    str_replace("\n", ' | ', bf_fmt_rows($c->fetch(9))), "\n";

echo "-- 10,000 mixed cursor lifecycles\n";
$p = bf_file("i,s\n1,a\n2,b\n3,c\n4,d\nx,e\n");
$m = bf_file("i,s\n1,a\n2,b\n");
$before = memory_get_usage();
for ($n = 0; $n < 10000; $n++) {
    $c = Csv::scan($n % 3 ? $p : $m, $S)->cursor();
    try {
        switch ($n % 6) {
            case 0: $c->close(); break;
            case 1: $c->fetch(2); $c->close(); break;
            case 2: while ($c->fetch(3)); break;
            case 3: $c->fetch(1); break;
            case 4: $c->fetch(2); $c->fetch(2); $c->fetch(2); break;
            case 5: $s = bf_mem("i,s\n1,a\n2,b\n"); $c = Csv::scanStream($s, $S)->cursor(); $c->fetch(1); break;
        }
    } catch (BatchFlow\CsvException $e) {
    }
    unset($c, $e, $s);
}
$growth = memory_get_usage() - $before;
echo $growth < 65536 ? "memory returned to baseline\n" : "LEAK: grew by $growth bytes\n";
?>
--EXPECTF--
-- between fetches a stream cursor holds the stream
fetch(1): {i=int(1), s=s"a"}
a terminal of the same Query: StreamException: a stream is already in use by another BatchFlow run or cannot be closed from PHP
another Query on the stream: StreamException: a stream is already in use by another BatchFlow run or cannot be closed from PHP
another cursor's first fetch: StreamException: a stream is already in use by another BatchFlow run or cannot be closed from PHP
another cursor that never fetches: no error
fclose(): refused, warning: fclose(): %s
the open cursor continues: {i=int(2), s=s"b"} | {i=int(3), s=s"c"}
after end of input: int(3)
after close(): int(3)
failed fetch: CsvException: row 2 (line 3, offset 8): column "i": expected int64, got malformed value
after a failed fetch: int(2)
after unset of an open cursor: int(3)
fclose() now: closed, no warning
-- a fetch finding its stream closed
closed before the first fetch: StreamException: a stream was closed before the run started, then BatchFlowException: cursor is closed
-- a path cursor's stream_close runs on every release
open: closes 0
end of input: closes 1
close(): closes 1
failed fetch: closes 1
unset: closes 1
close() of a new cursor: opens 0, closes 0
-- its exception propagates, discarding that fetch's rows
fetch(1): 1
the fetch reaching end of input: BfBoom: close boom
then: BatchFlowException: cursor is closed
close(): BfBoom: close boom
then: BatchFlowException: cursor is closed, close() again: no effect
a failed fetch: BfBoom: close boom, previous none
unset: BfBoom: close boom
-- stream_close's own calls cannot re-enter the release
during close(): fetch(): BatchFlowException: cursor is closed; close(): returned
during the fetch reaching end of input: fetch(): BatchFlowException: a fetch on this cursor is already running; close(): BatchFlowException: a fetch on this cursor is already running
-- a path cursor leaves other runs on the same path alone
count(): 3, another cursor: 3, the first continues: {i=int(2), s=s"b"} | {i=int(3), s=s"c"}
-- 10,000 mixed cursor lifecycles
memory returned to baseline
