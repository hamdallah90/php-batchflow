--TEST--
batchflow: predicate evaluation per type against the reference - 1, 2 and 64 predicates, NULL behaviour, -0.0, repeats on one column
--EXTENSIONS--
batchflow
--FILE--
<?php
require __DIR__ . '/bf_helper.inc';

$S = ['i' => 'int64', 'f' => 'float64', 'b' => 'bool', 's' => 'string'];
// Hand-checkable fixture. Row: i, f, b, s
$csv = "i,f,b,s\n"
    . "1,1.5,true,x\n"            // 1
    . "2,-0.0,false,y\n"          // 2
    . "3,0.0,,\"\"\n"             // 3  b NULL, s empty string
    . ",2,true,\n"                // 4  i NULL, s NULL
    . "9223372036854775807,1e308,false,x\n"   // 5
    . "-9223372036854775808,-1e308,true,z\n"  // 6
    . "0,,false,\n";              // 7  f NULL, s NULL

function p(string $label, string $csv, array $S, array $preds, string $term = 'count', ?string $col = null): void
{
    $a = bf_exec($csv, $S, [], $preds, $term, $col);
    $b = bf_ref($csv, $S, [], $preds, $term, $col);
    echo str_pad($label, 46), $a, $a === $b ? '' : "   [DIFFERS FROM REFERENCE: $b]", "\n";
}

echo "-- int64\n";
p('i = 1', $csv, $S, [['eq', 'i', 1]]);
p('i = PHP_INT_MAX', $csv, $S, [['eq', 'i', PHP_INT_MAX]]);
p('i = PHP_INT_MIN', $csv, $S, [['eq', 'i', PHP_INT_MIN]]);
p('i = 0', $csv, $S, [['eq', 'i', 0]]);
p('i IS NULL', $csv, $S, [['eq', 'i', null]]);
p('i > 0', $csv, $S, [['gt', 'i', 0]]);
p('i > PHP_INT_MAX - 1', $csv, $S, [['gt', 'i', PHP_INT_MAX - 1]]);
p('i > PHP_INT_MAX', $csv, $S, [['gt', 'i', PHP_INT_MAX]]);
p('i > PHP_INT_MIN', $csv, $S, [['gt', 'i', PHP_INT_MIN]]);
p('i > PHP_INT_MIN, NULL excluded', $csv, $S, [['gt', 'i', PHP_INT_MIN + 0]]);
p('i > -1', $csv, $S, [['gt', 'i', -1]]);
echo "-- float64\n";
p('f = 1.5', $csv, $S, [['eq', 'f', 1.5]]);
p('f = 0.0 matches -0.0 and 0.0', $csv, $S, [['eq', 'f', 0.0]]);
p('f = -0.0 matches -0.0 and 0.0', $csv, $S, [['eq', 'f', -0.0]]);
p('f = 0 (int literal)', $csv, $S, [['eq', 'f', 0]]);
p('f = 2 (int literal)', $csv, $S, [['eq', 'f', 2]]);
p('f IS NULL', $csv, $S, [['eq', 'f', null]]);
p('f > 0 (zero rows are not greater)', $csv, $S, [['gt', 'f', 0]]);
p('f > -0.0', $csv, $S, [['gt', 'f', -0.0]]);
p('f > 1.5', $csv, $S, [['gt', 'f', 1.5]]);
p('f > 1 (int literal)', $csv, $S, [['gt', 'f', 1]]);
p('f > -1e308', $csv, $S, [['gt', 'f', -1e308]]);
p('f > 1e308', $csv, $S, [['gt', 'f', 1e308]]);
p('f = 1e308', $csv, $S, [['eq', 'f', 1e308]]);
echo "-- bool\n";
p('b = true', $csv, $S, [['eq', 'b', true]]);
p('b = false', $csv, $S, [['eq', 'b', false]]);
p('b IS NULL', $csv, $S, [['eq', 'b', null]]);
echo "-- string\n";
p('s = x', $csv, $S, [['eq', 's', 'x']]);
p('s = empty string (not NULL)', $csv, $S, [['eq', 's', '']]);
p('s IS NULL (not empty string)', $csv, $S, [['eq', 's', null]]);
p('s = X (case-sensitive)', $csv, $S, [['eq', 's', 'X']]);
p('s = xx', $csv, $S, [['eq', 's', 'xx']]);
p('s = absent', $csv, $S, [['eq', 's', 'nope']]);
echo "-- several predicates, AND only, same column may repeat\n";
p('b = true AND s = x', $csv, $S, [['eq', 'b', true], ['eq', 's', 'x']]);
p('i > 0 AND i > 2', $csv, $S, [['gt', 'i', 0], ['gt', 'i', 2]]);
p('i > 0 AND i = 1 AND i = 1', $csv, $S, [['gt', 'i', 0], ['eq', 'i', 1], ['eq', 'i', 1]]);
p('i = 1 AND i = 2 (contradiction)', $csv, $S, [['eq', 'i', 1], ['eq', 'i', 2]]);
p('i IS NULL AND i = 1', $csv, $S, [['eq', 'i', null], ['eq', 'i', 1]]);
p('i IS NULL AND s IS NULL', $csv, $S, [['eq', 'i', null], ['eq', 's', null]]);
p('f > -2.0 AND b = false AND s IS NULL', $csv, $S, [['gt', 'f', -2.0], ['eq', 'b', false], ['eq', 's', null]]);
p('count + sum(i) with b = true', $csv, $S, [['eq', 'b', true]], 'sum', 'i');
p('sum(f) with i > 0', $csv, $S, [['gt', 'i', 0]], 'sum', 'f');

