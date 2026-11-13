--TEST--
batchflow: groupCount and groupSum on a table, dictionary and plain keys, equal streaming; first-seen order among matching rows; maxGroups counts produced groups; stored maxGroupBytes bounds a table group-by
--EXTENSIONS--
batchflow
--FILE--
<?php
require __DIR__ . '/bf_table.inc';

use BatchFlow\Table;

// SPEC 5.9, 6.6: first-seen order among rows that passed the predicates, on every path
echo "-- first-seen order among matching rows\n";
$K = ['k' => 'string', 'v' => 'int64', 'p' => 'int64'];
$csv = "k,v,p\nA,1,0\nB,2,1\nA,3,1\nC,4,1\nB,5,1\n";
$pred = [['eq', 'p', 1]];
foreach (['plain' => [], 'dictionary' => ['dictionary' => ['k']]] as $label => $o) {
    $t = tb_load($csv, $K, $o);
    echo $label, ' groupCount: ', tb_term(tb_apply($t, $pred), 'groupCount', ['key' => 'k']), "\n";
    echo $label, ' groupSum:   ', tb_term(tb_apply($t, $pred), 'groupSum', ['key' => 'k', 'sum' => 'v']), "\n";
    echo $label, ' no predicate: ', tb_term($t->query(), 'groupCount', ['key' => 'k']), "\n";
}
echo 'streaming groupCount: ', tb_term(bf_apply(bf_q($csv, $K), $pred), 'groupCount', ['key' => 'k']), "\n";

echo "-- NULL and the empty string: separate groups, positions by first matching row\n";
$csv = "k,v,p\n,1,0\nx,2,1\n,3,1\n\"\",4,1\nx,5,1\n,6,1\n";
foreach (['plain' => [], 'dictionary' => ['dictionary' => ['k']]] as $label => $o) {
    $t = tb_load($csv, $K, $o);
    echo $label, ': ', tb_term(tb_apply($t, $pred), 'groupSum', ['key' => 'k', 'sum' => 'v']), "\n";
}

// Dictionary and non-dictionary tables give identical output to streaming
echo "-- matrix: plain table, dictionary table, streaming\n";
[$csv, $S] = tb_fixture(1500, 106);
$o = ['dictionary' => ['k', 'g']];
$tp = tb_load($csv, $S);
$td = tb_load($csv, $S, $o);
$sets = [
    'none' => [],
    'k = red' => [['eq', 'k', 'red']],
    'k IS NULL' => [['eq', 'k', null]],
    'k = absent' => [['eq', 'k', 'zzz']],
    'g > 0' => [['gt', 'g', 0]],
    'g IS NULL' => [['eq', 'g', null]],
    'b = true, v > 0' => [['eq', 'b', true], ['gt', 'v', 0]],
    'id > 1400' => [['gt', 'id', 1400]],
    'k = green, g > 0' => [['eq', 'k', 'green'], ['gt', 'g', 0]],
];
$terms = [
    'groupCount k' => ['groupCount', ['key' => 'k']],
    'groupCount g' => ['groupCount', ['key' => 'g']],
    'groupCount s' => ['groupCount', ['key' => 's']],
    'groupSum k v' => ['groupSum', ['key' => 'k', 'sum' => 'v']],
    'groupSum g v' => ['groupSum', ['key' => 'g', 'sum' => 'v']],
    'groupSum k f' => ['groupSum', ['key' => 'k', 'sum' => 'f']],
    'groupSum g g' => ['groupSum', ['key' => 'g', 'sum' => 'g']],
    'groupSum s v' => ['groupSum', ['key' => 's', 'sum' => 'v']],
];
foreach ($terms as $label => $spec) {
    [$term, $x] = $spec;
    $same = [0, 0];
    $diffs = [];
    foreach ($sets as $sl => $preds) {
        foreach ([[$tp, [], 0], [$td, $o, 1]] as [$tt, $oo, $i]) {
            $r = tb_cmp($tt, $csv, $S, $oo, $preds, $term, $x);
            $r === 'same' ? $same[$i]++ : $diffs[] = "$sl: $r";
        }
    }
    echo $label, ': plain ', $same[0], ' of ', count($sets), ', dictionary ', $same[1], ' of ', count($sets), "\n";
    foreach (array_slice($diffs, 0, 2) as $df) {
        echo "  $df\n";
    }
}

