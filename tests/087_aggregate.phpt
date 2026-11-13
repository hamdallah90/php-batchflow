--TEST--
batchflow: aggregate() returns one result per pair in list order, equal to the single methods; repeats; count on string and bool columns; zero rows; one pass; overflow order across pairs; every shape ValueError with its index and the check order of SPEC 5.8
--EXTENSIONS--
batchflow
--FILE--
<?php
require __DIR__ . '/bf_helper.inc';
require __DIR__ . '/bf_reference_m2b.inc';

use BatchFlow\Csv;

$S = ['id' => 'int64', 'i' => 'int64', 'f' => 'float64', 'b' => 'bool', 's' => 'string'];
$csv = "id,i,f,b,s\n1,5,1.5,true,a\n2,,-2.25,false,\"\"\n3,-7,,true,\n4,12,0.5,,c\n5,,,false,d\n6,0,-0.0,true,e\n";

function a(string $label, string $csv, array $S, array $aggs, array $preds = [], array $opts = []): void
{
    $x = ['aggs' => $aggs];
    $r = bf_gexec($csv, $S, $opts, $preds, 'aggregate', $x);
    $w = bf_gref($csv, $S, $opts, $preds, 'aggregate', $x);
    echo str_pad($label, 42), $r, $r === $w ? '' : "   [DIFFERS FROM REFERENCE: $w]", "\n";
}

$all = [['count', 'id'], ['sum', 'i'], ['sum', 'f'], ['min', 'i'], ['max', 'f'], ['avg', 'i'], ['avg', 'f'], ['count', 's'], ['count', 'b'], ['min', 'f'], ['max', 'i'], ['count', 'i']];
echo "-- results in list order\n";
a('all ops', $csv, $S, $all);
a('reversed', $csv, $S, array_reverse($all));
a('where b = true', $csv, $S, $all, [['eq', 'b', true]]);
a('where f <= 0.5', $csv, $S, $all, [['lte', 'f', 0.5]]);
echo "-- each equals its single method\n";
$q = bf_q($csv, $S);
$res = $q->aggregate($all);
$singles = [];
foreach ($all as $k => [$op, $c]) {
    $single = match ($op) {
        'count' => $q->whereNe($c, null)->count(),
        'sum' => $q->sum($c),
        'min' => $q->min($c),
        'max' => $q->max($c),
        'avg' => $q->avg($c),
    };
    $singles[] = bf_val($single) === bf_val($res[$k]) ? 'same' : "DIFFERENT($op $c)";
}
echo implode(' ', array_unique($singles)), "\n";
echo "-- pairs may repeat; each repeat has its own result\n";
a('sum i three times', $csv, $S, [['sum', 'i'], ['sum', 'i'], ['sum', 'i']]);
a('count, min, count on one column', $csv, $S, [['count', 'f'], ['min', 'f'], ['count', 'f']]);
a('16 pairs', $csv, $S, array_fill(0, 16, ['max', 'id']));
echo "-- count accepts every column type and counts non-NULL values of matching rows\n";
a('count s, b, i, f, id', $csv, $S, [['count', 's'], ['count', 'b'], ['count', 'i'], ['count', 'f'], ['count', 'id']]);
a('quoted empty string is not NULL', "s\n\"\"\n\nx\n", ['s' => 'string'], [['count', 's']]);
echo "-- count still validates its column\n";
a('bad UTF-8 in a counted string', "id,s\n1,a\n2,\xFF\n", ['id' => 'int64', 's' => 'string'], [['count', 's']]);
a('bad bool in a counted bool', "id,b\n1,true\n2,yes\n", ['id' => 'int64', 'b' => 'bool'], [['count', 'b']]);
a('unreferenced bad bool', "id,b\n1,true\n2,yes\n", ['id' => 'int64', 'b' => 'bool'], [['count', 'id']]);
echo "-- zero matching rows: 0, 0 or 0.0, null, null, null\n";
a('no match', $csv, $S, [['count', 'i'], ['sum', 'i'], ['sum', 'f'], ['min', 'i'], ['max', 'f'], ['avg', 'i']], [['gt', 'id', 99]]);
a('header only', "id,i,f,b,s\n", $S, [['count', 's'], ['sum', 'i'], ['sum', 'f'], ['min', 'f'], ['max', 'i'], ['avg', 'f']]);
a('matching rows, all NULL', $csv, $S, [['count', 'i'], ['sum', 'i'], ['sum', 'f'], ['min', 'i'], ['max', 'f'], ['avg', 'f']], [['eq', 'id', 5]]);
echo "-- the -0.0 rule inside a list\n";
a('min f, max f over 0.0 / -0.0', "f\n0.0\n-0.0\n", ['f' => 'float64'], [['min', 'f'], ['max', 'f'], ['sum', 'f'], ['avg', 'f']]);
a('min f, max f over -0.0 / 0.0', "f\n-0.0\n0.0\n", ['f' => 'float64'], [['min', 'f'], ['max', 'f'], ['sum', 'f'], ['avg', 'f']]);

