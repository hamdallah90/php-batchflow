--TEST--
batchflow: toArray returns a list of associative rows in input order, schema key order, exact PHP types, extra columns absent
--EXTENSIONS--
batchflow
--FILE--
<?php
require __DIR__ . "/bf_helper.inc";

use BatchFlow\Csv;

function t(string $label, string $csv, array $S, array $preds = [], array $opts = [], int $maxRows = 10000): void
{
    $x = ["maxRows" => $maxRows];
    $a = bf_gexec($csv, $S, $opts, $preds, "toArray", $x);
    $b = bf_gref($csv, $S, $opts, $preds, "toArray", $x);
    echo "-- $label\n", $a, $a === $b ? "" : "\n   [DIFFERS FROM REFERENCE: $b]", "\n";
}

$ALL = ['i' => 'int64', 'f' => 'float64', 'b' => 'bool', 's' => 'string'];
t('one row of every type', "i,f,b,s\n42,1.5,true,hello\n", $ALL);
t('several rows in input order', "i,f,b,s\n3,0.5,false,c\n1,2.5,true,a\n2,-1.25,false,b\n3,0.5,false,c\n", $ALL);
t('NULL for every type', "i,f,b,s\n,,,\n", $ALL);
t('quoted empty string is "", not NULL', "i,f,b,s\n1,1.0,true,\"\"\n2,2.0,false,\n", $ALL);
t('keys follow the schema, not the file order', "s,b,f,i\nx,true,0.25,7\n", $ALL);
t('int boundaries', "i\n9223372036854775807\n-9223372036854775808\n0\n-1\n", ['i' => 'int64']);
t('floats: integral, exponent, signed zero, underflow', "f\n1.0\n1e2\n-0.0\n.5\n5.\n+2.5\n1e-400\n-1e-400\n12345678901234567890\n", ['f' => 'float64']);
t('float rounding: 0.1 and 9007199254740993', "f\n0.1\n9007199254740993\n0.30000000000000004\n", ['f' => 'float64']);
t('quoted numbers and bools are typed', "i,f,b\n\"5\",\"1.5\",\"true\"\n", ['i' => 'int64', 'f' => 'float64', 'b' => 'bool']);
t('numeric-looking strings stay strings', "s\n007\n1e3\n12\n-0\n 5\n", ['s' => 'string']);
t('strings: multibyte, comma, doubled quote, LF, CRLF, CR, NUL', "s\né\n日本語\n\"a,b\"\n\"say \"\"hi\"\"\"\n\"l1\nl2\"\n\"c1\r\nc2\"\n\"c1\rc2\"\n\"n\0n\"\n😀\n", ['s' => 'string']);
t('a string with leading and trailing spaces is not trimmed', "s\n a \n", ['s' => 'string']);
t('extra columns are absent, repeated, empty-named, empty and malformed', "x,i,x,,s\nbad\xFF,1,\"q\",z,a\n,2,,\"\"\"\",b\n", ['i' => 'int64', 's' => 'string'], [], ['allowExtraColumns' => true]);
t('CRLF file, no final terminator', "i,s\r\n1,a\r\n2,b", ['i' => 'int64', 's' => 'string']);
t('mixed LF and CRLF', "i\n1\r\n2\n3\r\n", ['i' => 'int64']);
t('UTF-8 BOM before the header', "\xEF\xBB\xBFi,s\n1,a\n", ['i' => 'int64', 's' => 'string']);
t('one-column file: blank line is a row of NULL', "s\na\n\nb\n", ['s' => 'string']);
echo "-- empty results\n";
t('header only', "i,s\n", ['i' => 'int64', 's' => 'string']);
t('header only, no final newline', "i,s", ['i' => 'int64', 's' => 'string']);
t('no row matches', "i,s\n1,a\n2,b\n", ['i' => 'int64', 's' => 'string'], [['gt', 'i', 5]]);
echo "-- predicates pick rows, in input order\n";
t('whereEq and whereGt together', "i,s\n1,a\n5,b\n9,a\n7,a\n", ['i' => 'int64', 's' => 'string'], [['eq', 's', 'a'], ['gt', 'i', 3]]);
t('whereEq null', "i,s\n1,\n2,x\n3,\n", ['i' => 'int64', 's' => 'string'], [['eq', 's', null]]);

