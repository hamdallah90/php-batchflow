--TEST--
batchflow: toArray maxRows exactly at and past the limit, no partial result, ValueError range and text, later rows are not read
--EXTENSIONS--
batchflow
--FILE--
<?php
require __DIR__ . '/bf_helper.inc';

use BatchFlow\Csv;

function t(string $label, string $csv, array $S, array $preds = [], array $opts = [], int $maxRows = 10000): void
{
    $x = ['maxRows' => $maxRows];
    $a = bf_gexec($csv, $S, $opts, $preds, 'toArray', $x);
    $b = bf_gref($csv, $S, $opts, $preds, 'toArray', $x);
    echo str_pad($label, 56), str_replace("\n", ' | ', $a), $a === $b ? '' : "   [DIFFERS FROM REFERENCE: $b]", "\n";
}
$S = ['i' => 'int64', 's' => 'string'];

echo "-- exactly at the limit succeeds\n";
t('3 rows, maxRows 3', "i,s\n1,a\n2,b\n3,c\n", $S, [], [], 3);
t('1 row, maxRows 1', "i,s\n1,a\n", $S, [], [], 1);
t('no rows, maxRows 1', "i,s\n", $S, [], [], 1);
echo "-- one past the limit raises at the row that would be matching row maxRows+1\n";
t('4 rows, maxRows 3', "i,s\n1,a\n2,b\n3,c\n4,d\n", $S, [], [], 3);
t('2 rows, maxRows 1', "i,s\n1,a\n2,b\n", $S, [], [], 1);
t('CRLF', "i,s\r\n1,a\r\n2,b\r\n", $S, [], [], 1);
t('LFs inside quoted fields count towards the line', "i,s\n1,\"a\nb\"\n2,\"c\nd\"\n3,e\n", $S, [], [], 2);
t('no final terminator', "i,s\n1,a\n2,b", $S, [], [], 1);
echo "-- only matching rows count\n";
t('non-matching rows do not count', "i,s\n1,a\n9,x\n2,b\n9,y\n3,c\n", $S, [['gt', 'i', 0], ['eq', 's', 'a']], [], 1);
t('the 3rd matching row is row 5', "i,s\n1,a\n0,x\n2,b\n0,y\n3,c\n", $S, [['gt', 'i', 0]], [], 2);
t('maxRows 2, exactly 2 matches among 5 rows', "i,s\n1,a\n0,x\n2,b\n0,y\n0,z\n", $S, [['gt', 'i', 0]], [], 2);
echo "-- the run stops: nothing after that row is read or validated\n";
t('later unterminated quote', "i,s\n1,a\n2,b\n\"never closed", $S, [], [], 1);
t('later bad value', "i,s\n1,a\n2,b\nx,c\n", $S, [], [], 1);
t('later wrong field count', "i,s\n1,a\n2,b\n3\n", $S, [], [], 1);
echo "-- an error before the limit row comes first\n";
t('bad value in row 2, limit would trip at row 3', "i,s\n1,a\nx,b\n3,c\n", $S, [], [], 1);
t('a bad value in a non-matching row before the limit row', "i,s\n1,a\nx,b\n3,c\n", $S, [['gt', 'i', 0]], [], 1);
echo "-- the default maxRows is 10000\n";
$csv = "i\n" . implode("\n", range(1, 10000)) . "\n";
echo count(bf_q($csv, ['i' => 'int64'])->toArray()), "\n";
$csv .= "10001\n";
echo bf_try(fn() => bf_q($csv, ['i' => 'int64'])->toArray()), "\n";
echo "-- no partial result\n";
$r = 'unset';
try {
    $r = bf_q("i\n1\n2\n3\n", ['i' => 'int64'])->toArray(2);
} catch (BatchFlow\ResultTooLargeException $e) {
    echo get_class($e), ' is a ', get_parent_class($e), "\n";
}
var_dump($r);
echo "-- maxRows range is a ValueError raised before any I/O\n";
$q = Csv::scan('/nonexistent/batchflow/none.csv', $S);
foreach ([0, -1, 100000001, PHP_INT_MIN, PHP_INT_MAX] as $n) {
    echo str_pad("toArray($n)", 32), bf_try(fn() => $q->toArray($n)), "\n";
}
echo "-- the bounds themselves are accepted\n";
t('maxRows 1', "i,s\n1,a\n", $S, [], [], 1);
t('maxRows 100000000', "i,s\n1,a\n2,b\n", $S, [], [], 100000000);
echo "-- named argument\n";
echo count(bf_q("i\n1\n2\n", ['i' => 'int64'])->toArray(maxRows: 2)), "\n";
echo "-- rows after the limit are not read: a 4 MiB tail is never pulled from the stream\n";
$path = bf_wrap_path(['segments' => [["i,s\n1,a\n2,b\n3,c\n", 1], ["9,z\n", 1000000]], 'max' => 65536]);
echo bf_try(fn() => Csv::scan($path, $S)->toArray(2)), "\n";
$served = BfWrap::$last->served;
echo 'served at most 1 MiB of a 4 MB input: ', $served <= 1048576 ? 'yes' : "NO ($served)", "\n";
echo 'stream closed after the refusal: ', BfWrap::$closes, "\n";
?>
--EXPECT--
-- exactly at the limit succeeds
3 rows, maxRows 3                                       {i=int(1), s=s"a"} | {i=int(2), s=s"b"} | {i=int(3), s=s"c"}
1 row, maxRows 1                                        {i=int(1), s=s"a"}
no rows, maxRows 1                                      []
-- one past the limit raises at the row that would be matching row maxRows+1
4 rows, maxRows 3                                       ResultTooLargeException: row 4 (line 5, offset 16): result exceeds maxRows (3)
2 rows, maxRows 1                                       ResultTooLargeException: row 2 (line 3, offset 8): result exceeds maxRows (1)
CRLF                                                    ResultTooLargeException: row 2 (line 3, offset 10): result exceeds maxRows (1)
LFs inside quoted fields count towards the line         ResultTooLargeException: row 3 (line 6, offset 20): result exceeds maxRows (2)
no final terminator                                     ResultTooLargeException: row 2 (line 3, offset 8): result exceeds maxRows (1)
-- only matching rows count
non-matching rows do not count                          {i=int(1), s=s"a"}
the 3rd matching row is row 5                           ResultTooLargeException: row 5 (line 6, offset 20): result exceeds maxRows (2)
maxRows 2, exactly 2 matches among 5 rows               {i=int(1), s=s"a"} | {i=int(2), s=s"b"}
-- the run stops: nothing after that row is read or validated
later unterminated quote                                ResultTooLargeException: row 2 (line 3, offset 8): result exceeds maxRows (1)
later bad value                                         ResultTooLargeException: row 2 (line 3, offset 8): result exceeds maxRows (1)
later wrong field count                                 ResultTooLargeException: row 2 (line 3, offset 8): result exceeds maxRows (1)
-- an error before the limit row comes first
bad value in row 2, limit would trip at row 3           CsvException: row 2 (line 3, offset 8): column "i": expected int64, got malformed value
a bad value in a non-matching row before the limit row  CsvException: row 2 (line 3, offset 8): column "i": expected int64, got malformed value
-- the default maxRows is 10000
10000
ResultTooLargeException: row 10001 (line 10002, offset 48896): result exceeds maxRows (10000)
-- no partial result
BatchFlow\ResultTooLargeException is a BatchFlow\LimitException
string(5) "unset"
-- maxRows range is a ValueError raised before any I/O
toArray(0)                      ValueError: BatchFlow\Query::toArray(): Argument #1 ($maxRows) must be between 1 and 100000000
toArray(-1)                     ValueError: BatchFlow\Query::toArray(): Argument #1 ($maxRows) must be between 1 and 100000000
toArray(100000001)              ValueError: BatchFlow\Query::toArray(): Argument #1 ($maxRows) must be between 1 and 100000000
toArray(-9223372036854775808)   ValueError: BatchFlow\Query::toArray(): Argument #1 ($maxRows) must be between 1 and 100000000
toArray(9223372036854775807)    ValueError: BatchFlow\Query::toArray(): Argument #1 ($maxRows) must be between 1 and 100000000
-- the bounds themselves are accepted
maxRows 1                                               {i=int(1), s=s"a"}
maxRows 100000000                                       {i=int(1), s=s"a"} | {i=int(2), s=s"b"}
-- named argument
2
-- rows after the limit are not read: a 4 MiB tail is never pulled from the stream
ResultTooLargeException: row 3 (line 4, offset 12): result exceeds maxRows (2)
served at most 1 MiB of a 4 MB input: yes
stream closed after the refusal: 1
