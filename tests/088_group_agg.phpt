--TEST--
batchflow: groupAgg over one to four string and int64 keys against the reference: NULL and "" per position, first-seen order among matching rows, per-group results equal aggregate() over the group, single-key equalities with groupSum and groupCount, empty aggregate lists, every argument error and the check order of SPEC 5.9
--EXTENSIONS--
batchflow
--FILE--
<?php
require __DIR__ . '/bf_helper.inc';
require __DIR__ . '/bf_reference_m2b.inc';

use BatchFlow\Csv;

$S = ['id' => 'int64', 'a' => 'string', 'n' => 'int64', 'c' => 'string', 'm' => 'int64', 'v' => 'float64', 'w' => 'int64', 'b' => 'bool'];
$csv = "id,a,n,c,m,v,w,b\n"
    . "1,x,1,p,7,1.5,10,true\n"
    . "2,y,2,p,7,2.5,20,false\n"
    . "3,x,1,q,7,,30,true\n"
    . "4,,1,p,,0.5,,true\n"
    . "5,\"\",1,p,,-1,40,false\n"
    . "6,x,,p,7,3,50,true\n"
    . "7,,,,,,,\n"
    . "8,x,1,p,7,-0.0,60,false\n"
    . "9,\"\",,\"\",,4,70,true\n"
    . "10,,,,,,80,true\n"
    . "11,y,2,p,8,5,90,false\n"
    . "12,x,1,q,7,6,100,true\n";

function g(string $label, string $csv, array $S, $keys, array $aggs, array $preds = [], int $max = 1000000): string
{
    $x = ['keys' => $keys, 'aggs' => $aggs, 'maxGroups' => $max];
    $r = bf_gexec($csv, $S, [], $preds, 'groupAgg', $x);
    $w = bf_gref($csv, $S, [], $preds, 'groupAgg', $x);
    echo "$label\n  ", str_replace('; ', "\n  ", $r), $r === $w ? '' : "\n  [DIFFERS FROM REFERENCE: $w]", "\n";
    return $r;
}

$aggs = [['count', 'v'], ['sum', 'w'], ['sum', 'v'], ['min', 'v'], ['max', 'w'], ['avg', 'v'], ['count', 'b']];
echo "-- one to four keys, mixed string and int64\n";
g("keys 'a'", $csv, $S, 'a', $aggs);
g("keys ['n']", $csv, $S, ['n'], $aggs);
g("keys ['a', 'n']", $csv, $S, ['a', 'n'], $aggs);
g("keys ['n', 'a'] (order of keys is the order of the result)", $csv, $S, ['n', 'a'], [['sum', 'w']]);
g("keys ['a', 'n', 'c']", $csv, $S, ['a', 'n', 'c'], [['max', 'v'], ['count', 'w']]);
g("keys ['a', 'n', 'c', 'm']", $csv, $S, ['a', 'n', 'c', 'm'], $aggs);
g("keys ['m', 'c', 'n', 'a'] with 16 pairs", $csv, $S, ['m', 'c', 'n', 'a'], array_merge($aggs, $aggs, [['min', 'w'], ['avg', 'w']]));
echo "-- NULL and the empty string are different in every position; (NULL, x) twice is one group\n";
$NE = ['k1' => 'string', 'k2' => 'string', 'i' => 'int64'];
g("NULL vs '' in position 1 and 2", "k1,k2,i\n,a,1\n\"\",a,2\n,a,3\na,,4\na,\"\",5\na,,6\n,,7\n\"\",\"\",8\n,,9\n", $NE, ['k1', 'k2'], [['sum', 'i']]);
g("NULL vs 0 for an int key", "k1,k2,i\na,b,0\na,b,\na,b,0\n", ['k1' => 'string', 'k2' => 'string', 'i' => 'int64'], ['k1', 'i'], []);
g("keys that would collide if joined: ('ab','c') vs ('a','bc')", "k1,k2,i\nab,c,1\na,bc,2\nab,c,3\n\"a\",\"bc\",4\n", $NE, ['k1', 'k2'], [['sum', 'i']]);
g("quoted equals unquoted; 123 vs 0123", "k1,k2,i\n\"123\",x,1\n123,\"x\",2\n0123,x,3\n", $NE, ['k1', 'k2'], [['count', 'i']]);
g("int64 bounds as keys", "k1,k2,i\na,b,-9223372036854775808\na,b,9223372036854775807\na,b,-9223372036854775808\n", $NE, ['i', 'k1'], [['count', 'k2']]);
echo "-- first-seen order among matching rows only\n";
g("where b = false", $csv, $S, ['a', 'n'], [['sum', 'w']], [['eq', 'b', false]]);
g("where id >= 6: earlier occurrences of x/1 do not count", $csv, $S, ['a', 'n'], [['sum', 'w']], [['gte', 'id', 6]]);
g("where v in [4, 5, 6, null]", $csv, $S, ['c', 'a'], [['count', 'v'], ['avg', 'w']], [['in', 'v', [4, 5, 6, null]]]);
g("no matching row", $csv, $S, ['a', 'n'], $aggs, [['gt', 'id', 99]]);
g("header only", "id,a,n,c,m,v,w,b\n", $S, ['a', 'n'], $aggs);