echo "-- one pass: the wrapper source is opened and read once\n";
$body = "id,i,f,b,s\n" . str_repeat("1,2,0.5,true,x\n", 5000);
$p = bf_wrap_path(['segments' => [[$body, 1]], 'max' => 4096]);
$r = Csv::scan($p, $S)->aggregate($all);
echo 'opens ', BfWrap::$opens, ', closes ', BfWrap::$closes, ', bytes served ', BfWrap::$last->served, ' of ', strlen($body), ', result ', bf_fmt_list($r), "\n";

echo "-- overflow among pairs: within a row in list order, the earliest row first\n";
$I = ['a' => 'int64', 'b' => 'int64'];
$ov = "a,b\n9223372036854775807,9223372036854775807\n1,1\n";
a('both overflow on row 2, a listed first', $ov, $I, [['sum', 'a'], ['sum', 'b']]);
a('both overflow on row 2, b listed first', $ov, $I, [['sum', 'b'], ['sum', 'a']]);
a('avg pair overflows like sum', $ov, $I, [['min', 'a'], ['avg', 'b'], ['sum', 'a']]);
$ov2 = "a,b\n1,9223372036854775807\n0,1\n";
a('b overflows on row 2, a never', $ov2, $I, [['sum', 'a'], ['sum', 'b']]);
$ov3 = "a,b\n9223372036854775807,1\n0,9223372036854775807\n1,0\n";
a('b overflows on row 2, a on row 3', $ov3, $I, [['sum', 'a'], ['sum', 'b']]);
a('min and max never overflow', $ov, $I, [['min', 'a'], ['max', 'b'], ['count', 'a']]);
echo "-- non-finite float64 sums after the scan, pairs in list order, no prefix\n";
$F = ['x' => 'float64', 'y' => 'float64'];
$inf = "x,y\n1e308,-1e308\n1e308,-1e308\n";
a('x then y', $inf, $F, [['sum', 'x'], ['sum', 'y']]);
a('y then x', $inf, $F, [['sum', 'y'], ['sum', 'x']]);
a('avg y then sum x', $inf, $F, [['min', 'x'], ['avg', 'y'], ['sum', 'x']]);
a('an int64 overflow comes first', "x,y,n\n1e308,1,9223372036854775807\n1e308,1,1\n", ['x' => 'float64', 'y' => 'float64', 'n' => 'int64'], [['sum', 'x'], ['sum', 'n']]);
a('min and max of huge values are fine', $inf, $F, [['max', 'x'], ['min', 'y']]);

