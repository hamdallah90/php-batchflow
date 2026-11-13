--TEST--
batchflow: dictionary columns, string and int64: results equal the plain table and streaming; literal resolution, absent literal, NULL versus the empty string, first-seen group order
--EXTENSIONS--
batchflow
--FILE--
<?php
require __DIR__ . '/bf_table.inc';

use BatchFlow\Table;

// SPEC 6.6: a dictionary changes storage, never results
echo "-- matrix on a dictionary table (k string, g int64), against streaming\n";
[$csv, $S] = tb_fixture(2000, 104);
$o = ['dictionary' => ['k', 'g']];
$d = tb_load($csv, $S, $o);
$pl = tb_load($csv, $S);
echo 'rowCount, columnNames, schema equal the plain table: ', json_encode([$d->rowCount() === $pl->rowCount(), $d->columnNames() === $pl->columnNames(), $d->schema() === $pl->schema()]), "\n";
$sets = [
    'none' => [],
    'k = red' => [['eq', 'k', 'red']],
    'k = Red (case)' => [['eq', 'k', 'Red']],
    'k = "" (empty string)' => [['eq', 'k', '']],
    'k IS NULL' => [['eq', 'k', null]],
    'k = absent' => [['eq', 'k', 'zzz']],
    'k = a,b' => [['eq', 'k', 'a,b']],
    'k = newline' => [['eq', 'k', "l\nf"]],
    'k = quote' => [['eq', 'k', 'q"t']],
    'k = 0' => [['eq', 'k', '0']],
    'k = 00' => [['eq', 'k', '00']],
    'k = unicode' => [['eq', 'k', '日本']],
    'g = 2000006' => [['eq', 'g', 2000006]],
    'g = -3000009' => [['eq', 'g', -3000009]],
    'g IS NULL' => [['eq', 'g', null]],
    'g = absent' => [['eq', 'g', 12345]],
    'g > 0' => [['gt', 'g', 0]],
    'g > 4000000 (none)' => [['gt', 'g', 4000000]],
    'k = red, g > 0' => [['eq', 'k', 'red'], ['gt', 'g', 0]],
    'k = red, k = Red' => [['eq', 'k', 'red'], ['eq', 'k', 'Red']],
    'k IS NULL, g IS NULL' => [['eq', 'k', null], ['eq', 'g', null]],
    'k = green, v > 0, b = true' => [['eq', 'k', 'green'], ['gt', 'v', 0], ['eq', 'b', true]],
];
$terms = [
    'count' => ['count', []],
    'sum v' => ['sum', ['col' => 'v']],
    'sum g (dictionary int)' => ['sum', ['col' => 'g']],
    'summary g' => ['summary', ['col' => 'g']],
    'toArray' => ['toArray', ['maxRows' => 5000]],
    'groupCount k' => ['groupCount', ['key' => 'k']],
    'groupCount g' => ['groupCount', ['key' => 'g']],
    'groupSum k v' => ['groupSum', ['key' => 'k', 'sum' => 'v']],
    'groupSum k g' => ['groupSum', ['key' => 'k', 'sum' => 'g']],
    'groupSum g f' => ['groupSum', ['key' => 'g', 'sum' => 'f']],
    'groupCount s (plain)' => ['groupCount', ['key' => 's']],
];
foreach ($terms as $label => [$term, $x]) {
    $same = 0;
    $diffs = [];
    foreach ($sets as $sl => $preds) {
        $r = tb_cmp($d, $csv, $S, $o, $preds, $term, $x);
        $r === 'same' ? $same++ : $diffs[] = "$sl: $r";
    }
    echo $label, ': ', $same, ' of ', count($sets), " same\n";
    foreach (array_slice($diffs, 0, 2) as $df) {
        echo "  $df\n";
    }
}

