--TEST--
batchflow: the Table shortcuts whereNe, whereLt, whereGte, whereLte, whereIn; min, max, avg, aggregate, groupAgg and cursor() on table queries equal streaming, on dictionary and plain columns; absent dictionary literals; a table cursor keeps its table alive
--EXTENSIONS--
batchflow
--FILE--
<?php
require __DIR__ . '/bf_table.inc';

use BatchFlow\Table;

$ids = fn($q) => implode(',', array_column($q->toArray(), 'id')) ?: '(none)';

// SPEC 5.7: the worked example of column a holding 5, NULL and 7, on a plain and on a dictionary int64 column
echo "-- the 5.7 example, int64 column a = 5, NULL, 7\n";
$A = ['id' => 'int64', 'a' => 'int64'];
$ac = "id,a\n1,5\n2,\n3,7\n";
foreach (['plain' => [], 'dictionary' => ['dictionary' => ['a']]] as $label => $o) {
    $t = tb_load($ac, $A, $o);
    foreach ([
        'whereEq 5' => fn() => $t->whereEq('a', 5),
        'whereNe 5' => fn() => $t->whereNe('a', 5),
        'whereNe null' => fn() => $t->whereNe('a', null),
        'whereLt 6' => fn() => $t->whereLt('a', 6),
        'whereGte 5' => fn() => $t->whereGte('a', 5),
        'whereLte 5' => fn() => $t->whereLte('a', 5),
        'whereLte 4' => fn() => $t->whereLte('a', 4),
        'whereIn [7, null]' => fn() => $t->whereIn('a', [7, null]),
        'whereIn [5]' => fn() => $t->whereIn('a', [5]),
        'whereIn [5, 5, 5]' => fn() => $t->whereIn('a', [5, 5, 5]),
        'whereIn [null]' => fn() => $t->whereIn('a', [null]),
        'whereNe 99 (absent)' => fn() => $t->whereNe('a', 99),
        'whereIn [99] (absent)' => fn() => $t->whereIn('a', [99]),
        'whereIn [99, null]' => fn() => $t->whereIn('a', [99, null]),
        'whereIn [99, 5]' => fn() => $t->whereIn('a', [99, 5]),
    ] as $pl => $mk) {
        echo $label, ' ', $pl, ': ids ', $ids($mk()), "\n";
    }
}

// SPEC 5.7: IEEE comparison, -0.0 equals 0.0; a float64 column cannot be a dictionary
echo "-- float64 column x = -0.0, 0.0, 1.5, NULL\n";
$t = tb_load("id,x\n1,-0.0\n2,0.0\n3,1.5\n4,\n", ['id' => 'int64', 'x' => 'float64']);
foreach ([
    'whereNe 0.0' => fn() => $t->whereNe('x', 0.0),
    'whereNe -0.0' => fn() => $t->whereNe('x', -0.0),
    'whereGte 0.0' => fn() => $t->whereGte('x', 0.0),
    'whereLte 0.0' => fn() => $t->whereLte('x', 0.0),
    'whereLt 0.0' => fn() => $t->whereLt('x', 0.0),
    'whereLte 0 (int literal)' => fn() => $t->whereLte('x', 0),
    'whereIn [0.0]' => fn() => $t->whereIn('x', [0.0]),
    'whereIn [0.0, -0.0]' => fn() => $t->whereIn('x', [0.0, -0.0]),
    'whereIn [1.5, null]' => fn() => $t->whereIn('x', [1.5, null]),
    'whereIn [1, 2] (int literals)' => fn() => $t->whereIn('x', [1, 2]),
] as $pl => $mk) {
    echo $pl, ': ids ', $ids($mk()), "\n";
}
foreach (['min', 'max'] as $m) {
    $z1 = tb_load("x\n0.0\n-0.0\n", ['x' => 'float64']);
    $z2 = tb_load("x\n-0.0\n0.0\n", ['x' => 'float64']);
    echo "$m over 0.0, -0.0: ", bf_fmt($z1->query()->$m('x')), "; over -0.0, 0.0: ", bf_fmt($z2->query()->$m('x')), "\n";
}

