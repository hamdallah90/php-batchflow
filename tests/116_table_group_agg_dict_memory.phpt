--TEST--
batchflow: the groupAgg result preflight on a table charges the strings of dictionary keys, so a memory_limit sweep ends in a result or LimitException, never in PHP's fatal
--EXTENSIONS--
batchflow
--SKIPIF--
<?php
if (getenv('USE_ZEND_ALLOC') === '0' && getenv('USE_TRACKED_ALLOC') !== '1') die('skip needs memory_limit accounting');
?>
--INI--
memory_limit=128M
--FILE--
<?php
use BatchFlow\{LimitException, Table};

// SPEC 6.4, 6.6: a dictionary key is tag 3 and its id in the groupAgg table; the result still builds one PHP string per
// group, and the preflight counts it. 64 groups of 65,536-byte keys need about 4.3 MB of strings, against a few KiB for
// the rest of the result: a preflight without the key strings passes at limits where building them crosses the limit.
$p = tempnam(sys_get_temp_dir(), 'bf');
$b = "k,v\n";
for ($i = 1; $i <= 64; $i++) {
    $b .= sprintf('%02d', $i) . str_repeat('y', 65534) . ",$i\n";
}
file_put_contents($p, $b);
unset($b);
$t = Table::fromCsv($p, ['k' => 'string', 'v' => 'int64'], ['dictionary' => ['k']]);
unlink($p);
$q = $t->query();
echo "groups at 128M: ", count($q->groupAgg(['k'], [['sum', 'v']])), "\n";
gc_mem_caches();
$base = memory_get_usage(true);
$refused = $built = 0;
for ($extra = 256 * 1024; $extra <= 16 * 1048576; $extra += 128 * 1024) {
    ini_set('memory_limit', (string)($base + $extra));
    try {
        $r = $q->groupAgg(['k'], [['sum', 'v']]);
        $built++;
        unset($r);
    } catch (LimitException $e) {
        $refused++;
    }
    ini_set('memory_limit', '128M');
    gc_mem_caches();
}
echo "first limits refused: ", var_export($refused > 0, true), ", later limits built: ", var_export($built > 0, true), "\n";
?>
--EXPECT--
groups at 128M: 64
first limits refused: true, later limits built: true
