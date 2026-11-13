--TEST--
batchflow: whereNe, whereLt, whereGte, whereLte over every accepted pair of SPEC 5.7 against the reference; the three-valued NULL example; -0.0; int64 and float round-trip boundaries; numeric-column and literal errors; receiver unchanged
--EXTENSIONS--
batchflow
--FILE--
<?php
require __DIR__ . '/bf_helper.inc';
require __DIR__ . '/bf_reference_m2b.inc';

use BatchFlow\Csv;

$S = ['id' => 'int64', 'i' => 'int64', 'f' => 'float64', 'b' => 'bool', 's' => 'string'];
$rows = [
    ['-9223372036854775808', '-0.0', 'true', 'a'],
    ['-5', '0.0', 'false', '""'],
    ['-1', '1.5', '', ''],
    ['0', '-2.5', 'true', 'é'],
    ['', '9007199254740992', 'false', 'b'],
    ['1', '-9007199254740992', '', 'ab'],
    ['5', '', 'true', '"a,b"'],
    ['7', '1e300', 'false', 'a'],
    ['9223372036854775807', '0.1', 'true', 'B'],
    ['9223372036854775806', '-1e-300', '', '"x""y"'],
];
$csv = "id,i,f,b,s\n";
foreach ($rows as $n => $r) {
    $csv .= ($n + 1) . ',' . implode(',', $r) . "\n";
}

/** Matching ids, through groupCount on the id column, so each row shows; "(none)" when no row matches. */
function ids(BatchFlow\Query $q): string
{
    $r = implode(' ', array_map(fn($g) => $g[0], $q->groupCount('id')));
    return $r === '' ? '(none)' : $r;
}

$lits = [
    'i' => [null, PHP_INT_MIN, PHP_INT_MIN + 1, -5, -1, 0, 1, 5, 6, 7, PHP_INT_MAX - 1, PHP_INT_MAX],
    'f' => [null, -0.0, 0.0, 1.5, -2.5, 0.1, 1e300, -1e300, 9007199254740992, -9007199254740992, 2 ** 52, 0, 1, -1, 5e-324],
    'b' => [null, true, false],
    's' => [null, '', 'a', 'é', 'ab', 'a,b', 'x"y', 'B', 'zz'],
];
echo "-- every accepted pair against the reference: count and sum(id)\n";
$total = 0;
$diffs = [];
foreach (['ne', 'lt', 'gte', 'lte'] as $op) {
    $n = 0;
    foreach ($lits as $col => $vals) {
        foreach ($vals as $lit) {
            if ($op !== 'ne' && ($lit === null || $col === 'b' || $col === 's')) {
                continue;
            }
            foreach (['count', 'sum'] as $term) {
                $r = bf_cmp($csv, $S, [], [[$op, $col, $lit]], $term, $term === 'sum' ? 'id' : null);
                $n++;
                if ($r !== 'same') {
                    $diffs[] = "$op $col " . var_export($lit, true) . " $term: $r";
                }
            }
        }
    }
    echo str_pad($op, 4), "$n comparisons\n";
    $total += $n;
}
echo "two-predicate combinations\n";
foreach ([['ne', 'i', 5], ['gte', 'f', 0.0], ['lt', 'i', 7], ['lte', 'f', 1.5], ['ne', 's', 'a'], ['ne', 'b', null]] as $p1) {
    foreach ([['ne', 'i', null], ['lte', 'i', 1], ['gte', 'id', 3], ['ne', 'b', true], ['eq', 's', 'a'], ['gt', 'f', -3]] as $p2) {
        $r = bf_cmp($csv, $S, [], [$p1, $p2], 'summary', 'f');
        $total++;
        if ($r !== 'same') {
            $diffs[] = json_encode([$p1, $p2]) . ": $r";
        }
    }
}
echo "comparisons: $total, differences: ", count($diffs), "\n";
foreach (array_slice($diffs, 0, 10) as $d) {
    echo "  $d\n";
}

echo "-- the SPEC 5.7 example: a column a holding 5, NULL and 7\n";
$q = bf_q("id,a\n1,5\n2,\n3,7\n", ['id' => 'int64', 'a' => 'int64']);
foreach ([
    "whereEq('a', 5)" => fn() => $q->whereEq('a', 5),
    "whereNe('a', 5)" => fn() => $q->whereNe('a', 5),
    "whereNe('a', null)" => fn() => $q->whereNe('a', null),
    "whereLt('a', 6)" => fn() => $q->whereLt('a', 6),
    "whereGte('a', 5)" => fn() => $q->whereGte('a', 5),
    "whereIn('a', [7, null])" => fn() => $q->whereIn('a', [7, null]),
    "whereEq('a', null)" => fn() => $q->whereEq('a', null),
    "whereLte('a', 7)" => fn() => $q->whereLte('a', 7),
    "whereGte('a', 8)" => fn() => $q->whereGte('a', 8),
] as $label => $fn) {
    echo str_pad($label, 26), 'ids ', ids($fn()), "\n";
}

