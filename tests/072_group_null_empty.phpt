--TEST--
batchflow: NULL keys form one group at the position of their first row, distinct from the empty string; all-NULL sums; empty results
--EXTENSIONS--
batchflow
--FILE--
<?php
require __DIR__ . "/bf_helper.inc";

function g(string $label, string $csv, array $S, string $key, ?string $sum = null, array $preds = [], array $opts = []): void
{
    $term = $sum === null ? "groupCount" : "groupSum";
    $x = ["key" => $key, "sum" => $sum];
    $a = bf_gexec($csv, $S, $opts, $preds, $term, $x);
    $b = bf_gref($csv, $S, $opts, $preds, $term, $x);
    echo str_pad($label, 52), $a, $a === $b ? "" : "   [DIFFERS FROM REFERENCE: $b]", "\n";
}
$SS = ['k' => 'string', 'v' => 'int64'];
$SI = ['k' => 'int64', 'v' => 'int64'];
$SF = ['k' => 'string', 'v' => 'float64'];

echo "-- NULL is one group, in first-seen position, with key PHP null\n";
g('NULL group last', "k,v\na,1\nb,2\n,3\n,4\n", $SS, 'k');
g('NULL group first', "k,v\n,1\na,2\n,3\na,4\n", $SS, 'k');
g('NULL group in the middle', "k,v\na,1\n,2\nb,3\n,4\na,5\n", $SS, 'k');
g('int key column: NULL group', "k,v\n1,1\n,2\n1,3\n,4\n", $SI, 'k');
g('only NULL keys', "k,v\n,1\n,2\n,3\n", $SS, 'k');
g('NULL key with groupSum', "k,v\n,5\na,1\n,6\n", $SS, 'k', 'v');
g('NULL key in an int-keyed groupSum', "k,v\n,5\n2,1\n,6\n", $SI, 'k', 'v');
echo "-- NULL and the empty string are different groups\n";
g('quoted empty string vs unquoted empty', "k,v\n\"\",1\n,2\n\"\",3\n,4\n", $SS, 'k');
g('empty string first, NULL second', "k,v\n\"\",1\n,2\n", $SS, 'k');
g('NULL first, empty string second', "k,v\n,1\n\"\",2\n", $SS, 'k');
g('empty string and NULL both with sums', "k,v\n\"\",10\n,20\n\"\",30\n", $SS, 'k', 'v');
g('one-column file: blank line is a NULL row', "k\na\n\na\n", ['k' => 'string'], 'k');
g('one-column file, int key: blank line is NULL', "k\n5\n\n5\n", ['k' => 'int64'], 'k');
echo "-- all-NULL sums\n";
g('int sum, every value NULL', "k,v\na,\na,\n", $SS, 'k', 'v');
g('float sum, every value NULL', "k,v\na,\nb,\n", $SF, 'k', 'v');
g('NULL group with NULL sum', "k,v\n,\n,\n", $SS, 'k', 'v');
g('mixed: one all-NULL group, one not', "k,v\na,\nb,3\na,\n", $SS, 'k', 'v');
echo "-- no matching row gives []\n";
g('predicate matches nothing', "k,v\na,1\nb,2\n", $SS, 'k', null, [['eq', 'v', 99]]);
g('predicate matches nothing, groupSum', "k,v\na,1\nb,2\n", $SS, 'k', 'v', [['gt', 'v', 99]]);
g('header only', "k,v\n", $SS, 'k');
g('header only, groupSum', "k,v\n", $SS, 'k', 'v');
g('header only, no final newline', "k,v", $SS, 'k', 'v');
g('whereEq NULL on a column with no NULLs', "k,v\na,1\n", $SS, 'k', null, [['eq', 'v', null]]);
g('NULL never matches whereGt, so no NULL group arises', "k,v\na,\nb,\n", $SS, 'k', null, [['gt', 'v', -5]]);
echo "-- whereEq(null) selects only the NULL group\n";
g('NULL group alone', "k,v\na,1\n,2\n,3\nb,4\n", $SS, 'k', null, [['eq', 'k', null]]);
g('NULL group alone, int keys', "k,v\n1,1\n,2\n", $SI, 'k', 'v', [['eq', 'k', null]]);
echo "-- an empty result is an empty list, not null\n";
var_dump(bf_q("k,v\n", $SS)->groupCount('k'), bf_q("k,v\n", $SS)->groupSum('k', 'v'));
?>
--EXPECT--
-- NULL is one group, in first-seen position, with key PHP null
NULL group last                                     [s"a",int(1)]; [s"b",int(1)]; [NULL,int(2)]
NULL group first                                    [NULL,int(2)]; [s"a",int(2)]
NULL group in the middle                            [s"a",int(2)]; [NULL,int(2)]; [s"b",int(1)]
int key column: NULL group                          [int(1),int(2)]; [NULL,int(2)]
only NULL keys                                      [NULL,int(3)]
NULL key with groupSum                              [NULL,int(2),int(11)]; [s"a",int(1),int(1)]
NULL key in an int-keyed groupSum                   [NULL,int(2),int(11)]; [int(2),int(1),int(1)]
-- NULL and the empty string are different groups
quoted empty string vs unquoted empty               [s"",int(2)]; [NULL,int(2)]
empty string first, NULL second                     [s"",int(1)]; [NULL,int(1)]
NULL first, empty string second                     [NULL,int(1)]; [s"",int(1)]
empty string and NULL both with sums                [s"",int(2),int(40)]; [NULL,int(1),int(20)]
one-column file: blank line is a NULL row           [s"a",int(2)]; [NULL,int(1)]
one-column file, int key: blank line is NULL        [int(5),int(2)]; [NULL,int(1)]
-- all-NULL sums
int sum, every value NULL                           [s"a",int(2),int(0)]
float sum, every value NULL                         [s"a",int(1),float(0.0)]; [s"b",int(1),float(0.0)]
NULL group with NULL sum                            [NULL,int(2),int(0)]
mixed: one all-NULL group, one not                  [s"a",int(2),int(0)]; [s"b",int(1),int(3)]
-- no matching row gives []
predicate matches nothing                           []
predicate matches nothing, groupSum                 []
header only                                         []
header only, groupSum                               []
header only, no final newline                       []
whereEq NULL on a column with no NULLs              []
NULL never matches whereGt, so no NULL group arises []
-- whereEq(null) selects only the NULL group
NULL group alone                                    [NULL,int(2)]
NULL group alone, int keys                          [NULL,int(1),int(2)]
-- an empty result is an empty list, not null
array(0) {
}
array(0) {
}