echo "-- each group's results equal aggregate() over that group's rows\n";
$q = bf_q($csv, $S);
$rows = $q->groupAgg(['a', 'n'], $aggs);
$ok = 0;
foreach ($rows as $row) {
    $g = $q;
    foreach (['a' => $row[0], 'n' => $row[1]] as $col => $val) {
        $g = $g->whereEq($col, $val);
    }
    $want = array_merge([$row[0], $row[1], $g->count()], $g->aggregate($aggs));
    $ok += bf_fmt_list($want) === bf_fmt_list($row) ? 1 : 0;
}
echo "$ok of ", count($rows), " groups equal\n";

echo "-- one key: groupAgg equals groupCount and groupSum (SPEC 5.9)\n";
foreach (['a', 'n'] as $k) {
    foreach (['w', 'v'] as $sc) {
        $gs = bf_fmt_groups($q->groupSum($k, $sc));
        $ga = bf_fmt_lists($q->groupAgg($k, [['sum', $sc]]));
        echo "groupAgg('$k', [['sum', '$sc']]) == groupSum('$k', '$sc'): ", $gs === $ga ? 'yes' : "NO $gs | $ga", "\n";
    }
    $gc = bf_fmt_groups($q->groupCount($k));
    echo "groupAgg('$k', []) == groupCount('$k'): ", $gc === bf_fmt_lists($q->groupAgg($k, [])) ? 'yes' : 'NO', "\n";
    echo "groupAgg(['$k'], []) == groupCount('$k'): ", $gc === bf_fmt_lists($q->groupAgg([$k], [])) ? 'yes' : 'NO', "\n";
    echo "groupAgg('$k', [['count', '$k']]): ", bf_fmt_lists($q->groupAgg($k, [['count', $k]])), "\n";
}
echo "-- with predicates and maxGroups too\n";
$p = $q->whereNe('b', null)->whereLte('id', 11);
foreach ([4, 3] as $max) {
    $gc = bf_try(fn() => bf_fmt_groups($p->groupCount('a', $max)));
    $ga = bf_try(fn() => bf_fmt_lists($p->groupAgg('a', [], $max)));
    $gs = bf_try(fn() => bf_fmt_groups($p->groupSum('a', 'w', $max)));
    $gw = bf_try(fn() => bf_fmt_lists($p->groupAgg('a', [['sum', 'w']], $max)));
    echo "maxGroups $max: ", $gc === $ga ? 'equal' : "NOT EQUAL $gc | $ga", ', ', $gs === $gw ? 'equal' : "NOT EQUAL $gs | $gw", ": $ga\n";
}
echo "-- an empty aggregate list gives [key1, ..., keyN, count] on one to four keys\n";
g("['a'], []", $csv, $S, ['a'], []);
g("['a', 'n'], []", $csv, $S, ['a', 'n'], []);
g("['a', 'n', 'c'], []", $csv, $S, ['a', 'n', 'c'], []);
g("['a', 'n', 'c', 'm'], []", $csv, $S, ['a', 'n', 'c', 'm'], []);
echo "aggregate([]) still raises: ", bf_try(fn() => $q->aggregate([])), "\n";

