--TEST--
batchflow: 10,000 mixed successful and failing runs leave no memory behind
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
// Under ASan (run-tests --asan) the same file checks native leaks, and the memory_get_usage() comparison counts
// the tracked allocator's bytes.
require __DIR__ . '/bf_helper.inc';

use BatchFlow\Csv;

$S = ['a' => 'int64', 's' => 'string', 'f' => 'float64'];
$files = [
    'ok' => bf_file("a,s,f\n1,x,1.5\n2,y,2.5\n3,\"q\nr\",\n"),
    'badvalue' => bf_file("a,s,f\n1,x,1.5\nbad,y,2.5\n"),
    'badquote' => bf_file("a,s,f\n1,x\"y,1.5\n"),
    'utf8' => bf_file("a,s,f\n1,\xff,1.5\n"),
    'overflow' => bf_file("a,s,f\n9223372036854775807,x,1\n1,x,1\n"),
    'nohead' => bf_file(""),
    'badhead' => bf_file("a,a,f\n"),
    'big' => bf_file("a,s,f\n" . str_repeat("1,abcdefghijklmnopqrstuvwxyz,2.5\n", 3000)),
    'limit' => bf_file("a,s,f\n1,x,1\n2,y,2\n3,z,3\n"),
];
$scenarios = [
    fn() => Csv::scan($files['ok'], $S)->count(),
    fn() => Csv::scan($files['ok'], $S)->whereEq('s', 'x')->sum('a'),
    fn() => Csv::scan($files['ok'], $S)->whereGt('f', 1.0)->summary('f'),
    fn() => Csv::scan($files['badvalue'], $S)->sum('a'),
    fn() => Csv::scan($files['badquote'], $S)->count(),
    fn() => Csv::scan($files['utf8'], $S)->whereEq('s', 'x')->count(),
    fn() => Csv::scan($files['overflow'], $S)->sum('a'),
    fn() => Csv::scan($files['nohead'], $S)->count(),
    fn() => Csv::scan($files['badhead'], $S)->count(),
    fn() => Csv::scan($files['big'], $S)->whereEq('s', 'zz')->count(),
    fn() => Csv::scan($files['limit'], $S, ['maxInputRows' => 2])->count(),
    fn() => Csv::scan($files['limit'], $S, ['maxFieldBytes' => 1])->whereEq('s', 'x')->count(),
    fn() => Csv::scan($files['limit'], $S, ['maxRowBytes' => 3])->count(),
    fn() => Csv::scan('/nonexistent/batchflow/none.csv', $S)->count(),
    fn() => Csv::scanStream(bf_mem("a,s,f\n1,x,1.5\n"), $S)->count(),
    fn() => Csv::scanStream(bf_mem("a,s,f\n1,\"x\n"), $S)->count(),
    fn() => Csv::scanStream(bf_wrap_stream("a,s,f\n1,x,1.5\n2,y,2\n", 3), $S)->sum('f'),
    fn() => Csv::scanStream(bf_wrap_stream("a,s,f\n1,x,1.5\n", 3, ['mode' => 'false_read', 'after' => 8]), $S)->count(),
    fn() => Csv::scanStream(bf_wrap_stream("a,s,f\n1,x,1.5\n", 3, ['mode' => 'throw_read', 'after' => 8, 'exc' => new BfBoom('x')]), $S)->count(),
    fn() => Csv::scan(bf_wrap_path(['segments' => [["a,s,f\n1,x,1.5\n", 1]], 'throw_close' => new BfBoom('c')]), $S)->count(),
    fn() => Csv::scan('', $S),
    fn() => Csv::scan($files['ok'], []),
    fn() => Csv::scan($files['ok'], $S)->whereEq('zz', 1),
    fn() => Csv::scanStream(fopen('php://memory', 'r'), $S)->count(),
];
function run(array $scenarios, int $n, array &$tally): void
{
    $c = count($scenarios);
    for ($i = 0; $i < $n; $i++) {
        try {
            $scenarios[$i % $c]();
            $k = 'ok';
        } catch (Throwable $e) {
            $k = str_replace('BatchFlow\\', '', get_class($e));
        }
        $tally[$k] = ($tally[$k] ?? 0) + 1;
    }
}
$t = [];
run($scenarios, count($scenarios) * 4, $t);   // warm-up
gc_collect_cycles();
$t = [];
$before = memory_get_usage();
run($scenarios, 10000, $t);
gc_collect_cycles();
$growth = memory_get_usage() - $before;
ksort($t);
echo json_encode($t), "\n";
echo $growth < 65536 ? "memory returned to baseline\n" : "LEAK: grew by $growth bytes\n";
?>
--EXPECT--
{"BfBoom":832,"CsvException":2918,"LimitException":834,"SchemaException":832,"StreamException":833,"SumOverflowException":417,"ValueError":416,"ok":2918}
memory returned to baseline