echo "-- whereNe(col, null) is IS NOT NULL on every type\n";
$N = "id,i,f,b,s\n1,1,1.5,true,x\n2,,,,\n3,0,0.0,false,\"\"\n";
$NS = ['id' => 'int64', 'i' => 'int64', 'f' => 'float64', 'b' => 'bool', 's' => 'string'];
foreach (['i', 'f', 'b', 's'] as $c) {
    echo "whereNe('$c', null)        ids ", ids(bf_q($N, $NS)->whereNe($c, null)), "   whereEq('$c', null) ids ", ids(bf_q($N, $NS)->whereEq($c, null)), "\n";
}
echo "-- a non-null literal never matches a NULL row\n";
echo "whereNe('i', 9)            ids ", ids(bf_q($N, $NS)->whereNe('i', 9)), "\n";
echo "whereNe('s', 'q')          ids ", ids(bf_q($N, $NS)->whereNe('s', 'q')), "\n";
echo "whereNe('b', true)         ids ", ids(bf_q($N, $NS)->whereNe('b', true)), "\n";
echo "whereNe('s', '')           ids ", ids(bf_q($N, $NS)->whereNe('s', '')), "\n";

echo "-- -0.0 equals 0.0 (IEEE) for each comparison\n";
$Z = bf_q("id,x\n1,-0.0\n2,0.0\n3,1\n4,-1\n", ['id' => 'int64', 'x' => 'float64']);
foreach (['whereNe', 'whereLt', 'whereGte', 'whereLte', 'whereEq', 'whereGt'] as $m) {
    foreach ([0.0, -0.0, 0] as $lit) {
        echo str_pad("$m('x', " . var_export($lit, true) . ')', 26), 'ids ', ids($Z->$m('x', $lit)), "\n";
    }
}

echo "-- int64 bounds\n";
$B = bf_q("id,i\n1,-9223372036854775808\n2,0\n3,9223372036854775807\n", ['id' => 'int64', 'i' => 'int64']);
foreach ([
    "whereLt('i', PHP_INT_MIN)" => fn() => $B->whereLt('i', PHP_INT_MIN),
    "whereLte('i', PHP_INT_MIN)" => fn() => $B->whereLte('i', PHP_INT_MIN),
    "whereGte('i', PHP_INT_MIN)" => fn() => $B->whereGte('i', PHP_INT_MIN),
    "whereNe('i', PHP_INT_MIN)" => fn() => $B->whereNe('i', PHP_INT_MIN),
    "whereGte('i', PHP_INT_MAX)" => fn() => $B->whereGte('i', PHP_INT_MAX),
    "whereLte('i', PHP_INT_MAX)" => fn() => $B->whereLte('i', PHP_INT_MAX),
    "whereLt('i', PHP_INT_MAX)" => fn() => $B->whereLt('i', PHP_INT_MAX),
    "whereNe('i', PHP_INT_MAX)" => fn() => $B->whereNe('i', PHP_INT_MAX),
] as $label => $fn) {
    echo str_pad($label, 30), 'ids ', ids($fn()), "\n";
}

echo "-- int literals on a float64 column must round-trip (2^53 yes, 2^53+1 and 2^63-1 no)\n";
$F = bf_q("id,f\n1,9007199254740992\n2,9007199254740993\n3,-9007199254740992\n4,1e19\n", ['id' => 'int64', 'f' => 'float64']);
foreach (['whereNe', 'whereLt', 'whereGte', 'whereLte'] as $m) {
    foreach ([2 ** 53, -(2 ** 53), 2 ** 53 + 1, -(2 ** 53) - 1, PHP_INT_MAX, PHP_INT_MIN] as $lit) {
        echo str_pad("$m('f', $lit)", 36), bf_try(fn() => ids($F->$m('f', $lit))), "\n";
    }
}

