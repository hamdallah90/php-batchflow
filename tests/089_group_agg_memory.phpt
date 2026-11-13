--TEST--
batchflow: groupAgg under a low memory_limit: 100k composite groups at 16M end in a catchable exception and the process exits normally; when the scan fits but the result estimate (SPEC 6.3) does not, LimitException without row context
--EXTENSIONS--
batchflow
--SKIPIF--
<?php
// run-tests --asan disables the Zend allocator but sets USE_TRACKED_ALLOC=1, whose allocator enforces memory_limit;
// only the untracked system allocator does not.
if (getenv('USE_ZEND_ALLOC') === '0' && getenv('USE_TRACKED_ALLOC') !== '1') die('skip memory_limit needs the Zend or the tracked allocator');
?>
--INI--
memory_limit=16M
--FILE--
<?php
require __DIR__ . '/bf_helper.inc';
require __DIR__ . '/bf_reference_m2b.inc';

use BatchFlow\Csv;

register_shutdown_function(static function () {
    echo "shutdown function ran\n";
});

/** Temp file of $n rows "s<i>,<i>,1" written in small chunks, so the test stays far below the limit. */
function rows(int $n): string
{
    $p = tempnam(sys_get_temp_dir(), 'batchflow_');
    $GLOBALS['__bf_tmp'][] = $p;
    $f = fopen($p, 'wb');
    fwrite($f, "a,b,v\n");
    for ($i = 0; $i < $n; $i += 100) {
        $b = '';
        for ($j = $i; $j < min($n, $i + 100); $j++) {
            $b .= "s$j,$j,1\n";
        }
        fwrite($f, $b);
    }
    fclose($f);
    return $p;
}

$S = ['a' => 'string', 'b' => 'int64', 'v' => 'int64'];
echo "-- 100,000 composite groups at memory_limit=16M\n";
$p = rows(100000);
foreach ([[['a', 'b'], []], [['a', 'b'], array_fill(0, 16, ['sum', 'v'])], [['b', 'a', 'v'], [['avg', 'v'], ['max', 'b']]]] as [$keys, $aggs]) {
    try {
        Csv::scan($p, $S)->groupAgg($keys, $aggs);
        echo "NO EXCEPTION\n";
    } catch (BatchFlow\LimitException $e) {
        // Refused during the scan (with the row prefix) or by the result preflight (without): both are catchable.
        echo get_class($e), ': ', preg_replace('/^row \d+ \(line \d+, offset \d+\): /', '', $e->getMessage()), "\n";
    }
}
echo "-- the process is usable afterwards\n";
echo bf_fmt_lists(Csv::scan(bf_file("a,b,v\nx,1,1\nx,1,2\n"), $S)->groupAgg(['a', 'b'], [['sum', 'v']])), "\n";

echo "-- the scan fits, the result does not: 40,000 groups * 336 bytes (two keys, P = 8) is above 10M\n";
ini_set('memory_limit', '64M');
$p = rows(40000);
gc_mem_caches();
ini_set('memory_limit', '10M');
try {
    Csv::scan($p, $S)->groupAgg(['b', 'v'], []);
    echo "NO EXCEPTION\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
echo "-- the same file succeeds with the limit raised, so only the result was too big\n";
ini_set('memory_limit', '128M');
$r = Csv::scan($p, $S)->groupAgg(['b', 'v'], []);
echo count($r), ' groups; last ', bf_fmt_list($r[39999]), "\n";
?>
--EXPECT--
-- 100,000 composite groups at memory_limit=16M
BatchFlow\LimitException: memory_limit would be exceeded
BatchFlow\LimitException: memory_limit would be exceeded
BatchFlow\LimitException: memory_limit would be exceeded
-- the process is usable afterwards
[s"x",int(1),int(2),int(3)]
-- the scan fits, the result does not: 40,000 groups * 336 bytes (two keys, P = 8) is above 10M
BatchFlow\LimitException: memory_limit would be exceeded
-- the same file succeeds with the limit raised, so only the result was too big
40000 groups; last [int(39999),int(1),int(1)]
shutdown function ran
