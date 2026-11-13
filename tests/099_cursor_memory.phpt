--TEST--
batchflow: a cursor walks a 1M-row wrapper source with fetch(1000) under memory_limit=16M at constant memory: the peak after 100k rows and after 1M rows differ by less than 1 MiB
--EXTENSIONS--
batchflow
--SKIPIF--
<?php
// run-tests --asan disables the Zend allocator but sets USE_TRACKED_ALLOC=1, whose allocator enforces memory_limit; its
// memory_get_peak_usage() reads 0, so there the 16M limit is what fails a walk that keeps rows. Only the untracked
// system allocator enforces nothing.
if (getenv('USE_ZEND_ALLOC') === '0' && getenv('USE_TRACKED_ALLOC') !== '1') die('skip memory_limit needs the Zend or the tracked allocator');
?>
--INI--
memory_limit=16M
--FILE--
<?php
require __DIR__ . '/bf_helper.inc';

use BatchFlow\Csv;

// 1,000,000 rows of "id,name,amount", about 27 MB served 64 KiB per read: more than the limit if it were held.
$f = bf_gen_stream([["id,name,amount\n", 1], ["123456,abcdefghij,1234.5\n", 1000000]], ['max' => 65536]);
$c = Csv::scanStream($f, ['id' => 'int64', 'name' => 'string', 'amount' => 'float64'])->cursor();
$n = 0;
$sum = 0.0;
$peak100k = 0;
while ($rows = $c->fetch(1000)) {
    $n += count($rows);
    foreach ($rows as $row) {
        $sum += $row['amount'];
    }
    if ($n === 100000) {
        $peak100k = memory_get_peak_usage();
    }
}
unset($rows);
$peak1m = memory_get_peak_usage();
echo "rows: $n, sum: $sum\n";
echo 'peak after 100k rows and after 1M rows differ by less than 1 MiB: ', $peak1m - $peak100k < 1048576 ? 'yes' : 'NO (' . ($peak1m - $peak100k) . ' bytes)', "\n";
echo 'about one byte per row or less: ', ($peak1m - $peak100k) / 900000 <= 1.0 ? 'yes' : 'NO', "\n";
echo 'the cursor is exhausted: ', json_encode($c->fetch()), "\n";
?>
--EXPECT--
rows: 1000000, sum: 1234500000
peak after 100k rows and after 1M rows differ by less than 1 MiB: yes
about one byte per row or less: yes
the cursor is exhausted: []
