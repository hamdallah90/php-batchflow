--TEST--
batchflow: Table::fromCsv and fromStream load every type; rowCount, columnNames, schema in schema order; memoryBytes non-zero, by capacity and stable; the load is eager and equals the streaming reader
--EXTENSIONS--
batchflow
--FILE--
<?php
require __DIR__ . '/bf_table.inc';

use BatchFlow\Table;

function show(string $label, mixed $v): void
{
    echo $label, ': ', is_array($v) ? json_encode($v) : bf_val($v), "\n";
}

// SPEC 5.2, 5.6, 5.10: every type, NULL for an unquoted empty field, "" is the empty string, int64 bounds, -0.0
echo "-- every type\n";
$S = ['i' => 'int64', 'f' => 'float64', 'b' => 'bool', 's' => 'string'];
$csv = "i,f,b,s\n1,1.5,true,abc\n-9223372036854775808,-0.0,false,\"a,b\"\n9223372036854775807,1e3,,\n,.5,true,\"\"\n";
$t = tb_load($csv, $S);
echo 'class: ', get_class($t), "\n";
show('rowCount', $t->rowCount());
show('columnNames', $t->columnNames());
show('schema', $t->schema());
echo bf_fmt_rows($t->query()->toArray()), "\n";
echo 'equals streaming toArray: ', tb_cmp($t, $csv, $S, [], [], 'toArray'), "\n";

// SPEC 5.4, 5.2: file column order is free, schema order wins; 5.4 allowExtraColumns, extra columns never appear
echo "-- schema order, extra columns\n";
$t = tb_load("s,x,b,f,i\nabc,ignored,true,1.5,1\n", $S, ['allowExtraColumns' => true]);
show('columnNames', $t->columnNames());
show('schema', $t->schema());
echo bf_fmt_rows($t->query()->toArray()), "\n";

// SPEC 5.2: an integer-like name is its decimal string; 5.10 symtable keys
echo "-- integer-like names\n";
$t = tb_load("2024,7\n1.5,x\n", ['2024' => 'float64', '7' => 'string']);
show('columnNames', $t->columnNames());
show('columnNames are strings', array_map('is_string', $t->columnNames()));
show('schema', $t->schema());
$row = $t->query()->toArray()[0];
show('row[2024] and row["2024"]', [$row[2024], $row['2024'], $row[7]]);

// the spec: "memoryBytes non-zero and stable"; stub comment "native bytes held by this table, by capacity"
echo "-- memoryBytes\n";
[$big, $BS] = tb_fixture(10000, 1001);
$small = tb_load("id,k,g,v,f,b,s\n1,a,1,1,1.0,true,x\n", $BS);
$t = tb_load($big, $BS);
$m = $t->memoryBytes();
show('is int', is_int($m));
show('non-zero', $m > 0);
show('stable across calls', [$t->memoryBytes() === $m, $t->memoryBytes() === $m]);
$t->query()->count();
$t->query()->groupSum('k', 'v');
$t->whereGt('v', 0)->toArray(20000);
$c = $t->query()->cursor();
$c->fetch(100);
show('stable after queries and a live cursor', $t->memoryBytes() === $m);
$c->close();
show('stable after the cursor closed', $t->memoryBytes() === $m);
show('bigger table holds at least the small one', $m >= $small->memoryBytes());
$I = ['v' => 'int64'];
$ti = tb_load("v\n" . str_repeat("1\n", 10000), $I);
show('10,000 int64 rows hold at least 9 bytes each', $ti->memoryBytes() >= 90000);
$str = str_repeat('x', 100);
$ts = tb_load("s\n" . str_repeat("$str\n", 10000), ['s' => 'string']);
show('10,000 strings of 100 bytes hold their bytes', $ts->memoryBytes() >= 1000000);

// SPEC 5.1: the whole source is read at load; no later I/O
echo "-- eager load\n";
$p = tb_file($csv);
$t = Table::fromCsv($p, $S);
unlink($p);
show('count after the file is deleted', $t->query()->count());
echo "toArray after the file is deleted:\n", bf_fmt_rows($t->query()->toArray()), "\n";
show('count again', $t->query()->count());

// SPEC 5.1, 6.5: fromStream reads from the current position, never closes the caller's stream
echo "-- fromStream\n";
$f = bf_mem($csv);
$t = Table::fromStream($f, $S);
show('stream still a resource', is_resource($f) && get_resource_type($f) === 'stream');
fclose($f);
show('count after fclose', $t->query()->count());
$f = bf_mem("junk line\n" . $csv);
fgets($f);
$t = Table::fromStream($f, $S);
show('starts at the current position', $t->rowCount());
$f = bf_wrap_stream($csv, 3);
$t = Table::fromStream($f, $S);
show('3-byte reads', $t->rowCount());
echo 'equals streaming toArray: ', tb_cmp($t, $csv, $S, [], [], 'toArray'), "\n";

// SPEC 6.5: a path-opened stream is closed after the load, exactly once
echo "-- wrapper path\n";
$path = bf_wrap_path(['segments' => [[$csv, 1]], 'max' => 5]);
$t = Table::fromCsv($path, $S);
show('rowCount', $t->rowCount());
show('opens, closes', [BfWrap::$opens, BfWrap::$closes]);

