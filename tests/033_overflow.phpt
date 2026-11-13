--TEST--
batchflow: int64 sum overflow in both directions at the offending row, float64 non-finite once after the scan
--EXTENSIONS--
batchflow
--FILE--
<?php
require __DIR__ . '/bf_helper.inc';

function o(string $label, string $csv, array $S, array $preds = [], string $term = 'sum', ?string $col = 'v', array $opts = []): void
{
    $a = bf_exec($csv, $S, $opts, $preds, $term, $col);
    $b = bf_ref($csv, $S, $opts, $preds, $term, $col);
    echo str_pad($label, 50), $a, $a === $b ? '' : "   [DIFFERS FROM REFERENCE: $b]", "\n";
}
$I = ['v' => 'int64'];
$F = ['v' => 'float64'];
$MAX = '9223372036854775807';
$MIN = '-9223372036854775808';

echo "-- int64: overflow at the row where it happens\n";
o('MAX + 1', "v\n$MAX\n1\n", $I);
o('1 + MAX', "v\n1\n$MAX\n", $I);
o('MIN + -1', "v\n$MIN\n-1\n", $I);
o('-1 + MIN', "v\n-1\n$MIN\n", $I);
o('MAX + MAX', "v\n$MAX\n$MAX\n", $I);
o('MIN + MIN', "v\n$MIN\n$MIN\n", $I);
o('overflow at row 4', "v\n5\n6\n$MAX\n7\n", $I);
o('overflow in CRLF file', "v\r\n$MAX\r\n1\r\n", $I);
o('overflow on a row of a longer file (line numbers)', "v\n1\n2\n3\n4\n$MAX\n", $I);
o('MAX + 1 + -1: the intermediate overflow counts', "v\n$MAX\n1\n-1\n", $I);
o('MAX + MIN + -1 fits', "v\n$MAX\n$MIN\n-1\n", $I);
o('MAX + -1 + 1 fits', "v\n$MAX\n-1\n1\n", $I);
o('MIN + 1 + -1 fits', "v\n$MIN\n1\n-1\n", $I);
o('MAX alone fits', "v\n$MAX\n", $I);
o('NULL rows add nothing', "v\n$MAX\n\n\n", $I);
o('overflow stops the run: later garbage is not read', "v\n$MAX\n1\n\"never closed", $I);
o('rows that do not match are not added', "v\n$MAX\n1\n", $I, [['gt', 'v', 5]]);
o('only matching rows overflow', "k,v\n1,$MAX\n2,$MAX\n1,1\n", ['k' => 'int64', 'v' => 'int64'], [['eq', 'k', 1]]);
o('non-matching big rows are skipped', "k,v\n1,$MAX\n2,$MAX\n2,$MAX\n1,0\n", ['k' => 'int64', 'v' => 'int64'], [['eq', 'k', 1]]);
o('other column invalid in the overflowing row', "k,v\n1,$MAX\nx,1\n", ['k' => 'int64', 'v' => 'int64'], [['gt', 'k', 0]]);
o('other column invalid in the overflowing row (2)', "k,v\n1,$MAX\n1,1\nx,1\n", ['k' => 'int64', 'v' => 'int64'], [['gt', 'k', 0]]);
o('summary: overflow', "v\n$MAX\n1\n", $I, [], 'summary');
o('summary: overflow', "v\n$MAX\n1\n", $I, [], 'summary', 'v');
o('count() never sums', "v\n$MAX\n1\n", $I, [], 'count', null);

