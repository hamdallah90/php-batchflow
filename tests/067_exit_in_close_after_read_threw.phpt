--TEST--
batchflow: an exit() inside stream_close after a throwing read wins over the held read exception, which is released
--EXTENSIONS--
batchflow
--FILE--
<?php
require __DIR__ . '/bf_helper.inc';

use BatchFlow\Csv;

// The read exception is held while stream_close runs; close exits, and PHP never chains an exception onto
// an exit, so the held exception is released and the exit continues. A debug build would report a leak of
// the held exception on stderr.
$p = bf_wrap_path(['segments' => [["a\n1\n2\n", 1]], 'mode' => 'throw_read', 'exc' => new BfBoom('read'),
    'on_close' => function () { exit(0); }]);
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
shutdown: closes=1, user-space streams still open: 0
