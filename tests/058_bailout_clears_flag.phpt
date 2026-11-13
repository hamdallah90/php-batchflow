--TEST--
batchflow: a fatal error inside a stream callback unwinds through the run and the bailout clears the in-use flag on the caller's stream and frees the run
--EXTENSIONS--
batchflow
--FILE--
<?php
require __DIR__ . '/bf_helper.inc';

use BatchFlow\Csv;

// A compile error in the read callback is a genuine fatal: it longjmps through the run's zend_try.
$f = bf_wrap_stream("a\n1\n", 3, ['on_read' => function () {
    eval('function bf_declared_twice() {} function bf_declared_twice() {}');
}]);
register_shutdown_function(function () use ($f) {
    $w = [];
    set_error_handler(function ($no, $msg) use (&$w) {
        $w[] = $msg;
        return true;
    });
    $closed = fclose($f);
    restore_error_handler();
    echo "\nshutdown: fclose ", $closed ? 'succeeded' : 'refused', ', warnings: ', count($w), "\n";
    // The run's 256 KiB first reader buffer, its masks and the run struct are freed by the zend_catch path.
    $held = memory_get_usage() - $GLOBALS['before'];
    echo 'shutdown: run memory released: ', $held < 262144 ? 'yes' : "NO ($held bytes still held)", "\n";
});
$GLOBALS['before'] = memory_get_usage();
Csv::scanStream($f, ['a' => 'int64'])->count();
echo "not reached\n";
?>
--EXPECTF--
%AFatal error: Cannot redeclare %s in %s on line %d

shutdown: fclose succeeded, warnings: 0
shutdown: run memory released: yes