echo "-- float64: finite check once, after the scan, without a row prefix\n";
o('1e308 + 1e308', "v\n1e308\n1e308\n", $F);
o('-1e308 + -1e308', "v\n-1e308\n-1e308\n", $F);
o('1.7976931348623157e308 + 1.7976931348623157e308', "v\n1.7976931348623157e308\n1.7976931348623157e308\n", $F);
o('1e308 + 1e308 + -1e308 stays infinite', "v\n1e308\n1e308\n-1e308\n", $F);
o('1e308 + 1e308 + -1e308 + -1e308', "v\n1e308\n1e308\n-1e308\n-1e308\n", $F);
o('1e308 + -1e308 is 0.0', "v\n1e308\n-1e308\n", $F);
o('largest finite value alone', "v\n1.7976931348623157e308\n", $F);
o('non-finite sum, later bad row: the bad row is reported', "v\n1e308\n1e308\nbad\n", $F);
o('non-finite sum, later unterminated quote', "v\n1e308\n1e308\n\"x", $F);
o('non-finite sum, later row over maxInputRows', "v\n1e308\n1e308\n1\n", $F, [], 'sum', 'v', ['maxInputRows' => 2]);
o('non-finite sum, CRLF', "v\r\n1e308\r\n1e308\r\n", $F);
o('summary: non-finite', "v\n1e308\n1e308\n", $F, [], 'summary');
o('summary: non-finite', "v\n1e308\n1e308\n", $F, [], 'summary', 'v');
o('predicates may exclude the large rows', "k,v\n1,1e308\n2,1e308\n1,1e308\n", ['k' => 'int64', 'v' => 'float64'], [['eq', 'k', 2]]);
?>
--EXPECT--
-- int64: overflow at the row where it happens
MAX + 1                                           SumOverflowException: row 2 (line 3, offset 22): int64 sum overflow in column "v"
1 + MAX                                           SumOverflowException: row 2 (line 3, offset 4): int64 sum overflow in column "v"
MIN + -1                                          SumOverflowException: row 2 (line 3, offset 23): int64 sum overflow in column "v"
-1 + MIN                                          SumOverflowException: row 2 (line 3, offset 5): int64 sum overflow in column "v"
MAX + MAX                                         SumOverflowException: row 2 (line 3, offset 22): int64 sum overflow in column "v"
MIN + MIN                                         SumOverflowException: row 2 (line 3, offset 23): int64 sum overflow in column "v"
overflow at row 4                                 SumOverflowException: row 3 (line 4, offset 6): int64 sum overflow in column "v"
overflow in CRLF file                             SumOverflowException: row 2 (line 3, offset 24): int64 sum overflow in column "v"
overflow on a row of a longer file (line numbers) SumOverflowException: row 5 (line 6, offset 10): int64 sum overflow in column "v"
MAX + 1 + -1: the intermediate overflow counts    SumOverflowException: row 2 (line 3, offset 22): int64 sum overflow in column "v"
MAX + MIN + -1 fits                               int(-2)
MAX + -1 + 1 fits                                 int(9223372036854775807)
MIN + 1 + -1 fits                                 int(-9223372036854775808)
MAX alone fits                                    int(9223372036854775807)
NULL rows add nothing                             int(9223372036854775807)
overflow stops the run: later garbage is not read SumOverflowException: row 2 (line 3, offset 22): int64 sum overflow in column "v"
rows that do not match are not added              int(9223372036854775807)
only matching rows overflow                       SumOverflowException: row 3 (line 4, offset 48): int64 sum overflow in column "v"
non-matching big rows are skipped                 int(9223372036854775807)
other column invalid in the overflowing row       CsvException: row 2 (line 3, offset 26): column "k": expected int64, got malformed value
other column invalid in the overflowing row (2)   SumOverflowException: row 2 (line 3, offset 26): int64 sum overflow in column "v"
summary: overflow                                 SumOverflowException: row 2 (line 3, offset 22): int64 sum overflow in column "v"
summary: overflow                                 SumOverflowException: row 2 (line 3, offset 22): int64 sum overflow in column "v"
count() never sums                                int(2)
-- float64: finite check once, after the scan, without a row prefix
1e308 + 1e308                                     SumOverflowException: float64 sum of column "v" is not finite
-1e308 + -1e308                                   SumOverflowException: float64 sum of column "v" is not finite
1.7976931348623157e308 + 1.7976931348623157e308   SumOverflowException: float64 sum of column "v" is not finite
1e308 + 1e308 + -1e308 stays infinite             SumOverflowException: float64 sum of column "v" is not finite
1e308 + 1e308 + -1e308 + -1e308                   SumOverflowException: float64 sum of column "v" is not finite
1e308 + -1e308 is 0.0                             float(0.0)
largest finite value alone                        float(1.7976931348623157E+308)
non-finite sum, later bad row: the bad row is reportedCsvException: row 3 (line 4, offset 14): column "v": expected float64, got malformed value
non-finite sum, later unterminated quote          CsvException: row 3 (line 4, offset 14): unterminated quoted field
non-finite sum, later row over maxInputRows       LimitException: row 3 (line 4, offset 14): input exceeds maxInputRows
non-finite sum, CRLF                              SumOverflowException: float64 sum of column "v" is not finite
summary: non-finite                               SumOverflowException: float64 sum of column "v" is not finite
summary: non-finite                               SumOverflowException: float64 sum of column "v" is not finite
predicates may exclude the large rows             float(1.0E+308)
