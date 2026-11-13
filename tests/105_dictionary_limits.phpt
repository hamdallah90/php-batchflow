--TEST--
batchflow: maxDictionarySize exact and +1 for string and int64 dictionary columns; NULL is not counted; the empty string and repeats are; the refusing row, column and prefix
--EXTENSIONS--
batchflow
--FILE--
<?php
require __DIR__ . '/bf_table.inc';

use BatchFlow\Table;

function load(string $csv, array $schema, array $opts): string
{
    return tb_try(fn() => 'LOADED rows=' . tb_load($csv, $schema, $opts)->rowCount());
}

// SPEC 5.3: maxDictionarySize counts distinct non-NULL values; 5.11: LimitException with the row prefix of the row
// that brings the (limit + 1)th distinct value; the message names the column.
$K = ['id' => 'int64', 'k' => 'string'];
$G = ['id' => 'int64', 'g' => 'int64'];
echo "-- string column, exact and +1\n";
$abc = "id,k\n1,a\n2,b\n3,c\n";
echo '3 distinct, limit 3: ', load($abc, $K, ['dictionary' => ['k'], 'maxDictionarySize' => 3]), "\n";
echo '3 distinct, limit 2: ', load($abc, $K, ['dictionary' => ['k'], 'maxDictionarySize' => 2]), "\n";
echo '3 distinct, limit 1: ', load($abc, $K, ['dictionary' => ['k'], 'maxDictionarySize' => 1]), "\n";
echo '1 distinct in 3 rows, limit 1: ', load("id,k\n1,a\n2,a\n3,a\n", $K, ['dictionary' => ['k'], 'maxDictionarySize' => 1]), "\n";
echo 'case differs, limit 1: ', load("id,k\n1,a\n2,A\n", $K, ['dictionary' => ['k'], 'maxDictionarySize' => 1]), "\n";
echo '"0" and "00", limit 1: ', load("id,k\n1,0\n2,00\n", $K, ['dictionary' => ['k'], 'maxDictionarySize' => 1]), "\n";

echo "-- NULL is not counted, the empty string is\n";
echo 'a, NULL, b, NULL, limit 2: ', load("id,k\n1,a\n2,\n3,b\n4,\n", $K, ['dictionary' => ['k'], 'maxDictionarySize' => 2]), "\n";
echo 'only NULLs, limit 1: ', load("id,k\n1,\n2,\n", $K, ['dictionary' => ['k'], 'maxDictionarySize' => 1]), "\n";
echo '"" and a, limit 2: ', load("id,k\n1,\"\"\n2,a\n", $K, ['dictionary' => ['k'], 'maxDictionarySize' => 2]), "\n";
echo '"" and a, limit 1: ', load("id,k\n1,\"\"\n2,a\n", $K, ['dictionary' => ['k'], 'maxDictionarySize' => 1]), "\n";
echo '"" and NULL, limit 1: ', load("id,k\n1,\"\"\n2,\n", $K, ['dictionary' => ['k'], 'maxDictionarySize' => 1]), "\n";

echo "-- int64 column\n";
$n = "id,g\n1,1\n2,2\n3,3\n";
echo '1, 2, 3 limit 3: ', load($n, $G, ['dictionary' => ['g'], 'maxDictionarySize' => 3]), "\n";
echo '1, 2, 3 limit 2: ', load($n, $G, ['dictionary' => ['g'], 'maxDictionarySize' => 2]), "\n";
echo '1, NULL, 2, NULL, 1 limit 2: ', load("id,g\n1,1\n2,\n3,2\n4,\n5,1\n", $G, ['dictionary' => ['g'], 'maxDictionarySize' => 2]), "\n";
echo '1 and -1 limit 1: ', load("id,g\n1,1\n2,-1\n", $G, ['dictionary' => ['g'], 'maxDictionarySize' => 1]), "\n";

echo "-- two dictionary columns, the limit is per column; a column outside the option is not limited\n";
$KG = ['id' => 'int64', 'k' => 'string', 'g' => 'int64'];
$two = "id,k,g\n1,x,1\n2,x,2\n3,y,3\n";
echo 'k has 2, g has 3, limit 2: ', load($two, $KG, ['dictionary' => ['k', 'g'], 'maxDictionarySize' => 2]), "\n";
echo 'only k is a dictionary, limit 2: ', load($two, $KG, ['dictionary' => ['k'], 'maxDictionarySize' => 2]), "\n";
echo 'no dictionary, limit 1: ', load($two, $KG, ['maxDictionarySize' => 1]), "\n";