// SPEC 6.6: whereNe with an absent literal matches every non-NULL row; whereIn keeps the ids found
echo "-- absent literals on a string dictionary column k = a, b, NULL, '', a\n";
$K = ['id' => 'int64', 'k' => 'string'];
$kc = "id,k\n1,a\n2,b\n3,\n4,\"\"\n5,a\n";
foreach (['plain' => [], 'dictionary' => ['dictionary' => ['k']]] as $label => $o) {
    $t = tb_load($kc, $K, $o);
    foreach ([
        "whereNe 'zzz' (absent)" => fn() => $t->whereNe('k', 'zzz'),
        "whereNe 'a'" => fn() => $t->whereNe('k', 'a'),
        "whereNe ''" => fn() => $t->whereNe('k', ''),
        'whereNe null' => fn() => $t->whereNe('k', null),
        "whereIn ['zzz']" => fn() => $t->whereIn('k', ['zzz']),
        "whereIn ['zzz', 'yyy']" => fn() => $t->whereIn('k', ['zzz', 'yyy']),
        "whereIn ['zzz', null]" => fn() => $t->whereIn('k', ['zzz', null]),
        "whereIn ['a', 'zzz']" => fn() => $t->whereIn('k', ['a', 'zzz']),
        "whereIn ['']" => fn() => $t->whereIn('k', ['']),
        "whereIn ['b', '', null]" => fn() => $t->whereIn('k', ['b', '', null]),
        "whereIn ['a', 'a', 'b', 'a']" => fn() => $t->whereIn('k', ['a', 'a', 'b', 'a']),
        "whereNe 'zzz' + whereIn ['a', 'b']" => fn() => $t->whereNe('k', 'zzz')->whereIn('k', ['a', 'b']),
        "whereNe 'a' + whereNe 'b'" => fn() => $t->whereNe('k', 'a')->whereNe('k', 'b'),
    ] as $pl => $mk) {
        echo $label, ' ', $pl, ': ids ', $ids($mk()), "\n";
    }
}

// SPEC 5.7: the whereIn and range checks of a Query, raised by the Table shortcut at the call
echo "-- whereIn and range errors on the Table shortcut\n";
$t = tb_load($kc, $K);
$N = tb_load("n,s\n1,x\n", ['n' => 'int64', 's' => 'string']);
foreach ([
    'empty list' => fn() => $N->whereIn('n', []),
    'not a list' => fn() => $N->whereIn('n', ['a' => 1]),
    '1,025 elements' => fn() => $N->whereIn('n', range(1, 1025)),
    '1,024 elements' => fn() => $N->whereIn('n', range(1, 1024)),
    'array element' => fn() => $N->whereIn('n', [1, [2]]),
    "[1, '2'] on int64" => fn() => $N->whereIn('n', [1, '2']),
    'float on int64' => fn() => $N->whereIn('n', [1, 2.5]),
    'int on string' => fn() => $N->whereIn('s', ['x', 3]),
    'unknown column' => fn() => $N->whereIn('nope', [1]),
    'whereLt on string' => fn() => $N->whereLt('s', 1),
    'whereGte on string' => fn() => $N->whereGte('s', 1),
    'whereLte on string' => fn() => $N->whereLte('s', 1),
    'whereNe float on int64' => fn() => $N->whereNe('n', 1.0),
    'whereNe unknown column' => fn() => $N->whereNe('nope', 1),
] as $label => $fn) {
    echo $label, ': ', tb_try(function () use ($fn) { $fn(); return 'accepted'; }), "\n";
}