// SPEC 5.6, 5.7, 5.9, 6.6 with values worked out by hand: NULL, '' and look-alikes are different values
echo "-- known values\n";
$K = ['id' => 'int64', 'k' => 'string', 'g' => 'int64'];
$kc = "id,k,g\n1,a,10\n2,,20\n3,\"\",10\n4,b,\n5,a,30\n6,A,10\n7,0,10\n8,00,10\n";
$t = tb_load($kc, $K, ['dictionary' => ['k', 'g']]);
$ids = fn($q) => implode(',', array_column($q->toArray(), 'id')) ?: '(none)';
foreach (['a' => 'a', '"" (empty string)' => '', 'NULL' => null, 'A' => 'A', '0' => '0', '00' => '00', 'absent zz' => 'zz', 'absent b2' => 'bb'] as $label => $lit) {
    echo 'whereEq k = ', $label, ': ids ', $ids($t->whereEq('k', $lit)), "\n";
}
echo 'whereEq g = 10: ids ', $ids($t->whereEq('g', 10)), "\n";
echo 'whereEq g IS NULL: ids ', $ids($t->whereEq('g', null)), "\n";
echo 'whereEq g = 99 (absent): ids ', $ids($t->whereEq('g', 99)), "\n";
echo 'whereGt g > 15: ids ', $ids($t->whereGt('g', 15)), "\n";
echo 'whereGt g > 30: ids ', $ids($t->whereGt('g', 30)), "\n";
echo 'whereGt g > -5 (every non-NULL): ids ', $ids($t->whereGt('g', -5)), "\n";
echo 'sum g: ', $t->query()->sum('g'), "\n";
echo "toArray keeps NULL, '' and the strings exactly:\n", bf_fmt_rows($t->query()->toArray()), "\n";
echo 'groupCount k: ', bf_fmt_groups($t->query()->groupCount('k')), "\n";
echo 'groupSum k g: ', bf_fmt_groups($t->query()->groupSum('k', 'g')), "\n";
echo 'groupCount g: ', bf_fmt_groups($t->query()->groupCount('g')), "\n";
echo 'groupCount k where id > 4: ', bf_fmt_groups($t->whereGt('id', 4)->groupCount('k')), "\n";

// SPEC 5.7: the literal checks are those of a plain column
echo "-- literal checks on a dictionary column\n";
foreach ([
    'int on string dictionary' => fn() => $t->whereEq('k', 1),
    'string on int dictionary' => fn() => $t->whereEq('g', '10'),
    'float on int dictionary' => fn() => $t->whereEq('g', 10.0),
    'invalid UTF-8' => fn() => $t->whereEq('k', "\xC3"),
    'whereGt on string dictionary' => fn() => $t->whereGt('k', 1),
    'string of 1,048,577 bytes' => fn() => $t->whereEq('k', str_repeat('a', 1048577)),
] as $label => $fn) {
    echo $label, ': ', tb_try(function () use ($fn) { $fn(); return 'accepted'; }), "\n";
}
echo 'string of 1,048,576 bytes (absent): ', $t->whereEq('k', str_repeat('a', 1048576))->count(), "\n";

// SPEC 6.6: a dictionary string column has no per-row strings
echo "-- storage\n";
$long = str_repeat('abcdefghij', 6);
$rows = '';
for ($i = 0; $i < 20000; $i++) {
    $rows .= "$i,$long" . ($i % 5) . "\n";
}
$SS = ['id' => 'int64', 's' => 'string'];
$dict = tb_load("id,s\n$rows", $SS, ['dictionary' => ['s']]);
$plain = tb_load("id,s\n$rows", $SS);
echo 'dictionary table smaller than plain for 5 distinct 61-byte values in 20,000 rows: ', $dict->memoryBytes() < $plain->memoryBytes() ? 'yes' : 'NO', "\n";
echo 'same results: ', tb_cmp($dict, "id,s\n$rows", $SS, ['dictionary' => ['s']], [['eq', 's', $long . '3']], 'count'), ' ', tb_cmp($dict, "id,s\n$rows", $SS, [], [], 'groupCount', ['key' => 's']), "\n";

