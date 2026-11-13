--TEST--
batchflow: when the scan fits but the group result (groups * 272 + key allocation sizes) does not, LimitException "memory_limit would be exceeded" without row context
--EXTENSIONS--
batchflow
--SKIPIF--
<?php
// run-tests --asan disables the Zend allocator but sets USE_TRACKED_ALLOC=1, whose allocator enforces memory_limit;
// only the untracked system allocator does not.
if (getenv('USE_ZEND_ALLOC') === '0' && getenv('USE_TRACKED_ALLOC') !== '1') die('skip memory_limit needs the Zend or the tracked allocator');
?>
--INI--
memory_limit=10M
--FILE--
<?php
require __DIR__ . '/bf_helper.inc';

use BatchFlow\Csv;

/** Temp file with $n rows "i,1", written in small chunks. */
function ints(int $n): string
{
    $p = tempnam(sys_get_temp_dir(), 'batchflow_');
    $GLOBALS['__bf_tmp'][] = $p;
    $f = fopen($p, 'wb');
    fwrite($f, "k,v\n");
    for ($i = 0; $i < $n; $i += 100) {
        $b = '';
        for ($j = $i; $j < min($n, $i + 100); $j++) {
            $b .= "$j,1\n";
        }
        fwrite($f, $b);
    }
    fclose($f);
    return $p;
}

$I = ['k' => 'int64', 'v' => 'int64'];
// 40,000 groups: native state is a few MiB, but 40,000 * 272 = 10,880,000 bytes of result is above 10M.
$p = ints(40000);
echo 'memory_limit: ', ini_get('memory_limit'), "\n";
foreach (['groupCount' => fn() => Csv::scan($p, $I)->groupCount('k'), 'groupSum' => fn() => Csv::scan($p, $I)->groupSum('k', 'v')] as $name => $fn) {
    try {
        $fn();
        echo "$name: NO EXCEPTION\n";
    } catch (Throwable $e) {
        echo "$name: ", get_class($e), ': ', $e->getMessage(), "\n";
    }
}
echo "-- the refusal is repeatable and leaves the process usable\n";
try {
    Csv::scan($p, $I)->groupCount('k');
} catch (BatchFlow\LimitException $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
echo bf_fmt_groups(Csv::scan(bf_file("k,v\n1,1\n2,2\n"), $I)->groupSum('k', 'v')), "\n";
echo "-- the same file succeeds when the limit is raised, so only the result was too big\n";
ini_set('memory_limit', '128M');
$r = Csv::scan($p, $I)->groupCount('k');
echo count($r), ' groups; first ', bf_fmt_groups([$r[0]]), '; last ', bf_fmt_groups([$r[39999]]), "\n";
unset($r);

// 196,000 groups: 262,144 slots and 262,144 array entries, 8 MiB of native state. 196,000 * 272 = 53,312,000
// bytes is above 52M on its own, whatever else is in use; at 136 per group the estimate would fit.
echo "-- the 272 bytes per group: 196,000 int groups need 53.3 MB of result at 52M; native state is 8 MiB\n";
$p3 = ints(196000);
gc_mem_caches(); // chunks cached by the runs above would count as in use
ini_set('memory_limit', '52M');
try {
    Csv::scan($p3, $I)->groupCount('k');
    echo "NO EXCEPTION\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}

// 4,000 keys of 4,072 bytes in a 16 MiB arena. Each key's zend_string is 4,097 bytes, so ZendMM takes two
// 4 KiB pages for it and the result needs about 4,000 * (8,192 + 240) bytes: more than the 40M limit leaves.
// Charged at 4,072 bytes a key, the estimate fit and the build ended in PHP's memory fatal (SPEC 6.4).
echo "-- key allocation sizes count: 4,000 string keys of 4,072 bytes at 40M, two pages each\n";
$p4 = tempnam(sys_get_temp_dir(), 'batchflow_');
$GLOBALS['__bf_tmp'][] = $p4;
$f = fopen($p4, 'wb');
fwrite($f, "k,v\n");
for ($i = 0; $i < 4000; $i++) {
    fwrite($f, str_pad(dechex($i) . '-', 4072, 'x') . ",1\n");
}
fclose($f);
$SK = ['k' => 'string', 'v' => 'int64'];
gc_mem_caches();
ini_set('memory_limit', '40M');
try {
    Csv::scan($p4, $SK)->groupCount('k');
    echo "NO EXCEPTION\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
ini_set('memory_limit', '128M');
$r = Csv::scan($p4, $SK)->groupCount('k');
echo 'at 128M: ', count($r), ' groups, key lengths ', strlen($r[0][0]), '..', strlen($r[3999][0]), "\n";
unset($r);

// The scan peaks near 32 + 64 MiB while the arena doubles; the result then needs 64 MB of key strings on top
// of the 64 MiB arena, about 128 MiB in all. 120M leaves the scan 24 MiB for everything else.
echo "-- key bytes count: 64 string keys of 1,000,000 bytes; 64 MB of key strings on top of a 64 MiB arena at 120M\n";
$pk = tempnam(sys_get_temp_dir(), 'batchflow_');
$GLOBALS['__bf_tmp'][] = $pk;
$f = fopen($pk, 'wb');
fwrite($f, "k,v\n");
for ($i = 0; $i < 64; $i++) {
    fwrite($f, str_pad("key$i-", 1000000, 'x') . ",1\n");
}
fclose($f);
$SK = ['k' => 'string', 'v' => 'int64'];
gc_mem_caches();
ini_set('memory_limit', '120M');
try {
    Csv::scan($pk, $SK)->groupCount('k');
    echo "NO EXCEPTION\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
ini_set('memory_limit', '256M');
$r = Csv::scan($pk, $SK)->groupCount('k');
echo 'at 256M: ', count($r), ' groups, key lengths ', strlen($r[0][0]), '..', strlen($r[63][0]), "\n";
?>
--EXPECT--
memory_limit: 10M
groupCount: BatchFlow\LimitException: memory_limit would be exceeded
groupSum: BatchFlow\LimitException: memory_limit would be exceeded
-- the refusal is repeatable and leaves the process usable
BatchFlow\LimitException: memory_limit would be exceeded
[int(1),int(1),int(1)]; [int(2),int(1),int(2)]
-- the same file succeeds when the limit is raised, so only the result was too big
40000 groups; first [int(0),int(1)]; last [int(39999),int(1)]
-- the 272 bytes per group: 196,000 int groups need 53.3 MB of result at 52M; native state is 8 MiB
BatchFlow\LimitException: memory_limit would be exceeded
-- key allocation sizes count: 4,000 string keys of 4,072 bytes at 40M, two pages each
BatchFlow\LimitException: memory_limit would be exceeded
at 128M: 4000 groups, key lengths 4072..4072
-- key bytes count: 64 string keys of 1,000,000 bytes; 64 MB of key strings on top of a 64 MiB arena at 120M
BatchFlow\LimitException: memory_limit would be exceeded
at 256M: 64 groups, key lengths 1000000..1000000
