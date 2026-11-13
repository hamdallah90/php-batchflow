--TEST--
batchflow: a failed load frees the partial table: repeated loads that fail late leave memory_get_usage() where it was
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

// SPEC 5.1: no partial table exists; 6.4: only Zend-allocated memory is held, so a failed load returns it.
$S = ['id' => 'int64', 'k' => 'string', 'v' => 'float64'];
$good = fn(int $i) => "$i,key$i,$i.5\n";
$rows = 40000; // about 40,000 x (9 + 9 + 12 + 8) bytes of columns plus the arena: far above the 256 KiB bound below
$files = [
    'bad value at the last row' => [tb_big_file("id,k,v\n", $rows, $good) , $S, []],
    'bad UTF-8 at the last row' => [null, $S, []],
    'maxDictionarySize at the last row' => [null, $S, ['dictionary' => ['k'], 'maxDictionarySize' => $rows - 1]],
    'maxInputRows at the last row' => [null, $S, ['maxInputRows' => $rows - 1]],
];
$base = file_get_contents($files['bad value at the last row'][0]);
$files['bad value at the last row'][0] = tb_file($base . "x,bad,1.5\n");
$files['bad UTF-8 at the last row'][0] = tb_file($base . "1,\xff,1.5\n");
$files['maxDictionarySize at the last row'][0] = tb_file($base);
$files['maxInputRows at the last row'][0] = tb_file($base);
unset($base);

function attempt(string $p, array $S, array $o): string
{
    try {
        Table::fromCsv($p, $S, $o);
        return 'LOADED';
    } catch (BatchFlow\BatchFlowException $e) {
        return bf_cls($e);
    }
}

foreach ($files as $label => [$p, $S, $o]) {
    $first = attempt($p, $S, $o); // warm-up: caches, first-use allocations
    gc_mem_caches();
    $before = memory_get_usage();
    for ($i = 0; $i < 10; $i++) {
        attempt($p, $S, $o);
    }
    gc_mem_caches();
    $growth = memory_get_usage() - $before;
    echo $label, ': ', $first, ', ten more failed loads grow memory by less than 256 KiB: ', $growth < 262144 ? 'yes' : "NO ($growth)", "\n";
}
?>
--EXPECT--
bad value at the last row: CsvException, ten more failed loads grow memory by less than 256 KiB: yes
bad UTF-8 at the last row: CsvException, ten more failed loads grow memory by less than 256 KiB: yes
maxDictionarySize at the last row: LimitException, ten more failed loads grow memory by less than 256 KiB: yes
maxInputRows at the last row: LimitException, ten more failed loads grow memory by less than 256 KiB: yes
