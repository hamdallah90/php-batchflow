--TEST--
batchflow: every accepted predicate pair of 5.7, int-to-float round-trip boundaries, immutable receiver, 64 predicates
--EXTENSIONS--
batchflow
--FILE--
<?php
require __DIR__ . '/bf_helper.inc';

use BatchFlow\{Csv, Query};

$q = Csv::scan('/nonexistent/batchflow/none.csv', ['i' => 'int64', 'f' => 'float64', 'b' => 'bool', 's' => 'string']);

function p(string $label, callable $fn): void
{
    echo str_pad($label, 40), bf_try(fn() => $fn() instanceof Query ? 'Query' : 'other'), "\n";
}

echo "-- int64\n";
p('whereEq int', fn() => $q->whereEq('i', 5));
p('whereEq int min', fn() => $q->whereEq('i', PHP_INT_MIN));
p('whereEq int max', fn() => $q->whereEq('i', PHP_INT_MAX));
p('whereEq null', fn() => $q->whereEq('i', null));
p('whereGt int', fn() => $q->whereGt('i', -1));
p('whereGt int max', fn() => $q->whereGt('i', PHP_INT_MAX));
echo "-- float64\n";
p('whereEq float', fn() => $q->whereEq('f', 1.5));
p('whereEq -0.0', fn() => $q->whereEq('f', -0.0));
p('whereEq huge finite', fn() => $q->whereEq('f', 1.7976931348623157e308));
p('whereEq int 0', fn() => $q->whereEq('f', 0));
p('whereEq null', fn() => $q->whereEq('f', null));
p('whereGt float', fn() => $q->whereGt('f', -2.25));
p('whereGt int', fn() => $q->whereGt('f', 3));
echo "-- bool\n";
p('whereEq true', fn() => $q->whereEq('b', true));
p('whereEq false', fn() => $q->whereEq('b', false));
p('whereEq null', fn() => $q->whereEq('b', null));
echo "-- string\n";
p('whereEq string', fn() => $q->whereEq('s', 'abc'));
p('whereEq empty string', fn() => $q->whereEq('s', ''));
p('whereEq UTF-8', fn() => $q->whereEq('s', "caf\u{e9} \u{1F600}"));
p('whereEq NUL byte', fn() => $q->whereEq('s', "a\0b"));
p('whereEq null', fn() => $q->whereEq('s', null));
p('whereEq 1048576 bytes', fn() => $q->whereEq('s', str_repeat('x', 1048576)));
p('whereEq 1048576 bytes (multibyte)', fn() => $q->whereEq('s', str_repeat("\u{e9}", 524288)));

echo "-- int literal on float64 must round-trip through binary64\n";
$cases = [
    '2^53' => 9007199254740992,
    '2^53 + 1' => 9007199254740993,
    '2^53 + 2' => 9007199254740994,
    '-(2^53)' => -9007199254740992,
    '-(2^53) - 1' => -9007199254740993,
    '2^62' => 4611686018427387904,
    '2^63 - 1024 (largest below 2^63)' => 9223372036854774784,
    '2^63 - 1023' => 9223372036854774785,
    '2^63 - 1 (PHP_INT_MAX)' => PHP_INT_MAX,
    'PHP_INT_MIN (-2^63)' => PHP_INT_MIN,
    'PHP_INT_MIN + 1' => PHP_INT_MIN + 1,
    '0' => 0,
    '-1' => -1,
    '3^30 (odd, exact)' => 205891132094649,
];
foreach ($cases as $l => $v) {
    p("whereEq $l", fn() => $q->whereEq('f', $v));
    p("whereGt $l", fn() => $q->whereGt('f', $v));
}

