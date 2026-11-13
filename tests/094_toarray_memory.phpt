--TEST--
batchflow: toArray over a 1M-row file under memory_limit=64M raises a catchable LimitException "memory_limit would be exceeded", frees the partial rows and leaves the process usable
--EXTENSIONS--
batchflow
--SKIPIF--
<?php
// run-tests --asan disables the Zend allocator but sets USE_TRACKED_ALLOC=1, whose allocator enforces memory_limit;
// only the untracked system allocator does not.
if (getenv('USE_ZEND_ALLOC') === '0' && getenv('USE_TRACKED_ALLOC') !== '1') die('skip memory_limit needs the Zend or the tracked allocator');
?>
--INI--
memory_limit=64M
--FILE--
<?php
require __DIR__ . '/bf_helper.inc';

use BatchFlow\Csv;

register_shutdown_function(static function () {
    echo "shutdown function ran\n";
});

/** Temp file with $n rows "i,name,v", written in chunks; about 16 MB for 1,000,000 rows. */
function fixture(int $n): string
{
    $p = tempnam(sys_get_temp_dir(), 'batchflow_');
    $GLOBALS['__bf_tmp'][] = $p;
    $f = fopen($p, 'wb');
    fwrite($f, "id,name,v\n");
    for ($i = 0; $i < $n; $i += 1000) {
        $b = '';
        for ($j = $i; $j < min($n, $i + 1000); $j++) {
            $b .= "$j,name$j," . ($j % 977) . "\n";
        }
        fwrite($f, $b);
    }
    fclose($f);
    return $p;
}

$S = ['id' => 'int64', 'name' => 'string', 'v' => 'int64'];
$p = fixture(1000000);
echo 'fixture bytes: ', filesize($p), "\n";
echo 'memory_limit: ', ini_get('memory_limit'), "\n";
$base = memory_get_usage();

echo "-- 1,000,000 rows asked for, 64M available\n";
try {
    $r = Csv::scan($p, $S)->toArray(1000000);
    echo 'NO EXCEPTION, rows: ', count($r), "\n";
} catch (Throwable $e) {
    echo get_class($e), "\n";
    $ok = preg_match('/^row (\d+) \(line (\d+), offset (\d+)\): memory_limit would be exceeded$/', $e->getMessage(), $m);
    echo 'message has row context and the exact reason: ', $ok ? 'yes' : 'NO: ' . $e->getMessage(), "\n";
    if ($ok) {
        echo 'refused after some rows and well before the end: ', ($m[1] > 10000 && $m[1] < 1000000) ? 'yes' : "NO (row {$m[1]})", "\n";
        echo 'line is row + 1: ', (int)$m[2] === (int)$m[1] + 1 ? 'yes' : 'NO', "\n";
    }
}
unset($r, $e);
echo "-- the partial rows were freed\n";
echo 'back within 2 MiB of the baseline: ', memory_get_usage() < $base + 2097152 ? 'yes' : 'NO (' . (memory_get_usage() - $base) . ' bytes above)', "\n";
echo "-- the process is alive and a smaller request succeeds\n";
$r = Csv::scan(fixture(20000), $S)->toArray(20000);
echo 'rows: ', count($r), ' first: ', bf_fmt_rows([$r[0]]), ' last: ', bf_fmt_rows([$r[19999]]), "\n";
unset($r);
echo "-- the same over the default maxRows: ResultTooLargeException comes first and is cheap\n";
echo bf_try(fn() => Csv::scan($p, $S)->toArray()), "\n";
echo "-- a stream source behaves the same\n";
$f = fopen($p, 'rb');
try {
    Csv::scanStream($f, $S)->toArray(1000000);
    echo "NO EXCEPTION\n";
} catch (BatchFlow\LimitException $e) {
    echo get_class($e), ': ', preg_replace('/^row \d+ \(line \d+, offset \d+\): /', '', $e->getMessage()), "\n";
}
echo 'caller stream still open: ', is_resource($f) ? 'yes' : 'no', "\n";
unset($e);
echo "end of script\n";
?>
--EXPECT--
fixture bytes: 21665150
memory_limit: 64M
-- 1,000,000 rows asked for, 64M available
BatchFlow\LimitException
message has row context and the exact reason: yes
refused after some rows and well before the end: yes
line is row + 1: yes
-- the partial rows were freed
back within 2 MiB of the baseline: yes
-- the process is alive and a smaller request succeeds
rows: 20000 first: {id=int(0), name=s"name0", v=int(0)} last: {id=int(19999), name=s"name19999", v=int(459)}
-- the same over the default maxRows: ResultTooLargeException comes first and is cheap
ResultTooLargeException: row 10001 (line 10002, offset 176580): result exceeds maxRows (10000)
-- a stream source behaves the same
BatchFlow\LimitException: memory_limit would be exceeded
caller stream still open: yes
end of script
shutdown function ran
