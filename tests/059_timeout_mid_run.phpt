--TEST--
batchflow: an execution timeout reaching the refill loop raises the unprefixed BatchFlowException, then the engine's timeout fatal
--EXTENSIONS--
batchflow
--SKIPIF--
<?php
if (!function_exists('popen')) die('skip popen is not available');
if (!function_exists('set_time_limit')) die('skip set_time_limit is not available');
if (PHP_OS_FAMILY === 'Windows') die('skip needs the yes(1) utility');
?>
--FILE--
<?php
use BatchFlow\Csv;

// maxInputRows at PHP_INT_MAX: the only way out of the endless stream is the timeout check in the refill.
// The engine delivers its fatal while the BatchFlowException is pending, so PHP reports it as uncaught.
$in = popen("yes 1 2>/dev/null", 'r');
register_shutdown_function(function () {
    echo "\nshutdown reached\n";
});
$q = Csv::scanStream($in, ['1' => 'int64'], ['maxInputRows' => PHP_INT_MAX]);
set_time_limit(1);
try {
    $q->count();
    echo "not reached\n";
} catch (Throwable $e) {
    echo "not reached\n";
}
while (true) {
}
?>
--EXPECTF--
%AWarning: Uncaught BatchFlow\BatchFlowException: stopped by execution timeout in %s:%d
Stack trace:
#0 %s(%d): BatchFlow\Query->count()
#1 {main}
  thrown in %s on line %d

Fatal error: Maximum execution time of 1 second exceeded in %s on line %d

shutdown reached