echo "-- 1. shape, as ValueError, in order\n";
$n = Csv::scan('/nonexistent/batchflow/none.csv', $S);
$o = new stdClass();
foreach ([
    "[]" => [],
    "[1 => ['sum', 'i']]" => [1 => ['sum', 'i']],
    "['a' => ['sum', 'i']]" => ['a' => ['sum', 'i']],
    "17 pairs" => array_fill(0, 17, ['sum', 'i']),
    "17 bad pairs" => array_fill(0, 17, 'x'),
    "['sum', 'i']" => ['sum', 'i'],
    "[['sum']]" => [['sum']],
    "[['sum', 'i', 'f']]" => [['sum', 'i', 'f']],
    "[['sum', 'i'], ['sum', 5]]" => [['sum', 'i'], ['sum', 5]],
    "[['sum', 'i'], [5, 'i']]" => [['sum', 'i'], [5, 'i']],
    "[['sum', 'i'], [1 => 'sum', 2 => 'i']]" => [['sum', 'i'], [1 => 'sum', 2 => 'i']],
    "[['op' => 'sum', 'col' => 'i']]" => [['op' => 'sum', 'col' => 'i']],
    "[[1 => 'i', 0 => 'sum']]" => [[1 => 'i', 0 => 'sum']],
    "[['sum', 'i'], 'sum']" => [['sum', 'i'], 'sum'],
    "[['sum', 'i'], null]" => [['sum', 'i'], null],
    "[['sum', 'i'], object]" => [['sum', 'i'], $o],
    "[['SUM', 'i']]" => [['SUM', 'i']],
    "[['sum', 'i'], ['median', 'f']]" => [['sum', 'i'], ['median', 'f']],
    "[['median', 'nope'], [5]]" => [['median', 'nope'], [5]],
    "[['', 'i']]" => [['', 'i']],
    "[['count ', 'i']]" => [['count ', 'i']],
] as $label => $aggs) {
    echo str_pad($label, 46), bf_try(fn() => $n->aggregate($aggs)), "\n";
}
echo "-- 2. then each pair's column in list order: unknown column, then numeric for sum, min, max, avg\n";
foreach ([
    "[['sum', 'nope']]" => [['sum', 'nope']],
    "[['count', 'nope']]" => [['count', 'nope']],
    "[['sum', 's'], ['sum', 'nope']]" => [['sum', 's'], ['sum', 'nope']],
    "[['sum', 'nope'], ['sum', 's']]" => [['sum', 'nope'], ['sum', 's']],
    "[['count', 's'], ['min', 'b']]" => [['count', 's'], ['min', 'b']],
    "[['max', 's']]" => [['max', 's']],
    "[['avg', 'b']]" => [['avg', 'b']],
    "[['count', 'b'], ['count', 's']] (valid)" => [['count', 'b'], ['count', 's']],
    "[['sum', 'nope'], ['median', 'i']]" => [['sum', 'nope'], ['median', 'i']],
] as $label => $aggs) {
    echo str_pad($label, 46), bf_try(fn() => $n->aggregate($aggs)), "\n";
}
echo "-- through the reference, the same order\n";
foreach ([[], [['sum', 'i'], ['median', 'f']], [['sum', 'nope'], ['median', 'i']], [['sum', 's'], ['sum', 'nope']], [['count', 's'], ['min', 'b']], array_fill(0, 17, 'x')] as $aggs) {
    echo bf_gcmp($csv, $S, [], [], 'aggregate', ['aggs' => $aggs]), "\n";
}
echo "-- argument type\n";
echo bf_try(fn() => $n->aggregate('sum')), "\n";
echo bf_try(fn() => $n->aggregate()), "\n";
?>
--EXPECT--
-- results in list order
all ops                                   [int(6),int(10),float(-0.25),int(-7),float(1.5),float(2.5),float(-0.0625),int(5),int(5),float(-2.25),int(12),int(4)]
reversed                                  [int(4),int(12),float(-2.25),int(5),int(5),float(-0.0625),float(2.5),float(1.5),int(-7),float(-0.25),int(10),int(6)]
where b = true                            [int(3),int(-2),float(1.5),int(-7),float(1.5),float(-0.6666666666666666),float(0.75),int(2),int(3),float(-0.0),int(5),int(3)]
where f <= 0.5                            [int(3),int(12),float(-1.75),int(0),float(0.5),float(6.0),float(-0.5833333333333334),int(3),int(2),float(-2.25),int(12),int(2)]
-- each equals its single method
same
-- pairs may repeat; each repeat has its own result
sum i three times                         [int(10),int(10),int(10)]
count, min, count on one column           [int(4),float(-2.25),int(4)]
16 pairs                                  [int(6),int(6),int(6),int(6),int(6),int(6),int(6),int(6),int(6),int(6),int(6),int(6),int(6),int(6),int(6),int(6)]
-- count accepts every column type and counts non-NULL values of matching rows
count s, b, i, f, id                      [int(5),int(5),int(4),int(4),int(6)]
quoted empty string is not NULL           [int(2)]
-- count still validates its column
bad UTF-8 in a counted string             CsvException: row 2 (line 3, offset 9): column "s": invalid UTF-8
bad bool in a counted bool                CsvException: row 2 (line 3, offset 12): column "b": expected bool, got malformed value
unreferenced bad bool                     [int(2)]
-- zero matching rows: 0, 0 or 0.0, null, null, null
no match                                  [int(0),int(0),float(0.0),NULL,NULL,NULL]
header only                               [int(0),int(0),float(0.0),NULL,NULL,NULL]
matching rows, all NULL                   [int(0),int(0),float(0.0),NULL,NULL,NULL]
-- the -0.0 rule inside a list
min f, max f over 0.0 / -0.0              [float(0.0),float(0.0),float(0.0),float(0.0)]
min f, max f over -0.0 / 0.0              [float(-0.0),float(-0.0),float(0.0),float(0.0)]
-- one pass: the wrapper source is opened and read once
opens 1, closes 1, bytes served 75011 of 75011, result [int(5000),int(10000),float(2500.0),int(2),float(0.5),float(2.0),float(0.5),int(5000),int(5000),float(0.5),int(2),int(5000)]
-- overflow among pairs: within a row in list order, the earliest row first
both overflow on row 2, a listed first    SumOverflowException: row 2 (line 3, offset 44): int64 sum overflow in column "a"
both overflow on row 2, b listed first    SumOverflowException: row 2 (line 3, offset 44): int64 sum overflow in column "b"
avg pair overflows like sum               SumOverflowException: row 2 (line 3, offset 44): int64 sum overflow in column "b"
b overflows on row 2, a never             SumOverflowException: row 2 (line 3, offset 26): int64 sum overflow in column "b"
b overflows on row 2, a on row 3          SumOverflowException: row 2 (line 3, offset 26): int64 sum overflow in column "b"
min and max never overflow                [int(1),int(9223372036854775807),int(2)]
-- non-finite float64 sums after the scan, pairs in list order, no prefix
x then y                                  SumOverflowException: float64 sum of column "x" is not finite
y then x                                  SumOverflowException: float64 sum of column "y" is not finite
avg y then sum x                          SumOverflowException: float64 sum of column "y" is not finite
an int64 overflow comes first             SumOverflowException: row 2 (line 3, offset 34): int64 sum overflow in column "n"
min and max of huge values are fine       [float(1.0E+308),float(-1.0E+308)]
-- 1. shape, as ValueError, in order
[]                                            ValueError: BatchFlow\Query::aggregate(): Argument #1 ($aggregates) must not be empty
[1 => ['sum', 'i']]                           ValueError: BatchFlow\Query::aggregate(): Argument #1 ($aggregates) must be a list
['a' => ['sum', 'i']]                         ValueError: BatchFlow\Query::aggregate(): Argument #1 ($aggregates) must be a list
17 pairs                                      ValueError: BatchFlow\Query::aggregate(): Argument #1 ($aggregates) must contain at most 16 aggregates
17 bad pairs                                  ValueError: BatchFlow\Query::aggregate(): Argument #1 ($aggregates) must contain at most 16 aggregates
['sum', 'i']                                  ValueError: BatchFlow\Query::aggregate(): Argument #1 ($aggregates) element 0 must be a list of two strings
[['sum']]                                     ValueError: BatchFlow\Query::aggregate(): Argument #1 ($aggregates) element 0 must be a list of two strings
[['sum', 'i', 'f']]                           ValueError: BatchFlow\Query::aggregate(): Argument #1 ($aggregates) element 0 must be a list of two strings
[['sum', 'i'], ['sum', 5]]                    ValueError: BatchFlow\Query::aggregate(): Argument #1 ($aggregates) element 1 must be a list of two strings
[['sum', 'i'], [5, 'i']]                      ValueError: BatchFlow\Query::aggregate(): Argument #1 ($aggregates) element 1 must be a list of two strings
[['sum', 'i'], [1 => 'sum', 2 => 'i']]        ValueError: BatchFlow\Query::aggregate(): Argument #1 ($aggregates) element 1 must be a list of two strings
[['op' => 'sum', 'col' => 'i']]               ValueError: BatchFlow\Query::aggregate(): Argument #1 ($aggregates) element 0 must be a list of two strings
[[1 => 'i', 0 => 'sum']]                      ValueError: BatchFlow\Query::aggregate(): Argument #1 ($aggregates) element 0 must be a list of two strings
[['sum', 'i'], 'sum']                         ValueError: BatchFlow\Query::aggregate(): Argument #1 ($aggregates) element 1 must be a list of two strings
[['sum', 'i'], null]                          ValueError: BatchFlow\Query::aggregate(): Argument #1 ($aggregates) element 1 must be a list of two strings
[['sum', 'i'], object]                        ValueError: BatchFlow\Query::aggregate(): Argument #1 ($aggregates) element 1 must be a list of two strings
[['SUM', 'i']]                                ValueError: BatchFlow\Query::aggregate(): Argument #1 ($aggregates) element 0: aggregate must be one of count, sum, min, max, avg
[['sum', 'i'], ['median', 'f']]               ValueError: BatchFlow\Query::aggregate(): Argument #1 ($aggregates) element 1: aggregate must be one of count, sum, min, max, avg
[['median', 'nope'], [5]]                     ValueError: BatchFlow\Query::aggregate(): Argument #1 ($aggregates) element 0: aggregate must be one of count, sum, min, max, avg
[['', 'i']]                                   ValueError: BatchFlow\Query::aggregate(): Argument #1 ($aggregates) element 0: aggregate must be one of count, sum, min, max, avg
[['count ', 'i']]                             ValueError: BatchFlow\Query::aggregate(): Argument #1 ($aggregates) element 0: aggregate must be one of count, sum, min, max, avg
-- 2. then each pair's column in list order: unknown column, then numeric for sum, min, max, avg
[['sum', 'nope']]                             SchemaException: unknown column "nope"
[['count', 'nope']]                           SchemaException: unknown column "nope"
[['sum', 's'], ['sum', 'nope']]               SchemaException: column "s": sum requires a numeric column
[['sum', 'nope'], ['sum', 's']]               SchemaException: unknown column "nope"
[['count', 's'], ['min', 'b']]                SchemaException: column "b": min requires a numeric column
[['max', 's']]                                SchemaException: column "s": max requires a numeric column
[['avg', 'b']]                                SchemaException: column "b": avg requires a numeric column
[['count', 'b'], ['count', 's']] (valid)      StreamException: cannot open input
[['sum', 'nope'], ['median', 'i']]            ValueError: BatchFlow\Query::aggregate(): Argument #1 ($aggregates) element 1: aggregate must be one of count, sum, min, max, avg
-- through the reference, the same order
same
same
same
same
same
same
-- argument type
TypeError: BatchFlow\Query::aggregate(): Argument #1 ($aggregates) must be of type array, string given
ArgumentCountError: BatchFlow\Query::aggregate() expects exactly 1 argument, 0 given
