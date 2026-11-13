--TEST--
batchflow: an execution timeout reaching a fetch's refill loop raises the unprefixed BatchFlowException and closes the cursor, then the engine's timeout fatal
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

// An endless stream whose rows never match: the fetch never fills its batch, so only the timeout check ends it.
$in = popen("yes 1 2>/dev/null", 'r');
$c = Csv::scanStream($in, ['1' => 'int64'], ['maxInputRows' => PHP_INT_MAX])->whereEq('1', 2)->cursor();
register_shutdown_function(function () use ($c) {
    try {
        $c->fetch();
        echo "\nshutdown: NOT CLOSED\n";
    } catch (Throwable $e) {
        echo "\nshutdown: ", get_class($e), ': ', $e->getMessage(), "\n";
    }
});
set_time_limit(1);
try {
    $c->fetch();
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
#0 %s(%d): BatchFlow\Cursor->fetch()
#1 {main}
  thrown in %s on line %d

Fatal error: Maximum execution time of 1 second exceeded in %s on line %d

shutdown: BatchFlow\BatchFlowException: cursor is closed
