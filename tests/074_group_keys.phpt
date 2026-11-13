--TEST--
batchflow: key identity is exact bytes after quote removal for strings and numeric for int64, with PHP-typed keys
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

echo "-- quoted and unquoted forms of one key are one group\n";
g('abc and "abc"', "k,v\nabc,1\n\"abc\",2\nabc,3\n", $SS, 'k', 'v');
g('key with a comma, quoted twice', "k,v\n\"a,b\",1\n\"a,b\",2\n", $SS, 'k');
g('doubled quote inside a key is one quote', "k,v\n\"a\"\"b\",1\n\"a\"\"b\",2\n\"a\",3\n", $SS, 'k');
g('"5" and 5 in an int column are one group', "k,v\n\"5\",1\n5,2\n", $SI, 'k', 'v');
echo "-- strings are compared as bytes, never as numbers\n";
g('123 and 0123', "k,v\n123,1\n0123,2\n123,3\n", $SS, 'k');
g('1 and 1.0 and 01 and +1', "k,v\n1,1\n1.0,1\n01,1\n+1,1\n1,1\n", $SS, 'k');
g('1e3 and 1000', "k,v\n1e3,1\n1000,1\n", $SS, 'k');
g('case matters', "k,v\na,1\nA,1\na,1\n", $SS, 'k');
g('leading and trailing spaces are data', "k,v\na,1\n a,1\na ,1\na,1\n", $SS, 'k');
g('a key that is a prefix of another', "k,v\nab,1\nabc,1\nab,1\na,1\n", $SS, 'k');
g('a NUL byte is data', "k,v\n\"a\0b\",1\n\"a\0c\",1\n\"a\0b\",1\n\"a\",1\n", $SS, 'k');
g('a key with an embedded LF, CR and CRLF', "k,v\n\"a\nb\",1\n\"a\r\nb\",1\n\"a\rb\",1\n\"a\nb\",1\n", $SS, 'k');
g('NUL-terminated lookalikes', "k,v\n\"a\0\",1\n\"a\",1\n\"a\0\0\",1\n", $SS, 'k');
g('multibyte: composed vs decomposed e-acute differ', "k,v\n\xC3\xA9,1\ne\xCC\x81,1\n\xC3\xA9,1\n", $SS, 'k');
echo "-- every length from 1 to 40 bytes is its own group, twice over\n";
$rows = '';
for ($pass = 0; $pass < 2; $pass++) {
    for ($n = 1; $n <= 40; $n++) {
        $rows .= str_repeat('a', $n) . ",1\n";
    }
}
$r = bf_q("k,v\n" . $rows, $SS)->groupCount('k');
echo count($r), ' groups; ', implode(',', array_unique(array_map(fn($g) => $g[1], $r))), ' rows each; ';
echo implode(',', array_map(fn($g) => strlen($g[0]), $r)) === implode(',', range(1, 40)) ? "lengths 1..40 in order" : "WRONG LENGTHS", "\n";
echo "-- int64 keys compare numerically and are PHP ints\n";
$MAX = '9223372036854775807';
$MIN = '-9223372036854775808';
g('min and max', "k,v\n$MAX,1\n$MIN,2\n$MAX,3\n$MIN,4\n", $SI, 'k', 'v');
g('neighbours of 2^53 are distinct', "k,v\n9007199254740992,1\n9007199254740993,1\n9007199254740991,1\n9007199254740993,1\n", $SI, 'k');
g('keys differing only in the high bits', "k,v\n1,1\n4294967297,1\n1,1\n8589934593,1\n", $SI, 'k');
g('keys differing only in the low bits', "k,v\n4294967296,1\n4294967297,1\n4294967296,1\n", $SI, 'k');
g('zero and negative one', "k,v\n0,1\n-1,1\n0,1\n", $SI, 'k');
g('MAX, MAX-1, MIN, MIN+1 with sums', "k,v\n$MAX,1\n9223372036854775806,2\n$MIN,3\n-9223372036854775807,4\n", $SI, 'k', 'v');
$r = bf_q("k,v\n$MIN,1\n$MAX,1\n", $SI)->groupCount('k');
var_dump($r[0][0] === PHP_INT_MIN, $r[1][0] === PHP_INT_MAX);
echo "-- a numeric-looking string key is never converted to int\n";
$r = bf_q("k,v\n5,1\n", $SS)->groupCount('k');
var_dump($r[0][0] === '5');
?>
--EXPECT--
-- quoted and unquoted forms of one key are one group
abc and "abc"                                       [s"abc",int(3),int(6)]
key with a comma, quoted twice                      [s"a,b",int(2)]
doubled quote inside a key is one quote             [s"a\"b",int(2)]; [s"a",int(1)]
"5" and 5 in an int column are one group            [int(5),int(2),int(3)]
-- strings are compared as bytes, never as numbers
123 and 0123                                        [s"123",int(2)]; [s"0123",int(1)]
1 and 1.0 and 01 and +1                             [s"1",int(2)]; [s"1.0",int(1)]; [s"01",int(1)]; [s"+1",int(1)]
1e3 and 1000                                        [s"1e3",int(1)]; [s"1000",int(1)]
case matters                                        [s"a",int(2)]; [s"A",int(1)]
leading and trailing spaces are data                [s"a",int(2)]; [s" a",int(1)]; [s"a ",int(1)]
a key that is a prefix of another                   [s"ab",int(2)]; [s"abc",int(1)]; [s"a",int(1)]
a NUL byte is data                                  [s"a\u0000b",int(2)]; [s"a\u0000c",int(1)]; [s"a",int(1)]
a key with an embedded LF, CR and CRLF              [s"a\nb",int(2)]; [s"a\r\nb",int(1)]; [s"a\rb",int(1)]
NUL-terminated lookalikes                           [s"a\u0000",int(1)]; [s"a",int(1)]; [s"a\u0000\u0000",int(1)]
multibyte: composed vs decomposed e-acute differ    [s"é",int(2)]; [s"é",int(1)]
-- every length from 1 to 40 bytes is its own group, twice over
40 groups; 2 rows each; lengths 1..40 in order
-- int64 keys compare numerically and are PHP ints
min and max                                         [int(9223372036854775807),int(2),int(4)]; [int(-9223372036854775808),int(2),int(6)]
neighbours of 2^53 are distinct                     [int(9007199254740992),int(1)]; [int(9007199254740993),int(2)]; [int(9007199254740991),int(1)]
keys differing only in the high bits                [int(1),int(2)]; [int(4294967297),int(1)]; [int(8589934593),int(1)]
keys differing only in the low bits                 [int(4294967296),int(2)]; [int(4294967297),int(1)]
zero and negative one                               [int(0),int(2)]; [int(-1),int(1)]
MAX, MAX-1, MIN, MIN+1 with sums                    [int(9223372036854775807),int(1),int(1)]; [int(9223372036854775806),int(1),int(2)]; [int(-9223372036854775808),int(1),int(3)]; [int(-9223372036854775807),int(1),int(4)]
bool(true)
bool(true)
-- a numeric-looking string key is never converted to int
bool(true)
