--TEST--
batchflow: each fetch's row budget starts at 0 (SPEC 6.4), so fetch(1) walks 60 rows of 200 KB with 3 MiB of headroom: no fetch reaches the 1 MiB trigger, while budgets carried across fetches would reach it at the sixth and be refused
--EXTENSIONS--
batchflow
--SKIPIF--
<?php
// run-tests --asan disables the Zend allocator but sets USE_TRACKED_ALLOC=1, whose allocator enforces memory_limit and
// counts memory_get_usage(); only the untracked system allocator does neither.
if (getenv('USE_ZEND_ALLOC') === '0' && getenv('USE_TRACKED_ALLOC') !== '1') die('skip memory_limit needs the Zend or the tracked allocator');
?>
--FILE--
<?php
require __DIR__ . '/bf_helper.inc';

use BatchFlow\Csv;

$F = str_repeat('x', 200000);
$p = bf_file("a\n" . str_repeat("$F\n", 60));
unset($F);
$c = Csv::scanStream(fopen($p, 'rb'), ['a' => 'string'])->cursor();
$b = $c->fetch(1);
$n = count($b);
unset($b);
// The reader has grown to hold a row. A check would ask for its pending bytes plus 2 MiB, more than 3 MiB: refused.
$limit = memory_get_usage(true) + 3 * 1048576;
echo 'limit set: ', ini_set('memory_limit', (string)$limit) !== false ? 'yes' : 'NO', "\n";
try {
    while ($b = $c->fetch(1)) {
        $n += count($b);
        $len = strlen($b[0]['a']);
    }
    echo "rows: $n, last length: $len\n";
} catch (BatchFlow\LimitException $e) {
    echo "LimitException after $n rows: ", $e->getMessage(), "\n";
}
echo 'real usage stayed under the limit: ', memory_get_usage(true) <= $limit ? 'yes' : 'NO', "\n";
?>
--EXPECT--
limit set: yes
rows: 60, last length: 200000
real usage stayed under the limit: yes
