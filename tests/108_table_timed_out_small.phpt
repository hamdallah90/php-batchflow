--TEST--
batchflow: an execution timeout reaching a table scan raises the unprefixed BatchFlowException "stopped by execution timeout", then the engine's timeout fatal (below one check interval)
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
require __DIR__ . '/bf_table.inc';

use BatchFlow\Table;

// SPEC 6.4: in table scans EG(timed_out) is checked every 1,048,576 rows; if set the run raises BatchFlowException
// "stopped by execution timeout" (no prefix, 5.11) and lets the engine raise its timeout fatal.
// 500,000 rows are below the first check point at 1,048,576 rows: only the check at the end of the scan can raise the exception.
// one-second timer fires inside a native scan in all but a vanishing share of runs (a timer that fires between two scans
// is delivered by the VM as the plain engine fatal, without the Uncaught line below).
$p = tb_big_file("v\n", 500000, fn($i) => ($i % 1000) . "\n");
$t = Table::fromCsv($p, ['v' => 'int64']);
$q = $t->query();
for ($i = 0; $i < 64; $i++) {
    $q = $q->whereGt('v', -1);
}
echo "rows: ", $t->rowCount(), ", matching: ", $q->count(), "\n";
register_shutdown_function(function () {
    echo "\nshutdown reached\n";
});
set_time_limit(1);
try {
    while (true) {
        $q->count();
    }
} catch (Throwable $e) {
    echo "not reached\n";
}
?>
--EXPECTF--
rows: 500000, matching: 500000
%AWarning: Uncaught BatchFlow\BatchFlowException: stopped by execution timeout in %s:%d
Stack trace:
#0 %s(%d): BatchFlow\Query->count()
#1 {main}
  thrown in %s on line %d

Fatal error: Maximum execution time of 1 second exceeded in %s on line %d

shutdown reached
