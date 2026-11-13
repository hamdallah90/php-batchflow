--TEST--
batchflow: exit() inside stream_read with a throwing stream_close stays an exit: close does not run, so its exception never replaces the exit
--EXTENSIONS--
batchflow
--FILE--
<?php
require __DIR__ . '/bf_helper.inc';

use BatchFlow\Csv;

// The run returns with UnwindExit pending. PHP runs no user code while an exit unwinds, so the wrapper's
// stream_close is not called; the run still closes the stream natively and drops its resource. Under a
// debug build a leaked allocation is reported on stderr at shutdown and breaks the exact expectation.
$p = bf_wrap_path(['segments' => [["a\n1\n2\n", 1]], 'on_read' => function () { exit(0); }, 'throw_close' => new BfBoom('close')]);
register_shutdown_function(function () {
    $open = 0;
    foreach (get_resources('stream') as $r) {
        $open += stream_get_meta_data($r)['wrapper_type'] === 'user-space';
    }
    echo 'shutdown: closes=', BfWrap::$closes, ', user-space streams still open: ', $open, "\n";
});
echo "before the run\n";
try {
    Csv::scan($p, ['a' => 'int64'])->count();
    echo "NOT REACHED: no exit\n";
} catch (Throwable $e) {
    echo 'NOT REACHED: caught ', get_class($e), "\n";
}
echo "NOT REACHED: after the run\n";
?>
--EXPECT--
before the run
shutdown: closes=0, user-space streams still open: 0
