--TEST--
batchflow: groups come out in first-seen order among rows that passed the predicates, whatever the key type or the simd choice
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

echo "-- a key whose earlier occurrences were filtered out is placed by its first matching row\n";
g('x seen first but filtered, then y, then x', "k,v\nx,0\ny,1\nx,1\nz,1\n", $SS, 'k', null, [['gt', 'v', 0]]);
g('same with groupSum', "k,v\nx,0\ny,1\nx,5\nz,2\n", $SS, 'k', 'v', [['gt', 'v', 0]]);
g('int keys, earlier filtered occurrences', "k,v\n9,0\n3,1\n9,1\n1,1\n", $SI, 'k', null, [['gt', 'v', 0]]);
g('a key present only in filtered rows is absent', "k,v\nq,0\na,1\nq,0\nb,1\n", $SS, 'k', null, [['gt', 'v', 0]]);
g('predicate on the key column itself', "k,v\nb,1\na,1\nb,2\nc,1\n", $SS, 'k', null, [['eq', 'k', 'b']]);
echo "-- order is first-seen, not sorted\n";
g('descending input', "k,v\nz,1\ny,1\nx,1\nz,1\n", $SS, 'k');
g('descending ints', "k,v\n30,1\n20,1\n10,1\n20,1\n", $SI, 'k');
g('negative ints', "k,v\n0,1\n-1,1\n1,1\n-2,1\n", $SI, 'k');
g('empty string and NULL ordering by first sight', "k,v\nb,1\n,1\n\"\",1\na,1\n", $SS, 'k');
g('numeric strings are not sorted numerically', "k,v\n10,1\n9,1\n100,1\n", $SS, 'k');
echo "-- groups within a quoted/CRLF file keep input order\n";
g('CRLF', "k,v\r\nb,1\r\na,1\r\nb,1\r\n", $SS, 'k');
g('quoted keys', "k,v\n\"b\",1\n\"a\",1\n\"b\",1\n", $SS, 'k');
echo "-- the order does not depend on the simd choice\n";
$csv = "k,v\n";
foreach (['m', 'c', 'q', 'a', 'z', 'b', 'y', 'c', 'm', 'x'] as $i => $k) {
    $csv .= "$k,$i\n";
}
$ref = bf_gref($csv, $SS, [], [], 'groupCount', ['key' => 'k']);
echo $ref, "\n";
$bad = [];
foreach (array_merge(['auto'], bf_simd_available()) as $simd) {
    if (bf_gexec($csv, $SS, ['simd' => $simd], [], 'groupCount', ['key' => 'k']) !== $ref) {
        $bad[] = $simd;
    }
}
echo 'ISAs that differ from first-seen order: ', $bad ? implode(',', $bad) : 'none', "\n";
?>
--EXPECT--
-- a key whose earlier occurrences were filtered out is placed by its first matching row
x seen first but filtered, then y, then x           [s"y",int(1)]; [s"x",int(1)]; [s"z",int(1)]
same with groupSum                                  [s"y",int(1),int(1)]; [s"x",int(1),int(5)]; [s"z",int(1),int(2)]
int keys, earlier filtered occurrences              [int(3),int(1)]; [int(9),int(1)]; [int(1),int(1)]
a key present only in filtered rows is absent       [s"a",int(1)]; [s"b",int(1)]
predicate on the key column itself                  [s"b",int(2)]
-- order is first-seen, not sorted
descending input                                    [s"z",int(2)]; [s"y",int(1)]; [s"x",int(1)]
descending ints                                     [int(30),int(1)]; [int(20),int(2)]; [int(10),int(1)]
negative ints                                       [int(0),int(1)]; [int(-1),int(1)]; [int(1),int(1)]; [int(-2),int(1)]
empty string and NULL ordering by first sight       [s"b",int(1)]; [NULL,int(1)]; [s"",int(1)]; [s"a",int(1)]
numeric strings are not sorted numerically          [s"10",int(1)]; [s"9",int(1)]; [s"100",int(1)]
-- groups within a quoted/CRLF file keep input order
CRLF                                                [s"b",int(2)]; [s"a",int(1)]
quoted keys                                         [s"b",int(2)]; [s"a",int(1)]
-- the order does not depend on the simd choice
[s"m",int(2)]; [s"c",int(2)]; [s"q",int(1)]; [s"a",int(1)]; [s"z",int(1)]; [s"b",int(1)]; [s"y",int(1)]; [s"x",int(1)]
ISAs that differ from first-seen order: none
