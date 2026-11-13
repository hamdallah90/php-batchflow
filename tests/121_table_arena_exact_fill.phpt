--TEST--
batchflow: a string arena filled to exactly its capacity does not grow and needs no memory preflight; one more byte doubles it
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
use BatchFlow\Table;

// SPEC 6.4, ruling 2: memoryBytes() is the exact capacity sum. One string column: 13 bytes per row of capacity
// (validity 1, offset 8, length 4), 4,096 rows of capacity for the first row, and the arena from 65,536 bytes,
// doubled only when a value does not fit in what is left.
function load(array $values): Table
{
    $p = tempnam(sys_get_temp_dir(), 'bf');
    file_put_contents($p, "s\n" . implode("\n", $values) . "\n");
    $t = Table::fromCsv($p, ['s' => 'string']);
    unlink($p);
    return $t;
}
$rows = 4096 * 13;
$exact = array_fill(0, 64, str_repeat('x', 1024));
echo "64 x 1024 bytes: ", load($exact)->memoryBytes() - $rows, "\n";
echo "then an empty value: ", load([...$exact, '""'])->memoryBytes() - $rows, "\n";
echo "then one byte: ", load([...$exact, 'y'])->memoryBytes() - $rows, "\n";
echo "65,535 bytes then one: ", load([...array_fill(0, 63, str_repeat('x', 1024)), str_repeat('x', 1023), 'y'])->memoryBytes() - $rows, "\n";
// A value that fills the arena exactly is copied without a growth, so it needs no memory_limit headroom: under a
// sweep, a 4 MiB arena filled exactly by its last value loads at the same limits as one left a byte short.
$full = tempnam(sys_get_temp_dir(), 'bf');
$short = tempnam(sys_get_temp_dir(), 'bf');
file_put_contents($full, "s\n" . str_repeat(str_repeat('x', 1024) . "\n", 4096));
file_put_contents($short, "s\n" . str_repeat(str_repeat('x', 1024) . "\n", 4095) . str_repeat('x', 1023) . "\n");
$ok = [];
foreach (['full' => $full, 'short' => $short] as $name => $p) {
    $ok[$name] = 0;
    gc_mem_caches();
    $base = memory_get_usage(true);
    // From 64 KiB above the usage: under the tracked allocator (ASan lane) memory_get_usage(true) is the exact usage,
    // so a closer limit fails in the run's fixed-size setup (bf_run_new, 5,320 bytes), before any preflight.
    for ($extra = 65536; $extra <= 16 * 1048576 + 65536; $extra += 128 * 1024) {
        ini_set('memory_limit', (string)($base + $extra));
        try {
            $t = Table::fromCsv($p, ['s' => 'string']);
            $ok[$name]++;
            unset($t);
        } catch (BatchFlow\LimitException $e) {
        }
        ini_set('memory_limit', '128M');
        gc_mem_caches();
    }
    unlink($p);
}
echo "loaded at some limits: ", var_export($ok['full'] > 0, true), ", full and short at the same limits within 256 KiB: ",
    var_export(abs($ok['full'] - $ok['short']) <= 2, true), "\n";
?>
--EXPECT--
64 x 1024 bytes: 65536
then an empty value: 65536
then one byte: 131072
65,535 bytes then one: 65536
loaded at some limits: true, full and short at the same limits within 256 KiB: true
