--TEST--
batchflow: record framing - LF, CRLF, mixed, no final terminator, blank lines, bare CR, and leftmost-byte precedence
--EXTENSIONS--
batchflow
--FILE--
<?php
require __DIR__ . '/bf_helper.inc';

$S2 = ['a' => 'int64', 'b' => 'int64'];
$S1 = ['a' => 'int64'];
$diffs = 0;

function c(string $label, string $csv, array $schema, array $opts = []): void
{
    global $diffs;
    $r = bf_exec($csv, $schema, $opts);
    if ($r !== bf_ref($csv, $schema, $opts)) {
        $diffs++;
        $r .= '   [DIFFERS FROM REFERENCE]';
    }
    echo str_pad($label, 40), $r, "\n";
}

echo "-- terminators\n";
c('LF', "a,b\n1,2\n3,4\n", $S2);
c('CRLF', "a,b\r\n1,2\r\n3,4\r\n", $S2);
c('mixed LF and CRLF', "a,b\r\n1,2\n3,4\r\n5,6\n", $S2);
c('no final terminator', "a,b\n1,2\n3,4", $S2);
c('no final terminator, header only', "a,b", $S2);
c('header only, LF', "a,b\n", $S2);
c('header only, CRLF', "a,b\r\n", $S2);
c('one row, no terminator', "a,b\n1,2", $S2);
c('terminator at end adds no record', "a,b\n1,2\n", $S2);

echo "-- blank lines\n";
c('one column: blank line is NULL row', "a\n\n1\n", $S1);
c('one column: trailing blank line', "a\n1\n\n", $S1);
c('one column: CRLF blank line', "a\r\n\r\n", $S1);
c('one column: only blanks', "a\n\n\n\n", $S1);
c('one column: NULL row is counted', "a\n\n", $S1);
echo bf_exec("a\n\n1\n\n", $S1, [], [['eq', 'a', null]]), "  (rows matching IS NULL)\n";
c('two columns: blank line fails', "a,b\n\n", $S2);
c('two columns: blank in the middle', "a,b\n1,2\n\n3,4\n", $S2);
c('two columns: trailing blank line', "a,b\n1,2\n\n", $S2);
c('two columns: CRLF blank line', "a,b\r\n1,2\r\n\r\n", $S2);
c('header: blank line is a header', "\n", $S1);

echo "-- bare CR\n";
c('final lone CR', "a,b\n1,2\r", $S2);
c('final lone CR after header', "a,b\r", $S2);
c('interior bare CR', "a,b\n1\r2,3\n", $S2);
c('CR before delimiter', "a,b\n1\r,2\n", $S2);
c('CR CR LF', "a,b\n1,2\r\r\n", $S2);
c('CR in header', "a\r,b\n", $S2);
c('CR at end of first record', "a,b\r1,2\n", $S2);

echo "-- leftmost byte decides\n";
c('text after quote beats too few fields', "a,b\n1,\"x\"y\n", $S2);
c('too many fields beats later quote error', "a,b\n1,2,3,\"x\"y\n", $S2);
c('bare quote beats later too many fields', "a,b\n1,2\"x,3\n", $S2);
c('too many fields beats later bare CR', "a,b\n1,2,3\r4\n", $S2);
c('CRLF record with too few fields', "a,b\n1\r\n2,3\n", $S2);
c('too few fields at terminator', "a,b\n1\n", $S2);
c('too few fields at end of input', "a,b\n1", $S2);
c('too many fields, one extra', "a,b\n1,2,3\n", $S2);
c('maxRowBytes beats later text-after-quote', "a,b\n1,\"abcdefghij\"x\n", $S2, ['maxRowBytes' => 6]);
c('maxRowBytes beats text-after-quote 1', "a,b\n1,\"ab\"x,zzzzzz\n", $S2, ['maxRowBytes' => 5]);
c('text-after-quote beats later maxRowBytes', "a,b\n1,\"a\"x,zzzzzzzz\n", $S2, ['maxRowBytes' => 100]);
c('bare quote before maxRowBytes', "a,b\n1,2\"xxxxxxxx\n", $S2, ['maxRowBytes' => 6]);
c('too many fields before maxRowBytes', "a,b\n1,2,3,4,5,6\n", $S2, ['maxRowBytes' => 8]);
c('maxRowBytes before too many fields', "a,b\n1234,5,6\n", $S2, ['maxRowBytes' => 3]);
c('maxRowBytes beats too few fields', "a,b\n1111111\n", $S2, ['maxRowBytes' => 3]);
c('unterminated quote at end', "a,b\n1,\"abc\n", $S2);
c('unterminated quote beats too few fields', "a,b\n\"abc", $S2);

