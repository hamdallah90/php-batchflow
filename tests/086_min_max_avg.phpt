--TEST--
batchflow: min, max and avg on int64 and float64 columns against the reference: NULL ignored, all-NULL and no match give null, the -0.0 first-seen rule, avg of an int64 sum above 2^53, avg overflow with the sum reasons, column errors before any I/O
--EXTENSIONS--
batchflow
--FILE--
<?php
require __DIR__ . '/bf_helper.inc';
require __DIR__ . '/bf_reference_m2b.inc';

use BatchFlow\Csv;

$S = ['id' => 'int64', 'i' => 'int64', 'f' => 'float64', 'b' => 'bool', 's' => 'string'];
$csv = "id,i,f,b,s\n1,5,1.5,true,a\n2,,-2.25,false,b\n3,-7,,true,\n4,12,0.5,,c\n5,,,false,d\n6,0,3e10,true,e\n";

function t(string $label, string $csv, array $S, string $term, string $col, array $preds = []): void
{
    $a = bf_gexec($csv, $S, [], $preds, $term, ['col' => $col]);
    $b = bf_gref($csv, $S, [], $preds, $term, ['col' => $col]);
    echo str_pad($label, 44), $a, $a === $b ? '' : "   [DIFFERS FROM REFERENCE: $b]", "\n";
}

echo "-- int64 and float64, NULL ignored\n";
foreach (['min', 'max', 'avg'] as $m) {
    t("$m(i)", $csv, $S, $m, 'i');
    t("$m(f)", $csv, $S, $m, 'f');
    t("$m(id)", $csv, $S, $m, 'id');
}
echo "-- with predicates\n";
foreach (['min', 'max', 'avg'] as $m) {
    t("$m(i) where b = true", $csv, $S, $m, 'i', [['eq', 'b', true]]);
    t("$m(f) where id >= 3", $csv, $S, $m, 'f', [['gte', 'id', 3]]);
    t("$m(f) where s in [a, c]", $csv, $S, $m, 'f', [['in', 's', ['a', 'c']]]);
}
echo "-- all-NULL and no match give null; zero rows too\n";
foreach (['min', 'max', 'avg'] as $m) {
    t("$m(i) where id in [2, 5] (all NULL)", $csv, $S, $m, 'i', [['in', 'id', [2, 5]]]);
    t("$m(f) where id > 99 (no match)", $csv, $S, $m, 'f', [['gt', 'id', 99]]);
    t("$m(i) on a header-only file", "id,i,f,b,s\n", $S, $m, 'i');
}
echo "-- results are int on int64 and float on float64; avg is always float\n";
$q = bf_q($csv, $S);
echo get_debug_type($q->min('i')), ' ', get_debug_type($q->max('i')), ' ', get_debug_type($q->avg('i')), ' ',
    get_debug_type($q->min('f')), ' ', get_debug_type($q->max('f')), ' ', get_debug_type($q->avg('f')), "\n";

echo "-- -0.0 and 0.0: the first seen in row order is kept\n";
$Z = ['x' => 'float64'];
t("min over 0.0 then -0.0", "x\n0.0\n-0.0\n", $Z, 'min', 'x');
t("max over 0.0 then -0.0", "x\n0.0\n-0.0\n", $Z, 'max', 'x');
t("min over -0.0 then 0.0", "x\n-0.0\n0.0\n", $Z, 'min', 'x');
t("max over -0.0 then 0.0", "x\n-0.0\n0.0\n", $Z, 'max', 'x');
t("min over 1, -0.0, 0.0, 2", "x\n1\n-0.0\n0.0\n2\n", $Z, 'min', 'x');
t("max over -1, 0.0, -0.0", "x\n-1\n0.0\n-0.0\n", $Z, 'max', 'x');
t("avg over -0.0 alone (sum from +0.0)", "x\n-0.0\n", $Z, 'avg', 'x');
echo "-- int64 bounds are exact\n";
$I = ['x' => 'int64'];
$ext = "x\n9223372036854775807\n-9223372036854775808\n0\n";
t("min at INT64_MIN", $ext, $I, 'min', 'x');
t("max at INT64_MAX", $ext, $I, 'max', 'x');
t("avg of INT64_MAX + INT64_MIN + 0", $ext, $I, 'avg', 'x');
t("max of 9223372036854775806, ...07", "x\n9223372036854775806\n9223372036854775807\n", $I, 'max', 'x');
t("min of -9223372036854775807, ...08", "x\n-9223372036854775807\n-9223372036854775808\n", $I, 'min', 'x');
t("max of 2^53+1 and 2^53 (no float rounding)", "x\n9007199254740993\n9007199254740992\n", $I, 'max', 'x');