// Every extended terminal equals streaming, plain and dictionary tables
echo "-- matrix: new predicates x new terminals, plain table and dictionary table, against streaming\n";
[$csv, $S] = tb_fixture(1200, 111);
$o = ['dictionary' => ['k', 'g']];
$tp = tb_load($csv, $S);
$td = tb_load($csv, $S, $o);
$sets = [
    'none' => [],
    'k != red' => [['ne', 'k', 'red']],
    'k != absent' => [['ne', 'k', 'zzz']],
    'k IS NOT NULL' => [['ne', 'k', null]],
    'g != NULL, g < 0' => [['ne', 'g', null], ['lt', 'g', 0]],
    'g >= 2000006' => [['gte', 'g', 2000006]],
    'g <= -1000003' => [['lte', 'g', -1000003]],
    'v >= 10, v <= 200' => [['gte', 'v', 10], ['lte', 'v', 200]],
    'f <= 0.0' => [['lte', 'f', 0.0]],
    'f < 10, f >= -10' => [['lt', 'f', 10.0], ['gte', 'f', -10.0]],
    'k in (red, blue, absent)' => [['in', 'k', ['red', 'blue', 'zzz']]],
    'k in (green, NULL)' => [['in', 'k', ['green', null]]],
    'k in (absent)' => [['in', 'k', ['zzz']]],
    'g in (0, 1000003, NULL)' => [['in', 'g', [0, 1000003, null]]],
    'g in (absent)' => [['in', 'g', [77]]],
    'id in (1, 5, 9999)' => [['in', 'id', [1, 5, 9999]]],
    'k in (red, "" , NULL), g != 0' => [['in', 'k', ['red', '', null]], ['ne', 'g', 0]],
    'b in (true), s != u5' => [['in', 'b', [true]], ['ne', 's', 'u5']],
];
$aggs = [['count', 'k'], ['sum', 'v'], ['min', 'f'], ['max', 'g'], ['avg', 'v'], ['count', 'f'], ['sum', 'g'], ['avg', 'f'], ['min', 'v']];
$terms = [
    'min v' => ['min', ['col' => 'v']],
    'max f' => ['max', ['col' => 'f']],
    'min g (dictionary int)' => ['min', ['col' => 'g']],
    'max g' => ['max', ['col' => 'g']],
    'avg v' => ['avg', ['col' => 'v']],
    'avg f' => ['avg', ['col' => 'f']],
    'aggregate all ops' => ['aggregate', ['aggs' => $aggs]],
    'aggregate 1' => ['aggregate', ['aggs' => [['max', 'id']]]],
    'groupAgg k' => ['groupAgg', ['keys' => 'k', 'aggs' => $aggs]],
    'groupAgg g, no pairs' => ['groupAgg', ['keys' => 'g', 'aggs' => []]],
    'groupAgg k,g' => ['groupAgg', ['keys' => ['k', 'g'], 'aggs' => [['sum', 'v'], ['min', 'f']]]],
    'groupAgg g,k' => ['groupAgg', ['keys' => ['g', 'k'], 'aggs' => [['count', 's'], ['max', 'v']]]],
    'groupAgg k,g,s (s plain)' => ['groupAgg', ['keys' => ['k', 'g', 's'], 'aggs' => [['count', 'v']]]],
    'groupAgg s,k' => ['groupAgg', ['keys' => ['s', 'k'], 'aggs' => []]],
    'groupAgg k maxGroups 4' => ['groupAgg', ['keys' => 'k', 'aggs' => [['sum', 'v']], 'maxGroups' => 4]],
    'groupAgg k,g maxGroups 9' => ['groupAgg', ['keys' => ['k', 'g'], 'aggs' => [], 'maxGroups' => 9]],
    'cursor batch 1' => ['cursor', ['batch' => 1]],
    'cursor batch 7' => ['cursor', ['batch' => 7]],
    'cursor batch 1000' => ['cursor', ['batch' => 1000]],
    'cursor batch 1201' => ['cursor', ['batch' => 1201]],
];
foreach ($terms as $label => [$term, $x]) {
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
        echo "  " . substr($df, 0, 300) . "\n";
    }
}