// SPEC 5.9, 6.6: maxGroups counts groups actually produced; the refusal is at the row that opens group maxGroups + 1
echo "-- maxGroups\n";
$csv = "k,v,p\nA,1,0\nB,2,1\nA,3,1\nC,4,1\nB,5,1\n";
foreach (['plain' => [], 'dictionary' => ['dictionary' => ['k']]] as $label => $o) {
    $t = tb_load($csv, $K, $o);
    foreach ([3, 2, 1] as $m) {
        echo $label, ' maxGroups ', $m, ': ', tb_try(fn() => tb_term(tb_apply($t, $pred), 'groupCount', ['key' => 'k', 'maxGroups' => $m])), "\n";
    }
    echo $label, ' groupSum maxGroups 2: ', tb_try(fn() => tb_term(tb_apply($t, $pred), 'groupSum', ['key' => 'k', 'sum' => 'v', 'maxGroups' => 2])), "\n";
}
$csv = "id,k\n1,a\n2,b\n3,c\n4,d\n5,e\n6,f\n7,a\n8,b\n";
$KI = ['id' => 'int64', 'k' => 'string'];
foreach (['plain' => [], 'dictionary' => ['dictionary' => ['k']]] as $label => $o) {
    $t = tb_load($csv, $KI, $o);
    echo $label, ', 6 distinct keys, rows 7 and 8 match (2 groups): maxGroups 2: ', tb_try(fn() => tb_term($t->whereGt('id', 6), 'groupCount', ['key' => 'k', 'maxGroups' => 2])), "\n";
    echo $label, ', same, maxGroups 1: ', tb_try(fn() => tb_term($t->whereGt('id', 6), 'groupCount', ['key' => 'k', 'maxGroups' => 1])), "\n";
    echo $label, ', all rows, maxGroups 6: ', tb_try(fn() => tb_term($t->query(), 'groupCount', ['key' => 'k', 'maxGroups' => 6])), "\n";
    echo $label, ', all rows, maxGroups 5: ', tb_try(fn() => tb_term($t->query(), 'groupCount', ['key' => 'k', 'maxGroups' => 5])), "\n";
}
$csv = "id,k\n1,a\n2,\n3,b\n";
foreach (['plain' => [], 'dictionary' => ['dictionary' => ['k']]] as $label => $o) {
    $t = tb_load($csv, $KI, $o);
    echo $label, ', NULL counts as a group: maxGroups 2: ', tb_try(fn() => tb_term($t->query(), 'groupCount', ['key' => 'k', 'maxGroups' => 2])), "\n";
}
echo 'maxGroups 0: ', tb_try(fn() => tb_term($t->query(), 'groupCount', ['key' => 'k', 'maxGroups' => 0])), "\n";
echo 'group key of type float64: ', tb_try(fn() => tb_term(tb_load("f\n1.5\n", ['f' => 'float64'])->query(), 'groupCount', ['key' => 'f'])), "\n";
echo 'group key of type bool: ', tb_try(fn() => tb_term(tb_load("b\ntrue\n", ['b' => 'bool'])->query(), 'groupCount', ['key' => 'b'])), "\n";

