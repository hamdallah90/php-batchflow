--TEST--
batchflow: an execution timeout reaching the rows loop of a table (toArray) raises "stopped by execution timeout" from the check at the end of the scan
--EXTENSIONS--
batchflow
--SKIPIF--
<?php
if (!function_exists('set_time_limit')) die('skip set_time_limit is not available');
?>
--CONFLICTS--
all
--INI--
memory_limit=-1
--FILE--
<?php
use BatchFlow\Table;

// SPEC 6.4: the rows loop of a table checks the timeout every 1,048,576 rows and once at the end of the scan.
// 500,000 rows stay below the first check point, so only the end check can raise the exception. No row matches
// (the last of the 64 predicates fails), so toArray scans every row and builds nothing; the timer fires inside
// a native scan in all but a vanishing share of runs.
$p = tempnam(sys_get_temp_dir(), 'bf');
$b = "v\n";
for ($i = 1; $i <= 500000; $i++) {
    $b .= ($i % 1000) . "\n";
}
file_put_contents($p, $b);
unset($b);
$t = Table::fromCsv($p, ['v' => 'int64']);
unlink($p);
$q = $t->query();
for ($i = 0; $i < 63; $i++) {
    $q = $q->whereGt('v', -1);
}
$q = $q->whereLt('v', -1);
echo "rows: ", $t->rowCount(), ", matching: ", count($q->toArray()), "\n";
register_shutdown_function(function () {
    echo "\nshutdown reached\n";
});
set_time_limit(1);
try {
    while (true) {
        $q->toArray();
    }
} catch (Throwable $e) {
    echo "not reached\n";
}
?>
--EXPECTF--
rows: 500000, matching: 0
%AWarning: Uncaught BatchFlow\BatchFlowException: stopped by execution timeout in %s:%d
Stack trace:
#0 %s(%d): BatchFlow\Query->toArray()
#1 {main}
  thrown in %s on line %d

Fatal error: Maximum execution time of 1 second exceeded in %s on line %d

shutdown reached
