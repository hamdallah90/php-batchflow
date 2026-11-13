--TEST--
batchflow: an execution timeout stops the run, the bailout clears the in-use flag and leaks nothing
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
require __DIR__ . '/bf_helper.inc';

use BatchFlow\Csv;

// An endless plain stream of one-column records: without the timeout check in the refill loop the
// native loop never returns to the VM. After the engine's timeout fatal, the shutdown function proves
// the run's bailout path cleared the in-use flag: a second fclose() would warn if it were still set.
$in = popen("yes 1 2>/dev/null", 'r');
register_shutdown_function(function () use ($in) {
    $w = [];
    set_error_handler(function ($no, $msg) use (&$w) {
        $w[] = $msg;
        return true;
    });
    $closed = @pclose($in);
    restore_error_handler();
    echo "\nshutdown: stream closable without a warning: ", $w === [] ? 'yes' : 'NO ' . json_encode($w), "\n";
    // The run's 256 KiB first reader buffer, its masks and the run struct are not held after the fatal.
    $held = memory_get_usage() - $GLOBALS['before'];
    echo 'shutdown: run memory released: ', $held < 262144 ? 'yes' : "NO ($held bytes still held)", "\n";
});
$q = Csv::scanStream($in, ['1' => 'int64']);
$GLOBALS['before'] = memory_get_usage();
set_time_limit(1);
try {
    $q->count();
} catch (Throwable $e) {
    // the run may raise BatchFlowException before the engine delivers its fatal; either order is fine
}
while (true) {
}   // the next interrupt check delivers the timeout fatal
echo "not reached\n";
?>
--EXPECTF--
%AFatal error: Maximum execution time of 1 second exceeded in %s on line %d

shutdown: stream closable without a warning: yes
shutdown: run memory released: yes
