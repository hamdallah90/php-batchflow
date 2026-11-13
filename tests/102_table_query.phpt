--TEST--
batchflow: whereEq, whereGt, count, sum, summary, toArray on a Table equal the streaming results on the same bytes; int64 overflow on a table carries the "row R: " prefix; no I/O and no consumption
--EXTENSIONS--
batchflow
--FILE--
<?php
require __DIR__ . '/bf_table.inc';

use BatchFlow\Table;

// SPEC 5.1: table results equal streaming results; 5.11: the only difference is the prefix of a query error
echo "-- matrix: terminals x predicate sets, each against Csv::scan over the same bytes\n";
[$csv, $S] = tb_fixture(3000, 102);
$t = tb_load($csv, $S);
$sets = [
    'none' => [],
    'k = red' => [['eq', 'k', 'red']],
    'k = "" (empty string)' => [['eq', 'k', '']],
    'k IS NULL' => [['eq', 'k', null]],
    'k = absent' => [['eq', 'k', 'zzz']],
    'g = 2000006' => [['eq', 'g', 2000006]],
    'g IS NULL' => [['eq', 'g', null]],
    'b = true' => [['eq', 'b', true]],
    'f = 0.0 (also -0.0 rows)' => [['eq', 'f', 0.0]],
    'id = 77' => [['eq', 'id', 77]],
    's = u5' => [['eq', 's', 'u5']],
    'v > 100' => [['gt', 'v', 100]],
    'f > 10.5' => [['gt', 'f', 10.5]],
    'f > -0.0' => [['gt', 'f', -0.0]],
    'id > 2990' => [['gt', 'id', 2990]],
    'v > 100000 (none)' => [['gt', 'v', 100000]],
    'k = red, v > 0' => [['eq', 'k', 'red'], ['gt', 'v', 0]],
    'k = red, k = green (empty)' => [['eq', 'k', 'red'], ['eq', 'k', 'green']],
    'b = false, g > 0, f > 0' => [['eq', 'b', false], ['gt', 'g', 0], ['gt', 'f', 0.0]],
];
$terms = [
    'count' => ['count', []],
    'sum v' => ['sum', ['col' => 'v']],
    'sum f' => ['sum', ['col' => 'f']],
    'summary' => ['summary', []],
    'summary v' => ['summary', ['col' => 'v']],
    'summary f' => ['summary', ['col' => 'f']],
    'toArray' => ['toArray', ['maxRows' => 5000]],
];
foreach ($terms as $label => [$term, $x]) {
    $same = 0;
    $diffs = [];
    foreach ($sets as $sl => $preds) {
        $r = tb_cmp($t, $csv, $S, [], $preds, $term, $x);
        $r === 'same' ? $same++ : $diffs[] = "$sl: $r";
    }
    echo str_pad($label, 12), "$same of ", count($sets), " same\n";
    foreach (array_slice($diffs, 0, 2) as $d) {
        echo "  $d\n";
    }
}