// SPEC 6.6: groupAgg on a table reads a dictionary key through its id and converts it back; known values
echo "-- groupAgg with dictionary keys, known values\n";
$G = ['id' => 'int64', 'k' => 'string', 'g' => 'int64', 'v' => 'int64'];
$gc = "id,k,g,v\n1,a,1,10\n2,,1,20\n3,a,,30\n4,\"\",1,40\n5,a,1,50\n6,,,60\n";
foreach (['plain' => [], 'dictionary' => ['dictionary' => ['k', 'g']]] as $label => $o2) {
    $t = tb_load($gc, $G, $o2);
    echo $label, ' [k,g] count,sum: ', tb_term($t->query(), 'groupAgg', ['keys' => ['k', 'g'], 'aggs' => [['sum', 'v']]]), "\n";
    echo $label, ' [k] only, id > 2: ', tb_term($t->whereGt('id', 2), 'groupAgg', ['keys' => 'k', 'aggs' => [['count', 'g'], ['max', 'v']]]), "\n";
    echo $label, ' [k] no pairs equals groupCount: ', tb_term($t->query(), 'groupAgg', ['keys' => 'k', 'aggs' => []]) === tb_term($t->query(), 'groupCount', ['key' => 'k']) ? 'yes' : 'NO', "\n";
}

// SPEC 5.12, 6.8: a cursor holds its Query, so the Table; the table survives unset of table and query
echo "-- a table cursor keeps its table alive\n";
$p = tb_file($csv);
$c = Table::fromCsv($p, $S, $o)->whereEq('k', 'red')->cursor();
gc_collect_cycles();
$t2 = Table::fromCsv($p, $S, $o);
$q2 = $t2->whereEq('k', 'red');
$c2 = $q2->cursor();
unset($t2, $q2);
gc_collect_cycles();
$want = tb_norm(tb_try(fn() => bf_fmt_rows(bf_apply(bf_q($csv, $S), [['eq', 'k', 'red']])->toArray(10000))));
echo 'temporary table, cursor drained: ', bf_fmt_rows(tb_drain($c, 25)) === $want ? 'same' : 'DIFFERENT', "\n";
echo 'unset table and query, cursor drained: ', bf_fmt_rows(tb_drain($c2, 40)) === $want ? 'same' : 'DIFFERENT', "\n";
echo 'then exhausted: ', json_encode([$c->fetch(), $c2->fetch()]), "\n";

echo "-- cursor() performs no I/O and works after the source is gone\n";
$p3 = tb_file("a\n1\n2\n3\n");
$t3 = Table::fromCsv($p3, ['a' => 'int64']);
unlink($p3);
$c3 = $t3->whereGt('a', 1)->cursor();
echo 'rows: ', json_encode(tb_drain($c3, 1)), "\n";

