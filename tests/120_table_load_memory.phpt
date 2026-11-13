--TEST--
batchflow: a plain table load, under a memory_limit sweep, ends in a table or LimitException, never in PHP's fatal
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

// SPEC 6.4: the row arrays grow by doubling, and each growth reallocates every array to the new capacity. The
// memory preflight prices the new capacity, not the added rows: realloc can hold the old and new block at once.
// Coverage only: this sweep passes on d6c6f9b and with the preflight pricing the added rows.
$p = tempnam(sys_get_temp_dir(), 'bf');
$b = "k,v\n";
for ($i = 0; $i < 300000; $i++) {
    $b .= 's' . ($i % 1000) . ',' . $i . "\n";
}
file_put_contents($p, $b);
unset($b);
$s = ['k' => 'string', 'v' => 'int64'];
echo "rows at 256M: ", Table::fromCsv($p, $s)->rowCount(), "\n";
// Each point runs in a new process: a fresh heap, as the first load of a request has.
$c = $p . '.php';
file_put_contents($c, '<?php
gc_mem_caches();
ini_set("memory_limit", (string)(memory_get_usage(true) + (int)$argv[2]));
try {
    BatchFlow\Table::fromCsv($argv[1], ["k" => "string", "v" => "int64"]);
    echo "table";
} catch (BatchFlow\LimitException $e) {
    echo "limit";
}
');
$php = getenv('TEST_PHP_EXECUTABLE') . ' ' . getenv('TEST_PHP_EXTRA_ARGS');
$seen = [];
// From 64 KiB above the usage: under the tracked allocator (ASan lane) memory_get_usage(true) is the exact usage,
// so a closer limit fails in the run's fixed-size setup (bf_run_new, 5,320 bytes), before any preflight.
for ($extra = 65536; $extra <= 24 * 1048576 + 65536; $extra += 256 * 1024) {
    $o = trim((string)shell_exec($php . ' ' . escapeshellarg($c) . ' ' . escapeshellarg($p) . ' ' . $extra . ' 2>&1'));
    if ($o !== 'table' && $o !== 'limit') {
        $o = "other at +$extra: " . substr($o, 0, 120);
    }
    $seen[$o] = ($seen[$o] ?? 0) + 1;
}
unlink($c);
unlink($p);
ksort($seen);
foreach ($seen as $o => $n) {
    echo $o, ": seen\n";
}
?>
--EXPECT--
rows at 256M: 300000
limit: seen
table: seen
