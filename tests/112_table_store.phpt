--TEST--
batchflow: Table load stores every row, memoryBytes is the exact sum of capacities, load errors carry the CSV prefix and leave no table
--EXTENSIONS--
batchflow
--FILE--
<?php
require __DIR__ . '/bf_helper.inc';

use BatchFlow\Table;

function load(string $data, array $schema, array $opts = []): string
{
    return bf_try(fn() => 'rows=' . ($t = Table::fromCsv(bf_file($data), $schema, $opts))->rowCount() . ' bytes=' . $t->memoryBytes());
}

$S = ['i' => 'int64', 'f' => 'float64', 'b' => 'bool', 's' => 'string'];
$data = "i,f,b,s\n1,1.5,true,x\n,,,\n-3,-0.0,false,\"\"\n";

echo "-- accessors\n";
$t = Table::fromCsv(bf_file($data), $S);
echo 'rowCount: ', $t->rowCount(), "\n";
echo 'columnNames: ', implode(',', $t->columnNames()), "\n";
echo 'schema: ', json_encode($t->schema()), "\n";
$n = Table::fromCsv(bf_file("2024,0,x\n1,2,3\n"), ['2024' => 'int64', '0' => 'int64', 'x' => 'int64']);
var_dump($n->columnNames(), $n->schema());

echo "-- memoryBytes by capacity (SPEC 6.6): 9 + 9 + 2 + 13 bytes per row of 4096, the arena 65,536\n";
echo 'four types: ', $t->memoryBytes() === 4096 * 33 + 65536 ? 'exact' : 'NO ' . $t->memoryBytes(), "\n";
echo 'no string bytes, no arena: ', load("i,f,b,s\n1,1.5,true,\n", $S), "\n";
echo 'header only: ', load("i,f,b,s\n", $S), "\n";
echo '4097 rows of one int64: ', load("i\n" . str_repeat("7\n", 4097), ['i' => 'int64']), "\n";
echo 'maxInputRows 3 clamps the rows: ', load("i\n1\n2\n3\n", ['i' => 'int64'], ['maxInputRows' => 3]), "\n";
echo 'a 70,000-byte string doubles the arena: ', load("s\n" . str_repeat('y', 70000) . "\n", ['s' => 'string']), "\n";
echo 'dictionary string: ', load("k\na\nb\na\n", ['k' => 'string'], ['dictionary' => ['k']]), "\n";
echo 'dictionary int64: ', load("k\n5\n5\n", ['k' => 'int64'], ['dictionary' => ['k']]), "\n";
echo 'dictionary arrays clamp to maxDictionarySize: ', load("k\na\n", ['k' => 'string'], ['dictionary' => ['k'], 'maxDictionarySize' => 2]), "\n";
echo 'an empty dictionary list: ', load("k\na\n", ['k' => 'string'], ['dictionary' => []]), "\n";

echo "-- every schema column validated on load (SPEC 5.5)\n";
echo load("i,s\n1,x\n2,\xFF\n", ['i' => 'int64', 's' => 'string']), "\n";
echo load("i,s\n1,x\n2\n", ['i' => 'int64', 's' => 'string']), "\n";
echo load("i\n1\n2\n", ['i' => 'int64'], ['maxInputRows' => 1]), "\n";
echo load("i\n" . str_repeat("7\n", 3), ['i' => 'int64'], ['maxTableBytes' => 1048576]), "\n";

echo "-- limits with the row prefix\n";
echo load("k\na\nb\nc\n", ['k' => 'string'], ['dictionary' => ['k'], 'maxDictionarySize' => 2]), "\n";
echo load("k\na\n\n\nb\n", ['k' => 'string'], ['dictionary' => ['k'], 'maxDictionarySize' => 1]), "\n";
$S10 = [];
for ($c = 0; $c < 10; $c++) {
    $S10["c$c"] = 'int64';
}
$csv10 = implode(',', array_keys($S10)) . "\n" . str_repeat(implode(',', array_fill(0, 10, '7')) . "\n", 8193);
echo load($csv10, $S10, ['maxTableBytes' => 1048576]), "\n";
echo load($csv10, $S10, ['maxTableBytes' => 1474560]), "\n";
echo load($csv10, $S10, ['maxTableBytes' => 1474559]), "\n";

