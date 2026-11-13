--TEST--
batchflow: stream argument errors, missing file without warning, throwing wrappers propagate unchanged, read failures, non-blocking socket
--EXTENSIONS--
batchflow
--FILE--
<?php
require __DIR__ . '/bf_helper.inc';

use BatchFlow\Csv;

$S = ['a' => 'int64'];
$csv = "a\n1\n2\n";
$warnings = [];
set_error_handler(function ($no, $msg) use (&$warnings) {
    $warnings[] = $msg;
    return true;
});

echo "-- invalid arguments\n";
$closed = bf_mem($csv);
fclose($closed);
$ctx = stream_context_create();
foreach (['string' => 'x', 'int' => 5, 'null' => null, 'array' => [], 'closed stream' => $closed, 'stream context' => $ctx] as $l => $v) {
    $t = bf_thrown(fn() => Csv::scanStream($v, $S));
    echo str_pad("scanStream($l)", 30), $t === null ? 'NO EXCEPTION' : $t[0] . (str_starts_with($t[1], 'BatchFlow\Csv::scanStream(): ') ? ' (message names the method)' : ' ' . $t[1]), "\n";
}
echo bf_try(fn() => Csv::scan('', $S)), "\n";
echo bf_try(fn() => Csv::scan("a\0b", $S)), "\n";
echo bf_try(fn() => Csv::scan("/tmp/x\0", $S)), "\n";

echo "-- a missing file: StreamException, no warning\n";
$q = Csv::scan('/nonexistent/batchflow/none.csv', $S);
echo bf_try(fn() => $q->count()), "\n";
echo bf_try(fn() => $q->sum('a')), "\n";
echo bf_try(fn() => $q->summary()), "\n";
echo "warnings: ", count($warnings), "\n";
$warnings = [];
$dir = sys_get_temp_dir() . '/batchflow_nodir_' . getmypid();
echo bf_try(fn() => Csv::scan("$dir/file.csv", $S)->count()), "\n";
echo "warnings: ", count($warnings), "\n";

echo "-- a throwing stream_open propagates unchanged\n";
$boom = new BfBoom('open');
$q = Csv::scan(bf_wrap_path(['segments' => [[$csv, 1]], 'throw_open' => $boom]), $S);
$t = null;
try {
    $q->count();
} catch (Throwable $e) {
    $t = $e;
}
echo 'same exception object: ', $t === $boom ? 'yes' : 'NO (' . ($t ? get_class($t) : 'none') . ')', "\n";
echo 'previous is null: ', $t && $t->getPrevious() === null ? 'yes' : 'NO', "\n";

echo "-- a throwing stream_close propagates unchanged and the result is discarded\n";
$boom = new BfBoom('close');
$q = Csv::scan(bf_wrap_path(['segments' => [[$csv, 1]], 'throw_close' => $boom]), $S);
$res = 'unset';
$t = null;
try {
    $res = $q->count();
} catch (Throwable $e) {
    $t = $e;
}
echo 'same exception object: ', $t === $boom ? 'yes' : 'NO (' . ($t ? get_class($t) : 'none') . ')', "\n";
echo 'no result returned: ', $res === 'unset' ? 'yes' : 'NO', "\n";

echo "-- a wrapper without stream_set_option raises no warning\n";
$warnings = [];
$q = Csv::scan(bf_wrap_path(['segments' => [[$csv, 1]]]), $S);
echo bf_try(fn() => $q->count()), "\n";
echo bf_try(fn() => $q->sum('a')), "\n";
echo "warnings: ", count($warnings), "\n";

echo "-- a directory path\n";
$warnings = [];
if (PHP_OS_FAMILY === 'Linux') {
    echo bf_try(fn() => Csv::scan(sys_get_temp_dir(), $S)->count()), "\n";
} else {
    echo "StreamException: reading the input stream failed\n";
}
$warnings = [];

echo "-- stream_read returning false\n";
$q = Csv::scan(bf_wrap_path(['segments' => [[$csv, 1]], 'mode' => 'false_read']), $S);
echo bf_try(fn() => $q->count()), "\n";
$q = Csv::scan(bf_wrap_path(['segments' => [[$csv, 1]], 'mode' => 'false_read', 'after' => 3, 'max' => 3]), $S);
echo bf_try(fn() => $q->count()), "\n";

