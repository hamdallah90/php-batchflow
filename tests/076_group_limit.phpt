--TEST--
batchflow: maxGroups exactly at and past the limit, NULL counted, ValueError range and text, no partial result, key column checks
--EXTENSIONS--
batchflow
--FILE--
<?php
require __DIR__ . "/bf_helper.inc";

function g(string $label, string $csv, array $S, string $key, ?string $sum = null, array $preds = [], array $opts = [], int $maxGroups = 1000000): void
{
    $term = $sum === null ? "groupCount" : "groupSum";
    $x = ["key" => $key, "sum" => $sum, "maxGroups" => $maxGroups];
    $a = bf_gexec($csv, $S, $opts, $preds, $term, $x);
    $b = bf_gref($csv, $S, $opts, $preds, $term, $x);
    echo str_pad($label, 52), $a, $a === $b ? "" : "   [DIFFERS FROM REFERENCE: $b]", "\n";
}
$S = ['k' => 'string', 'v' => 'int64'];
$I = ['k' => 'int64', 'v' => 'int64'];

echo "-- exactly at the limit succeeds\n";
g('3 distinct keys, maxGroups 3', "k,v\na,1\nb,1\nc,1\n", $S, 'k', null, [], [], 3);
g('3 distinct keys, maxGroups 3, groupSum', "k,v\na,1\nb,2\nc,3\n", $S, 'k', 'v', [], [], 3);
g('1 key repeated, maxGroups 1', "k,v\na,1\na,1\na,1\n", $S, 'k', null, [], [], 1);
g('repeats after the limit is reached are fine', "k,v\na,1\nb,1\na,1\nb,1\na,1\n", $S, 'k', null, [], [], 2);
g('int keys at the limit', "k,v\n1,1\n2,1\n3,1\n", $I, 'k', null, [], [], 3);
echo "-- one past the limit raises at the first key that would be group maxGroups+1\n";
g('4th distinct key, maxGroups 3', "k,v\na,1\nb,1\nc,1\nd,1\n", $S, 'k', null, [], [], 3);
g('same, groupSum', "k,v\na,1\nb,2\nc,3\nd,4\n", $S, 'k', 'v', [], [], 3);
g('int keys', "k,v\n1,1\n2,1\n3,1\n4,1\n", $I, 'k', null, [], [], 3);
g('2nd distinct key, maxGroups 1', "k,v\na,1\na,1\nb,1\n", $S, 'k', null, [], [], 1);
g('line count includes LFs inside quoted fields', "k,v\n\"a\nb\",1\nc,1\nd,1\n", $S, 'k', null, [], [], 2);
g('CRLF', "k,v\r\na,1\r\nb,1\r\nc,1\r\n", $S, 'k', null, [], [], 2);
echo "-- the NULL group counts\n";
g('NULL is group 3 of 3', "k,v\na,1\nb,1\n,1\n,1\n", $S, 'k', null, [], [], 3);
g('NULL is group 3 of 2', "k,v\na,1\nb,1\n,1\n", $S, 'k', null, [], [], 2);
g('NULL first then one key, maxGroups 1', "k,v\n,1\na,1\n", $S, 'k', null, [], [], 1);
g('NULL and empty string are two groups', "k,v\n,1\n\"\",1\n", $S, 'k', null, [], [], 1);
g('int key NULL counts', "k,v\n1,1\n,1\n2,1\n", $I, 'k', null, [], [], 2);
echo "-- only rows that passed the predicates create groups\n";
g('filtered rows with new keys do not count', "k,v\na,1\nb,0\nc,0\nd,0\ne,1\n", $S, 'k', null, [['gt', 'v', 0]], [], 2);
g('the first matching row past the limit raises', "k,v\na,1\nb,0\nc,1\nd,1\n", $S, 'k', null, [['gt', 'v', 0]], [], 1);
echo "-- the run stops: nothing after that row is read or validated\n";
g('later unterminated quote', "k,v\na,1\nb,1\n\"never closed", $S, 'k', null, [], [], 1);
g('later bad value', "k,v\na,1\nb,1\nc,x\n", $S, 'k', null, [], [], 1);
echo "-- an error before the limit row comes first\n";
g('bad value before the limit row', "k,v\na,1\nb,x\nc,1\n", $S, 'k', 'v', [], [], 1);
echo "-- no partial result\n";
$r = 'unset';
try {
    $r = bf_q("k,v\na,1\nb,1\nc,1\n", $S)->groupCount('k', 2);
} catch (BatchFlow\ResultTooLargeException $e) {
    echo get_class($e), ' is a ', get_parent_class($e), ", not a result\n";
}
var_dump($r);
echo "-- maxGroups range is a ValueError raised before any I/O and before column checks\n";
$q = BatchFlow\Csv::scan('/nonexistent/batchflow/none.csv', $S);
foreach ([0, -1, 100000001, PHP_INT_MIN, PHP_INT_MAX] as $n) {
    echo str_pad("groupCount('k', $n)", 44), bf_try(fn() => $q->groupCount('k', $n)), "\n";
    echo str_pad("groupSum('k', 'v', $n)", 44), bf_try(fn() => $q->groupSum('k', 'v', $n)), "\n";
}
echo str_pad("groupCount('nope', 0)", 44), bf_try(fn() => $q->groupCount('nope', 0)), "\n";
echo str_pad("groupSum('nope', 'nope', 0)", 44), bf_try(fn() => $q->groupSum('nope', 'nope', 0)), "\n";
echo "-- the bounds themselves are accepted\n";
g('maxGroups 1', "k,v\na,1\n", $S, 'k', null, [], [], 1);
g('maxGroups 100000000', "k,v\na,1\nb,1\n", $S, 'k', null, [], [], 100000000);
g('maxGroups 100000000, groupSum', "k,v\na,1\nb,1\n", $S, 'k', 'v', [], [], 100000000);
echo "-- the key must be string or int64, checked before any I/O\n";
$q = BatchFlow\Csv::scan('/nonexistent/batchflow/none.csv', ['k' => 'string', 'f' => 'float64', 'b' => 'bool', 'i' => 'int64']);
foreach (['f', 'b', 'zz', "a\"b", ''] as $c) {
    echo str_pad("groupCount('$c')", 24), bf_try(fn() => $q->groupCount($c)), "\n";
}
echo str_pad("groupCount('i')", 24), bf_try(fn() => $q->groupCount('i')), "\n";
?>
--EXPECT--
-- exactly at the limit succeeds
3 distinct keys, maxGroups 3                        [s"a",int(1)]; [s"b",int(1)]; [s"c",int(1)]
3 distinct keys, maxGroups 3, groupSum              [s"a",int(1),int(1)]; [s"b",int(1),int(2)]; [s"c",int(1),int(3)]
1 key repeated, maxGroups 1                         [s"a",int(3)]
repeats after the limit is reached are fine         [s"a",int(3)]; [s"b",int(2)]
int keys at the limit                               [int(1),int(1)]; [int(2),int(1)]; [int(3),int(1)]
-- one past the limit raises at the first key that would be group maxGroups+1
4th distinct key, maxGroups 3                       ResultTooLargeException: row 4 (line 5, offset 16): result exceeds maxGroups (3)
same, groupSum                                      ResultTooLargeException: row 4 (line 5, offset 16): result exceeds maxGroups (3)
int keys                                            ResultTooLargeException: row 4 (line 5, offset 16): result exceeds maxGroups (3)
2nd distinct key, maxGroups 1                       ResultTooLargeException: row 3 (line 4, offset 12): result exceeds maxGroups (1)
line count includes LFs inside quoted fields        ResultTooLargeException: row 3 (line 5, offset 16): result exceeds maxGroups (2)
CRLF                                                ResultTooLargeException: row 3 (line 4, offset 15): result exceeds maxGroups (2)
-- the NULL group counts
NULL is group 3 of 3                                [s"a",int(1)]; [s"b",int(1)]; [NULL,int(2)]
NULL is group 3 of 2                                ResultTooLargeException: row 3 (line 4, offset 12): result exceeds maxGroups (2)
NULL first then one key, maxGroups 1                ResultTooLargeException: row 2 (line 3, offset 7): result exceeds maxGroups (1)
NULL and empty string are two groups                ResultTooLargeException: row 2 (line 3, offset 7): result exceeds maxGroups (1)
int key NULL counts                                 ResultTooLargeException: row 3 (line 4, offset 11): result exceeds maxGroups (2)
-- only rows that passed the predicates create groups
filtered rows with new keys do not count            [s"a",int(1)]; [s"e",int(1)]
the first matching row past the limit raises        ResultTooLargeException: row 3 (line 4, offset 12): result exceeds maxGroups (1)
-- the run stops: nothing after that row is read or validated
later unterminated quote                            ResultTooLargeException: row 2 (line 3, offset 8): result exceeds maxGroups (1)
later bad value                                     ResultTooLargeException: row 2 (line 3, offset 8): result exceeds maxGroups (1)
-- an error before the limit row comes first
bad value before the limit row                      CsvException: row 2 (line 3, offset 8): column "v": expected int64, got malformed value
-- no partial result
BatchFlow\ResultTooLargeException is a BatchFlow\LimitException, not a result
string(5) "unset"
-- maxGroups range is a ValueError raised before any I/O and before column checks
groupCount('k', 0)                          ValueError: BatchFlow\Query::groupCount(): Argument #2 ($maxGroups) must be between 1 and 100000000
groupSum('k', 'v', 0)                       ValueError: BatchFlow\Query::groupSum(): Argument #3 ($maxGroups) must be between 1 and 100000000
groupCount('k', -1)                         ValueError: BatchFlow\Query::groupCount(): Argument #2 ($maxGroups) must be between 1 and 100000000
groupSum('k', 'v', -1)                      ValueError: BatchFlow\Query::groupSum(): Argument #3 ($maxGroups) must be between 1 and 100000000
groupCount('k', 100000001)                  ValueError: BatchFlow\Query::groupCount(): Argument #2 ($maxGroups) must be between 1 and 100000000
groupSum('k', 'v', 100000001)               ValueError: BatchFlow\Query::groupSum(): Argument #3 ($maxGroups) must be between 1 and 100000000
groupCount('k', -9223372036854775808)       ValueError: BatchFlow\Query::groupCount(): Argument #2 ($maxGroups) must be between 1 and 100000000
groupSum('k', 'v', -9223372036854775808)    ValueError: BatchFlow\Query::groupSum(): Argument #3 ($maxGroups) must be between 1 and 100000000
groupCount('k', 9223372036854775807)        ValueError: BatchFlow\Query::groupCount(): Argument #2 ($maxGroups) must be between 1 and 100000000
groupSum('k', 'v', 9223372036854775807)     ValueError: BatchFlow\Query::groupSum(): Argument #3 ($maxGroups) must be between 1 and 100000000
groupCount('nope', 0)                       ValueError: BatchFlow\Query::groupCount(): Argument #2 ($maxGroups) must be between 1 and 100000000
groupSum('nope', 'nope', 0)                 ValueError: BatchFlow\Query::groupSum(): Argument #3 ($maxGroups) must be between 1 and 100000000
-- the bounds themselves are accepted
maxGroups 1                                         [s"a",int(1)]
maxGroups 100000000                                 [s"a",int(1)]; [s"b",int(1)]
maxGroups 100000000, groupSum                       [s"a",int(1),int(1)]; [s"b",int(1),int(1)]
-- the key must be string or int64, checked before any I/O
groupCount('f')         SchemaException: column "f": group key must be string or int64
groupCount('b')         SchemaException: column "b": group key must be string or int64
groupCount('zz')        SchemaException: unknown column "zz"
groupCount('a"b')       SchemaException: unknown column "a\x22b"
groupCount('')          SchemaException: unknown column ""
groupCount('i')         StreamException: cannot open input
