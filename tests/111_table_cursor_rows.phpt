--TEST--
batchflow: a table cursor's "row R: " prefix continues across fetches: the row budget LimitException of a later fetch names a row after the earlier batches, earlier batches stay delivered, the cursor is then closed
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
memory_limit=8M
--FILE--
<?php
require __DIR__ . '/bf_table.inc';

use BatchFlow\Table;

// SPEC 5.13: R continues across fetches; 5.11: errors during a Table query have the prefix "row R: "; 6.4: the toArray
// and Cursor::fetch row budget, a failed fetch returns nothing, closes the cursor.
// 3,000 rows of an int and a dictionary string column of 4,000 bytes each: the table holds each distinct string once
// (about 200 KB), but every PHP row copies its string (a 4,096-byte block): 3,000 rows would need 12 MB against the 8M limit.
$long = static fn(int $d) => str_repeat(chr(97 + $d), 4000);
$p = tb_big_file("id,s\n", 3000, fn($i) => "$i," . str_repeat(chr(97 + $i % 10), 4000) . "\n");
$t = Table::fromCsv($p, ['id' => 'int64', 's' => 'string'], ['dictionary' => ['s']]);
echo 'rows: ', $t->rowCount(), ', table under 1 MiB: ', $t->memoryBytes() < 1048576 ? 'yes' : 'NO', "\n";

$c = $t->query()->cursor();
$first = $c->fetch(100);
echo 'first batch: ', count($first), ' rows, ids ', $first[0]['id'], ' to ', $first[99]['id'], ', strings intact: ', $first[7]['s'] === $long(8) ? 'yes' : 'NO', "\n";
unset($first);
try {
    $rows = $c->fetch(3000);
    echo 'NO EXCEPTION, rows: ', count($rows), "\n";
} catch (BatchFlow\LimitException $e) {
    echo get_class($e), "\n";
    $ok = preg_match('/^row (\d+): memory_limit would be exceeded$/', $e->getMessage(), $m);
    echo 'message form "row R: memory_limit would be exceeded": ', $ok ? 'yes' : 'NO (' . $e->getMessage() . ')', "\n";
    echo 'R continues past the first batch of 100 (and is a row of the table): ', $ok && $m[1] > 100 && $m[1] <= 3000 ? 'yes' : 'NO (' . ($m[1] ?? '?') . ')', "\n";
}
unset($rows, $e);
echo 'every later fetch: ', tb_try(fn() => bf_fmt_rows($c->fetch(1))), "\n";
echo 'close(): ', tb_try(function () use ($c) { $c->close(); return 'no effect'; }), "\n";

echo "-- with a predicate, R is still the table row\n";
$c = $t->whereGt('id', 1000)->cursor();
$c->fetch(100);
unset($rows);
try {
    $c->fetch(3000);
    echo "NO EXCEPTION\n";
} catch (BatchFlow\LimitException $e) {
    $ok = preg_match('/^row (\d+): memory_limit would be exceeded$/', $e->getMessage(), $m);
    echo 'row after the 100 delivered rows, past id 1100: ', $ok && $m[1] > 1100 ? 'yes' : 'NO (' . $e->getMessage() . ')', "\n";
}
echo 'the table still answers: ', $t->query()->count(), "\n";
$n = 0;
$c = $t->query()->cursor();
while (($b = $c->fetch(50)) !== []) {
    $n += count($b);
}
unset($b);
echo 'a fresh cursor with small batches walks every row: ', $n, "\n";
?>
--EXPECT--
rows: 3000, table under 1 MiB: yes
first batch: 100 rows, ids 1 to 100, strings intact: yes
BatchFlow\LimitException
message form "row R: memory_limit would be exceeded": yes
R continues past the first batch of 100 (and is a row of the table): yes
every later fetch: BatchFlowException: cursor is closed
close(): no effect
-- with a predicate, R is still the table row
row after the 100 delivered rows, past id 1100: yes
the table still answers: 3000
a fresh cursor with small batches walks every row: 3000
