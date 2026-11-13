--TEST--
batchflow: whereIn membership on all four types against the reference; null in the list; duplicates and [0.0, -0.0] collapse; 1, 1024 and 1025 elements; one predicate; every ValueError, TypeError and element reason with its index; the full check order of SPEC 5.7
--EXTENSIONS--
batchflow
--FILE--
<?php
require __DIR__ . '/bf_helper.inc';
require __DIR__ . '/bf_reference_m2b.inc';

use BatchFlow\Csv;

$S = ['id' => 'int64', 'i' => 'int64', 'f' => 'float64', 'b' => 'bool', 's' => 'string'];
$csv = "id,i,f,b,s\n"
    . "1,-9223372036854775808,-0.0,true,a\n"
    . "2,5,0.0,false,\"\"\n"
    . "3,,1.5,,\n"
    . "4,7,,true,é\n"
    . "5,5,-2.5,false,ab\n"
    . "6,9223372036854775807,1e300,,\"a,b\"\n"
    . "7,0,9007199254740992,true,b\n"
    . "8,-1,0.1,false,\"x\"\"y\"\n";

function ids(BatchFlow\Query $q): string
{
    $r = implode(' ', array_map(fn($g) => $g[0], $q->groupCount('id')));
    return $r === '' ? '(none)' : $r;
}

echo "-- membership per type, against the reference (count and sum)\n";
$lists = [
    'i' => [[5], [5, 7], [null], [null, 0], [PHP_INT_MIN, PHP_INT_MAX], [1, 2, 3], [7, 5, 7, 5, 5], [-1, 0, 1]],
    'f' => [[0.0], [-0.0], [0.0, -0.0], [1.5, null], [1, 2], [9007199254740992], [0.1, 1e300, -2.5], [null], [3.25]],
    'b' => [[true], [false], [null], [true, false], [true, null], [false, false, false]],
    's' => [['a'], [''], [null], ['', null], ['a,b', 'x"y'], ['é', 'b', 'zz'], ['A'], ['ab', 'a', 'ab', 'a']],
];
$n = 0;
$diffs = [];
foreach ($lists as $col => $ls) {
    foreach ($ls as $l) {
        foreach (['count' => null, 'sum' => 'id'] as $term => $sc) {
            $n++;
            $r = bf_cmp($csv, $S, [], [['in', $col, $l]], $term, $sc);
            if ($r !== 'same') {
                $diffs[] = "$col " . json_encode($l) . " $term: $r";
            }
        }
        foreach ([[['ne', 'i', null]], [['gte', 'id', 3], ['ne', 'b', true]]] as $more) {
            $n++;
            $r = bf_cmp($csv, $S, [], array_merge([['in', $col, $l]], $more), 'summary', 'f');
            if ($r !== 'same') {
                $diffs[] = "$col " . json_encode($l) . " with more: $r";
            }
        }
    }
}
echo "comparisons: $n, differences: ", count($diffs), "\n";
foreach (array_slice($diffs, 0, 10) as $d) {
    echo "  $d\n";
}

echo "-- explicit memberships\n";
$q = bf_q($csv, $S);
foreach ([
    "whereIn('i', [5])" => fn() => $q->whereIn('i', [5]),
    "whereIn('i', [null])" => fn() => $q->whereIn('i', [null]),
    "whereIn('i', [5, null])" => fn() => $q->whereIn('i', [5, null]),
    "whereIn('f', [0.0, -0.0])" => fn() => $q->whereIn('f', [0.0, -0.0]),
    "whereIn('f', [-0.0])" => fn() => $q->whereIn('f', [-0.0]),
    "whereIn('f', [0])" => fn() => $q->whereIn('f', [0]),
    "whereIn('b', [true, null])" => fn() => $q->whereIn('b', [true, null]),
    "whereIn('b', [false])" => fn() => $q->whereIn('b', [false]),
    "whereIn('s', [''])" => fn() => $q->whereIn('s', ['']),
    "whereIn('s', [null])" => fn() => $q->whereIn('s', [null]),
    "whereIn('s', ['a,b', 'x\"y'])" => fn() => $q->whereIn('s', ['a,b', 'x"y']),
    "whereIn('s', ['A', 'zz'])" => fn() => $q->whereIn('s', ['A', 'zz']),
] as $label => $fn) {
    echo str_pad($label, 30), 'ids ', ids($fn()), "\n";
}
echo "-- duplicates collapse and never change a result\n";
echo ids($q->whereIn('i', [5, 5, 5])), ' = ', ids($q->whereIn('i', [5])), "\n";
echo ids($q->whereIn('s', ['a', 'a', 'b', 'a'])), ' = ', ids($q->whereIn('s', ['a', 'b'])), "\n";
echo ids($q->whereIn('f', [-0.0, 0.0, -0.0, 0])), ' = ', ids($q->whereIn('f', [0.0])), "\n";
echo ids($q->whereIn('b', [null, null, true])), ' = ', ids($q->whereIn('b', [true, null])), "\n";

