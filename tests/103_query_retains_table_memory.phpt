--TEST--
batchflow: a table's native memory stays while a Query holds it and is returned when the last Query and the Table are gone
--EXTENSIONS--
batchflow
--SKIPIF--
<?php
// run-tests --asan disables the Zend allocator but sets USE_TRACKED_ALLOC=1, whose allocator counts memory_get_usage();
// only the untracked system allocator leaves it at 0.
if (getenv('USE_ZEND_ALLOC') === '0' && getenv('USE_TRACKED_ALLOC') !== '1') die('skip memory_get_usage() needs the Zend or the tracked allocator');
?>
--FILE--
<?php
require __DIR__ . '/bf_table.inc';

use BatchFlow\Table;

// SPEC 5.12 (a Query holds a strong reference to its Table), 6.4 (table memory is Zend-allocated, so memory_get_usage sees it)
$S = ['id' => 'int64', 'k' => 'string', 'v' => 'int64'];
$p = tb_big_file("id,k,v\n", 100000, fn($i) => "$i,key" . ($i % 50) . ',' . ($i % 977) . "\n");
$warm = Table::fromCsv($p, $S); // first-use allocations
$warm->query()->count();
unset($warm);
gc_mem_caches();

$base = memory_get_usage();
$t = Table::fromCsv($p, $S);
$q = $t->whereEq('k', 'key7');
$held = memory_get_usage() - $base;
echo 'table holds at least 1 MiB: ', $held >= 1048576 ? 'yes' : "NO ($held)", "\n";

unset($t);
gc_collect_cycles();
$mid = memory_get_usage() - $base;
echo 'table dropped, query alive: memory kept: ', $mid >= $held * 0.9 ? 'yes' : "NO ($mid of $held)", "\n";
echo 'query still answers: ', $q->count(), "\n";

unset($q);
gc_collect_cycles();
gc_mem_caches();
$after = memory_get_usage() - $base;
echo 'last query dropped: memory returned to within 64 KiB: ', $after < 65536 ? 'yes' : "NO ($after)", "\n";

// the same through a temporary and a derived chain
for ($i = 0; $i < 3; $i++) {
    $q = Table::fromCsv($p, $S)->whereGt('v', 5)->whereEq('k', 'key9');
    $q->count();
    unset($q);
}
gc_mem_caches();
$after = memory_get_usage() - $base;
echo 'three temporaries dropped: within 64 KiB: ', $after < 65536 ? 'yes' : "NO ($after)", "\n";
?>
--EXPECT--
table holds at least 1 MiB: yes
table dropped, query alive: memory kept: yes
query still answers: 2000
last query dropped: memory returned to within 64 KiB: yes
three temporaries dropped: within 64 KiB: yes