// SPEC 5.7, 5.8, 5.10 with values worked out by hand
echo "-- known values\n";
$K = ['id' => 'int64', 'k' => 'string', 'v' => 'int64', 'f' => 'float64'];
$kc = "id,k,v,f\n1,a,10,1.5\n2,b,20,2.5\n3,a,,0.5\n4,,40,\n5,a,50,-0.0\n";
$k = tb_load($kc, $K);
echo 'whereEq k=a count: ', $k->whereEq('k', 'a')->count(), "\n";
echo 'whereEq k=a sum v (NULL ignored): ', $k->whereEq('k', 'a')->sum('v'), "\n";
echo 'whereEq k=a summary v: ', bf_fmt($k->whereEq('k', 'a')->summary('v')), "\n";
echo 'whereEq k IS NULL count: ', $k->whereEq('k', null)->count(), "\n";
echo 'whereGt v>15 count: ', $k->whereGt('v', 15)->count(), "\n";
echo 'whereGt f>0.0 count (-0.0 and NULL excluded): ', $k->whereGt('f', 0.0)->count(), "\n";
echo 'whereEq f=0.0 count (-0.0 equals 0.0): ', $k->whereEq('f', 0.0)->count(), "\n";
echo 'whereEq f=0 (int literal for float64, matches -0.0): ', $k->whereEq('f', 0)->count(), "\n";
echo "k=a, then v>15, toArray:\n", bf_fmt_rows($k->whereEq('k', 'a')->whereGt('v', 15)->toArray()), "\n";
echo "ids with k=a:\n", bf_fmt_rows(array_map(fn($r) => ['id' => $r['id']], $k->whereEq('k', 'a')->toArray())), "\n";
echo 'no match sum, int and float: ', bf_fmt($k->whereEq('k', 'zz')->sum('v')), ' ', bf_fmt($k->whereEq('k', 'zz')->sum('f')), "\n";
echo 'summary, nothing requested: ', bf_fmt($k->whereEq('k', 'b')->summary()), "\n";
echo 'summary scans every row of the table: ', $k->whereEq('k', 'zz')->summary()->rowsScanned, "\n";
echo 'shortcut equals query(): ', $k->whereGt('v', 15)->count() === $k->query()->whereGt('v', 15)->count() ? 'yes' : 'NO', "\n";

// SPEC 5.1: a terminal does not consume a table; where* returns a new Query and leaves the receiver unchanged
echo "-- repeatable, immutable\n";
$q = $k->query();
$q2 = $q->whereEq('k', 'a');
echo 'receiver unchanged: ', $q->count(), ' and ', $q2->count(), "\n";
echo 'three runs: ', $q2->count(), ' ', $q2->count(), ' ', $q2->count(), "\n";

// SPEC 5.8, 5.11: int64 overflow at the row where it happens, prefix "row R: "; float overflow after the scan, no prefix
echo "-- overflow\n";
$O = ['id' => 'int64', 'k' => 'string', 'v' => 'int64', 'f' => 'float64'];
$oc = "id,k,v,f\n1,a,9223372036854775807,1e308\n2,b,0,1e308\n3,a,1,-1\n4,b,5,\n";
$o = tb_load($oc, $O);
foreach ([['sum', ['col' => 'v']], ['summary', ['col' => 'v']], ['groupSum', ['key' => 'k', 'sum' => 'v']], ['sum', ['col' => 'f']]] as [$term, $x]) {
    echo $term, ' ', json_encode($x), ': ', tb_try(fn() => tb_term($o->query(), $term, $x)), "\n";
    echo '   streaming gives the same: ', tb_cmp($o, $oc, $O, [], [], $term, $x), "\n";
}
echo 'rows 2 to 4 only (no overflow): ', tb_try(fn() => tb_term($o->whereGt('id', 1), 'sum', ['col' => 'v'])), "\n";
echo 'row 1 only: ', tb_try(fn() => tb_term($o->whereEq('id', 1), 'sum', ['col' => 'v'])), "\n";
$neg = tb_load("id,v\n1,-9223372036854775808\n2,0\n3,-1\n", ['id' => 'int64', 'v' => 'int64']);
echo 'negative overflow: ', tb_try(fn() => tb_term($neg->query(), 'sum', ['col' => 'v'])), "\n";

// SPEC 5.10, 5.11: maxRows exact and +1, the row of the refusal
echo "-- toArray maxRows\n";
echo 'maxRows 5 (5 rows): ', count($k->query()->toArray(5)), "\n";
echo 'maxRows 4: ', tb_try(fn() => tb_term($k->query(), 'toArray', ['maxRows' => 4])), "\n";
echo 'maxRows 0: ', tb_try(fn() => tb_term($k->query(), 'toArray', ['maxRows' => 0])), "\n";
echo 'maxRows 100000001: ', tb_try(fn() => tb_term($k->query(), 'toArray', ['maxRows' => 100000001])), "\n";