echo "-- sources\n";
echo 'missing path: ', bf_try(fn() => Table::fromCsv('/nonexistent/bf_m4_112.csv', $S)), "\n";
$f = bf_mem("junk\ni\n1\n2\n");
fgets($f);
$t = Table::fromStream($f, ['i' => 'int64']);
echo 'stream from its position: ', $t->rowCount(), ' rows, stream still open: ', is_resource($f) ? 'yes' : 'no', "\n";
echo 'consumed stream: ', bf_try(fn() => Table::fromStream($f, ['i' => 'int64'])), "\n";
echo 'schema error before any I/O: ', bf_try(fn() => Table::fromCsv('/nonexistent/x.csv', ['i' => 'int'])), "\n";

echo "-- failed loads free everything\n";
$p = bf_file($csv10);
$pd = bf_file("k\na\nb\n");
$po = bf_file("k,v\na,1\nb,\n");
$m0 = memory_get_usage();
for ($k = 0; $k < 200; $k++) {
    bf_try(fn() => Table::fromCsv($p, $S10, ['maxTableBytes' => 1048576]));
    bf_try(fn() => Table::fromCsv($pd, ['k' => 'string'], ['dictionary' => ['k'], 'maxDictionarySize' => 1]));
    $ok = Table::fromCsv($po, ['k' => 'string', 'v' => 'int64'], ['dictionary' => ['k']]);
    unset($ok);
}
echo 'memory back to baseline: ', memory_get_usage() - $m0 < 65536 ? 'yes' : 'NO ' . (memory_get_usage() - $m0), "\n";
?>
--EXPECT--
-- accessors
rowCount: 3
columnNames: i,f,b,s
schema: {"i":"int64","f":"float64","b":"bool","s":"string"}
array(3) {
  [0]=>
  string(4) "2024"
  [1]=>
  string(1) "0"
  [2]=>
  string(1) "x"
}
array(3) {
  [2024]=>
  string(5) "int64"
  [0]=>
  string(5) "int64"
  ["x"]=>
  string(5) "int64"
}
-- memoryBytes by capacity (SPEC 6.6): 9 + 9 + 2 + 13 bytes per row of 4096, the arena 65,536
four types: exact
no string bytes, no arena: rows=1 bytes=135168
header only: rows=0 bytes=0
4097 rows of one int64: rows=4097 bytes=73728
maxInputRows 3 clamps the rows: rows=3 bytes=27
a 70,000-byte string doubles the arena: rows=1 bytes=184320
dictionary string: rows=3 bytes=101376
dictionary int64: rows=2 bytes=67584
dictionary arrays clamp to maxDictionarySize: rows=1 bytes=94264
an empty dictionary list: rows=1 bytes=118784
-- every schema column validated on load (SPEC 5.5)
CsvException: row 2 (line 3, offset 8): column "s": invalid UTF-8
CsvException: row 2 (line 3, offset 8): expected 2 fields, got 1
LimitException: row 2 (line 3, offset 4): input exceeds maxInputRows
rows=3 bytes=36864
-- limits with the row prefix
LimitException: row 3 (line 4, offset 6): dictionary for column "k" exceeds maxDictionarySize
LimitException: row 4 (line 5, offset 6): dictionary for column "k" exceeds maxDictionarySize
LimitException: row 8193 (line 8194, offset 163870): table exceeds maxTableBytes
rows=8193 bytes=1474560
LimitException: row 8193 (line 8194, offset 163870): table exceeds maxTableBytes
-- sources
missing path: StreamException: cannot open input
stream from its position: 2 rows, stream still open: yes
consumed stream: CsvException: missing header
schema error before any I/O: SchemaException: column "i": unknown type
-- failed loads free everything
memory back to baseline: yes
