--TEST--
batchflow: the row budget refusing inside a fetch raises LimitException with the row's context, keeps the earlier batches and closes the cursor; a retry needs a new cursor
--EXTENSIONS--
batchflow
--SKIPIF--
<?php
// run-tests --asan disables the Zend allocator but sets USE_TRACKED_ALLOC=1, whose allocator enforces memory_limit and
// counts memory_get_usage(); only the untracked system allocator does neither.
if (getenv('USE_ZEND_ALLOC') === '0' && getenv('USE_TRACKED_ALLOC') !== '1') die('skip memory_limit needs the Zend or the tracked allocator');
?>
--INI--
memory_limit=32M
--FILE--
<?php
require __DIR__ . '/bf_helper.inc';

use BatchFlow\Csv;

// 40 rows of two 700,000-byte fields: 1.4 MB each, so every row's estimate reaches the 1 MiB trigger on its own.
$F = str_repeat('x', 700000);
$p = tempnam(sys_get_temp_dir(), 'batchflow_');
$GLOBALS['__bf_tmp'][] = $p;
$f = fopen($p, 'wb');
fwrite($f, "a,b\n");
for ($i = 0; $i < 40; $i++) {
    fwrite($f, "$F,$F\n");
}
fclose($f);
unset($F);
$S = ['a' => 'string', 'b' => 'string'];

$c = Csv::scan($p, $S)->cursor();
$kept = 0;
foreach ([1, 2] as $k) {
    $b = $c->fetch($k);
    $kept += count($b);
    echo "fetch($k): ", count($b), " rows\n";
    unset($b);
}
$base = memory_get_usage();
try {
    $b = $c->fetch(100);
    echo 'NO EXCEPTION: ', count($b), " rows\n";
} catch (BatchFlow\LimitException $e) {
    $ok = preg_match('/^row (\d+) \(line (\d+), offset (\d+)\): memory_limit would be exceeded$/', $e->getMessage(), $m);
    echo get_class($e), ', row context and reason: ', $ok ? 'yes' : 'NO: ' . $e->getMessage(), "\n";
    if ($ok) {
        echo 'the refused row follows the kept ones: ', $m[1] > $kept + 1 && $m[1] <= 40 ? 'yes' : "NO (row {$m[1]})", ', line is row + 1: ', (int)$m[2] === $m[1] + 1 ? 'yes' : 'NO',
            ', offset is that row\'s: ', (int)$m[3] === 4 + ($m[1] - 1) * 1400002 ? 'yes' : "NO ({$m[3]})", "\n";
    }
}
unset($e);
echo 'the failed fetch freed its rows: ', memory_get_usage() < $base + 2097152 ? 'yes' : 'NO (' . (memory_get_usage() - $base) . ' above)', "\n";
echo 'then: ', bf_try(fn() => $c->fetch(1)), "\n";
echo 'a new cursor with smaller batches walks the whole file: ';
$c = Csv::scan($p, $S)->cursor();
$n = 0;
while ($b = $c->fetch(2)) {
    $n += count($b);
}
echo "$n rows\n";
?>
--EXPECT--
fetch(1): 1 rows
fetch(2): 2 rows
BatchFlow\LimitException, row context and reason: yes
the refused row follows the kept ones: yes, line is row + 1: yes, offset is that row's: yes
the failed fetch freed its rows: yes
then: BatchFlowException: cursor is closed
a new cursor with smaller batches walks the whole file: 40 rows
