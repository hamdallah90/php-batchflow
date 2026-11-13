--TEST--
batchflow: maxTableBytes by total capacity: the boundary row, the maxInputRows clamp, the limit equal to memoryBytes(); with memory_limit=-1 it alone bounds the table
--EXTENSIONS--
batchflow
--INI--
memory_limit=-1
--FILE--
<?php
require __DIR__ . '/bf_table.inc';

use BatchFlow\Table;

// SPEC 6.4: rows start at 4096 and double; a growth is refused when the sum of all capacities after it would exceed
// maxTableBytes; 6.6: an int64 column holds 8 bytes and a validity byte per row. Ten int64 columns: 90 bytes per row of
// capacity, so capacity 8192 is 737,280 bytes (under 1 MiB) and capacity 16384 is 1,474,560 (over). The 8193rd row needs the growth.
function ints(int $cols): array
{
    $names = [];
    for ($c = 0; $c < $cols; $c++) {
        $names["c$c"] = 'int64';
    }
    return $names;
}
function csv_rows(array $schema, int $rows): array
{
    $h = implode(',', array_keys($schema)) . "\n";
    $line = implode(',', array_fill(0, count($schema), '7')) . "\n";
    return [$h, $line, $h . str_repeat($line, $rows)];
}
$S10 = ints(10);
$min = ['maxTableBytes' => 1048576];

echo "-- maxTableBytes 1 MiB, 10 int64 columns, memory_limit=-1\n";
[$h, $line, $csv] = csv_rows($S10, 8192);
echo '8,192 rows: ', tb_try(fn() => 'LOADED rows=' . tb_load($csv, $S10, $min)->rowCount()), "\n";
[$h, $line, $csv] = csv_rows($S10, 8193);
$off = strlen($h) + 8192 * strlen($line);
$got = tb_try(fn() => 'LOADED rows=' . tb_load($csv, $S10, $min)->rowCount());
echo '8,193 rows: ', $got === "LimitException: row 8193 (line 8194, offset $off): table exceeds maxTableBytes" ? 'LimitException at row 8193, exact prefix' : "DIFFERENT: $got", "\n";
[$h, $line, $csv] = csv_rows($S10, 20000);
$got = tb_try(fn() => 'LOADED rows=' . tb_load($csv, $S10, $min)->rowCount());
echo '20,000 rows: ', $got === "LimitException: row 8193 (line 8194, offset $off): table exceeds maxTableBytes" ? 'LimitException at row 8193 (the first refused growth)' : "DIFFERENT: $got", "\n";
echo 'no limit given, 20,000 rows: ', tb_try(fn() => 'LOADED rows=' . tb_load($csv, $S10)->rowCount()), "\n";
echo 'limit 2 MiB, 20,000 rows (capacity 32,768 is 2,949,120): ', preg_replace('/row \d+ \(line \d+, offset \d+\)/', 'row R', tb_try(fn() => 'LOADED rows=' . tb_load($csv, $S10, ['maxTableBytes' => 2097152])->rowCount())), "\n";
echo 'limit 4 MiB, 20,000 rows: ', tb_try(fn() => 'LOADED rows=' . tb_load($csv, $S10, ['maxTableBytes' => 4194304])->rowCount()), "\n";

// SPEC 5.3, 6.4: the limit applies to the stream path as well, and the failed load leaves nothing
echo "-- fromStream\n";
echo 'stream, 20,000 rows: ', tb_try(function () use ($csv, $S10, $min) {
    $f = bf_wrap_stream($csv, 4096);
    return 'LOADED ' . Table::fromStream($f, $S10, $min)->rowCount();
}) === "LimitException: row 8193 (line 8194, offset $off): table exceeds maxTableBytes" ? 'same refusal' : 'DIFFERENT', "\n";

// SPEC 6.4 table: rows double from 4096, "clamped to maxInputRows"; 30 columns at capacity 4096 are 1,105,920 bytes
echo "-- the maxInputRows clamp\n";
$S30 = ints(30);
[$h, $line, $csv] = csv_rows($S30, 3);
echo '30 columns, 3 rows, maxInputRows 3: ', tb_try(fn() => 'LOADED rows=' . tb_load($csv, $S30, $min + ['maxInputRows' => 3])->rowCount()), "\n";
$got = tb_try(fn() => 'LOADED rows=' . tb_load($csv, $S30, $min)->rowCount());
echo '30 columns, 3 rows, default maxInputRows: ', str_starts_with($got, 'LimitException: ') && str_ends_with($got, 'table exceeds maxTableBytes') ? 'LimitException table exceeds maxTableBytes' : "DIFFERENT: $got", "\n";