echo "-- range predicates require a numeric column, whatever the literal\n";
$q = Csv::scan('/nonexistent/batchflow/none.csv', $S);
foreach (['whereLt', 'whereGte', 'whereLte', 'whereGt'] as $m) {
    foreach ([['b', 1], ['s', 1.5], ['b', 0]] as [$c, $lit]) {
        echo str_pad("$m('$c', " . var_export($lit, true) . ')', 26), bf_try(fn() => $q->$m($c, $lit)), "\n";
    }
}
echo "-- a null or string literal on a range predicate is a TypeError, under either strict_types mode\n";
foreach (['whereLt', 'whereGte', 'whereLte'] as $m) {
    foreach ([null, '5', true, [1]] as $lit) {
        echo str_pad("$m('i', " . json_encode($lit) . ')', 26), bf_try(fn() => $q->$m('i', $lit)), "\n";
    }
}
echo "-- literal checks for whereNe and the ranges\n";
foreach ([
    "whereNe('i', 1.0)" => fn() => $q->whereNe('i', 1.0),
    "whereNe('i', '1')" => fn() => $q->whereNe('i', '1'),
    "whereNe('i', true)" => fn() => $q->whereNe('i', true),
    "whereNe('f', '1.5')" => fn() => $q->whereNe('f', '1.5'),
    "whereNe('f', INF)" => fn() => $q->whereNe('f', INF),
    "whereNe('f', NAN)" => fn() => $q->whereNe('f', NAN),
    "whereNe('b', 1)" => fn() => $q->whereNe('b', 1),
    "whereNe('b', 'true')" => fn() => $q->whereNe('b', 'true'),
    "whereNe('s', 1)" => fn() => $q->whereNe('s', 1),
    "whereNe('s', \"\\xFF\")" => fn() => $q->whereNe('s', "\xFF"),
    "whereNe('s', 1 MiB + 1)" => fn() => $q->whereNe('s', str_repeat('a', 1048577)),
    "whereNe('s', 1 MiB)" => fn() => get_class($q->whereNe('s', str_repeat('a', 1048576))),
    "whereNe('s', [1])" => fn() => $q->whereNe('s', [1]),
    "whereNe('s', new stdClass)" => fn() => $q->whereNe('s', new stdClass()),
    "whereLt('i', 1.5)" => fn() => $q->whereLt('i', 1.5),
    "whereGte('f', -INF)" => fn() => $q->whereGte('f', -INF),
    "whereLte('f', NAN)" => fn() => $q->whereLte('f', NAN),
    "whereLt('nope', 1)" => fn() => $q->whereLt('nope', 1),
    "whereNe('nope', [1])" => fn() => $q->whereNe('nope', [1]),
] as $label => $fn) {
    echo str_pad($label, 30), bf_try($fn), "\n";
}
echo "-- unknown column before any I/O (the path does not exist)\n";
foreach (['whereNe', 'whereLt', 'whereGte', 'whereLte'] as $m) {
    echo str_pad("$m('nope', 1)", 30), bf_try(fn() => $q->$m('nope', 1)), "\n";
}
echo "-- predicate count comes before the column and literal checks\n";
$p = $q;
for ($i = 0; $i < 64; $i++) {
    $p = $p->whereGte('i', $i);
}
echo "64 predicates: ", get_class($p), "\n";
foreach (['whereNe', 'whereLt', 'whereGte', 'whereLte'] as $m) {
    echo str_pad("65th $m('nope', 1.5)", 30), bf_try(fn() => $p->$m('nope', 1.5)), "\n";
}

