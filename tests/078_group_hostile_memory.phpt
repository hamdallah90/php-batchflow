--TEST--
batchflow: hostile cardinality under a low memory_limit ends in a catchable LimitException, shutdown functions run and the process exits normally
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

use BatchFlow\Csv;

// A hard-limit _exit(137) would skip this and the rest of the output.
register_shutdown_function(static function () {
    echo "shutdown function ran\n";
});

/** Temp file written row by row so the test itself stays far below the limit. */
function big(int $n, callable $row): string
{
    $p = tempnam(sys_get_temp_dir(), 'batchflow_');
    $GLOBALS['__bf_tmp'][] = $p;
    $f = fopen($p, 'wb');
    fwrite($f, "k,v\n");
    for ($i = 0; $i < $n; $i += 100) {
        $b = '';
        for ($j = $i; $j < min($n, $i + 100); $j++) {
            $b .= $row($j);
        }
        fwrite($f, $b);
    }
    fclose($f);
    return $p;
}

/** Class and reason with the optional row context removed: whether the scan or only the result was refused is not specified. */
function reason(Throwable $e): string
{
    return get_class($e) . ': ' . preg_replace('/^row \d+ \(line \d+, offset \d+\): /', '', $e->getMessage());
}

echo 'memory_limit: ', ini_get('memory_limit'), "\n";
$base = memory_get_usage();
$S = ['k' => 'string', 'v' => 'int64'];

echo "-- 100,000 distinct string keys\n";
$p = big(100000, fn($j) => "key$j,1\n");
foreach (['groupCount' => fn() => Csv::scan($p, $S)->groupCount('k'), 'groupSum' => fn() => Csv::scan($p, $S)->groupSum('k', 'v')] as $name => $fn) {
    try {
        $fn();
        echo "$name: NO EXCEPTION\n";
    } catch (Throwable $e) {
        echo "$name: ", reason($e), "\n";
    }
}
echo "-- 100,000 distinct int keys\n";
$pi = big(100000, fn($j) => "$j,1\n");
$I = ['k' => 'int64', 'v' => 'int64'];
foreach (['groupCount' => fn() => Csv::scan($pi, $I)->groupCount('k'), 'groupSum' => fn() => Csv::scan($pi, $I)->groupSum('k', 'v')] as $name => $fn) {
    try {
        $fn();
        echo "$name: NO EXCEPTION\n";
    } catch (Throwable $e) {
        echo "$name: ", reason($e), "\n";
    }
}
echo "-- 5,000 distinct 4 KiB keys (about 20 MB of keys)\n";
$pw = big(5000, fn($j) => str_pad("w$j", 4096, 'x') . ",1\n");
try {
    Csv::scan($pw, $S)->groupCount('k');
    echo "NO EXCEPTION\n";
} catch (Throwable $e) {
    echo reason($e), "\n";
}

echo "-- the exception is catchable as LimitException and as BatchFlowException\n";
try {
    Csv::scan($pi, $I)->groupCount('k');
} catch (BatchFlow\LimitException $e) {
    echo get_class($e), ' is a ', get_parent_class($e), "\n";
}
echo "-- memory is released after the refusals\n";
echo 'back near baseline: ', memory_get_usage() < $base + 1048576 ? 'yes' : 'NO (' . (memory_get_usage() - $base) . ' bytes above)', "\n";
echo "-- the process is still usable: a small group-by works\n";
echo bf_fmt_groups(Csv::scan(bf_file("k,v\na,1\nb,2\na,3\n"), $S)->groupSum('k', 'v')), "\n";
echo "-- a refused group-state growth names the row whose new key needed it\n";
// Odd rows carry a new key, even rows repeat -1, so group state grows only on odd rows. At 4M the slots or
// group arrays are refused long before the 500,000th key; the reader (256 KiB) never grows on these rows.
$pa = big(1000000, fn($j) => ($j % 2 === 0 ? (string)($j / 2 + 1) : '-1') . ",1\n");
ini_set('memory_limit', '4M');
try {
    Csv::scan($pa, $I)->groupCount('k');
    echo "NO EXCEPTION\n";
} catch (BatchFlow\LimitException $e) {
    $m = $e->getMessage();
}
ini_set('memory_limit', '16M');
if (preg_match('/^row (\d+) \(line (\d+), offset (\d+)\): memory_limit would be exceeded$/', $m, $x)) {
    [$row, $line, $off] = [(int)$x[1], (int)$x[2], (int)$x[3]];
    $want = 4; // "k,v\n" written by big(), then one row per $j
    for ($j = 0; $j < $row - 1; $j++) {
        $want += $j % 2 === 0 ? strlen((string)($j / 2 + 1)) + 3 : 5;
    }
    echo 'row carries a new key (odd): ', $row % 2 === 1 ? 'yes' : "NO ($row)", ', line = row + 1: ', $line === $row + 1 ? 'yes' : 'NO',
        ', offset is that row\'s first byte: ', $off === $want ? 'yes' : "NO ($off, want $want)", ', row past 1000: ', $row > 1000 ? 'yes' : 'NO', "\n";
} else {
    echo 'UNEXPECTED: ', $m ?? 'no message', "\n";
}
echo "-- and 100,000 groups succeed once memory allows\n";
ini_set('memory_limit', '256M');
echo count(Csv::scan($pi, $I)->groupCount('k')), "\n";
echo "end of script\n";
?>
--EXPECT--
memory_limit: 16M
-- 100,000 distinct string keys
groupCount: BatchFlow\LimitException: memory_limit would be exceeded
groupSum: BatchFlow\LimitException: memory_limit would be exceeded
-- 100,000 distinct int keys
groupCount: BatchFlow\LimitException: memory_limit would be exceeded
groupSum: BatchFlow\LimitException: memory_limit would be exceeded
-- 5,000 distinct 4 KiB keys (about 20 MB of keys)
BatchFlow\LimitException: memory_limit would be exceeded
-- the exception is catchable as LimitException and as BatchFlowException
BatchFlow\LimitException is a BatchFlow\BatchFlowException
-- memory is released after the refusals
back near baseline: yes
-- the process is still usable: a small group-by works
[s"a",int(2),int(4)]; [s"b",int(1),int(2)]
-- a refused group-state growth names the row whose new key needed it
row carries a new key (odd): yes, line = row + 1: yes, offset is that row's first byte: yes, row past 1000: yes
-- and 100,000 groups succeed once memory allows
100000
end of script
shutdown function ran