// SPEC 5.3, 5.4: delimiter, BOM, CRLF, newline in quotes, simd only affect reading; same table either way
echo "-- dialect options\n";
$S2 = ['a' => 'int64', 'b' => 'string', 'c' => 'float64'];
$cases = [
    'semicolon' => ["a;b;c\n1;x,y;1.5\n2;\"p;q\";\n", ['delimiter' => ';']],
    'tab' => ["a\tb\tc\n1\tx\t1.5\n", ['delimiter' => "\t"]],
    'pipe' => ["a|b|c\n1|x|1.5\n", ['delimiter' => '|']],
    'bom and crlf' => ["\xEF\xBB\xBFa,b,c\r\n1,x,1.5\r\n2,y,\r\n", []],
    'newline in quotes' => ["a,b,c\n1,\"l1\nl2\r\nl3\",1.5\n", []],
    'no final newline' => ["a,b,c\n1,x,1.5", []],
    'simd scalar' => ["a,b,c\n1,x,1.5\n2,\"é日本\",-2\n", ['simd' => 'scalar']],
    'simd auto' => ["a,b,c\n1,x,1.5\n2,\"é日本\",-2\n", ['simd' => 'auto']],
];
foreach ($cases as $label => [$d, $o]) {
    $t = tb_load($d, $S2, $o);
    echo $label, ': ', $t->rowCount(), ' rows, ', tb_cmp($t, $d, $S2, $o, [], 'toArray'), "\n";
}

// SPEC 5.4: zero data rows is a valid table; every terminal gives its empty result
echo "-- header only\n";
$t = tb_load("i,f,b,s\n", $S);
show('rowCount', $t->rowCount());
show('memoryBytes', $t->memoryBytes());
show('columnNames', $t->columnNames());
show('count', $t->query()->count());
show('sum i', $t->query()->sum('i'));
show('sum f', $t->query()->sum('f'));
show('toArray', $t->query()->toArray());
show('groupCount', $t->query()->groupCount('s'));
echo 'summary: ', bf_fmt($t->query()->summary('f')), "\n";

// SPEC 5.5: load references every schema column; NULL in every column is fine
echo "-- all NULL\n";
$t = tb_load("i,f,b,s\n,,,\n,,,\n", $S);
show('rowCount', $t->rowCount());
echo bf_fmt_rows($t->query()->toArray()), "\n";

// SPEC 6.4, 6.6: rows double from 4096 and the string arena from 65,536 bytes; 20,000 rows cross every growth step
echo "-- growth\n";
[$csv20k, $S20k] = tb_fixture(20000, 2002);
$t = tb_load($csv20k, $S20k);
show('rowCount', $t->rowCount());
foreach (['toArray' => ['maxRows' => 20000], 'count' => [], 'sum' => ['col' => 'v'], 'sum f' => ['col' => 'f'], 'groupCount' => ['key' => 'k']] as $term => $x) {
    $name = explode(' ', $term)[0];
    echo $term, ': ', tb_cmp($t, $csv20k, $S20k, [], [], $name, $x), "\n";
}
?>
--EXPECT--
-- every type
class: BatchFlow\Table
rowCount: int(4)
columnNames: ["i","f","b","s"]
schema: {"i":"int64","f":"float64","b":"bool","s":"string"}
{i=int(1), f=float(1.5), b=bool(true), s=s"abc"}
{i=int(-9223372036854775808), f=float(-0.0), b=bool(false), s=s"a,b"}
{i=int(9223372036854775807), f=float(1000.0), b=NULL, s=NULL}
{i=NULL, f=float(0.5), b=bool(true), s=s""}
equals streaming toArray: same
-- schema order, extra columns
columnNames: ["i","f","b","s"]
schema: {"i":"int64","f":"float64","b":"bool","s":"string"}
{i=int(1), f=float(1.5), b=bool(true), s=s"abc"}
-- integer-like names
columnNames: ["2024","7"]
columnNames are strings: [true,true]
schema: {"2024":"float64","7":"string"}
row[2024] and row["2024"]: [1.5,1.5,"x"]
-- memoryBytes
is int: bool(true)
non-zero: bool(true)
stable across calls: [true,true]
stable after queries and a live cursor: bool(true)
stable after the cursor closed: bool(true)
bigger table holds at least the small one: bool(true)
10,000 int64 rows hold at least 9 bytes each: bool(true)
10,000 strings of 100 bytes hold their bytes: bool(true)
-- eager load
count after the file is deleted: int(4)
toArray after the file is deleted:
{i=int(1), f=float(1.5), b=bool(true), s=s"abc"}
{i=int(-9223372036854775808), f=float(-0.0), b=bool(false), s=s"a,b"}
{i=int(9223372036854775807), f=float(1000.0), b=NULL, s=NULL}
{i=NULL, f=float(0.5), b=bool(true), s=s""}
count again: int(4)
-- fromStream
stream still a resource: bool(true)
count after fclose: int(4)
starts at the current position: int(4)
3-byte reads: int(4)
equals streaming toArray: same
-- wrapper path
rowCount: int(4)
opens, closes: [1,1]
-- dialect options
semicolon: 2 rows, same
tab: 1 rows, same
pipe: 1 rows, same
bom and crlf: 2 rows, same
newline in quotes: 1 rows, same
no final newline: 1 rows, same
simd scalar: 2 rows, same
simd auto: 2 rows, same
-- header only
rowCount: int(0)
memoryBytes: int(0)
columnNames: ["i","f","b","s"]
count: int(0)
sum i: int(0)
sum f: float(0.0)
toArray: []
groupCount: []
summary: Summary{scanned=0;matched=0;sum=float(0.0)}
-- all NULL
rowCount: int(2)
{i=NULL, f=NULL, b=NULL, s=NULL}
{i=NULL, f=NULL, b=NULL, s=NULL}
-- growth
rowCount: int(20000)
toArray: same
count: same
sum: same
sum f: same
groupCount: same