echo "-- avg of an int64 sum above 2^53 is (float)\$sum / \$n, bit-exact\n";
$vals = [9007199254740993, 9007199254740995, 3, 4611686018427387904, 1];
$big = "x\n" . implode("\n", $vals) . "\n";
$sum = array_sum($vals);
$want = (float)$sum / count($vals);
$got = bf_q($big, $I)->avg('x');
echo 'sum is above 2^53: ', $sum > 2 ** 53 ? 'yes' : 'no', '; bits equal: ', pack('e', $got) === pack('e', $want) ? 'yes' : 'NO', '; ', bf_val($got), "\n";
$three = "x\n9007199254740993\n9007199254740993\n9007199254740994\n";
$w3 = (float)(9007199254740993 + 9007199254740993 + 9007199254740994) / 3;
$g3 = bf_q($three, $I)->avg('x');
echo 'three values near 2^53: ', pack('e', $g3) === pack('e', $w3) ? 'bit-exact' : 'NO', ' ', bf_val($g3), ' (the mean of the doubles would be ', bf_val((9007199254740993.0 + 9007199254740993.0 + 9007199254740994.0) / 3), ")\n";
t("the same through the reference", $three, $I, 'avg', 'x');

echo "-- avg overflows exactly as sum: int64 at its row with the row prefix, float64 after the scan\n";
$ov = "x\n9223372036854775807\n1\n5\n";
t("avg int64 overflow", $ov, $I, 'avg', 'x');
echo str_pad("sum int64 overflow (same reason)", 44), bf_exec($ov, $I, [], [], 'sum', 'x'), "\n";
t("avg int64 negative overflow", "x\n-9223372036854775808\n-1\n", $I, 'avg', 'x');
t("avg float64 sum not finite, mean would be", "x\n1e308\n1e308\n", $Z, 'avg', 'x');
echo str_pad("sum float64 (same reason)", 44), bf_exec("x\n1e308\n1e308\n", $Z, [], [], 'sum', 'x'), "\n";
t("avg float64 sum stays infinite", "x\n1e308\n1e308\n-1e308\n", $Z, 'avg', 'x');
t("min and max never overflow", $ov, $I, 'max', 'x');
t("min over 1e308 twice", "x\n1e308\n1e308\n", $Z, 'min', 'x');
echo "-- a bad value in the column raises, matched or not; other columns are not validated\n";
t("bad int in i", "id,i,f,b,s\n1,5,1,true,a\n2,x,1,true,a\n", $S, 'min', 'i', [['eq', 'id', 1]]);
t("bad float in f", "id,i,f,b,s\n1,5,1,true,a\n2,5,1e400,true,a\n", $S, 'max', 'f');
t("bad bool in unreferenced b", "id,i,f,b,s\n1,5,1,yes,a\n", $S, 'avg', 'i');

