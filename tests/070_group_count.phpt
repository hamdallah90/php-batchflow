--TEST--
batchflow: groupCount on string and int64 keys gives packed [key, count] lists, equal to the reference, with PHP-typed keys
--EXTENSIONS--
batchflow
--FILE--
<?php
require __DIR__ . '/bf_helper.inc';

use BatchFlow\Csv;

function g(string $label, string $csv, array $S, string $key, array $preds = [], array $opts = [], int $maxGroups = 1000000): void
{
    $x = ['key' => $key, 'maxGroups' => $maxGroups];
    $a = bf_gexec($csv, $S, $opts, $preds, 'groupCount', $x);
    $b = bf_gref($csv, $S, $opts, $preds, 'groupCount', $x);
    echo str_pad($label, 48), $a, $a === $b ? '' : "   [DIFFERS FROM REFERENCE: $b]", "\n";
}
$S = ['k' => 'string', 'v' => 'int64'];
$I = ['k' => 'int64', 'v' => 'int64'];

echo "-- string keys\n";
g('three groups', "k,v\na,1\nb,2\na,3\nc,4\nb,5\na,6\n", $S, 'k');
g('single group', "k,v\nx,1\nx,2\nx,3\n", $S, 'k');
g('every row its own group', "k,v\nc,1\nb,2\na,3\n", $S, 'k');
g('key column is not first in the file', "v,k\n1,x\n2,y\n3,x\n", $S, 'k');
g('other columns NULL: count is rows, not values', "k,v\na,\na,\na,1\n", $S, 'k');
g('numeric-looking keys stay strings', "k,v\n123,1\n123,2\n7,3\n", $S, 'k');
g('CRLF and no final terminator', "k,v\r\na,1\r\nb,2\r\na,3", $S, 'k');
g('multibyte keys', "k,v\né,1\n日本,2\né,3\n😀,4\n", $S, 'k');
echo "-- int keys\n";
g('int keys', "k,v\n5,1\n-3,2\n5,3\n0,4\n-3,5\n", $I, 'k');
g('int key column second', "v,k\n1,10\n2,20\n3,10\n", $I, 'k');
g('all the same int key', "k,v\n7,1\n7,2\n", $I, 'k');
echo "-- predicates restrict the rows that are counted\n";
g('whereEq on another column', "k,v\na,1\nb,1\na,2\nb,1\n", $S, 'k', [['eq', 'v', 1]]);
g('whereGt', "k,v\na,1\nb,5\na,9\nb,2\n", $S, 'k', [['gt', 'v', 4]]);
g('whereEq on the key column itself', "k,v\na,1\nb,5\na,9\nb,2\n", $S, 'k', [['eq', 'k', 'a']]);
g('two predicates', "k,v\na,1\nb,5\na,9\nb,2\na,5\n", $S, 'k', [['gt', 'v', 1], ['eq', 'k', 'a']]);
echo "-- keys seen again after the table grew: 5,000 keys twice (slots rehashed from 1024 to 8192)\n";
foreach (['string' => $S, 'int64' => $I] as $type => $schema) {
    $csv = "k,v\n";
    for ($pass = 0; $pass < 2; $pass++) {
        for ($i = 0; $i < 5000; $i++) {
            $csv .= ($type === 'string' ? "key$i" : (string)($i * 7919 - 20000000)) . ",$pass\n";
        }
    }
    $r = bf_q($csv, $schema)->groupCount('k');
    $twice = count(array_filter($r, fn($g) => $g[1] === 2));
    $same = bf_gexec($csv, $schema, [], [], 'groupCount', ['key' => 'k']) === bf_gref($csv, $schema, [], [], 'groupCount', ['key' => 'k']);
    echo str_pad($type, 8), count($r), ' groups, ', $twice, ' counted twice, equal to the reference: ', $same ? 'yes' : 'NO', "\n";
}
echo "-- the result shape\n";
$r = bf_q("k,v\na,1\nb,2\na,3\n", $S)->groupCount('k');
var_dump(array_is_list($r), count($r), array_is_list($r[0]), count($r[0]), $r[0][0] === 'a', $r[0][1] === 2);
$r = bf_q("k,v\n12,1\n", $I)->groupCount('k');
var_dump($r[0][0] === 12, is_int($r[0][1]));
$r = bf_q("k,v\n123,1\n", $S)->groupCount('k');
var_dump($r[0][0] === '123');
echo "-- maxGroups may be named and is optional\n";
echo bf_fmt_groups(bf_q("k,v\na,1\nb,1\n", $S)->groupCount('k', maxGroups: 2)), "\n";
echo "-- terminals reopen the source: no cache\n";
$p = bf_file("k,v\na,1\n");
$q = Csv::scan($p, $S);
echo bf_fmt_groups($q->groupCount('k')), "\n";
file_put_contents($p, "k,v\nb,1\nb,2\n");
echo bf_fmt_groups($q->groupCount('k')), "\n";
echo "-- a stream source\n";
$f = bf_mem("k,v\na,1\nb,2\na,3\n");
echo bf_fmt_groups(Csv::scanStream($f, $S)->groupCount('k')), "\n";
echo "-- where* leaves the receiver unchanged\n";
$q = bf_q("k,v\na,1\nb,2\n", $S);
$q->whereEq('k', 'a');
echo bf_fmt_groups($q->groupCount('k')), "\n";
?>
--EXPECT--
-- string keys
three groups                                    [s"a",int(3)]; [s"b",int(2)]; [s"c",int(1)]
single group                                    [s"x",int(3)]
every row its own group                         [s"c",int(1)]; [s"b",int(1)]; [s"a",int(1)]
key column is not first in the file             [s"x",int(2)]; [s"y",int(1)]
other columns NULL: count is rows, not values   [s"a",int(3)]
numeric-looking keys stay strings               [s"123",int(2)]; [s"7",int(1)]
CRLF and no final terminator                    [s"a",int(2)]; [s"b",int(1)]
multibyte keys                                  [s"é",int(2)]; [s"日本",int(1)]; [s"😀",int(1)]
-- int keys
int keys                                        [int(5),int(2)]; [int(-3),int(2)]; [int(0),int(1)]
int key column second                           [int(10),int(2)]; [int(20),int(1)]
all the same int key                            [int(7),int(2)]
-- predicates restrict the rows that are counted
whereEq on another column                       [s"a",int(1)]; [s"b",int(2)]
whereGt                                         [s"b",int(1)]; [s"a",int(1)]
whereEq on the key column itself                [s"a",int(2)]
two predicates                                  [s"a",int(2)]
-- keys seen again after the table grew: 5,000 keys twice (slots rehashed from 1024 to 8192)
string  5000 groups, 5000 counted twice, equal to the reference: yes
int64   5000 groups, 5000 counted twice, equal to the reference: yes
-- the result shape
bool(true)
int(2)
bool(true)
int(2)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
-- maxGroups may be named and is optional
[s"a",int(1)]; [s"b",int(1)]
-- terminals reopen the source: no cache
[s"a",int(1)]
[s"b",int(2)]
-- a stream source
[s"a",int(2)]; [s"b",int(1)]
-- where* leaves the receiver unchanged
[s"a",int(1)]; [s"b",int(1)]