echo "-- every batch size from 1 to the row count + 1 gives toArray (plain and dictionary)\n";
[$csv, $S] = tb_fixture(40, 1111);
$bad = 0;
foreach (['plain' => [], 'dictionary' => ['dictionary' => ['k', 'g']]] as $label => $o3) {
    $t = tb_load($csv, $S, $o3);
    foreach ([[], [['ne', 'k', 'red']], [['in', 'g', [0, 1000003, null]]], [['eq', 'k', 'zzz']]] as $preds) {
        $full = tb_term(tb_apply($t, $preds), 'toArray');
        for ($b = 1; $b <= 41; $b++) {
            if (tb_term(tb_apply($t, $preds), 'cursor', ['batch' => $b]) !== $full) {
                $bad++;
            }
        }
    }
}
echo 'mismatches: ', $bad, "\n";
?>
--EXPECT--
-- the 5.7 example, int64 column a = 5, NULL, 7
plain whereEq 5: ids 1
plain whereNe 5: ids 3
plain whereNe null: ids 1,3
plain whereLt 6: ids 1
plain whereGte 5: ids 1,3
plain whereLte 5: ids 1
plain whereLte 4: ids (none)
plain whereIn [7, null]: ids 2,3
plain whereIn [5]: ids 1
plain whereIn [5, 5, 5]: ids 1
plain whereIn [null]: ids 2
plain whereNe 99 (absent): ids 1,3
plain whereIn [99] (absent): ids (none)
plain whereIn [99, null]: ids 2
plain whereIn [99, 5]: ids 1
dictionary whereEq 5: ids 1
dictionary whereNe 5: ids 3
dictionary whereNe null: ids 1,3
dictionary whereLt 6: ids 1
dictionary whereGte 5: ids 1,3
dictionary whereLte 5: ids 1
dictionary whereLte 4: ids (none)
dictionary whereIn [7, null]: ids 2,3
dictionary whereIn [5]: ids 1
dictionary whereIn [5, 5, 5]: ids 1
dictionary whereIn [null]: ids 2
dictionary whereNe 99 (absent): ids 1,3
dictionary whereIn [99] (absent): ids (none)
dictionary whereIn [99, null]: ids 2
dictionary whereIn [99, 5]: ids 1
-- float64 column x = -0.0, 0.0, 1.5, NULL
whereNe 0.0: ids 3
whereNe -0.0: ids 3
whereGte 0.0: ids 1,2,3
whereLte 0.0: ids 1,2
whereLt 0.0: ids (none)
whereLte 0 (int literal): ids 1,2
whereIn [0.0]: ids 1,2
whereIn [0.0, -0.0]: ids 1,2
whereIn [1.5, null]: ids 3,4
whereIn [1, 2] (int literals): ids (none)
min over 0.0, -0.0: float(0.0); over -0.0, 0.0: float(-0.0)
max over 0.0, -0.0: float(0.0); over -0.0, 0.0: float(-0.0)
-- absent literals on a string dictionary column k = a, b, NULL, '', a
plain whereNe 'zzz' (absent): ids 1,2,4,5
plain whereNe 'a': ids 2,4
plain whereNe '': ids 1,2,5
plain whereNe null: ids 1,2,4,5
plain whereIn ['zzz']: ids (none)
plain whereIn ['zzz', 'yyy']: ids (none)
plain whereIn ['zzz', null]: ids 3
plain whereIn ['a', 'zzz']: ids 1,5
plain whereIn ['']: ids 4
plain whereIn ['b', '', null]: ids 2,3,4
plain whereIn ['a', 'a', 'b', 'a']: ids 1,2,5
plain whereNe 'zzz' + whereIn ['a', 'b']: ids 1,2,5
plain whereNe 'a' + whereNe 'b': ids 4
dictionary whereNe 'zzz' (absent): ids 1,2,4,5
dictionary whereNe 'a': ids 2,4
dictionary whereNe '': ids 1,2,5
dictionary whereNe null: ids 1,2,4,5
dictionary whereIn ['zzz']: ids (none)
dictionary whereIn ['zzz', 'yyy']: ids (none)
dictionary whereIn ['zzz', null]: ids 3
dictionary whereIn ['a', 'zzz']: ids 1,5
dictionary whereIn ['']: ids 4
dictionary whereIn ['b', '', null]: ids 2,3,4
dictionary whereIn ['a', 'a', 'b', 'a']: ids 1,2,5
dictionary whereNe 'zzz' + whereIn ['a', 'b']: ids 1,2,5
dictionary whereNe 'a' + whereNe 'b': ids 4
-- whereIn and range errors on the Table shortcut
empty list: ValueError: BatchFlow\Table::whereIn(): Argument #2 ($values) must not be empty
not a list: ValueError: BatchFlow\Table::whereIn(): Argument #2 ($values) must be a list
1,025 elements: ValueError: BatchFlow\Table::whereIn(): Argument #2 ($values) must contain at most 1024 values
1,024 elements: accepted
array element: TypeError: BatchFlow\Table::whereIn(): Argument #2 ($values) element 1 must be of type string|int|float|bool|null, array given
[1, '2'] on int64: SchemaException: column "n": whereIn element 1: value of type string cannot be compared with int64 column
float on int64: SchemaException: column "n": whereIn element 1: value of type float cannot be compared with int64 column
int on string: SchemaException: column "s": whereIn element 1: value of type int cannot be compared with string column
unknown column: SchemaException: unknown column "nope"
whereLt on string: SchemaException: column "s": whereLt requires a numeric column
whereGte on string: SchemaException: column "s": whereGte requires a numeric column
whereLte on string: SchemaException: column "s": whereLte requires a numeric column
whereNe float on int64: SchemaException: column "n": value of type float cannot be compared with int64 column
whereNe unknown column: SchemaException: unknown column "nope"
-- matrix: new predicates x new terminals, plain table and dictionary table, against streaming
min v: plain 18 of 18, dictionary 18 of 18
max f: plain 18 of 18, dictionary 18 of 18
min g (dictionary int): plain 18 of 18, dictionary 18 of 18
max g: plain 18 of 18, dictionary 18 of 18
avg v: plain 18 of 18, dictionary 18 of 18
avg f: plain 18 of 18, dictionary 18 of 18
aggregate all ops: plain 18 of 18, dictionary 18 of 18
aggregate 1: plain 18 of 18, dictionary 18 of 18
groupAgg k: plain 18 of 18, dictionary 18 of 18
groupAgg g, no pairs: plain 18 of 18, dictionary 18 of 18
groupAgg k,g: plain 18 of 18, dictionary 18 of 18
groupAgg g,k: plain 18 of 18, dictionary 18 of 18
groupAgg k,g,s (s plain): plain 18 of 18, dictionary 18 of 18
groupAgg s,k: plain 18 of 18, dictionary 18 of 18
groupAgg k maxGroups 4: plain 18 of 18, dictionary 18 of 18
groupAgg k,g maxGroups 9: plain 18 of 18, dictionary 18 of 18
cursor batch 1: plain 18 of 18, dictionary 18 of 18
cursor batch 7: plain 18 of 18, dictionary 18 of 18
cursor batch 1000: plain 18 of 18, dictionary 18 of 18
cursor batch 1201: plain 18 of 18, dictionary 18 of 18
-- groupAgg with dictionary keys, known values
plain [k,g] count,sum: [s"a",int(1),int(2),int(60)]; [NULL,int(1),int(1),int(20)]; [s"a",NULL,int(1),int(30)]; [s"",int(1),int(1),int(40)]; [NULL,NULL,int(1),int(60)]
plain [k] only, id > 2: [s"a",int(2),int(1),int(50)]; [s"",int(1),int(1),int(40)]; [NULL,int(1),int(0),int(60)]
plain [k] no pairs equals groupCount: yes
dictionary [k,g] count,sum: [s"a",int(1),int(2),int(60)]; [NULL,int(1),int(1),int(20)]; [s"a",NULL,int(1),int(30)]; [s"",int(1),int(1),int(40)]; [NULL,NULL,int(1),int(60)]
dictionary [k] only, id > 2: [s"a",int(2),int(1),int(50)]; [s"",int(1),int(1),int(40)]; [NULL,int(1),int(0),int(60)]
dictionary [k] no pairs equals groupCount: yes
-- a table cursor keeps its table alive
temporary table, cursor drained: same
unset table and query, cursor drained: same
then exhausted: [[],[]]
-- cursor() performs no I/O and works after the source is gone
rows: [{"a":2},{"a":3}]
-- every batch size from 1 to the row count + 1 gives toArray (plain and dictionary)
mismatches: 0
