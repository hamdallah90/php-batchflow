--TEST--
batchflow: groupCount and groupSum on a dictionary key with 1,000,000 groups, under a memory_limit sweep, end in a result or LimitException, never in PHP's fatal
--EXTENSIONS--
batchflow
--SKIPIF--
<?php
if (getenv('USE_ZEND_ALLOC') === '0' && getenv('USE_TRACKED_ALLOC') !== '1') die('skip needs memory_limit accounting');
?>
--CONFLICTS--
all
--INI--
memory_limit=512M
--FILE--
<?php
use BatchFlow\{LimitException, Table};

// SPEC 6.4, 6.6: a dictionary group-by's result first copies the produced groups into six arrays (count, ikey, off,
// len and both sums: 44 bytes per group, 8 MB per 8-byte array at 1,000,000 groups) and then builds the PHP rows.
// Every point of the sweep must end in a result or LimitException: a preflight that prices fewer bytes than the
// arrays take lets them cross the limit inside the allocator.
$p = tempnam(sys_get_temp_dir(), 'bf');
$f = fopen($p, 'w');
fwrite($f, "k,v\n");
for ($i = 0; $i < 1000000; $i += 100000) {
    $b = '';
    for ($j = $i; $j < $i + 100000; $j++) {
        $b .= 'k' . $j . ',' . ($j % 7) . "\n";
    }
    fwrite($f, $b);
}
fclose($f);
unset($b);
$t = Table::fromCsv($p, ['k' => 'string', 'v' => 'int64'], ['dictionary' => ['k']]);
unlink($p);
$q = $t->query();
echo "groups at 512M: ", count($q->groupCount('k')), "\n";
$runs = [
    'groupCount' => static fn() => $q->groupCount('k'),
    'groupSum' => static fn() => $q->groupSum('k', 'v'),
];
foreach ($runs as $name => $run) {
    gc_mem_caches();
    $base = memory_get_usage(true);
    $points = $refused = 0;
    // From 64 KiB above the usage: under the tracked allocator (ASan lane) memory_get_usage(true) is the exact usage,
    // so a closer limit fails in the run's fixed-size setup (bf_run_new, 5,320 bytes), before any preflight.
    for ($extra = 65536; $extra <= 64 * 1048576 + 65536; $extra += 512 * 1024) {
        ini_set('memory_limit', (string)($base + $extra));
        try {
            $r = $run();
            unset($r);
        } catch (LimitException $e) {
            $refused++;
        }
        $points++;
        ini_set('memory_limit', '512M');
        gc_mem_caches();
    }
    echo "$name: $points points, refused at the first: ", var_export($refused > 0, true), "\n";
}
?>
--EXPECT--
groups at 512M: 1000000
groupCount: 129 points, refused at the first: true
groupSum: 129 points, refused at the first: true