echo "-- result shape: packed lists; types per key and per aggregate\n";
$r = $q->groupAgg(['a', 'n'], [['count', 'v'], ['sum', 'w'], ['sum', 'v'], ['avg', 'w'], ['min', 'v']]);
echo 'list: ', array_is_list($r) ? 'yes' : 'no', ', rows lists: ', count(array_filter($r, 'array_is_list')) === count($r) ? 'yes' : 'no',
    ', first row types: ', implode(' ', array_map('get_debug_type', $r[0])), "\n";

echo "-- a bad value in any referenced column raises, matched or not\n";
$bad = "id,a,n,c,m,v,w,b\n1,x,1,p,7,1.5,10,true\n2,x,zz,p,7,1.5,10,true\n";
g("bad key value", $bad, $S, ['a', 'n'], [], [['eq', 'id', 1]]);
g("bad aggregate value", "id,a,n,c,m,v,w,b\n1,x,1,p,7,1.5,10,true\n2,x,1,p,7,abc,10,true\n", $S, 'a', [['count', 'v']]);
g("bad unreferenced value", "id,a,n,c,m,v,w,b\n1,x,1,p,7,1.5,10,yes\n", $S, 'a', [['count', 'v']]);

echo "-- argument errors, before any I/O, in the order of SPEC 5.9\n";
$n = Csv::scan('/nonexistent/batchflow/none.csv', $S);
$cases = [
    // (1) $keys as an array
    "[], [['x']], 0" => [[], [['x']], 0],
    "[1 => 'a'], [], 0" => [[1 => 'a'], [], 0],
    "['k' => 'a'], [], 1" => [['k' => 'a'], [], 1],
    "5 keys" => [['a', 'n', 'c', 'm', 'id'], [], 1],
    "5 bad keys" => [[1, 2, 3, 4, 5], [], 1],
    "['a', 5]" => [['a', 5], [['x']], 0],
    "['a', null]" => [['a', null], [], 1],
    "['a', ['n']]" => [['a', ['n']], [], 1],
    "['a', 'a']" => [['a', 'a'], [], 1],
    "['a', 'n', 'a', 5]" => [['a', 'n', 'a', 5], [], 1],
    "['a', 'a', 5]" => [['a', 'a', 5], [], 1],
    "['a', 5, 'a']" => [['a', 5, 'a'], [], 1],
    "['nope', 'nope']" => [['nope', 'nope'], [], 1],
    // (2) $aggregates, argument 2; empty is valid
    "'a', [1 => ['sum', 'w']]" => ['a', [1 => ['sum', 'w']], 0],
    "'a', 17 pairs" => ['a', array_fill(0, 17, ['sum', 'w']), 0],
    "'a', [['sum']]" => ['a', [['sum']], 0],
    "'a', [['sum', 'w'], ['median', 'w']]" => ['a', [['sum', 'w'], ['median', 'w']], 0],
    "'nope', [['median', 'nope']], 0" => ['nope', [['median', 'nope']], 0],
    // (3) maxGroups, argument 3
    "'a', [], 0" => ['a', [], 0],
    "'nope', [['sum', 'nope']], 100000001" => ['nope', [['sum', 'nope']], 100000001],
    "'a', [], -1" => ['a', [], -1],
    // (4) each key column in order
    "'nope', [['sum', 'nope']]" => ['nope', [['sum', 'nope']], 1],
    "['a', 'nope', 'v']" => [['a', 'nope', 'v'], [], 1],
    "['a', 'v', 'nope']" => [['a', 'v', 'nope'], [], 1],
    "['b']" => [['b'], [], 1],
    "'v', [['sum', 'nope']]" => ['v', [['sum', 'nope']], 1],
    "''" => ['', [], 1],
    // (5) each pair's column in list order
    "'a', [['sum', 'nope']]" => ['a', [['sum', 'nope']], 1],
    "'a', [['count', 'b'], ['sum', 'c'], ['sum', 'nope']]" => ['a', [['count', 'b'], ['sum', 'c'], ['sum', 'nope']], 1],
    "'a', [['avg', 'nope'], ['min', 'a']]" => ['a', [['avg', 'nope'], ['min', 'a']], 1],
    "'a', [['count', 'a']] (valid)" => ['a', [['count', 'a']], 1],
];
foreach ($cases as $label => [$keys, $ag, $max]) {
    $r = bf_try(fn() => $n->groupAgg($keys, $ag, $max));
    $w = bf_gref($csv, $S, [], [], 'groupAgg', ['keys' => $keys, 'aggs' => $ag, 'maxGroups' => $max]);
    echo str_pad($label, 54), $r, ($r === $w || str_starts_with($r, 'StreamException')) ? '' : "   [REFERENCE: $w]", "\n";
}
echo "-- parameter types (coercive mode converts scalar arguments as PHP does)\n";
echo bf_try(fn() => $n->groupAgg(5, [])), "\n";
echo bf_try(fn() => $n->groupAgg('a', 'sum')), "\n";
echo bf_try(fn() => $n->groupAgg('a')), "\n";
echo bf_try(fn() => $n->groupAgg('a', [], '5')), "\n";
?>
--EXPECT--
-- one to four keys, mixed string and int64
keys 'a'
  [s"x",int(5),int(4),int(250),float(10.5),float(-0.0),int(100),float(2.625),int(5)]
  [s"y",int(2),int(2),int(110),float(7.5),float(2.5),int(90),float(3.75),int(2)]
  [NULL,int(3),int(1),int(80),float(0.5),float(0.5),int(80),float(0.5),int(2)]
  [s"",int(2),int(2),int(110),float(3.0),float(-1.0),int(70),float(1.5),int(2)]