echo "-- sizes: 1 and 1024 elements accepted, 1025 rejected\n";
$big = range(1, 1024);
echo "1024 ints:     ", bf_try(fn() => $q->whereIn('i', $big)->count()), "\n";
$bigs = array_map(fn($k) => "k$k", range(1, 1023));
$bigs[] = 'ab';
echo "1024 strings:  ", bf_try(fn() => $q->whereIn('s', $bigs)->count()), "\n";
echo "1 element:     ", bf_try(fn() => $q->whereIn('i', [7])->count()), "\n";
echo "1025 elements: ", bf_try(fn() => $q->whereIn('i', range(1, 1025))), "\n";
echo "1025 elements on an unknown column: ", bf_try(fn() => $q->whereIn('nope', range(1, 1025))), "\n";
echo "-- a large set against many rows, binary search against linear reference\n";
mt_srand(83);
$rowsCsv = "id,i,s\n";
for ($r = 1; $r <= 2000; $r++) {
    $rowsCsv .= $r . ',' . (mt_rand(0, 9) === 0 ? '' : mt_rand(-3000, 3000)) . ',' . (mt_rand(0, 9) === 0 ? '' : 'v' . mt_rand(0, 3000)) . "\n";
}
$set = [];
for ($k = 0; $k < 1024; $k++) {
    $set[] = mt_rand(-3000, 3000);
}
$sset = array_map(fn($v) => "v$v", array_slice($set, 0, 1000));
$sset[] = null;
$IS = ['id' => 'int64', 'i' => 'int64', 's' => 'string'];
echo 'ints 1024:    ', bf_cmp($rowsCsv, $IS, [], [['in', 'i', $set]], 'summary', 'id'), "\n";
echo 'strings 1001: ', bf_cmp($rowsCsv, $IS, [], [['in', 's', $sset]], 'summary', 'id'), "\n";
echo 'both:         ', bf_cmp($rowsCsv, $IS, [], [['in', 's', $sset], ['in', 'i', array_slice($set, 0, 500)]], 'summary', 'i'), "\n";

echo "-- whereIn is one predicate: 63 whereEq plus one whereIn are accepted, the next is refused\n";
$p = Csv::scan('/nonexistent/batchflow/none.csv', $S);
for ($k = 0; $k < 63; $k++) {
    $p = $p->whereEq('i', $k);
}
$p = $p->whereIn('i', range(1, 1024));
echo '64 predicates: ', get_class($p), "\n";
echo '65th:          ', bf_try(fn() => $p->whereIn('i', [1])), "\n";
echo '65th whereEq:  ', bf_try(fn() => $p->whereEq('i', 1)), "\n";