echo "-- 64 predicates\n";
$many = [];
for ($k = 0; $k < 64; $k++) {
    $many[] = ['gt', 'i', -1 - $k];
}
p('64 x (i > -k)', $csv, $S, $many);
$many[63] = ['eq', 'i', 3];
p('63 passing + i = 3', $csv, $S, $many);
$many2 = array_fill(0, 63, ['eq', 'b', false]);
$many2[] = ['eq', 's', 'x'];
p('63 x (b = false) + s = x', $csv, $S, $many2);
$many3 = array_fill(0, 64, ['eq', 'i', null]);
p('64 x (i IS NULL)', $csv, $S, $many3);

echo "-- 3000 generated rows against the reference\n";
mt_srand(20261109);
$rows = "i,f,b,s\n";
$vals = ['i' => ['', '0', '1', '-1', '5', '9223372036854775807', '-9223372036854775808', '42', '7'],
         'f' => ['', '0', '-0', '0.0', '1.5', '-1.5', '1e308', '-1e308', '2', '1e-320'],
         'b' => ['', 'true', 'false'],
         's' => ['', '""', 'x', 'y', '"x"', 'caf' . "\u{e9}", '"a,b"', 'xx']];
for ($n = 0; $n < 3000; $n++) {
    $r = [];
    foreach ($vals as $v) {
        $r[] = $v[mt_rand(0, count($v) - 1)];
    }
    $rows .= implode(',', $r) . "\n";
}
$lits = ['i' => [null, 0, 1, 5, -1, PHP_INT_MAX, PHP_INT_MIN], 'f' => [null, 0, 0.0, -0.0, 1.5, 2, 1e308, -1e308, 1e-320], 'b' => [null, true, false],
         's' => [null, '', 'x', 'y', 'a,b', "caf\u{e9}", 'q']];
$bad = 0;
$runs = 0;
for ($t = 0; $t < 120; $t++) {
    $preds = [];
    for ($k = mt_rand(1, 4); $k > 0; $k--) {
        $c = ['i', 'f', 'b', 's'][mt_rand(0, 3)];
        $gt = in_array($c, ['i', 'f'], true) && mt_rand(0, 1);
        $lit = $lits[$c][mt_rand(0, count($lits[$c]) - 1)];
        if ($gt && $lit === null) {
            $lit = 0;
        }
        $preds[] = [$gt ? 'gt' : 'eq', $c, $lit];
    }
    foreach ([['count', null], ['sum', 'i'], ['summary', 'f']] as [$term, $col]) {
        $runs++;
        if (bf_exec($rows, $S, [], $preds, $term, $col) !== bf_ref($rows, $S, [], $preds, $term, $col)) {
            $bad++;
        }
    }
}
echo "differential runs: $runs, disagreements: $bad\n";
?>
--EXPECT--
-- int64
i = 1                                         int(1)
i = PHP_INT_MAX                               int(1)
i = PHP_INT_MIN                               int(1)
i = 0                                         int(1)
i IS NULL                                     int(1)
i > 0                                         int(4)
i > PHP_INT_MAX - 1                           int(1)
i > PHP_INT_MAX                               int(0)
i > PHP_INT_MIN                               int(5)
i > PHP_INT_MIN, NULL excluded                int(5)
i > -1                                        int(5)
-- float64
f = 1.5                                       int(1)
f = 0.0 matches -0.0 and 0.0                  int(2)
f = -0.0 matches -0.0 and 0.0                 int(2)
f = 0 (int literal)                           int(2)
f = 2 (int literal)                           int(1)
f IS NULL                                     int(1)
f > 0 (zero rows are not greater)             int(3)
f > -0.0                                      int(3)
f > 1.5                                       int(2)
f > 1 (int literal)                           int(3)
f > -1e308                                    int(5)
f > 1e308                                     int(0)
f = 1e308                                     int(1)
-- bool
b = true                                      int(3)
b = false                                     int(3)
b IS NULL                                     int(1)
-- string
s = x                                         int(2)
s = empty string (not NULL)                   int(1)
s IS NULL (not empty string)                  int(2)
s = X (case-sensitive)                        int(0)
s = xx                                        int(0)
s = absent                                    int(0)
-- several predicates, AND only, same column may repeat
b = true AND s = x                            int(1)
i > 0 AND i > 2                               int(2)
i > 0 AND i = 1 AND i = 1                     int(1)
i = 1 AND i = 2 (contradiction)               int(0)
i IS NULL AND i = 1                           int(0)
i IS NULL AND s IS NULL                       int(1)
f > -2.0 AND b = false AND s IS NULL          int(0)
count + sum(i) with b = true                  int(-9223372036854775807)
sum(f) with i > 0                             float(1.0E+308)
-- 64 predicates
64 x (i > -k)                                 int(5)
63 passing + i = 3                            int(1)
63 x (b = false) + s = x                      int(1)
64 x (i IS NULL)                              int(1)
-- 3000 generated rows against the reference
differential runs: 360, disagreements: 0
