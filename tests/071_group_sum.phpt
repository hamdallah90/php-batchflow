--TEST--
batchflow: groupSum gives [key, count, sum] with int sums as int, float sums as bit-exact IEEE row-order floats, NULLs ignored
--EXTENSIONS--
batchflow
--FILE--
<?php
require __DIR__ . '/bf_helper.inc';

function g(string $label, string $csv, array $S, string $key, string $sum, array $preds = [], array $opts = []): void
{
    $x = ['key' => $key, 'sum' => $sum];
    $a = bf_gexec($csv, $S, $opts, $preds, 'groupSum', $x);
    $b = bf_gref($csv, $S, $opts, $preds, 'groupSum', $x);
    echo str_pad($label, 52), $a, $a === $b ? '' : "   [DIFFERS FROM REFERENCE: $b]", "\n";
}
$SI = ['k' => 'string', 'v' => 'int64'];
$SF = ['k' => 'string', 'v' => 'float64'];
$II = ['k' => 'int64', 'v' => 'int64'];
$IF = ['k' => 'int64', 'v' => 'float64'];

echo "-- int64 sums\n";
g('string key, int sum', "k,v\na,1\nb,10\na,2\nb,-4\na,3\n", $SI, 'k', 'v');
g('int key, int sum', "k,v\n1,5\n2,6\n1,7\n", $II, 'k', 'v');
g('negative and zero sums', "k,v\na,-5\na,5\nb,0\n", $SI, 'k', 'v');
g('NULL sum values are ignored, rows still counted', "k,v\na,1\na,\na,2\n", $SI, 'k', 'v');
g('a group whose sums are all NULL sums to int(0)', "k,v\na,\nb,4\na,\n", $SI, 'k', 'v');
g('column order: sum column before the key column', "v,k\n1,a\n2,b\n3,a\n", $SI, 'k', 'v');
g('a third, unreferenced column', "k,x,v\na,zz,1\nb,,2\na,,3\n", ['k' => 'string', 'x' => 'string', 'v' => 'int64'], 'k', 'v');
g('whereGt on the sum column', "k,v\na,1\nb,10\na,20\nb,3\n", $SI, 'k', 'v', [['gt', 'v', 2]]);
g('whereEq on the key', "k,v\na,1\nb,10\na,20\n", $SI, 'k', 'v', [['eq', 'k', 'a']]);
g('CRLF', "k,v\r\na,1\r\na,2\r\n", $SI, 'k', 'v');
echo "-- float64 sums\n";
g('plain floats', "k,v\na,1.5\nb,2.25\na,0.5\n", $SF, 'k', 'v');
g('row-order IEEE addition per group (0.1+0.2+0.3)', "k,v\na,0.1\nb,1\na,0.2\na,0.3\n", $SF, 'k', 'v');
g('row order is not re-associated (0.3+0.2+0.1)', "k,v\na,0.3\na,0.2\na,0.1\n", $SF, 'k', 'v');
g('large then small: no compensated summation', "k,v\na,1e16\na,1\na,1\na,-1e16\n", $SF, 'k', 'v');
g('all NULL float group sums to float(0.0)', "k,v\na,\nb,1.5\n", $SF, 'k', 'v');
g('negative zero values sum from +0.0', "k,v\na,-0.0\n", $SF, 'k', 'v');
g('int key, float sum', "k,v\n1,0.5\n2,0.25\n1,0.5\n", $IF, 'k', 'v');
g('exponent forms', "k,v\na,1e2\na,2.5E-1\na,.5\na,5.\n", $SF, 'k', 'v');
echo "-- the exact floats\n";
$r = bf_q("k,v\na,0.1\na,0.2\na,0.3\n", $SF)->groupSum('k', 'v');
var_dump($r[0][2] === (0.1 + 0.2) + 0.3, $r[0][2] === 0.1 + (0.2 + 0.3));
echo "-- column errors are raised before any I/O (the path does not exist)\n";
$q = BatchFlow\Csv::scan('/nonexistent/batchflow/none.csv', ['k' => 'string', 'v' => 'int64', 'f' => 'float64', 'b' => 'bool']);
foreach ([['k', 'k'], ['k', 'b'], ['k', 'zz'], ['zz', 'v'], ['f', 'v'], ['b', 'v'], ['k', 'a"b']] as [$k, $c]) {
    echo str_pad("groupSum('$k', '$c')", 24), bf_try(fn() => $q->groupSum($k, $c)), "\n";
}
?>
--EXPECT--
-- int64 sums
string key, int sum                                 [s"a",int(3),int(6)]; [s"b",int(2),int(6)]
int key, int sum                                    [int(1),int(2),int(12)]; [int(2),int(1),int(6)]
negative and zero sums                              [s"a",int(2),int(0)]; [s"b",int(1),int(0)]
NULL sum values are ignored, rows still counted     [s"a",int(3),int(3)]
a group whose sums are all NULL sums to int(0)      [s"a",int(2),int(0)]; [s"b",int(1),int(4)]
column order: sum column before the key column      [s"a",int(2),int(4)]; [s"b",int(1),int(2)]
a third, unreferenced column                        [s"a",int(2),int(4)]; [s"b",int(1),int(2)]
whereGt on the sum column                           [s"b",int(2),int(13)]; [s"a",int(1),int(20)]
whereEq on the key                                  [s"a",int(2),int(21)]
CRLF                                                [s"a",int(2),int(3)]
-- float64 sums
plain floats                                        [s"a",int(2),float(2.0)]; [s"b",int(1),float(2.25)]
row-order IEEE addition per group (0.1+0.2+0.3)     [s"a",int(3),float(0.6000000000000001)]; [s"b",int(1),float(1.0)]
row order is not re-associated (0.3+0.2+0.1)        [s"a",int(3),float(0.6)]
large then small: no compensated summation          [s"a",int(4),float(0.0)]
all NULL float group sums to float(0.0)             [s"a",int(1),float(0.0)]; [s"b",int(1),float(1.5)]
negative zero values sum from +0.0                  [s"a",int(1),float(0.0)]
int key, float sum                                  [int(1),int(2),float(1.0)]; [int(2),int(1),float(0.25)]
exponent forms                                      [s"a",int(4),float(105.75)]
-- the exact floats
bool(true)
bool(false)
-- column errors are raised before any I/O (the path does not exist)
groupSum('k', 'k')      SchemaException: column "k": sum requires a numeric column
groupSum('k', 'b')      SchemaException: column "b": sum requires a numeric column
groupSum('k', 'zz')     SchemaException: unknown column "zz"
groupSum('zz', 'v')     SchemaException: unknown column "zz"
groupSum('f', 'v')      SchemaException: column "f": group key must be string or int64
groupSum('b', 'v')      SchemaException: column "b": group key must be string or int64
groupSum('k', 'a"b')    SchemaException: unknown column "a\x22b"