echo "-- the result shape\n";
$r = bf_q("i,s\n1,a\n2,b\n", ['i' => 'int64', 's' => 'string'])->toArray();
var_dump(array_is_list($r), array_keys($r[0]) === ['i', 's'], $r[0]['i'] === 1, $r[1]['s'] === 'b');
echo "-- rows are independent arrays\n";
$r = bf_q("i,s\n1,a\n2,a\n3,a\n", ['i' => 'int64', 's' => 'string'])->toArray();
$r[0]['i'] = 99;
$r[0]['s'] .= 'zzz';
$r[0]['extra'] = true;
echo json_encode($r), "\n";
echo "-- a row array is a normal array that can be extended and copied\n";
$r = bf_q("i,s\n1,a\n", ['i' => 'int64', 's' => 'string'])->toArray();
$row = $r[0];
$row[] = 'n';
var_dump($row);
echo "-- terminals reopen the source: no cache\n";
$p = bf_file("i\n1\n");
$q = Csv::scan($p, ['i' => 'int64']);
echo count($q->toArray()), ' ';
file_put_contents($p, "i\n1\n2\n3\n");
echo count($q->toArray()), "\n";
echo "-- a stream source\n";
echo bf_fmt_rows(Csv::scanStream(bf_mem("i,s\n1,a\n2,b\n"), ['i' => 'int64', 's' => 'string'])->toArray()), "\n";
echo "-- default maxRows is 10000 and toArray works after other terminals on the same Query\n";
$q = bf_q("i\n1\n2\n", ['i' => 'int64']);
echo $q->count(), ' ', $q->sum('i'), ' ', count($q->toArray()), ' ', count($q->toArray()), "\n";
?>
--EXPECT--
-- one row of every type
{i=int(42), f=float(1.5), b=bool(true), s=s"hello"}
-- several rows in input order
{i=int(3), f=float(0.5), b=bool(false), s=s"c"}
{i=int(1), f=float(2.5), b=bool(true), s=s"a"}
{i=int(2), f=float(-1.25), b=bool(false), s=s"b"}
{i=int(3), f=float(0.5), b=bool(false), s=s"c"}
-- NULL for every type
{i=NULL, f=NULL, b=NULL, s=NULL}
-- quoted empty string is "", not NULL
{i=int(1), f=float(1.0), b=bool(true), s=s""}
{i=int(2), f=float(2.0), b=bool(false), s=NULL}
-- keys follow the schema, not the file order
{i=int(7), f=float(0.25), b=bool(true), s=s"x"}
-- int boundaries
{i=int(9223372036854775807)}
{i=int(-9223372036854775808)}
{i=int(0)}
{i=int(-1)}
-- floats: integral, exponent, signed zero, underflow
{f=float(1.0)}
{f=float(100.0)}
{f=float(-0.0)}
{f=float(0.5)}
{f=float(5.0)}
{f=float(2.5)}
{f=float(0.0)}
{f=float(-0.0)}
{f=float(1.2345678901234567E+19)}
-- float rounding: 0.1 and 9007199254740993
{f=float(0.1)}
{f=float(9007199254740992.0)}
{f=float(0.30000000000000004)}
-- quoted numbers and bools are typed
{i=int(5), f=float(1.5), b=bool(true)}
-- numeric-looking strings stay strings
{s=s"007"}
{s=s"1e3"}
{s=s"12"}
{s=s"-0"}
{s=s" 5"}
-- strings: multibyte, comma, doubled quote, LF, CRLF, CR, NUL
{s=s"é"}
{s=s"日本語"}
{s=s"a,b"}
{s=s"say \"hi\""}
{s=s"l1\nl2"}
{s=s"c1\r\nc2"}
{s=s"c1\rc2"}
{s=s"n\u0000n"}
{s=s"😀"}
-- a string with leading and trailing spaces is not trimmed
{s=s" a "}
-- extra columns are absent, repeated, empty-named, empty and malformed
{i=int(1), s=s"a"}
{i=int(2), s=s"b"}
-- CRLF file, no final terminator
{i=int(1), s=s"a"}
{i=int(2), s=s"b"}
-- mixed LF and CRLF
{i=int(1)}
{i=int(2)}
{i=int(3)}
-- UTF-8 BOM before the header
{i=int(1), s=s"a"}
-- one-column file: blank line is a row of NULL
{s=s"a"}
{s=NULL}
{s=s"b"}
-- empty results
-- header only
[]
-- header only, no final newline
[]
-- no row matches
[]
-- predicates pick rows, in input order
-- whereEq and whereGt together
{i=int(9), s=s"a"}
{i=int(7), s=s"a"}
-- whereEq null
{i=int(1), s=NULL}
{i=int(3), s=NULL}
-- the result shape
bool(true)
bool(true)
bool(true)
bool(true)
-- rows are independent arrays
[{"i":99,"s":"azzz","extra":true},{"i":2,"s":"a"},{"i":3,"s":"a"}]
-- a row array is a normal array that can be extended and copied
array(3) {
  ["i"]=>
  int(1)
  ["s"]=>
  string(1) "a"
  [0]=>
  string(1) "n"
}
-- terminals reopen the source: no cache
1 3
-- a stream source
{i=int(1), s=s"a"}
{i=int(2), s=s"b"}
-- default maxRows is 10000 and toArray works after other terminals on the same Query
2 3 2 2
