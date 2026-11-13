--TEST--
batchflow: count and sum - int64, float64 row-order bit-exact, all NULL, no match, type and column errors before I/O
--EXTENSIONS--
batchflow
--FILE--
<?php
require __DIR__ . '/bf_helper.inc';

use BatchFlow\Csv;

function s(string $label, string $csv, array $S, array $preds = [], string $term = 'sum', ?string $col = 'v'): void
{
    $a = bf_exec($csv, $S, [], $preds, $term, $col);
    $b = bf_ref($csv, $S, [], $preds, $term, $col);
    echo str_pad($label, 44), $a, $a === $b ? '' : "   [DIFFERS FROM REFERENCE: $b]", "\n";
}
$I = ['v' => 'int64'];
$F = ['v' => 'float64'];

echo "-- int64\n";
s('1+2+3', "v\n1\n2\n3\n", $I);
s('negative and positive', "v\n-5\n3\n-1\n", $I);
s('PHP_INT_MAX + PHP_INT_MIN', "v\n9223372036854775807\n-9223372036854775808\n", $I);
s('PHP_INT_MAX + PHP_INT_MIN + -1', "v\n9223372036854775807\n-9223372036854775808\n-1\n", $I);
s('PHP_INT_MAX alone', "v\n9223372036854775807\n", $I);
s('PHP_INT_MIN alone', "v\n-9223372036854775808\n", $I);
s('NULLs are ignored', "v\n1\n\n2\n\n", $I);
s('all NULL gives int 0', "v\n\n\n", $I);
s('no rows gives int 0', "v\n", $I);
s('no match gives int 0', "v\n1\n2\n", $I, [['eq', 'v', 99]]);
s('only matching rows are summed', "v\n1\n2\n3\n", $I, [['gt', 'v', 1]]);
s('result type is int', "v\n5\n", $I);
echo "-- float64\n";
s('1.5+2.5', "v\n1.5\n2.5\n", $F);
s('0.1+0.2+0.3 in row order', "v\n0.1\n0.2\n0.3\n", $F);
s('0.3+0.2+0.1 in row order', "v\n0.3\n0.2\n0.1\n", $F);
s('no compensated summation (1e16,1,1,-1e16)', "v\n1e16\n1\n1\n-1e16\n", $F);
s('row order matters (1e16,-1e16,1)', "v\n1e16\n-1e16\n1\n", $F);
s('row order matters (1,1e16,-1e16)', "v\n1\n1e16\n-1e16\n", $F);
s('NULLs are ignored', "v\n1.5\n\n2.5\n", $F);
s('all NULL gives float 0.0', "v\n\n\n", $F);
s('no rows gives float 0.0', "v\n", $F);
s('no match gives float 0.0', "v\n1.5\n", $F, [['gt', 'v', 99.0]]);
s('negative zero sums to +0.0', "v\n-0.0\n", $F);
s('large finite values', "v\n1e308\n-1e308\n", $F);
s('subnormal values', "v\n5e-324\n5e-324\n", $F);

// Bit-exact against a plain PHP loop over many values.
mt_srand(20261109);
$vals = [];
for ($n = 0; $n < 5000; $n++) {
    $vals[] = (mt_rand(0, 1) ? '-' : '') . mt_rand(0, 99999) . '.' . mt_rand(0, 999999) . 'e' . mt_rand(-5, 8);
}
$expect = 0.0;
foreach ($vals as $v) {
    $expect += (float)$v;
}
$got = Csv::scan(bf_file("v\n" . implode("\n", $vals) . "\n"), $F)->sum('v');
echo 'bit-exact over 5000 values: ', is_float($got) && pack('E', $got) === pack('E', $expect) ? 'yes' : 'NO', "\n";

echo "-- count\n";
s('count() counts all rows', "v\n1\n\n3\n", $I, [], 'count', null);
s('count() with NULL rows only', "v\n\n\n", $I, [], 'count', null);
s('count() header only', "v\n", $I, [], 'count', null);
s('count() with a predicate', "v\n1\n2\n3\n", $I, [['gt', 'v', 1]], 'count', null);
s('count() after sum() on the same data is independent', "v\n1\n2\n", $I, [], 'count', null);

echo "-- sum errors, before any I/O\n";
$q = Csv::scan('/nonexistent/batchflow/none.csv', ['i' => 'int64', 's' => 'string', 'b' => 'bool', 'f' => 'float64']);
foreach (['s', 'b'] as $c) {
    echo "sum($c): ", bf_try(fn() => $q->sum($c)), "\n";
}
echo 'sum(unknown): ', bf_try(fn() => $q->sum('zz')), "\n";
echo 'sum(escaped): ', bf_try(fn() => $q->sum("a\"\xff")), "\n";
echo 'sum(i) on a missing file: ', bf_try(fn() => $q->sum('i')), "\n";
echo 'count() on a missing file: ', bf_try(fn() => $q->count()), "\n";
echo "-- terminals can be repeated on a path source\n";
$qq = bf_q("v\n1\n2\n", $I);
echo bf_try(fn() => $qq->count()), ' ', bf_try(fn() => $qq->sum('v')), ' ', bf_try(fn() => $qq->count()), ' ', bf_try(fn() => $qq->sum('v')), "\n";
?>
--EXPECT--
-- int64
1+2+3                                       int(6)
negative and positive                       int(-3)
PHP_INT_MAX + PHP_INT_MIN                   int(-1)
PHP_INT_MAX + PHP_INT_MIN + -1              int(-2)
PHP_INT_MAX alone                           int(9223372036854775807)
PHP_INT_MIN alone                           int(-9223372036854775808)
NULLs are ignored                           int(3)
all NULL gives int 0                        int(0)
no rows gives int 0                         int(0)
no match gives int 0                        int(0)
only matching rows are summed               int(5)
result type is int                          int(5)
-- float64
1.5+2.5                                     float(4.0)
0.1+0.2+0.3 in row order                    float(0.6000000000000001)
0.3+0.2+0.1 in row order                    float(0.6)
no compensated summation (1e16,1,1,-1e16)   float(0.0)
row order matters (1e16,-1e16,1)            float(1.0)
row order matters (1,1e16,-1e16)            float(0.0)
NULLs are ignored                           float(4.0)
all NULL gives float 0.0                    float(0.0)
no rows gives float 0.0                     float(0.0)
no match gives float 0.0                    float(0.0)
negative zero sums to +0.0                  float(0.0)
large finite values                         float(0.0)
subnormal values                            float(1.0E-323)
bit-exact over 5000 values: yes
-- count
count() counts all rows                     int(3)
count() with NULL rows only                 int(2)
count() header only                         int(0)
count() with a predicate                    int(2)
count() after sum() on the same data is independentint(2)
-- sum errors, before any I/O
sum(s): SchemaException: column "s": sum requires a numeric column
sum(b): SchemaException: column "b": sum requires a numeric column
sum(unknown): SchemaException: unknown column "zz"
sum(escaped): SchemaException: unknown column "a\x22\xFF"
sum(i) on a missing file: StreamException: cannot open input
count() on a missing file: StreamException: cannot open input
-- terminals can be repeated on a path source
int(2) int(3) int(2) int(3)