keys ['n']
  [int(1),int(6),int(5),int(240),float(7.0),float(-1.0),int(100),float(1.4),int(6)]
  [int(2),int(2),int(2),int(110),float(7.5),float(2.5),int(90),float(3.75),int(2)]
  [NULL,int(4),int(2),int(200),float(7.0),float(3.0),int(80),float(3.5),int(3)]
keys ['a', 'n']
  [s"x",int(1),int(4),int(3),int(200),float(7.5),float(-0.0),int(100),float(2.5),int(4)]
  [s"y",int(2),int(2),int(2),int(110),float(7.5),float(2.5),int(90),float(3.75),int(2)]
  [NULL,int(1),int(1),int(1),int(0),float(0.5),float(0.5),NULL,float(0.5),int(1)]
  [s"",int(1),int(1),int(1),int(40),float(-1.0),float(-1.0),int(40),float(-1.0),int(1)]
  [s"x",NULL,int(1),int(1),int(50),float(3.0),float(3.0),int(50),float(3.0),int(1)]
  [NULL,NULL,int(2),int(0),int(80),float(0.0),NULL,int(80),NULL,int(1)]
  [s"",NULL,int(1),int(1),int(70),float(4.0),float(4.0),int(70),float(4.0),int(1)]
keys ['n', 'a'] (order of keys is the order of the result)
  [int(1),s"x",int(4),int(200)]
  [int(2),s"y",int(2),int(110)]
  [int(1),NULL,int(1),int(0)]
  [int(1),s"",int(1),int(40)]
  [NULL,s"x",int(1),int(50)]
  [NULL,NULL,int(2),int(80)]
  [NULL,s"",int(1),int(70)]
keys ['a', 'n', 'c']
  [s"x",int(1),s"p",int(2),float(1.5),int(2)]
  [s"y",int(2),s"p",int(2),float(5.0),int(2)]
  [s"x",int(1),s"q",int(2),float(6.0),int(2)]
  [NULL,int(1),s"p",int(1),float(0.5),int(0)]
  [s"",int(1),s"p",int(1),float(-1.0),int(1)]
  [s"x",NULL,s"p",int(1),float(3.0),int(1)]
  [NULL,NULL,NULL,int(2),NULL,int(1)]
  [s"",NULL,s"",int(1),float(4.0),int(1)]