echo "-- receiver unchanged; derived queries independent\n";
$base = bf_q($csv, $S);
$d1 = $base->whereNe('i', 5);
$d2 = $base->whereLt('i', 0);
$d3 = $d2->whereGte('f', 0.0);
$d4 = $d3->whereLte('id', 2);
echo $base->count(), ' ', $d1->count(), ' ', $d2->count(), ' ', $d3->count(), ' ', $d4->count(), ' ', $base->count(), "\n";
?>
--EXPECT--
-- every accepted pair against the reference: count and sum(id)
ne  78 comparisons
lt  50 comparisons
gte 50 comparisons
lte 50 comparisons
two-predicate combinations
comparisons: 264, differences: 0
-- the SPEC 5.7 example: a column a holding 5, NULL and 7
whereEq('a', 5)           ids 1
whereNe('a', 5)           ids 3
whereNe('a', null)        ids 1 3
whereLt('a', 6)           ids 1
whereGte('a', 5)          ids 1 3
whereIn('a', [7, null])   ids 2 3
whereEq('a', null)        ids 2
whereLte('a', 7)          ids 1 3
whereGte('a', 8)          ids (none)
-- whereNe(col, null) is IS NOT NULL on every type
whereNe('i', null)        ids 1 3   whereEq('i', null) ids 2
whereNe('f', null)        ids 1 3   whereEq('f', null) ids 2
whereNe('b', null)        ids 1 3   whereEq('b', null) ids 2
whereNe('s', null)        ids 1 3   whereEq('s', null) ids 2
-- a non-null literal never matches a NULL row
whereNe('i', 9)            ids 1 3
whereNe('s', 'q')          ids 1 3
whereNe('b', true)         ids 3
whereNe('s', '')           ids 1
-- -0.0 equals 0.0 (IEEE) for each comparison
whereNe('x', 0.0)         ids 3 4
whereNe('x', -0.0)        ids 3 4
whereNe('x', 0)           ids 3 4
whereLt('x', 0.0)         ids 4
whereLt('x', -0.0)        ids 4
whereLt('x', 0)           ids 4
whereGte('x', 0.0)        ids 1 2 3
whereGte('x', -0.0)       ids 1 2 3
whereGte('x', 0)          ids 1 2 3
whereLte('x', 0.0)        ids 1 2 4
whereLte('x', -0.0)       ids 1 2 4
whereLte('x', 0)          ids 1 2 4
whereEq('x', 0.0)         ids 1 2
whereEq('x', -0.0)        ids 1 2
whereEq('x', 0)           ids 1 2
whereGt('x', 0.0)         ids 3
whereGt('x', -0.0)        ids 3
whereGt('x', 0)           ids 3
-- int64 bounds
whereLt('i', PHP_INT_MIN)     ids (none)
whereLte('i', PHP_INT_MIN)    ids 1
whereGte('i', PHP_INT_MIN)    ids 1 2 3
whereNe('i', PHP_INT_MIN)     ids 2 3
whereGte('i', PHP_INT_MAX)    ids 3
whereLte('i', PHP_INT_MAX)    ids 1 2 3
whereLt('i', PHP_INT_MAX)     ids 1 2
whereNe('i', PHP_INT_MAX)     ids 1 2
-- int literals on a float64 column must round-trip (2^53 yes, 2^53+1 and 2^63-1 no)
whereNe('f', 9007199254740992)      3 4
whereNe('f', -9007199254740992)     1 2 4
whereNe('f', 9007199254740993)      SchemaException: column "f": integer literal is not exactly representable as float64
whereNe('f', -9007199254740993)     SchemaException: column "f": integer literal is not exactly representable as float64
whereNe('f', 9223372036854775807)   SchemaException: column "f": integer literal is not exactly representable as float64
whereNe('f', -9223372036854775808)  1 2 3 4
whereLt('f', 9007199254740992)      3
whereLt('f', -9007199254740992)     (none)
whereLt('f', 9007199254740993)      SchemaException: column "f": integer literal is not exactly representable as float64
whereLt('f', -9007199254740993)     SchemaException: column "f": integer literal is not exactly representable as float64
whereLt('f', 9223372036854775807)   SchemaException: column "f": integer literal is not exactly representable as float64
whereLt('f', -9223372036854775808)  (none)
whereGte('f', 9007199254740992)     1 2 4
whereGte('f', -9007199254740992)    1 2 3 4
whereGte('f', 9007199254740993)     SchemaException: column "f": integer literal is not exactly representable as float64
whereGte('f', -9007199254740993)    SchemaException: column "f": integer literal is not exactly representable as float64
whereGte('f', 9223372036854775807)  SchemaException: column "f": integer literal is not exactly representable as float64
whereGte('f', -9223372036854775808) 1 2 3 4
whereLte('f', 9007199254740992)     1 2 3
whereLte('f', -9007199254740992)    3
whereLte('f', 9007199254740993)     SchemaException: column "f": integer literal is not exactly representable as float64
whereLte('f', -9007199254740993)    SchemaException: column "f": integer literal is not exactly representable as float64
whereLte('f', 9223372036854775807)  SchemaException: column "f": integer literal is not exactly representable as float64
whereLte('f', -9223372036854775808) (none)
-- range predicates require a numeric column, whatever the literal
whereLt('b', 1)           SchemaException: column "b": whereLt requires a numeric column
whereLt('s', 1.5)         SchemaException: column "s": whereLt requires a numeric column
whereLt('b', 0)           SchemaException: column "b": whereLt requires a numeric column
whereGte('b', 1)          SchemaException: column "b": whereGte requires a numeric column
whereGte('s', 1.5)        SchemaException: column "s": whereGte requires a numeric column
whereGte('b', 0)          SchemaException: column "b": whereGte requires a numeric column
whereLte('b', 1)          SchemaException: column "b": whereLte requires a numeric column
whereLte('s', 1.5)        SchemaException: column "s": whereLte requires a numeric column
whereLte('b', 0)          SchemaException: column "b": whereLte requires a numeric column
whereGt('b', 1)           SchemaException: column "b": whereGt requires a numeric column
whereGt('s', 1.5)         SchemaException: column "s": whereGt requires a numeric column
whereGt('b', 0)           SchemaException: column "b": whereGt requires a numeric column
-- a null or string literal on a range predicate is a TypeError, under either strict_types mode
whereLt('i', null)        TypeError: BatchFlow\Query::whereLt(): Argument #2 ($value) must be of type int|float, null given
whereLt('i', "5")         TypeError: BatchFlow\Query::whereLt(): Argument #2 ($value) must be of type int|float, string given
whereLt('i', true)        TypeError: BatchFlow\Query::whereLt(): Argument #2 ($value) must be of type int|float, true given
whereLt('i', [1])         TypeError: BatchFlow\Query::whereLt(): Argument #2 ($value) must be of type int|float, array given
whereGte('i', null)       TypeError: BatchFlow\Query::whereGte(): Argument #2 ($value) must be of type int|float, null given
whereGte('i', "5")        TypeError: BatchFlow\Query::whereGte(): Argument #2 ($value) must be of type int|float, string given
whereGte('i', true)       TypeError: BatchFlow\Query::whereGte(): Argument #2 ($value) must be of type int|float, true given
whereGte('i', [1])        TypeError: BatchFlow\Query::whereGte(): Argument #2 ($value) must be of type int|float, array given
whereLte('i', null)       TypeError: BatchFlow\Query::whereLte(): Argument #2 ($value) must be of type int|float, null given
whereLte('i', "5")        TypeError: BatchFlow\Query::whereLte(): Argument #2 ($value) must be of type int|float, string given
whereLte('i', true)       TypeError: BatchFlow\Query::whereLte(): Argument #2 ($value) must be of type int|float, true given
whereLte('i', [1])        TypeError: BatchFlow\Query::whereLte(): Argument #2 ($value) must be of type int|float, array given
-- literal checks for whereNe and the ranges
whereNe('i', 1.0)             SchemaException: column "i": value of type float cannot be compared with int64 column
whereNe('i', '1')             SchemaException: column "i": value of type string cannot be compared with int64 column
whereNe('i', true)            SchemaException: column "i": value of type bool cannot be compared with int64 column
whereNe('f', '1.5')           SchemaException: column "f": value of type string cannot be compared with float64 column
whereNe('f', INF)             SchemaException: column "f": float literal must be finite
whereNe('f', NAN)             SchemaException: column "f": float literal must be finite
whereNe('b', 1)               SchemaException: column "b": value of type int cannot be compared with bool column
whereNe('b', 'true')          SchemaException: column "b": value of type string cannot be compared with bool column
whereNe('s', 1)               SchemaException: column "s": value of type int cannot be compared with string column
whereNe('s', "\xFF")          SchemaException: column "s": string literal is not valid UTF-8
whereNe('s', 1 MiB + 1)       SchemaException: column "s": string literal exceeds 1048576 bytes
whereNe('s', 1 MiB)           BatchFlow\Query
whereNe('s', [1])             TypeError: BatchFlow\Query::whereNe(): Argument #2 ($value) must be of type string|int|float|bool|null, array given
whereNe('s', new stdClass)    TypeError: BatchFlow\Query::whereNe(): Argument #2 ($value) must be of type string|int|float|bool|null, stdClass given
whereLt('i', 1.5)             SchemaException: column "i": value of type float cannot be compared with int64 column
whereGte('f', -INF)           SchemaException: column "f": float literal must be finite
whereLte('f', NAN)            SchemaException: column "f": float literal must be finite
whereLt('nope', 1)            SchemaException: unknown column "nope"
whereNe('nope', [1])          TypeError: BatchFlow\Query::whereNe(): Argument #2 ($value) must be of type string|int|float|bool|null, array given
-- unknown column before any I/O (the path does not exist)
whereNe('nope', 1)            SchemaException: unknown column "nope"
whereLt('nope', 1)            SchemaException: unknown column "nope"
whereGte('nope', 1)           SchemaException: unknown column "nope"
whereLte('nope', 1)           SchemaException: unknown column "nope"
-- predicate count comes before the column and literal checks
64 predicates: BatchFlow\Query
65th whereNe('nope', 1.5)     SchemaException: too many predicates (limit 64)
65th whereLt('nope', 1.5)     SchemaException: too many predicates (limit 64)
65th whereGte('nope', 1.5)    SchemaException: too many predicates (limit 64)
65th whereLte('nope', 1.5)    SchemaException: too many predicates (limit 64)
-- receiver unchanged; derived queries independent
10 8 3 3 2 10