echo "-- receiver is unchanged by where*\n";
$base = Csv::scan('/nonexistent/batchflow/none.csv', ['i' => 'int64']);
$derived = $base->whereEq('i', 1);
var_dump($derived !== $base, $derived instanceof Query);
$ok = 0;
for ($n = 1; $n <= 100; $n++) {
    // If a call mutated its receiver, the 65th would fail with "too many predicates".
    $base->whereEq('i', $n);
    $ok++;
}
echo "100 predicates from one receiver: $ok\n";
$chain = $base->whereGt('i', 0);
$chain2 = $chain->whereGt('i', 1);
for ($n = 1; $n <= 62; $n++) {
    $chain->whereGt('i', $n);
}
echo "branches from a 1-predicate receiver: ok\n";

echo "-- 64 predicates accepted, the same column may repeat\n";
$c = $base;
for ($n = 1; $n <= 64; $n++) {
    $c = $c->whereGt('i', $n);
}
var_dump($c instanceof Query);
$m = Csv::scan('/nonexistent/batchflow/none.csv', ['i' => 'int64', 'f' => 'float64']);
for ($n = 1; $n <= 32; $n++) {
    $m = $m->whereEq('i', $n)->whereGt('f', $n);
}
var_dump($m instanceof Query);
?>
--EXPECT--
-- int64
whereEq int                             Query
whereEq int min                         Query
whereEq int max                         Query
whereEq null                            Query
whereGt int                             Query
whereGt int max                         Query
-- float64
whereEq float                           Query
whereEq -0.0                            Query
whereEq huge finite                     Query
whereEq int 0                           Query
whereEq null                            Query
whereGt float                           Query
whereGt int                             Query
-- bool
whereEq true                            Query
whereEq false                           Query
whereEq null                            Query
-- string
whereEq string                          Query
whereEq empty string                    Query
whereEq UTF-8                           Query
whereEq NUL byte                        Query
whereEq null                            Query
whereEq 1048576 bytes                   Query
whereEq 1048576 bytes (multibyte)       Query
-- int literal on float64 must round-trip through binary64
whereEq 2^53                            Query
whereGt 2^53                            Query
whereEq 2^53 + 1                        SchemaException: column "f": integer literal is not exactly representable as float64
whereGt 2^53 + 1                        SchemaException: column "f": integer literal is not exactly representable as float64
whereEq 2^53 + 2                        Query
whereGt 2^53 + 2                        Query
whereEq -(2^53)                         Query
whereGt -(2^53)                         Query
whereEq -(2^53) - 1                     SchemaException: column "f": integer literal is not exactly representable as float64
whereGt -(2^53) - 1                     SchemaException: column "f": integer literal is not exactly representable as float64
whereEq 2^62                            Query
whereGt 2^62                            Query
whereEq 2^63 - 1024 (largest below 2^63)Query
whereGt 2^63 - 1024 (largest below 2^63)Query
whereEq 2^63 - 1023                     SchemaException: column "f": integer literal is not exactly representable as float64
whereGt 2^63 - 1023                     SchemaException: column "f": integer literal is not exactly representable as float64
whereEq 2^63 - 1 (PHP_INT_MAX)          SchemaException: column "f": integer literal is not exactly representable as float64
whereGt 2^63 - 1 (PHP_INT_MAX)          SchemaException: column "f": integer literal is not exactly representable as float64
whereEq PHP_INT_MIN (-2^63)             Query
whereGt PHP_INT_MIN (-2^63)             Query
whereEq PHP_INT_MIN + 1                 SchemaException: column "f": integer literal is not exactly representable as float64
whereGt PHP_INT_MIN + 1                 SchemaException: column "f": integer literal is not exactly representable as float64
whereEq 0                               Query
whereGt 0                               Query
whereEq -1                              Query
whereGt -1                              Query
whereEq 3^30 (odd, exact)               Query
whereGt 3^30 (odd, exact)               Query
-- receiver is unchanged by where*
bool(true)
bool(true)
100 predicates from one receiver: 100
branches from a 1-predicate receiver: ok
-- 64 predicates accepted, the same column may repeat
bool(true)
bool(true)