keys ['a', 'n', 'c', 'm']
  [s"x",int(1),s"p",int(7),int(2),int(2),int(70),float(1.5),float(-0.0),int(60),float(0.75),int(2)]
  [s"y",int(2),s"p",int(7),int(1),int(1),int(20),float(2.5),float(2.5),int(20),float(2.5),int(1)]
  [s"x",int(1),s"q",int(7),int(2),int(1),int(130),float(6.0),float(6.0),int(100),float(6.0),int(2)]
  [NULL,int(1),s"p",NULL,int(1),int(1),int(0),float(0.5),float(0.5),NULL,float(0.5),int(1)]
  [s"",int(1),s"p",NULL,int(1),int(1),int(40),float(-1.0),float(-1.0),int(40),float(-1.0),int(1)]
  [s"x",NULL,s"p",int(7),int(1),int(1),int(50),float(3.0),float(3.0),int(50),float(3.0),int(1)]
  [NULL,NULL,NULL,NULL,int(2),int(0),int(80),float(0.0),NULL,int(80),NULL,int(1)]
  [s"",NULL,s"",NULL,int(1),int(1),int(70),float(4.0),float(4.0),int(70),float(4.0),int(1)]
  [s"y",int(2),s"p",int(8),int(1),int(1),int(90),float(5.0),float(5.0),int(90),float(5.0),int(1)]
keys ['m', 'c', 'n', 'a'] with 16 pairs
  [int(7),s"p",int(1),s"x",int(2),int(2),int(70),float(1.5),float(-0.0),int(60),float(0.75),int(2),int(2),int(70),float(1.5),float(-0.0),int(60),float(0.75),int(2),int(10),float(35.0)]
  [int(7),s"p",int(2),s"y",int(1),int(1),int(20),float(2.5),float(2.5),int(20),float(2.5),int(1),int(1),int(20),float(2.5),float(2.5),int(20),float(2.5),int(1),int(20),float(20.0)]
  [int(7),s"q",int(1),s"x",int(2),int(1),int(130),float(6.0),float(6.0),int(100),float(6.0),int(2),int(1),int(130),float(6.0),float(6.0),int(100),float(6.0),int(2),int(30),float(65.0)]
  [NULL,s"p",int(1),NULL,int(1),int(1),int(0),float(0.5),float(0.5),NULL,float(0.5),int(1),int(1),int(0),float(0.5),float(0.5),NULL,float(0.5),int(1),NULL,NULL]
  [NULL,s"p",int(1),s"",int(1),int(1),int(40),float(-1.0),float(-1.0),int(40),float(-1.0),int(1),int(1),int(40),float(-1.0),float(-1.0),int(40),float(-1.0),int(1),int(40),float(40.0)]
  [int(7),s"p",NULL,s"x",int(1),int(1),int(50),float(3.0),float(3.0),int(50),float(3.0),int(1),int(1),int(50),float(3.0),float(3.0),int(50),float(3.0),int(1),int(50),float(50.0)]
  [NULL,NULL,NULL,NULL,int(2),int(0),int(80),float(0.0),NULL,int(80),NULL,int(1),int(0),int(80),float(0.0),NULL,int(80),NULL,int(1),int(80),float(80.0)]
  [NULL,s"",NULL,s"",int(1),int(1),int(70),float(4.0),float(4.0),int(70),float(4.0),int(1),int(1),int(70),float(4.0),float(4.0),int(70),float(4.0),int(1),int(70),float(70.0)]
  [int(8),s"p",int(2),s"y",int(1),int(1),int(90),float(5.0),float(5.0),int(90),float(5.0),int(1),int(1),int(90),float(5.0),float(5.0),int(90),float(5.0),int(1),int(90),float(90.0)]
-- NULL and the empty string are different in every position; (NULL, x) twice is one group
NULL vs '' in position 1 and 2
  [NULL,s"a",int(2),int(4)]
  [s"",s"a",int(1),int(2)]
  [s"a",NULL,int(2),int(10)]
  [s"a",s"",int(1),int(5)]
  [NULL,NULL,int(2),int(16)]
  [s"",s"",int(1),int(8)]
NULL vs 0 for an int key
  [s"a",int(0),int(2)]
  [s"a",NULL,int(1)]
keys that would collide if joined: ('ab','c') vs ('a','bc')
  [s"ab",s"c",int(2),int(4)]
  [s"a",s"bc",int(2),int(6)]
quoted equals unquoted; 123 vs 0123
  [s"123",s"x",int(2),int(2)]
  [s"0123",s"x",int(1),int(1)]
int64 bounds as keys
  [int(-9223372036854775808),s"a",int(2),int(2)]
  [int(9223372036854775807),s"a",int(1),int(1)]
-- first-seen order among matching rows only
where b = false
  [s"y",int(2),int(2),int(110)]
  [s"",int(1),int(1),int(40)]
  [s"x",int(1),int(1),int(60)]