echo "-- edge shapes\n";
$e = tb_load("id,k\n1,\n2,\n3,\n", ['id' => 'int64', 'k' => 'string'], ['dictionary' => ['k']]);
echo 'all-NULL dictionary column, groupCount: ', bf_fmt_groups($e->query()->groupCount('k')), '; whereEq NULL: ', $e->whereEq('k', null)->count(), '; whereEq "": ', $e->whereEq('k', '')->count(), "\n";
$e = tb_load("id,k\n", ['id' => 'int64', 'k' => 'string'], ['dictionary' => ['k']]);
echo 'zero rows: ', $e->rowCount(), ', groupCount ', bf_fmt_groups($e->query()->groupCount('k')), ', whereEq ', $e->whereEq('k', 'a')->count(), "\n";
$e = tb_load("k,x,id\nb,1,1\na,2,2\nb,3,3\n", ['id' => 'int64', 'k' => 'string'], ['dictionary' => ['k'], 'allowExtraColumns' => true]);
echo 'file order differs, extra column: ', bf_fmt_groups($e->query()->groupCount('k')), "\n";
$e = tb_load("n\n9223372036854775807\n-9223372036854775808\n0\n9223372036854775807\n", ['n' => 'int64'], ['dictionary' => ['n']]);
echo 'int64 extremes: ', bf_fmt_groups($e->query()->groupCount('n')), '; whereGt min: ', $e->whereGt('n', PHP_INT_MIN)->count(), "\n";
?>
--EXPECT--
-- matrix on a dictionary table (k string, g int64), against streaming
rowCount, columnNames, schema equal the plain table: [true,true,true]
count: 22 of 22 same
sum v: 22 of 22 same
sum g (dictionary int): 22 of 22 same
summary g: 22 of 22 same
toArray: 22 of 22 same
groupCount k: 22 of 22 same
groupCount g: 22 of 22 same
groupSum k v: 22 of 22 same
groupSum k g: 22 of 22 same
groupSum g f: 22 of 22 same
groupCount s (plain): 22 of 22 same
-- known values
whereEq k = a: ids 1,5
whereEq k = "" (empty string): ids 3
whereEq k = NULL: ids 2
whereEq k = A: ids 6
whereEq k = 0: ids 7
whereEq k = 00: ids 8
whereEq k = absent zz: ids (none)
whereEq k = absent b2: ids (none)
whereEq g = 10: ids 1,3,6,7,8
whereEq g IS NULL: ids 4
whereEq g = 99 (absent): ids (none)
whereGt g > 15: ids 2,5
whereGt g > 30: ids (none)
whereGt g > -5 (every non-NULL): ids 1,2,3,5,6,7,8
sum g: 100
toArray keeps NULL, '' and the strings exactly:
{id=int(1), k=s"a", g=int(10)}
{id=int(2), k=NULL, g=int(20)}
{id=int(3), k=s"", g=int(10)}
{id=int(4), k=s"b", g=NULL}
{id=int(5), k=s"a", g=int(30)}
{id=int(6), k=s"A", g=int(10)}
{id=int(7), k=s"0", g=int(10)}
{id=int(8), k=s"00", g=int(10)}
groupCount k: [s"a",int(2)]; [NULL,int(1)]; [s"",int(1)]; [s"b",int(1)]; [s"A",int(1)]; [s"0",int(1)]; [s"00",int(1)]
groupSum k g: [s"a",int(2),int(40)]; [NULL,int(1),int(20)]; [s"",int(1),int(10)]; [s"b",int(1),int(0)]; [s"A",int(1),int(10)]; [s"0",int(1),int(10)]; [s"00",int(1),int(10)]
groupCount g: [int(10),int(5)]; [int(20),int(1)]; [NULL,int(1)]; [int(30),int(1)]
groupCount k where id > 4: [s"a",int(1)]; [s"A",int(1)]; [s"0",int(1)]; [s"00",int(1)]
-- literal checks on a dictionary column
int on string dictionary: SchemaException: column "k": value of type int cannot be compared with string column
string on int dictionary: SchemaException: column "g": value of type string cannot be compared with int64 column
float on int dictionary: SchemaException: column "g": value of type float cannot be compared with int64 column
invalid UTF-8: SchemaException: column "k": string literal is not valid UTF-8
whereGt on string dictionary: SchemaException: column "k": whereGt requires a numeric column
string of 1,048,577 bytes: SchemaException: column "k": string literal exceeds 1048576 bytes
string of 1,048,576 bytes (absent): 0
-- storage
dictionary table smaller than plain for 5 distinct 61-byte values in 20,000 rows: yes
same results: same same
-- edge shapes
all-NULL dictionary column, groupCount: [NULL,int(3)]; whereEq NULL: 3; whereEq "": 0
zero rows: 0, groupCount [], whereEq 0
file order differs, extra column: [s"b",int(2)]; [s"a",int(1)]
int64 extremes: [int(9223372036854775807),int(2)]; [int(-9223372036854775808),int(1)]; [int(0),int(1)]; whereGt min: 3
