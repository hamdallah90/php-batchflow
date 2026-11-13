--TEST--
batchflow: a load with a 200,000-entry dictionary, under a memory_limit sweep, ends in a table or LimitException, never in PHP's fatal
--EXTENSIONS--
batchflow
--SKIPIF--
<?php
if (getenv('USE_ZEND_ALLOC') === '0' && getenv('USE_TRACKED_ALLOC') !== '1') die('skip needs memory_limit accounting');
?>
--CONFLICTS--
all
--INI--
memory_limit=256M
--FILE--
<?php
use BatchFlow\{LimitException, Table};

// SPEC 6.4: the dictionary's group arrays (hash, off, len, count) grow together, preflighted as one sum. Coverage
// only: this in-process sweep passes on d6c6f9b, so it does not catch the fatal that the summed preflight allows on
// a fresh heap (one process per point, 300,000 rows, 200,003 keys). Every point must end in a table or
// LimitException.
$p = tempnam(sys_get_temp_dir(), 'bf');
$b = "k,v\n";
for ($i = 0; $i < 300000; $i++) {
    $b .= 'key' . ($i % 200000) . ',' . $i . "\n";
}
file_put_contents($p, $b);
unset($b);
echo "entries at 256M: ", Table::fromCsv($p, ['k' => 'string', 'v' => 'int64'], ['dictionary' => ['k']])->query()->groupCount('k') === [] ? 0 : 200000, "\n";
gc_mem_caches();
$base = memory_get_usage(true);
$points = $refused = $built = 0;
// From 64 KiB above the usage: under the tracked allocator (ASan lane) memory_get_usage(true) is the exact usage,
// so a closer limit fails in the run's fixed-size setup (bf_run_new, 5,320 bytes), before any preflight.
for ($extra = 65536; $extra <= 24 * 1048576 + 65536; $extra += 64 * 1024) {
    ini_set('memory_limit', (string)($base + $extra));
    try {
        $t = Table::fromCsv($p, ['k' => 'string', 'v' => 'int64'], ['dictionary' => ['k']]);
        $built++;
        unset($t);
    } catch (LimitException $e) {
        $refused++;
    }
    $points++;
    ini_set('memory_limit', '256M');
    gc_mem_caches();
}
unlink($p);
echo "$points points, first refused: ", var_export($refused > 0, true), ", last built: ", var_export($built > 0, true), "\n";
?>
--EXPECT--
entries at 256M: 200000
385 points, first refused: true, last built: true