// SPEC 5.7, 5.11: the where* checks of a Table are those of a Query, raised at the call
echo "-- where checks\n";
foreach ([
    'unknown column' => fn() => $k->whereEq('nope', 1),
    'float literal on int64' => fn() => $k->whereEq('v', 1.5),
    'string literal on int64' => fn() => $k->whereEq('v', '1'),
    'int literal on string' => fn() => $k->whereEq('k', 1),
    'whereGt on string' => fn() => $k->whereGt('k', 1),
    'whereGt string literal' => fn() => $k->whereGt('v', '1'),
    'non-UTF-8 literal' => fn() => $k->whereEq('k', "\xff"),
    'int not representable' => fn() => $k->whereEq('f', 9007199254740993),
    'non-finite float' => fn() => $k->whereGt('f', NAN),
    '65 predicates' => function () use ($k) {
        $q = $k->query();
        for ($i = 0; $i < 65; $i++) {
            $q = $q->whereGt('v', $i);
        }
    },
] as $label => $fn) {
    echo $label, ': ', tb_try(function () use ($fn) { $fn(); return 'accepted'; }), "\n";
}
?>
--EXPECT--
-- matrix: terminals x predicate sets, each against Csv::scan over the same bytes
count       19 of 19 same
sum v       19 of 19 same
sum f       19 of 19 same
summary     19 of 19 same
summary v   19 of 19 same
summary f   19 of 19 same
toArray     19 of 19 same
-- known values
whereEq k=a count: 3
whereEq k=a sum v (NULL ignored): 60
whereEq k=a summary v: Summary{scanned=5;matched=3;sum=int(60)}
whereEq k IS NULL count: 1
whereGt v>15 count: 3
whereGt f>0.0 count (-0.0 and NULL excluded): 3
whereEq f=0.0 count (-0.0 equals 0.0): 1
whereEq f=0 (int literal for float64, matches -0.0): 1
k=a, then v>15, toArray:
{id=int(5), k=s"a", v=int(50), f=float(-0.0)}
ids with k=a:
{id=int(1)}
{id=int(3)}
{id=int(5)}
no match sum, int and float: int(0) float(0.0)
summary, nothing requested: Summary{scanned=5;matched=1;sum=NULL}
summary scans every row of the table: 5
shortcut equals query(): yes
-- repeatable, immutable
receiver unchanged: 5 and 3
three runs: 3 3 3
-- overflow
sum {"col":"v"}: SumOverflowException: row 3: int64 sum overflow in column "v"
   streaming gives the same: same
summary {"col":"v"}: SumOverflowException: row 3: int64 sum overflow in column "v"
   streaming gives the same: same
groupSum {"key":"k","sum":"v"}: SumOverflowException: row 3: int64 sum overflow in column "v"
   streaming gives the same: same
sum {"col":"f"}: SumOverflowException: float64 sum of column "f" is not finite
   streaming gives the same: same
rows 2 to 4 only (no overflow): int(6)
row 1 only: int(9223372036854775807)
negative overflow: SumOverflowException: row 3: int64 sum overflow in column "v"
-- toArray maxRows
maxRows 5 (5 rows): 5
maxRows 4: ResultTooLargeException: row 5: result exceeds maxRows (4)
maxRows 0: ValueError: BatchFlow\Query::toArray(): Argument #1 ($maxRows) must be between 1 and 100000000
maxRows 100000001: ValueError: BatchFlow\Query::toArray(): Argument #1 ($maxRows) must be between 1 and 100000000
-- where checks
unknown column: SchemaException: unknown column "nope"
float literal on int64: SchemaException: column "v": value of type float cannot be compared with int64 column
string literal on int64: SchemaException: column "v": value of type string cannot be compared with int64 column
int literal on string: SchemaException: column "k": value of type int cannot be compared with string column
whereGt on string: SchemaException: column "k": whereGt requires a numeric column
whereGt string literal: TypeError: BatchFlow\Table::whereGt(): Argument #2 ($value) must be of type int|float, string given
non-UTF-8 literal: SchemaException: column "k": string literal is not valid UTF-8
int not representable: SchemaException: column "f": integer literal is not exactly representable as float64
non-finite float: SchemaException: column "f": float literal must be finite
65 predicates: SchemaException: too many predicates (limit 64)
