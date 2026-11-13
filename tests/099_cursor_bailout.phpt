--TEST--
batchflow: a fatal error inside a stream callback during a later fetch unwinds through the cursor's run: the bailout clears the in-use flag, frees the run and closes the cursor
--EXTENSIONS--
batchflow
--FILE--
<?php
require __DIR__ . '/bf_helper.inc';

use BatchFlow\Csv;

// A compile error in the read callback is a genuine fatal: it longjmps through the fetch's zend_try.
$GLOBALS['boom'] = false;
$f = bf_wrap_stream("a\n1\n2\n3\n4\n", 2, ['on_read' => function () {
    if ($GLOBALS['boom']) {
        eval('function bf_declared_twice() {} function bf_declared_twice() {}');
    }
}]);
$c = Csv::scanStream($f, ['a' => 'int64'])->cursor();
register_shutdown_function(function () use ($f, $c) {
    $w = [];
    set_error_handler(function ($no, $msg) use (&$w) {
        $w[] = $msg;
        return true;
    });
    $closed = fclose($f);
    restore_error_handler();
    echo "\nshutdown: fclose ", $closed ? 'succeeded' : 'refused', ', warnings: ', count($w), "\n";
    try {
        $c->fetch();
        echo "shutdown: cursor NOT CLOSED\n";
    } catch (Throwable $e) {
        echo 'shutdown: ', get_class($e), ': ', $e->getMessage(), "\n";
    }
    unset($e);
    // The run's 256 KiB reader buffer, its masks, the template row and the run struct are freed by the zend_catch path.
    $held = memory_get_usage() - $GLOBALS['before'];
    echo 'shutdown: run memory released: ', $held < 262144 ? 'yes' : "NO ($held bytes still held)", "\n";
});
$GLOBALS['before'] = memory_get_usage();
echo 'fetch(1): ', count($c->fetch(1)), "\n";
$GLOBALS['boom'] = true;
$c->fetch(3);
echo "not reached\n";
?>
--EXPECTF--
fetch(1): 1
%AFatal error: Cannot redeclare %s in %s on line %d

shutdown: fclose succeeded, warnings: 0
shutdown: BatchFlow\BatchFlowException: cursor is closed
shutdown: run memory released: yes