// SPEC 5.3 and "memoryBytes ... by capacity": a limit equal to what the table holds accepts the same load; half of it does not
echo "-- limit equal to memoryBytes()\n";
$S10 = ints(10);
[$h, $line, $csv] = csv_rows($S10, 30000);
$m = tb_load($csv, $S10)->memoryBytes();
echo 'memoryBytes of 30,000 rows over 1 MiB: ', $m > 1048576 ? 'yes' : "NO ($m)", "\n";
echo 'maxTableBytes = memoryBytes: ', tb_try(fn() => 'LOADED rows=' . tb_load($csv, $S10, ['maxTableBytes' => $m])->rowCount()), "\n";
$got = tb_try(fn() => tb_load($csv, $S10, ['maxTableBytes' => $m - 1])->rowCount());
echo 'maxTableBytes = memoryBytes - 1: ', preg_match('/^LimitException: row \d+ \(line \d+, offset \d+\): table exceeds maxTableBytes$/', $got) ? 'LimitException table exceeds maxTableBytes' : "DIFFERENT: $got", "\n";
$got = tb_try(fn() => tb_load($csv, $S10, ['maxTableBytes' => intdiv($m, 2)])->rowCount());
echo 'maxTableBytes = half of it: ', preg_match('/^LimitException: row \d+ \(line \d+, offset \d+\): table exceeds maxTableBytes$/', $got) ? 'LimitException table exceeds maxTableBytes' : "DIFFERENT: $got", "\n";

echo "-- option range (SPEC 5.3: 1,048,576 to 1,099,511,627,776)\n";
$tiny = tb_file("c0\n1\n");
foreach ([1048575, 1048576, 1099511627776, 1099511627777, 0, -1, '1048576', 1048576.0] as $v) {
    echo 'maxTableBytes ', var_export($v, true), ': ', tb_try(fn() => 'LOADED rows=' . Table::fromCsv($tiny, ['c0' => 'int64'], ['maxTableBytes' => $v])->rowCount()), "\n";
}
?>
--EXPECT--
-- maxTableBytes 1 MiB, 10 int64 columns, memory_limit=-1
8,192 rows: LOADED rows=8192
8,193 rows: LimitException at row 8193, exact prefix
20,000 rows: LimitException at row 8193 (the first refused growth)
no limit given, 20,000 rows: LOADED rows=20000
limit 2 MiB, 20,000 rows (capacity 32,768 is 2,949,120): LimitException: row R: table exceeds maxTableBytes
limit 4 MiB, 20,000 rows: LOADED rows=20000
-- fromStream
stream, 20,000 rows: same refusal
-- the maxInputRows clamp
30 columns, 3 rows, maxInputRows 3: LOADED rows=3
30 columns, 3 rows, default maxInputRows: LimitException table exceeds maxTableBytes
-- limit equal to memoryBytes()
memoryBytes of 30,000 rows over 1 MiB: yes
maxTableBytes = memoryBytes: LOADED rows=30000
maxTableBytes = memoryBytes - 1: LimitException table exceeds maxTableBytes
maxTableBytes = half of it: LimitException table exceeds maxTableBytes
-- option range (SPEC 5.3: 1,048,576 to 1,099,511,627,776)
maxTableBytes 1048575: SchemaException: option "maxTableBytes": out of range
maxTableBytes 1048576: LOADED rows=1
maxTableBytes 1099511627776: LOADED rows=1
maxTableBytes 1099511627777: SchemaException: option "maxTableBytes": out of range
maxTableBytes 0: SchemaException: option "maxTableBytes": out of range
maxTableBytes -1: SchemaException: option "maxTableBytes": out of range
maxTableBytes '1048576': SchemaException: option "maxTableBytes": wrong type
maxTableBytes 1048576.0: SchemaException: option "maxTableBytes": wrong type