// SPEC 5.3: maxGroupBytes given to Table::from* is stored and bounds each later group-by on that table
echo "-- stored maxGroupBytes\n";
$n = 200000;
$p = tb_big_file("id,k\n", $n, fn($i) => "$i,key$i\n");
$csv = file_get_contents($p);
$lo = ['maxGroupBytes' => 1048576];
$plain = Table::fromCsv($p, $KI, $lo);
$dict = Table::fromCsv($p, $KI, $lo + ['dictionary' => ['k']]);
echo 'load is not limited by maxGroupBytes: ', $plain->rowCount(), ' ', $dict->rowCount(), "\n";
$a = tb_try(fn() => tb_term($plain->query(), 'groupCount', ['key' => 'k']));
echo 'plain groupCount, 200,000 groups: ', preg_replace('/row \d+:/', 'row R:', $a), "\n";
echo 'plain: error equals streaming with the same maxGroupBytes: ', $a === tb_norm(tb_try(fn() => tb_term(bf_q($csv, $KI, $lo)->whereGt('id', 0), 'groupCount', ['key' => 'k']))) ? 'yes' : "NO ($a)", "\n";
$a = tb_try(fn() => tb_term($plain->query(), 'groupSum', ['key' => 'k', 'sum' => 'id']));
echo 'plain groupSum: ', preg_replace('/row \d+:/', 'row R:', $a), "\n";
$a = tb_try(fn() => tb_term($dict->query(), 'groupCount', ['key' => 'k']));
echo 'dictionary groupCount: ', $a === 'LimitException: group state exceeds maxGroupBytes' ? $a : "NO ($a)", "\n";
$a = tb_try(fn() => tb_term($dict->query(), 'groupSum', ['key' => 'k', 'sum' => 'id']));
echo 'dictionary groupSum: ', $a === 'LimitException: group state exceeds maxGroupBytes' ? $a : "NO ($a)", "\n";
$small = tb_load("id,k\n1,a\n2,b\n3,a\n", $KI, $lo + ['dictionary' => ['k']]);
echo 'few groups under the same limit: ', bf_fmt_groups($small->query()->groupCount('k')), "\n";
// free the earlier tables and the copy of the file first: a second 200,000-row table plus the result must fit memory_limit (128M)
unset($plain, $dict, $csv, $a, $small);
$big = Table::fromCsv($p, $KI, ['dictionary' => ['k']]);
echo 'default maxGroupBytes, dictionary: ', count($big->query()->groupCount('k')), ' groups; plain: ', count(Table::fromCsv($p, $KI)->query()->groupCount('k')), " groups\n";
echo 'maxGroupBytes 1,048,575: ', tb_try(fn() => tb_term(tb_load("id,k\n1,a\n", $KI, ['maxGroupBytes' => 1048575])->query(), 'count')), "\n";
?>
--EXPECT--
-- first-seen order among matching rows
plain groupCount: [s"B",int(2)]; [s"A",int(1)]; [s"C",int(1)]
plain groupSum:   [s"B",int(2),int(7)]; [s"A",int(1),int(3)]; [s"C",int(1),int(4)]
plain no predicate: [s"A",int(2)]; [s"B",int(2)]; [s"C",int(1)]
dictionary groupCount: [s"B",int(2)]; [s"A",int(1)]; [s"C",int(1)]
dictionary groupSum:   [s"B",int(2),int(7)]; [s"A",int(1),int(3)]; [s"C",int(1),int(4)]
dictionary no predicate: [s"A",int(2)]; [s"B",int(2)]; [s"C",int(1)]
streaming groupCount: [s"B",int(2)]; [s"A",int(1)]; [s"C",int(1)]
-- NULL and the empty string: separate groups, positions by first matching row
plain: [s"x",int(2),int(7)]; [NULL,int(2),int(9)]; [s"",int(1),int(4)]
dictionary: [s"x",int(2),int(7)]; [NULL,int(2),int(9)]; [s"",int(1),int(4)]
-- matrix: plain table, dictionary table, streaming
groupCount k: plain 9 of 9, dictionary 9 of 9
groupCount g: plain 9 of 9, dictionary 9 of 9
groupCount s: plain 9 of 9, dictionary 9 of 9
groupSum k v: plain 9 of 9, dictionary 9 of 9
groupSum g v: plain 9 of 9, dictionary 9 of 9
groupSum k f: plain 9 of 9, dictionary 9 of 9
groupSum g g: plain 9 of 9, dictionary 9 of 9
groupSum s v: plain 9 of 9, dictionary 9 of 9
-- maxGroups
plain maxGroups 3: [s"B",int(2)]; [s"A",int(1)]; [s"C",int(1)]
plain maxGroups 2: ResultTooLargeException: row 4: result exceeds maxGroups (2)
plain maxGroups 1: ResultTooLargeException: row 3: result exceeds maxGroups (1)
plain groupSum maxGroups 2: ResultTooLargeException: row 4: result exceeds maxGroups (2)
dictionary maxGroups 3: [s"B",int(2)]; [s"A",int(1)]; [s"C",int(1)]
dictionary maxGroups 2: ResultTooLargeException: row 4: result exceeds maxGroups (2)
dictionary maxGroups 1: ResultTooLargeException: row 3: result exceeds maxGroups (1)
dictionary groupSum maxGroups 2: ResultTooLargeException: row 4: result exceeds maxGroups (2)
plain, 6 distinct keys, rows 7 and 8 match (2 groups): maxGroups 2: [s"a",int(1)]; [s"b",int(1)]
plain, same, maxGroups 1: ResultTooLargeException: row 8: result exceeds maxGroups (1)
plain, all rows, maxGroups 6: [s"a",int(2)]; [s"b",int(2)]; [s"c",int(1)]; [s"d",int(1)]; [s"e",int(1)]; [s"f",int(1)]
plain, all rows, maxGroups 5: ResultTooLargeException: row 6: result exceeds maxGroups (5)
dictionary, 6 distinct keys, rows 7 and 8 match (2 groups): maxGroups 2: [s"a",int(1)]; [s"b",int(1)]
dictionary, same, maxGroups 1: ResultTooLargeException: row 8: result exceeds maxGroups (1)
dictionary, all rows, maxGroups 6: [s"a",int(2)]; [s"b",int(2)]; [s"c",int(1)]; [s"d",int(1)]; [s"e",int(1)]; [s"f",int(1)]
dictionary, all rows, maxGroups 5: ResultTooLargeException: row 6: result exceeds maxGroups (5)
plain, NULL counts as a group: maxGroups 2: ResultTooLargeException: row 3: result exceeds maxGroups (2)
dictionary, NULL counts as a group: maxGroups 2: ResultTooLargeException: row 3: result exceeds maxGroups (2)
maxGroups 0: ValueError: BatchFlow\Query::groupCount(): Argument #2 ($maxGroups) must be between 1 and 100000000
group key of type float64: SchemaException: column "f": group key must be string or int64
group key of type bool: SchemaException: column "b": group key must be string or int64
-- stored maxGroupBytes
load is not limited by maxGroupBytes: 200000 200000
plain groupCount, 200,000 groups: LimitException: row R: group state exceeds maxGroupBytes
plain: error equals streaming with the same maxGroupBytes: yes
plain groupSum: LimitException: row R: group state exceeds maxGroupBytes
dictionary groupCount: LimitException: group state exceeds maxGroupBytes
dictionary groupSum: LimitException: group state exceeds maxGroupBytes
few groups under the same limit: [s"a",int(2)]; [s"b",int(1)]
default maxGroupBytes, dictionary: 200000 groups; plain: 200000 groups
maxGroupBytes 1,048,575: SchemaException: option "maxGroupBytes": out of range
