--TEST--
batchflow: groupSum with a bad key column and a bad sum column reports the key column first (argument order, SPEC 5.11), after the maxGroups ValueError and before any I/O
--EXTENSIONS--
batchflow
--FILE--
<?php
require __DIR__ . '/bf_helper.inc';

$S = ['k' => 'string', 'i' => 'int64', 'f' => 'float64', 'b' => 'bool', 's' => 'string'];
$csv = "k,i,f,b,s\na,1,1.5,true,x\n";
$q = BatchFlow\Csv::scan('/nonexistent/batchflow/none.csv', $S);

$cases = [
    // [key, sum, maxGroups]
    ['nokey', 'nosum', 1000000],
    ['nokey', 's', 1000000],
    ['f', 'nosum', 1000000],
    ['b', 's', 1000000],
    ['f', 'b', 1000000],
    ['nokey', 'i', 1000000],
    ['k', 'nosum', 1000000],
    ['k', 's', 1000000],
    ['b', 'i', 1000000],
    ['nokey', 'nosum', 0],
    ['b', 's', 100000001],
];
foreach ($cases as [$key, $sum, $max]) {
    $label = "groupSum('$key', '$sum', $max)";
    $x = ['key' => $key, 'sum' => $sum, 'maxGroups' => $max];
    $a = bf_try(fn() => $q->groupSum($key, $sum, $max));
    $b = bf_gref($csv, $S, [], [], 'groupSum', $x);
    echo str_pad($label, 40), $a, $a === $b ? '' : "   [DIFFERS FROM REFERENCE: $b]", "\n";
}
echo "-- the same order on a readable file, through the comparison helper\n";
foreach ($cases as [$key, $sum, $max]) {
    echo str_pad("groupSum('$key', '$sum', $max)", 40), bf_gcmp($csv, $S, [], [], 'groupSum', ['key' => $key, 'sum' => $sum, 'maxGroups' => $max]), "\n";
}
?>
--EXPECT--
groupSum('nokey', 'nosum', 1000000)     SchemaException: unknown column "nokey"
groupSum('nokey', 's', 1000000)         SchemaException: unknown column "nokey"
groupSum('f', 'nosum', 1000000)         SchemaException: column "f": group key must be string or int64
groupSum('b', 's', 1000000)             SchemaException: column "b": group key must be string or int64
groupSum('f', 'b', 1000000)             SchemaException: column "f": group key must be string or int64
groupSum('nokey', 'i', 1000000)         SchemaException: unknown column "nokey"
groupSum('k', 'nosum', 1000000)         SchemaException: unknown column "nosum"
groupSum('k', 's', 1000000)             SchemaException: column "s": sum requires a numeric column
groupSum('b', 'i', 1000000)             SchemaException: column "b": group key must be string or int64
groupSum('nokey', 'nosum', 0)           ValueError: BatchFlow\Query::groupSum(): Argument #3 ($maxGroups) must be between 1 and 100000000
groupSum('b', 's', 100000001)           ValueError: BatchFlow\Query::groupSum(): Argument #3 ($maxGroups) must be between 1 and 100000000
-- the same order on a readable file, through the comparison helper
groupSum('nokey', 'nosum', 1000000)     same
groupSum('nokey', 's', 1000000)         same
groupSum('f', 'nosum', 1000000)         same
groupSum('b', 's', 1000000)             same
groupSum('f', 'b', 1000000)             same
groupSum('nokey', 'i', 1000000)         same
groupSum('k', 'nosum', 1000000)         same
groupSum('k', 's', 1000000)             same
groupSum('b', 'i', 1000000)             same
groupSum('nokey', 'nosum', 0)           same
groupSum('b', 's', 100000001)           same
