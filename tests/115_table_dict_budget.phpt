--TEST--
batchflow: a dictionary's growth counts against what is left of maxTableBytes, so no load ends above its limit
--EXTENSIONS--
batchflow
--FILE--
<?php
use BatchFlow\{LimitException, Table};

// SPEC 5.3, 6.4: dictionary construction counts against maxTableBytes, and memoryBytes() is the sum compared with it.
// 4,096 rows fill the first row capacity, so after row 1 only the dictionary grows: its arena reaches 2 MiB near row
// 3,500, the last growth of the load. A dictionary bounded by the whole maxTableBytes instead of what the columns leave
// would load at every limit below memoryBytes() and end above it.
$p = tempnam(sys_get_temp_dir(), 'bf');
$b = "s\n";
for ($i = 1; $i <= 4096; $i++) {
    $b .= sprintf('%05d', $i) . str_repeat('x', 295) . "\n";
}
file_put_contents($p, $b);
unset($b);
$S = ['s' => 'string'];
$o = ['dictionary' => ['s']];
$m = Table::fromCsv($p, $S, $o)->memoryBytes();
echo "memoryBytes above 2 MiB: ", var_export($m > 2097152, true), "\n";
echo "maxTableBytes = memoryBytes(): ", Table::fromCsv($p, $S, $o + ['maxTableBytes' => $m])->memoryBytes() === $m ? 'loads' : 'NO', "\n";
try {
    $t = Table::fromCsv($p, $S, $o + ['maxTableBytes' => $m - 1]);
    echo "memoryBytes() - 1: loaded with memoryBytes ", $t->memoryBytes(), "\n";
} catch (LimitException $e) {
    echo "memoryBytes() - 1: ", preg_replace('/\d+/', 'N', $e->getMessage()), "\n";
}
$over = $loads = 0;
for ($lim = 1048576; $lim < $m; $lim += 65536) {
    try {
        $t = Table::fromCsv($p, $S, $o + ['maxTableBytes' => $lim]);
        $loads++;
        $over += $t->memoryBytes() > $lim;
    } catch (LimitException $e) {
    }
}
echo "limits below memoryBytes() that load: $loads, of them above the limit: $over\n";
unlink($p);
?>
--EXPECT--
memoryBytes above 2 MiB: true
maxTableBytes = memoryBytes(): loads
memoryBytes() - 1: row N (line N, offset N): table exceeds maxTableBytes
limits below memoryBytes() that load: 0, of them above the limit: 0
