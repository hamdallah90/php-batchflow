--TEST--
batchflow: a significand above 2^53 takes the correctly rounded path, not Clinger's fast path
--EXTENSIONS--
batchflow
--FILE--
<?php
require __DIR__ . '/bf_helper.inc';

$S = ['x' => 'float64'];
foreach ([
    // significand, expected from an arbitrary-precision computation of the nearest binary64
    ['9007199254740992e1', 9.007199254740992E+16],    // 2^53: the fast path is exact
    ['9007199254740993e1', 9.007199254740994E+16],    // 2^53 + 1
    ['9007199254740995e2', 9.007199254740995E+17],    // 2^53 + 3
    ['18014398509481983e-1', 1801439850948198.2],     // 2^54 - 1
] as [$lit, $want]) {
    $got = bf_exec("x\n$lit\n", $S, [], [], 'sum', 'x');
    echo str_pad($lit, 24), $got, $got === 'float(' . var_export($want, true) . ')' ? '' : '  WRONG', "\n";
}
?>
--EXPECT--
9007199254740992e1      float(90071992547409920.0)
9007199254740993e1      float(90071992547409940.0)
9007199254740995e2      float(9.007199254740995E+17)
18014398509481983e-1    float(1801439850948198.2)