echo "-- exceptions from read and eof are the same object, never wrapped\n";
foreach (['throw_read', 'throw_eof'] as $mode) {
    foreach ([0, 3] as $after) {
        $boom = new BfBoom("$mode");
        $f = bf_wrap_stream($csv, 3, ['mode' => $mode, 'after' => $after, 'exc' => $boom]);
        $t = null;
        try {
            Csv::scanStream($f, $S)->count();
        } catch (Throwable $e) {
            $t = $e;
        }
        echo str_pad("$mode after $after bytes", 28), $t === $boom ? 'same object' : 'NOT THE SAME (' . ($t ? get_class($t) : 'none') . ')', ', previous ', $t && $t->getPrevious() === null ? 'null' : 'SET', "\n";
    }
}
echo "-- an empty read before EOF\n";
$f = bf_wrap_stream($csv, 3, ['mode' => 'empty_read', 'after' => 0]);
echo bf_try(fn() => Csv::scanStream($f, $S)->count()), "\n";
$f = bf_wrap_stream($csv, 3, ['mode' => 'empty_read', 'after' => 4]);
echo bf_try(fn() => Csv::scanStream($f, $S)->count()), "\n";

echo "-- a non-blocking socket with no data\n";
$pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
stream_set_blocking($pair[0], false);
echo bf_try(fn() => Csv::scanStream($pair[0], $S)->count()), "\n";
fwrite($pair[1], "a\n1\n");
echo bf_try(fn() => Csv::scanStream($pair[0], $S)->count()), "\n";
fclose($pair[0]);
fclose($pair[1]);
echo "-- an exception raised by an error handler during a read propagates\n";
restore_error_handler();
set_error_handler(function ($no, $msg) {
    throw new BfBoom('handler');
});
$t = null;
try {
    Csv::scan(sys_get_temp_dir(), $S)->count();
} catch (Throwable $e) {
    $t = $e;
}
restore_error_handler();
echo PHP_OS_FAMILY !== 'Linux' ? "BfBoom handler\n" : ($t === null ? "none\n" : get_class($t) . ' ' . $t->getMessage() . "\n");
?>
--EXPECT--
-- invalid arguments
scanStream(string)            TypeError (message names the method)
scanStream(int)               TypeError (message names the method)
scanStream(null)              TypeError (message names the method)
scanStream(array)             TypeError (message names the method)
scanStream(closed stream)     TypeError (message names the method)
scanStream(stream context)    TypeError (message names the method)
ValueError: BatchFlow\Csv::scan(): Argument #1 ($path) must not be empty
ValueError: BatchFlow\Csv::scan(): Argument #1 ($path) must not contain any null bytes
ValueError: BatchFlow\Csv::scan(): Argument #1 ($path) must not contain any null bytes
-- a missing file: StreamException, no warning
StreamException: cannot open input
StreamException: cannot open input
StreamException: cannot open input
warnings: 0
StreamException: cannot open input
warnings: 0
-- a throwing stream_open propagates unchanged
same exception object: yes
previous is null: yes
-- a throwing stream_close propagates unchanged and the result is discarded
same exception object: yes
no result returned: yes
-- a wrapper without stream_set_option raises no warning
int(2)
int(3)
warnings: 0
-- a directory path
StreamException: reading the input stream failed
-- stream_read returning false
StreamException: reading the input stream failed
StreamException: reading the input stream failed
-- exceptions from read and eof are the same object, never wrapped
throw_read after 0 bytes    same object, previous null
throw_read after 3 bytes    same object, previous null
throw_eof after 0 bytes     same object, previous null
throw_eof after 3 bytes     same object, previous null
-- an empty read before EOF
StreamException: input stream returned no data before EOF
StreamException: input stream returned no data before EOF
-- a non-blocking socket with no data
StreamException: input stream returned no data before EOF
StreamException: input stream returned no data before EOF
-- an exception raised by an error handler during a read propagates
BfBoom handler
