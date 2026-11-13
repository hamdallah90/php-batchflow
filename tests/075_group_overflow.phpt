--TEST--
batchflow: int64 group sums overflow per group at the offending row; a non-finite float group sum raises once after the scan
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
$I = ['k' => 'string', 'v' => 'int64'];
$F = ['k' => 'string', 'v' => 'float64'];
$MAX = '9223372036854775807';
$MIN = '-9223372036854775808';

echo "-- int64: the overflow is per group, at the row where it happens\n";
g('one group overflows upward', "k,v\na,$MAX\na,1\n", $I, 'k', 'v');
g('one group overflows downward', "k,v\na,$MIN\na,-1\n", $I, 'k', 'v');
g('overflow on the fourth data row', "k,v\na,1\nb,2\nb,3\na,$MAX\n", $I, 'k', 'v');
g('two groups at MAX each do not overflow', "k,v\na,$MAX\nb,$MAX\n", $I, 'k', 'v');
g('two groups at MIN each do not overflow', "k,v\na,$MIN\nb,$MIN\n", $I, 'k', 'v');
g('MAX in a, then b and a interleaved, a overflows', "k,v\na,$MAX\nb,1\nb,$MAX\na,1\n", $I, 'k', 'v');
g('a group exactly at MAX is fine', "k,v\na,$MAX\na,0\nb,1\n", $I, 'k', 'v');
g('a group exactly at MIN is fine', "k,v\na,$MIN\na,0\n", $I, 'k', 'v');
g('MAX then -1 then 1 stays within range', "k,v\na,$MAX\na,-1\na,1\n", $I, 'k', 'v');
g('MAX then 1: the intermediate overflow counts', "k,v\na,$MAX\na,1\na,-1\n", $I, 'k', 'v');
g('int key, overflow', "k,v\n1,$MAX\n2,$MAX\n1,2\n", ['k' => 'int64', 'v' => 'int64'], 'k', 'v');
g('rows that do not match are not added', "k,v\na,$MAX\na,1\n", $I, 'k', 'v', [['gt', 'v', 5]]);
g('NULL values add nothing', "k,v\na,$MAX\na,\na,\n", $I, 'k', 'v');
g('CRLF line numbers', "k,v\r\na,$MAX\r\nb,1\r\na,1\r\n", $I, 'k', 'v');
g('overflow stops the run: later garbage is not read', "k,v\na,$MAX\na,1\n\"never closed", $I, 'k', 'v');
g('an earlier bad row wins over a later overflow', "k,v\na,$MAX\na,x\na,1\n", $I, 'k', 'v');
g('groupCount over the same data cannot overflow', "k,v\na,$MAX\na,1\n", $I, 'k');
echo "-- float64: checked once per group after the scan, no row prefix\n";
g('1e308 + 1e308 in one group', "k,v\na,1e308\na,1e308\n", $F, 'k', 'v');
g('negative infinity', "k,v\na,-1e308\na,-1e308\n", $F, 'k', 'v');
g('only one of two groups overflows', "k,v\na,1\nb,1e308\na,2\nb,1e308\n", $F, 'k', 'v');
g('inf stays inf after adding the opposite sign', "k,v\na,1e308\na,1e308\na,-1e308\n", $F, 'k', 'v');
g('two groups at 1e308 each are finite', "k,v\na,1e308\nb,1e308\n", $F, 'k', 'v');
g('a finite sum near the limit', "k,v\na,1e308\na,7e307\n", $F, 'k', 'v');
g('the scan finishes first: a later bad row wins', "k,v\na,1e308\na,1e308\nb,x\n", $F, 'k', 'v');
g('maxGroups raised before the final float check', "k,v\na,1e308\na,1e308\nb,1\nc,1\n", $F, 'k', 'v', [], [], 2);
?>
--EXPECT--
-- int64: the overflow is per group, at the row where it happens
one group overflows upward                          SumOverflowException: row 2 (line 3, offset 26): int64 sum overflow in column "v"
one group overflows downward                        SumOverflowException: row 2 (line 3, offset 27): int64 sum overflow in column "v"
overflow on the fourth data row                     SumOverflowException: row 4 (line 5, offset 16): int64 sum overflow in column "v"
two groups at MAX each do not overflow              [s"a",int(1),int(9223372036854775807)]; [s"b",int(1),int(9223372036854775807)]
two groups at MIN each do not overflow              [s"a",int(1),int(-9223372036854775808)]; [s"b",int(1),int(-9223372036854775808)]
MAX in a, then b and a interleaved, a overflows     SumOverflowException: row 3 (line 4, offset 30): int64 sum overflow in column "v"
a group exactly at MAX is fine                      [s"a",int(2),int(9223372036854775807)]; [s"b",int(1),int(1)]
a group exactly at MIN is fine                      [s"a",int(2),int(-9223372036854775808)]
MAX then -1 then 1 stays within range               [s"a",int(3),int(9223372036854775807)]
MAX then 1: the intermediate overflow counts        SumOverflowException: row 2 (line 3, offset 26): int64 sum overflow in column "v"
int key, overflow                                   SumOverflowException: row 3 (line 4, offset 48): int64 sum overflow in column "v"
rows that do not match are not added                [s"a",int(1),int(9223372036854775807)]
NULL values add nothing                             [s"a",int(3),int(9223372036854775807)]
CRLF line numbers                                   SumOverflowException: row 3 (line 4, offset 33): int64 sum overflow in column "v"
overflow stops the run: later garbage is not read   SumOverflowException: row 2 (line 3, offset 26): int64 sum overflow in column "v"
an earlier bad row wins over a later overflow       CsvException: row 2 (line 3, offset 26): column "v": expected int64, got malformed value
groupCount over the same data cannot overflow       [s"a",int(2)]
-- float64: checked once per group after the scan, no row prefix
1e308 + 1e308 in one group                          SumOverflowException: float64 sum of column "v" is not finite
negative infinity                                   SumOverflowException: float64 sum of column "v" is not finite
only one of two groups overflows                    SumOverflowException: float64 sum of column "v" is not finite
inf stays inf after adding the opposite sign        SumOverflowException: float64 sum of column "v" is not finite
two groups at 1e308 each are finite                 [s"a",int(1),float(1.0E+308)]; [s"b",int(1),float(1.0E+308)]
a finite sum near the limit                         [s"a",int(2),float(1.7E+308)]
the scan finishes first: a later bad row wins       CsvException: row 3 (line 4, offset 20): column "v": expected float64, got malformed value
maxGroups raised before the final float check       ResultTooLargeException: row 4 (line 5, offset 24): result exceeds maxGroups (2)