echo "-- non-numeric columns and unknown columns, before any I/O\n";
$n = Csv::scan('/nonexistent/batchflow/none.csv', $S);
foreach (['min', 'max', 'avg'] as $m) {
    foreach (['b', 's', 'nope', ''] as $c) {
        echo str_pad("$m('$c')", 14), bf_try(fn() => $n->$m($c)), "\n";
    }
}
echo "-- arguments: one string\n";
echo bf_try(fn() => $n->min()), "\n";
echo bf_try(fn() => $n->max(['i'])), "\n";
echo bf_try(fn() => $n->avg('i', 'f')), "\n";
echo "-- the results equal aggregate() with one pair\n";
foreach (['min', 'max', 'avg'] as $m) {
    foreach (['i', 'f'] as $c) {
        echo "$m($c) ", bf_val($q->$m($c)) === bf_val($q->aggregate([[$m, $c]])[0]) ? 'same' : 'DIFFERENT', "\n";
    }
}
?>
--EXPECT--
-- int64 and float64, NULL ignored
min(i)                                      int(-7)
min(f)                                      float(-2.25)
min(id)                                     int(1)
max(i)                                      int(12)
max(f)                                      float(30000000000.0)
max(id)                                     int(6)
avg(i)                                      float(2.5)
avg(f)                                      float(7499999999.9375)
avg(id)                                     float(3.5)
-- with predicates
min(i) where b = true                       int(-7)
min(f) where id >= 3                        float(0.5)
min(f) where s in [a, c]                    float(0.5)
max(i) where b = true                       int(5)
max(f) where id >= 3                        float(30000000000.0)
max(f) where s in [a, c]                    float(1.5)
avg(i) where b = true                       float(-0.6666666666666666)
avg(f) where id >= 3                        float(15000000000.25)
avg(f) where s in [a, c]                    float(1.0)
-- all-NULL and no match give null; zero rows too
min(i) where id in [2, 5] (all NULL)        NULL
min(f) where id > 99 (no match)             NULL
min(i) on a header-only file                NULL
max(i) where id in [2, 5] (all NULL)        NULL
max(f) where id > 99 (no match)             NULL
max(i) on a header-only file                NULL
avg(i) where id in [2, 5] (all NULL)        NULL
avg(f) where id > 99 (no match)             NULL
avg(i) on a header-only file                NULL
-- results are int on int64 and float on float64; avg is always float
int int float float float float
-- -0.0 and 0.0: the first seen in row order is kept
min over 0.0 then -0.0                      float(0.0)
max over 0.0 then -0.0                      float(0.0)
min over -0.0 then 0.0                      float(-0.0)
max over -0.0 then 0.0                      float(-0.0)
min over 1, -0.0, 0.0, 2                    float(-0.0)
max over -1, 0.0, -0.0                      float(0.0)
avg over -0.0 alone (sum from +0.0)         float(0.0)
-- int64 bounds are exact
min at INT64_MIN                            int(-9223372036854775808)
max at INT64_MAX                            int(9223372036854775807)
avg of INT64_MAX + INT64_MIN + 0            float(-0.3333333333333333)
max of 9223372036854775806, ...07           int(9223372036854775807)
min of -9223372036854775807, ...08          int(-9223372036854775808)
max of 2^53+1 and 2^53 (no float rounding)  int(9007199254740993)
-- avg of an int64 sum above 2^53 is (float)$sum / $n, bit-exact
sum is above 2^53: yes; bits equal: yes; float(9.25940083387374E+17)
three values near 2^53: bit-exact float(9007199254740994.0) (the mean of the doubles would be float(9007199254740992.0))
the same through the reference              float(9007199254740994.0)
-- avg overflows exactly as sum: int64 at its row with the row prefix, float64 after the scan
avg int64 overflow                          SumOverflowException: row 2 (line 3, offset 22): int64 sum overflow in column "x"
sum int64 overflow (same reason)            SumOverflowException: row 2 (line 3, offset 22): int64 sum overflow in column "x"
avg int64 negative overflow                 SumOverflowException: row 2 (line 3, offset 23): int64 sum overflow in column "x"
avg float64 sum not finite, mean would be   SumOverflowException: float64 sum of column "x" is not finite
sum float64 (same reason)                   SumOverflowException: float64 sum of column "x" is not finite
avg float64 sum stays infinite              SumOverflowException: float64 sum of column "x" is not finite
min and max never overflow                  int(9223372036854775807)
min over 1e308 twice                        float(1.0E+308)
-- a bad value in the column raises, matched or not; other columns are not validated
bad int in i                                CsvException: row 2 (line 3, offset 24): column "i": expected int64, got malformed value
bad float in f                              CsvException: row 2 (line 3, offset 24): column "f": expected float64, got out-of-range number
bad bool in unreferenced b                  float(5.0)
-- non-numeric columns and unknown columns, before any I/O
min('b')      SchemaException: column "b": min requires a numeric column
min('s')      SchemaException: column "s": min requires a numeric column
min('nope')   SchemaException: unknown column "nope"
min('')       SchemaException: unknown column ""
max('b')      SchemaException: column "b": max requires a numeric column
max('s')      SchemaException: column "s": max requires a numeric column
max('nope')   SchemaException: unknown column "nope"
max('')       SchemaException: unknown column ""
avg('b')      SchemaException: column "b": avg requires a numeric column
avg('s')      SchemaException: column "s": avg requires a numeric column
avg('nope')   SchemaException: unknown column "nope"
avg('')       SchemaException: unknown column ""
-- arguments: one string
ArgumentCountError: BatchFlow\Query::min() expects exactly 1 argument, 0 given
TypeError: BatchFlow\Query::max(): Argument #1 ($column) must be of type string, array given
ArgumentCountError: BatchFlow\Query::avg() expects exactly 1 argument, 2 given
-- the results equal aggregate() with one pair
min(i) same
min(f) same
max(i) same
max(f) same
avg(i) same
avg(f) same