echo "-- 1. shape, as ValueError\n";
$n = Csv::scan('/nonexistent/batchflow/none.csv', $S);
foreach ([
    "[]" => fn() => $n->whereIn('i', []),
    "[1 => 5]" => fn() => $n->whereIn('i', [1 => 5]),
    "['a' => 5]" => fn() => $n->whereIn('i', ['a' => 5]),
    "[0 => 1, 2 => 3]" => fn() => $n->whereIn('i', [0 => 1, 2 => 3]),
    "1025 elements" => fn() => $n->whereIn('i', range(0, 1024)),
] as $label => $fn) {
    echo str_pad($label, 20), bf_try($fn), "\n";
}
echo "-- 2. element types in list order, as TypeError with the element's 0-based index\n";
$h = fopen('php://memory', 'r');
foreach ([
    "[[1]]" => fn() => $n->whereIn('i', [[1]]),
    "[1, 2, new stdClass]" => fn() => $n->whereIn('i', [1, 2, new stdClass()]),
    "[1, resource]" => fn() => $n->whereIn('i', [1, $h]),
    "['x', [], new stdClass]" => fn() => $n->whereIn('i', ['x', [], new stdClass()]),
    "[1, closure]" => fn() => $n->whereIn('i', [1, fn() => 1]),
] as $label => $fn) {
    echo str_pad($label, 26), bf_try($fn), "\n";
}
echo "-- 3. then predicate count, then unknown column\n";
echo str_pad("65th, ['x'] on 'nope'", 26), bf_try(fn() => $p->whereIn('nope', ['x'])), "\n";
echo str_pad("['x'] on 'nope'", 26), bf_try(fn() => $n->whereIn('nope', ['x'])), "\n";
echo "-- 4. then each element against the column in list order, as SchemaException\n";
foreach ([
    "i [1, '2']" => fn() => $n->whereIn('i', [1, '2']),
    "i [null, 1.5, '2']" => fn() => $n->whereIn('i', [null, 1.5, '2']),
    "i [true]" => fn() => $n->whereIn('i', [true]),
    "f [1.5, INF]" => fn() => $n->whereIn('f', [1.5, INF]),
    "f [NAN]" => fn() => $n->whereIn('f', [NAN]),
    "f [2**53, 2**53+1]" => fn() => $n->whereIn('f', [2 ** 53, 2 ** 53 + 1]),
    "f [PHP_INT_MAX]" => fn() => $n->whereIn('f', [PHP_INT_MAX]),
    "f ['1']" => fn() => $n->whereIn('f', ['1']),
    "b [true, 1]" => fn() => $n->whereIn('b', [true, 1]),
    "b ['true']" => fn() => $n->whereIn('b', ['true']),
    "s ['ok', \"\\xFF\"]" => fn() => $n->whereIn('s', ['ok', "\xFF"]),
    "s ['ok', 1 MiB + 1]" => fn() => $n->whereIn('s', ['ok', str_repeat('a', 1048577)]),
    "s ['ok', 2]" => fn() => $n->whereIn('s', ['ok', 2]),
    "s [1 MiB] accepted" => fn() => get_class($n->whereIn('s', [str_repeat('a', 1048576)])),
] as $label => $fn) {
    echo str_pad($label, 24), bf_try($fn), "\n";
}
echo "-- the order across steps: shape before element types before the column before element values\n";
foreach ([
    "[] on 'nope', 65th" => fn() => $p->whereIn('nope', []),
    "[1 => [1]] on 'nope'" => fn() => $n->whereIn('nope', [1 => [1]]),
    "[[1]] on 'nope', 65th" => fn() => $p->whereIn('nope', [[1]]),
    "['2', [1]] on i" => fn() => $n->whereIn('i', ['2', [1]]),
    "['2'] on 'nope'" => fn() => $n->whereIn('nope', ['2']),
    "['2'] on i, 65th" => fn() => $p->whereIn('i', ['2']),
    "1025 x [1] on i" => fn() => $n->whereIn('i', array_fill(0, 1025, [1])),
] as $label => $fn) {
    echo str_pad($label, 24), bf_try($fn), "\n";
}
echo "-- all checks before any I/O, and the receiver is unchanged\n";
$base = bf_q($csv, $S);
$d = $base->whereIn('i', [5, 7]);
echo $base->count(), ' ', $d->count(), ' ', $d->whereIn('b', [false])->count(), ' ', $base->count(), "\n";
echo "-- the set is owned: changing the PHP array afterwards changes nothing\n";
$arr = ['a', 'b'];
$owned = $base->whereIn('s', $arr);
$arr[0] = 'zz';
$arr[] = 'é';
unset($arr);
echo ids($owned), "\n";
$str = str_repeat('é', 1);
$owned2 = $base->whereIn('s', [$str]);
$str .= 'x';
echo ids($owned2), "\n";
?>
--EXPECT--
-- membership per type, against the reference (count and sum)
comparisons: 124, differences: 0
-- explicit memberships
whereIn('i', [5])             ids 2 5
whereIn('i', [null])          ids 3
whereIn('i', [5, null])       ids 2 3 5
whereIn('f', [0.0, -0.0])     ids 1 2
whereIn('f', [-0.0])          ids 1 2
whereIn('f', [0])             ids 1 2
whereIn('b', [true, null])    ids 1 3 4 6 7
whereIn('b', [false])         ids 2 5 8
whereIn('s', [''])            ids 2
whereIn('s', [null])          ids 3
whereIn('s', ['a,b', 'x"y'])  ids 6 8
whereIn('s', ['A', 'zz'])     ids (none)
-- duplicates collapse and never change a result
2 5 = 2 5
1 7 = 1 7
1 2 = 1 2
1 3 4 6 7 = 1 3 4 6 7
-- sizes: 1 and 1024 elements accepted, 1025 rejected
1024 ints:     int(3)
1024 strings:  int(1)
1 element:     int(1)
1025 elements: ValueError: BatchFlow\Query::whereIn(): Argument #2 ($values) must contain at most 1024 values
1025 elements on an unknown column: ValueError: BatchFlow\Query::whereIn(): Argument #2 ($values) must contain at most 1024 values
-- a large set against many rows, binary search against linear reference
ints 1024:    same
strings 1001: same
both:         same
-- whereIn is one predicate: 63 whereEq plus one whereIn are accepted, the next is refused
64 predicates: BatchFlow\Query
65th:          SchemaException: too many predicates (limit 64)
65th whereEq:  SchemaException: too many predicates (limit 64)
-- 1. shape, as ValueError
[]                  ValueError: BatchFlow\Query::whereIn(): Argument #2 ($values) must not be empty
[1 => 5]            ValueError: BatchFlow\Query::whereIn(): Argument #2 ($values) must be a list
['a' => 5]          ValueError: BatchFlow\Query::whereIn(): Argument #2 ($values) must be a list
[0 => 1, 2 => 3]    ValueError: BatchFlow\Query::whereIn(): Argument #2 ($values) must be a list
1025 elements       ValueError: BatchFlow\Query::whereIn(): Argument #2 ($values) must contain at most 1024 values
-- 2. element types in list order, as TypeError with the element's 0-based index
[[1]]                     TypeError: BatchFlow\Query::whereIn(): Argument #2 ($values) element 0 must be of type string|int|float|bool|null, array given
[1, 2, new stdClass]      TypeError: BatchFlow\Query::whereIn(): Argument #2 ($values) element 2 must be of type string|int|float|bool|null, stdClass given
[1, resource]             TypeError: BatchFlow\Query::whereIn(): Argument #2 ($values) element 1 must be of type string|int|float|bool|null, resource given
['x', [], new stdClass]   TypeError: BatchFlow\Query::whereIn(): Argument #2 ($values) element 1 must be of type string|int|float|bool|null, array given
[1, closure]              TypeError: BatchFlow\Query::whereIn(): Argument #2 ($values) element 1 must be of type string|int|float|bool|null, Closure given
-- 3. then predicate count, then unknown column
65th, ['x'] on 'nope'     SchemaException: too many predicates (limit 64)
['x'] on 'nope'           SchemaException: unknown column "nope"
-- 4. then each element against the column in list order, as SchemaException
i [1, '2']              SchemaException: column "i": whereIn element 1: value of type string cannot be compared with int64 column
i [null, 1.5, '2']      SchemaException: column "i": whereIn element 1: value of type float cannot be compared with int64 column
i [true]                SchemaException: column "i": whereIn element 0: value of type bool cannot be compared with int64 column
f [1.5, INF]            SchemaException: column "f": whereIn element 1: float literal must be finite
f [NAN]                 SchemaException: column "f": whereIn element 0: float literal must be finite
f [2**53, 2**53+1]      SchemaException: column "f": whereIn element 1: integer literal is not exactly representable as float64
f [PHP_INT_MAX]         SchemaException: column "f": whereIn element 0: integer literal is not exactly representable as float64
f ['1']                 SchemaException: column "f": whereIn element 0: value of type string cannot be compared with float64 column
b [true, 1]             SchemaException: column "b": whereIn element 1: value of type int cannot be compared with bool column
b ['true']              SchemaException: column "b": whereIn element 0: value of type string cannot be compared with bool column
s ['ok', "\xFF"]        SchemaException: column "s": whereIn element 1: string literal is not valid UTF-8
s ['ok', 1 MiB + 1]     SchemaException: column "s": whereIn element 1: string literal exceeds 1048576 bytes
s ['ok', 2]             SchemaException: column "s": whereIn element 1: value of type int cannot be compared with string column
s [1 MiB] accepted      BatchFlow\Query
-- the order across steps: shape before element types before the column before element values
[] on 'nope', 65th      ValueError: BatchFlow\Query::whereIn(): Argument #2 ($values) must not be empty
[1 => [1]] on 'nope'    ValueError: BatchFlow\Query::whereIn(): Argument #2 ($values) must be a list
[[1]] on 'nope', 65th   TypeError: BatchFlow\Query::whereIn(): Argument #2 ($values) element 0 must be of type string|int|float|bool|null, array given
['2', [1]] on i         TypeError: BatchFlow\Query::whereIn(): Argument #2 ($values) element 1 must be of type string|int|float|bool|null, array given
['2'] on 'nope'         SchemaException: unknown column "nope"
['2'] on i, 65th        SchemaException: too many predicates (limit 64)
1025 x [1] on i         ValueError: BatchFlow\Query::whereIn(): Argument #2 ($values) must contain at most 1024 values
-- all checks before any I/O, and the receiver is unchanged
8 3 2 8
-- the set is owned: changing the PHP array afterwards changes nothing
1 7
4
