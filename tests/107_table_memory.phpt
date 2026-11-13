--TEST--
batchflow: under a low memory_limit a table load that cannot fit raises a catchable LimitException "memory_limit would be exceeded", frees the partial table and leaves the process usable
--EXTENSIONS--
batchflow
--SKIPIF--
<?php
// run-tests --asan disables the Zend allocator but sets USE_TRACKED_ALLOC=1, whose allocator enforces memory_limit;
// only the untracked system allocator does not.
if (getenv('USE_ZEND_ALLOC') === '0' && getenv('USE_TRACKED_ALLOC') !== '1') die('skip memory_limit needs the Zend or the tracked allocator');
?>
--CONFLICTS--
all
--INI--
memory_limit=32M
--FILE--
<?php
require __DIR__ . '/bf_table.inc';

use BatchFlow\Table;

register_shutdown_function(static function () {
    echo "shutdown function ran\n";
});

// SPEC 6.4: every growth is preflighted against memory_limit; a refused growth is a catchable LimitException, never PHP's fatal.
// 2,000,000 rows of two int64 columns need 2,097,152 rows x 18 bytes = 37.7 MB of capacity: more than the 32M limit.
$S = ['i' => 'int64', 'v' => 'int64'];
$p = tb_big_file("i,v\n", 2000000, fn($i) => "$i," . ($i % 1000) . "\n");
echo 'memory_limit: ', ini_get('memory_limit'), ', fixture bytes: ', filesize($p), "\n";
$base = memory_get_usage();

echo "-- the load that cannot fit\n";
try {
    $t = Table::fromCsv($p, $S);
    echo 'NO EXCEPTION, rows: ', $t->rowCount(), "\n";
} catch (BatchFlow\LimitException $e) {
    echo get_class($e), "\n";
    echo preg_match('/^row (\d+) \(line (\d+), offset (\d+)\): memory_limit would be exceeded$/', $e->getMessage(), $m) ? "message: row R (line L, offset O): memory_limit would be exceeded\n" : 'DIFFERENT message: ' . $e->getMessage() . "\n";
    echo 'refused after the first 4096 rows and before the end: ', ((int)$m[1] > 4096 && (int)$m[1] <= 2000000) ? 'yes' : 'NO (' . ($m[1] ?? '?') . ')', "\n";
    echo 'line is row + 1: ', (int)$m[2] === (int)$m[1] + 1 ? 'yes' : 'NO', "\n";
}
unset($t, $e);
gc_collect_cycles();
gc_mem_caches();
echo 'the partial table was freed: ', memory_get_usage() - $base < 262144 ? 'yes' : 'NO (' . (memory_get_usage() - $base) . ')', "\n";

echo "-- the process is usable\n";
$small = tb_load("i,v\n1,2\n3,4\n", $S);
echo 'a small table loads: ', $small->rowCount(), ', sum v ', $small->query()->sum('v'), "\n";
$c = 0;
for ($i = 0; $i < 5; $i++) {
    try {
        Table::fromCsv($p, $S);
    } catch (BatchFlow\LimitException $e) {
        $c++;
    }
}
echo 'five more refusals: ', $c, ', memory returned: ', memory_get_usage() - $base < 262144 ? 'yes' : 'NO', "\n";

echo "-- the same file, a stream source\n";
$f = fopen($p, 'rb');
try {
    Table::fromStream($f, $S);
    echo "NO EXCEPTION\n";
} catch (BatchFlow\LimitException $e) {
    echo 'LimitException: ', preg_match('/^row \d+ \(line \d+, offset \d+\): memory_limit would be exceeded$/', $e->getMessage()) ? 'memory_limit would be exceeded' : $e->getMessage(), "\n";
}
echo 'caller stream still open: ', is_resource($f) ? 'yes' : 'no', "\n";

echo "-- a table that fits, then a group-by that cannot\n";
$p2 = tb_big_file("id,k\n", 150000, fn($i) => "$i,key$i\n");
$t = Table::fromCsv($p2, ['id' => 'int64', 'k' => 'string']);
echo 'table of 150,000 rows loaded: ', $t->rowCount(), "\n";
try {
    $r = $t->query()->groupCount('k');
    echo 'groups: ', count($r), "\n";
} catch (BatchFlow\LimitException $e) {
    echo 'LimitException: ', preg_match('/^row \d+: memory_limit would be exceeded$|^memory_limit would be exceeded$/', $e->getMessage()) ? 'memory_limit would be exceeded' : $e->getMessage(), "\n";
}
echo 'the table still answers: ', $t->query()->count(), "\n";
?>
--EXPECT--
memory_limit: 32M, fixture bytes: 22668900
-- the load that cannot fit
BatchFlow\LimitException
message: row R (line L, offset O): memory_limit would be exceeded
refused after the first 4096 rows and before the end: yes
line is row + 1: yes
the partial table was freed: yes
-- the process is usable
a small table loads: 2, sum v 6
five more refusals: 5, memory returned: yes
-- the same file, a stream source
LimitException: memory_limit would be exceeded
caller stream still open: yes
-- a table that fits, then a group-by that cannot
table of 150,000 rows loaded: 150000
LimitException: memory_limit would be exceeded
the table still answers: 150000
shutdown function ran
