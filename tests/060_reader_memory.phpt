--TEST--
batchflow: a row above a low memory_limit raises LimitException "memory_limit would be exceeded" instead of a fatal; 2M and 3M limits work on small input
--EXTENSIONS--
batchflow
--SKIPIF--
<?php
// The expected output depends on the Zend allocator's accounting of chunks and pages; run-tests --asan replaces it
// with the tracked allocator, which enforces memory_limit but counts differently.
if (getenv('USE_ZEND_ALLOC') === '0') die('skip needs the Zend allocator');
?>
--INI--
memory_limit=2M
--FILE--
<?php
require __DIR__ . '/bf_helper.inc';

use BatchFlow\Csv;

$S = ['a' => 'int64', 's' => 'string'];
$small = "a,s\n1,x\n2,y\n3,x\n";
$q = Csv::scan(bf_file($small), $S);
echo "-- memory_limit 2M, small input\n";
echo ini_get('memory_limit'), ' count: ', bf_try(fn() => $q->count()), "\n";
echo 'sum: ', bf_try(fn() => $q->sum('a')), ', where: ', bf_try(fn() => $q->whereEq('s', 'x')->count()), "\n";
echo 'summary: ', bf_try(fn() => $q->summary('a')), "\n";

echo "-- memory_limit 3M, small input\n";
ini_set('memory_limit', '3M');
echo ini_get('memory_limit'), ' count: ', bf_try(fn() => $q->count()), "\n";
echo 'sum: ', bf_try(fn() => $q->sum('a')), "\n";
ini_set('memory_limit', '2M');

echo "-- a lazy row far above the limit: LimitException, not a fatal\n";
// The reader buffer would have to grow past memory_limit before the default maxRowBytes (16 MiB) trips.
BfWrap::$cfg = ['segments' => [["a,s\n1,", 1], ['x', 64 * 1048576], ["\n", 1]], 'max' => 65536];
echo bf_try(fn() => Csv::scan('bfwrap://in', $S)->whereEq('s', 'q')->count()), "\n";
echo "-- the same in the header\n";
BfWrap::$cfg = ['segments' => [['a,', 1], ['h', 64 * 1048576], ["\n1,x\n", 1]], 'max' => 65536];
echo bf_try(fn() => Csv::scan('bfwrap://in', $S)->count()), "\n";
echo "-- the process is still alive and usable\n";
echo bf_try(fn() => $q->count()), "\n";
echo 'memory_limit untouched: ', ini_get('memory_limit'), "\n";
?>
--EXPECT--
-- memory_limit 2M, small input
2M count: int(3)
sum: int(6), where: int(2)
summary: Summary{scanned=3;matched=3;sum=int(6)}
-- memory_limit 3M, small input
3M count: int(3)
sum: int(6)
-- a lazy row far above the limit: LimitException, not a fatal
LimitException: row 1 (line 2, offset 4): memory_limit would be exceeded
-- the same in the header
LimitException: header (line 1, offset 0): memory_limit would be exceeded
-- the process is still alive and usable
int(3)
memory_limit untouched: 2M