where id >= 6: earlier occurrences of x/1 do not count
  [s"x",NULL,int(1),int(50)]
  [NULL,NULL,int(2),int(80)]
  [s"x",int(1),int(2),int(160)]
  [s"",NULL,int(1),int(70)]
  [s"y",int(2),int(1),int(90)]
where v in [4, 5, 6, null]
  [s"q",s"x",int(2),int(1),float(65.0)]
  [NULL,NULL,int(2),int(0),float(80.0)]
  [s"",s"",int(1),int(1),float(70.0)]
  [s"p",s"y",int(1),int(1),float(90.0)]
no matching row
  []
header only
  []
-- each group's results equal aggregate() over that group's rows
7 of 7 groups equal
-- one key: groupAgg equals groupCount and groupSum (SPEC 5.9)
groupAgg('a', [['sum', 'w']]) == groupSum('a', 'w'): yes
groupAgg('a', [['sum', 'v']]) == groupSum('a', 'v'): yes
groupAgg('a', []) == groupCount('a'): yes
groupAgg(['a'], []) == groupCount('a'): yes
groupAgg('a', [['count', 'a']]): [s"x",int(5),int(5)]; [s"y",int(2),int(2)]; [NULL,int(3),int(0)]; [s"",int(2),int(2)]
groupAgg('n', [['sum', 'w']]) == groupSum('n', 'w'): yes
groupAgg('n', [['sum', 'v']]) == groupSum('n', 'v'): yes
groupAgg('n', []) == groupCount('n'): yes
groupAgg(['n'], []) == groupCount('n'): yes
groupAgg('n', [['count', 'n']]): [int(1),int(6),int(6)]; [int(2),int(2),int(2)]; [NULL,int(4),int(0)]
-- with predicates and maxGroups too
maxGroups 4: equal, equal: [s"x",int(4)]; [s"y",int(2)]; [NULL,int(2)]; [s"",int(2)]
maxGroups 3: equal, equal: ResultTooLargeException: row 5 (line 6, offset 99): result exceeds maxGroups (3)
-- an empty aggregate list gives [key1, ..., keyN, count] on one to four keys
['a'], []
  [s"x",int(5)]
  [s"y",int(2)]
  [NULL,int(3)]
  [s"",int(2)]
['a', 'n'], []
  [s"x",int(1),int(4)]
  [s"y",int(2),int(2)]
  [NULL,int(1),int(1)]
  [s"",int(1),int(1)]
  [s"x",NULL,int(1)]
  [NULL,NULL,int(2)]
  [s"",NULL,int(1)]
['a', 'n', 'c'], []
  [s"x",int(1),s"p",int(2)]
  [s"y",int(2),s"p",int(2)]
  [s"x",int(1),s"q",int(2)]
  [NULL,int(1),s"p",int(1)]
  [s"",int(1),s"p",int(1)]
  [s"x",NULL,s"p",int(1)]
  [NULL,NULL,NULL,int(2)]
  [s"",NULL,s"",int(1)]
['a', 'n', 'c', 'm'], []
  [s"x",int(1),s"p",int(7),int(2)]
  [s"y",int(2),s"p",int(7),int(1)]
  [s"x",int(1),s"q",int(7),int(2)]
  [NULL,int(1),s"p",NULL,int(1)]
  [s"",int(1),s"p",NULL,int(1)]
  [s"x",NULL,s"p",int(7),int(1)]
  [NULL,NULL,NULL,NULL,int(2)]
  [s"",NULL,s"",NULL,int(1)]
  [s"y",int(2),s"p",int(8),int(1)]
aggregate([]) still raises: ValueError: BatchFlow\Query::aggregate(): Argument #1 ($aggregates) must not be empty
-- result shape: packed lists; types per key and per aggregate
list: yes, rows lists: yes, first row types: string int int int int float float float
-- a bad value in any referenced column raises, matched or not
bad key value
  CsvException: row 2 (line 3, offset 39): column "n": expected int64, got malformed value
bad aggregate value
  CsvException: row 2 (line 3, offset 39): column "v": expected float64, got malformed value
bad unreferenced value
  [s"x",int(1),int(1)]