echo "reference disagreements: $diffs\n";
?>
--EXPECT--
-- terminators
LF                                      int(2)
CRLF                                    int(2)
mixed LF and CRLF                       int(3)
no final terminator                     int(2)
no final terminator, header only        int(0)
header only, LF                         int(0)
header only, CRLF                       int(0)
one row, no terminator                  int(1)
terminator at end adds no record        int(1)
-- blank lines
one column: blank line is NULL row      int(2)
one column: trailing blank line         int(2)
one column: CRLF blank line             int(1)
one column: only blanks                 int(3)
one column: NULL row is counted         int(1)
int(2)  (rows matching IS NULL)
two columns: blank line fails           CsvException: row 1 (line 2, offset 4): expected 2 fields, got 1
two columns: blank in the middle        CsvException: row 2 (line 3, offset 8): expected 2 fields, got 1
two columns: trailing blank line        CsvException: row 2 (line 3, offset 8): expected 2 fields, got 1
two columns: CRLF blank line            CsvException: row 2 (line 3, offset 10): expected 2 fields, got 1
header: blank line is a header          CsvException: header (line 1, offset 0): unexpected column at position 1
-- bare CR
final lone CR                           CsvException: row 1 (line 2, offset 4): bare CR
final lone CR after header              CsvException: header (line 1, offset 0): bare CR
interior bare CR                        CsvException: row 1 (line 2, offset 4): bare CR
CR before delimiter                     CsvException: row 1 (line 2, offset 4): bare CR
CR CR LF                                CsvException: row 1 (line 2, offset 4): bare CR
CR in header                            CsvException: header (line 1, offset 0): bare CR
CR at end of first record               CsvException: header (line 1, offset 0): bare CR
-- leftmost byte decides
text after quote beats too few fields   CsvException: row 1 (line 2, offset 4): text after closing quote
too many fields beats later quote error CsvException: row 1 (line 2, offset 4): expected 2 fields, got more
bare quote beats later too many fields  CsvException: row 1 (line 2, offset 4): bare quote in unquoted field
too many fields beats later bare CR     CsvException: row 1 (line 2, offset 4): expected 2 fields, got more
CRLF record with too few fields         CsvException: row 1 (line 2, offset 4): expected 2 fields, got 1
too few fields at terminator            CsvException: row 1 (line 2, offset 4): expected 2 fields, got 1
too few fields at end of input          CsvException: row 1 (line 2, offset 4): expected 2 fields, got 1
too many fields, one extra              CsvException: row 1 (line 2, offset 4): expected 2 fields, got more
maxRowBytes beats later text-after-quoteLimitException: row 1 (line 2, offset 4): row exceeds maxRowBytes
maxRowBytes beats text-after-quote 1    LimitException: row 1 (line 2, offset 4): row exceeds maxRowBytes
text-after-quote beats later maxRowBytesCsvException: row 1 (line 2, offset 4): text after closing quote
bare quote before maxRowBytes           CsvException: row 1 (line 2, offset 4): bare quote in unquoted field
too many fields before maxRowBytes      CsvException: row 1 (line 2, offset 4): expected 2 fields, got more
maxRowBytes before too many fields      LimitException: row 1 (line 2, offset 4): row exceeds maxRowBytes
maxRowBytes beats too few fields        LimitException: row 1 (line 2, offset 4): row exceeds maxRowBytes
unterminated quote at end               CsvException: row 1 (line 2, offset 4): unterminated quoted field
unterminated quote beats too few fields CsvException: row 1 (line 2, offset 4): unterminated quoted field
reference disagreements: 0