echo "-- later rows, many rows\n";
function expect_prefix(string $msg, int $row, string $header, array $rows): string
{
    $off = strlen($header);
    for ($i = 0; $i < $row - 1; $i++) {
        $off += strlen($rows[$i]);
    }
    $want = "LimitException: row $row (line " . ($row + 1) . ", offset $off): dictionary for column \"k\" exceeds maxDictionarySize";
    return $msg === $want ? 'exact prefix, row ' . $row : "DIFFERENT: $msg (wanted $want)";
}
$rows = [];
for ($i = 1; $i <= 1000; $i++) {
    $rows[] = "$i,key$i\n";
}
$csv = "id,k\n" . implode('', $rows);
echo '1,000 distinct, limit 1000: ', load($csv, $K, ['dictionary' => ['k'], 'maxDictionarySize' => 1000]), "\n";
echo '1,000 distinct, limit 999: ', expect_prefix(load($csv, $K, ['dictionary' => ['k'], 'maxDictionarySize' => 999]), 1000, "id,k\n", $rows), "\n";
$rows = [];
for ($i = 1; $i <= 100; $i++) {
    $rows[] = "$i," . ($i % 2 ? 'a' : 'b') . "\n";
}
$rows[] = "101,c\n";
$csv = "id,k\n" . implode('', $rows);
echo 'a, b alternate for 100 rows, c at row 101, limit 2: ', expect_prefix(load($csv, $K, ['dictionary' => ['k'], 'maxDictionarySize' => 2]), 101, "id,k\n", $rows), "\n";
echo 'same, limit 3: ', load($csv, $K, ['dictionary' => ['k'], 'maxDictionarySize' => 3]), "\n";

echo "-- no sticky state: the same bytes load with a larger limit\n";
echo 'limit 2: ', load($abc, $K, ['dictionary' => ['k'], 'maxDictionarySize' => 2]), "\n";
echo 'limit 3: ', load($abc, $K, ['dictionary' => ['k'], 'maxDictionarySize' => 3]), "\n";
$t = tb_load($abc, $K, ['dictionary' => ['k'], 'maxDictionarySize' => 3]);
echo 'the loaded table: ', bf_fmt_groups($t->query()->groupCount('k')), "\n";

echo "-- the option range (SPEC 5.3: 1 to 4,294,967,294)\n";
foreach ([0, 1, 4294967294, 4294967295, -1, PHP_INT_MAX] as $v) {
    echo 'maxDictionarySize ', $v, ': ', load($abc, $K, ['dictionary' => ['k'], 'maxDictionarySize' => $v]), "\n";
}
echo 'maxDictionarySize "3": ', load($abc, $K, ['dictionary' => ['k'], 'maxDictionarySize' => '3']), "\n";
echo 'maxDictionarySize 3.0: ', load($abc, $K, ['dictionary' => ['k'], 'maxDictionarySize' => 3.0]), "\n";
?>
--EXPECT--
-- string column, exact and +1
3 distinct, limit 3: LOADED rows=3
3 distinct, limit 2: LimitException: row 3 (line 4, offset 13): dictionary for column "k" exceeds maxDictionarySize
3 distinct, limit 1: LimitException: row 2 (line 3, offset 9): dictionary for column "k" exceeds maxDictionarySize
1 distinct in 3 rows, limit 1: LOADED rows=3
case differs, limit 1: LimitException: row 2 (line 3, offset 9): dictionary for column "k" exceeds maxDictionarySize
"0" and "00", limit 1: LimitException: row 2 (line 3, offset 9): dictionary for column "k" exceeds maxDictionarySize
-- NULL is not counted, the empty string is
a, NULL, b, NULL, limit 2: LOADED rows=4
only NULLs, limit 1: LOADED rows=2
"" and a, limit 2: LOADED rows=2
"" and a, limit 1: LimitException: row 2 (line 3, offset 10): dictionary for column "k" exceeds maxDictionarySize
"" and NULL, limit 1: LOADED rows=2
-- int64 column
1, 2, 3 limit 3: LOADED rows=3
1, 2, 3 limit 2: LimitException: row 3 (line 4, offset 13): dictionary for column "g" exceeds maxDictionarySize
1, NULL, 2, NULL, 1 limit 2: LOADED rows=5
1 and -1 limit 1: LimitException: row 2 (line 3, offset 9): dictionary for column "g" exceeds maxDictionarySize
-- two dictionary columns, the limit is per column; a column outside the option is not limited
k has 2, g has 3, limit 2: LimitException: row 3 (line 4, offset 19): dictionary for column "g" exceeds maxDictionarySize
only k is a dictionary, limit 2: LOADED rows=3
no dictionary, limit 1: LOADED rows=3
-- later rows, many rows
1,000 distinct, limit 1000: LOADED rows=1000
1,000 distinct, limit 999: exact prefix, row 1000
a, b alternate for 100 rows, c at row 101, limit 2: exact prefix, row 101
same, limit 3: LOADED rows=101
-- no sticky state: the same bytes load with a larger limit
limit 2: LimitException: row 3 (line 4, offset 13): dictionary for column "k" exceeds maxDictionarySize
limit 3: LOADED rows=3
the loaded table: [s"a",int(1)]; [s"b",int(1)]; [s"c",int(1)]
-- the option range (SPEC 5.3: 1 to 4,294,967,294)
maxDictionarySize 0: SchemaException: option "maxDictionarySize": out of range
maxDictionarySize 1: LimitException: row 2 (line 3, offset 9): dictionary for column "k" exceeds maxDictionarySize
maxDictionarySize 4294967294: LOADED rows=3
maxDictionarySize 4294967295: SchemaException: option "maxDictionarySize": out of range
maxDictionarySize -1: SchemaException: option "maxDictionarySize": out of range
maxDictionarySize 9223372036854775807: SchemaException: option "maxDictionarySize": out of range
maxDictionarySize "3": SchemaException: option "maxDictionarySize": wrong type
maxDictionarySize 3.0: SchemaException: option "maxDictionarySize": wrong type