-- argument errors, before any I/O, in the order of SPEC 5.9
[], [['x']], 0                                        ValueError: BatchFlow\Query::groupAgg(): Argument #1 ($keys) must not be empty
[1 => 'a'], [], 0                                     ValueError: BatchFlow\Query::groupAgg(): Argument #1 ($keys) must be a list
['k' => 'a'], [], 1                                   ValueError: BatchFlow\Query::groupAgg(): Argument #1 ($keys) must be a list
5 keys                                                ValueError: BatchFlow\Query::groupAgg(): Argument #1 ($keys) must contain at most 4 key columns
5 bad keys                                            ValueError: BatchFlow\Query::groupAgg(): Argument #1 ($keys) must contain at most 4 key columns
['a', 5]                                              ValueError: BatchFlow\Query::groupAgg(): Argument #1 ($keys) element 1 must be a string
['a', null]                                           ValueError: BatchFlow\Query::groupAgg(): Argument #1 ($keys) element 1 must be a string
['a', ['n']]                                          ValueError: BatchFlow\Query::groupAgg(): Argument #1 ($keys) element 1 must be a string
['a', 'a']                                            ValueError: BatchFlow\Query::groupAgg(): Argument #1 ($keys) must not contain a column twice
['a', 'n', 'a', 5]                                    ValueError: BatchFlow\Query::groupAgg(): Argument #1 ($keys) must not contain a column twice
['a', 'a', 5]                                         ValueError: BatchFlow\Query::groupAgg(): Argument #1 ($keys) must not contain a column twice
['a', 5, 'a']                                         ValueError: BatchFlow\Query::groupAgg(): Argument #1 ($keys) element 1 must be a string
['nope', 'nope']                                      ValueError: BatchFlow\Query::groupAgg(): Argument #1 ($keys) must not contain a column twice
'a', [1 => ['sum', 'w']]                              ValueError: BatchFlow\Query::groupAgg(): Argument #2 ($aggregates) must be a list
'a', 17 pairs                                         ValueError: BatchFlow\Query::groupAgg(): Argument #2 ($aggregates) must contain at most 16 aggregates
'a', [['sum']]                                        ValueError: BatchFlow\Query::groupAgg(): Argument #2 ($aggregates) element 0 must be a list of two strings
'a', [['sum', 'w'], ['median', 'w']]                  ValueError: BatchFlow\Query::groupAgg(): Argument #2 ($aggregates) element 1: aggregate must be one of count, sum, min, max, avg
'nope', [['median', 'nope']], 0                       ValueError: BatchFlow\Query::groupAgg(): Argument #2 ($aggregates) element 0: aggregate must be one of count, sum, min, max, avg
'a', [], 0                                            ValueError: BatchFlow\Query::groupAgg(): Argument #3 ($maxGroups) must be between 1 and 100000000
'nope', [['sum', 'nope']], 100000001                  ValueError: BatchFlow\Query::groupAgg(): Argument #3 ($maxGroups) must be between 1 and 100000000
'a', [], -1                                           ValueError: BatchFlow\Query::groupAgg(): Argument #3 ($maxGroups) must be between 1 and 100000000
'nope', [['sum', 'nope']]                             SchemaException: unknown column "nope"
['a', 'nope', 'v']                                    SchemaException: unknown column "nope"
['a', 'v', 'nope']                                    SchemaException: column "v": group key must be string or int64
['b']                                                 SchemaException: column "b": group key must be string or int64
'v', [['sum', 'nope']]                                SchemaException: column "v": group key must be string or int64
''                                                    SchemaException: unknown column ""
'a', [['sum', 'nope']]                                SchemaException: unknown column "nope"
'a', [['count', 'b'], ['sum', 'c'], ['sum', 'nope']]  SchemaException: column "c": sum requires a numeric column
'a', [['avg', 'nope'], ['min', 'a']]                  SchemaException: unknown column "nope"
'a', [['count', 'a']] (valid)                         StreamException: cannot open input
-- parameter types (coercive mode converts scalar arguments as PHP does)
SchemaException: unknown column "5"
TypeError: BatchFlow\Query::groupAgg(): Argument #2 ($aggregates) must be of type array, string given
ArgumentCountError: BatchFlow\Query::groupAgg() expects at least 2 arguments, 1 given
StreamException: cannot open input
