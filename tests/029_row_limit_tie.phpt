--TEST--
batchflow: a framing error on the first byte past maxRowBytes is reported as that error; one byte further the row limit is reported (SPEC 5.4)
--EXTENSIONS--
batchflow
--FILE--
<?php
require __DIR__ . '/bf_helper.inc';

$diffs = 0;
function t(string $label, string $csv, array $schema, int $maxRow): void
{
    global $diffs;
    $x = bf_exec($csv, $schema, ['maxRowBytes' => $maxRow]);
    if ($x !== bf_ref($csv, $schema, ['maxRowBytes' => $maxRow])) {
        $diffs++;
        $x .= '   [DIFFERS FROM REFERENCE]';
    }
    echo str_pad($label, 44), $x, "\n";
}
$S = ['s' => 'string'];
$T = ['a' => 'int64', 'b' => 'int64'];

echo "-- the framing error is on the byte right after the limit\n";
t('bare quote', "s\nabcd\"e\n", $S, 4);
t('bare CR', "s\nabcd\rx\n", $S, 4);
t('text after closing quote', "s\n\"abc\"x\n", $S, 5);
t('delimiter opening one field too many', "a,b\n11,2,3\n", $T, 4);
t('end of input inside a quoted field', "s\n\"abc", $S, 4);
t('bare quote in the header', "ab\"c\n1\n", ['ab"c' => 'int64'], 2);

echo "-- a limit one byte smaller: the row limit comes first\n";
t('bare quote', "s\nabcd\"e\n", $S, 3);
t('bare CR', "s\nabcd\rx\n", $S, 3);
t('text after closing quote', "s\n\"abc\"x\n", $S, 4);
t('delimiter opening one field too many', "a,b\n11,2,3\n", $T, 3);
t('end of input inside a quoted field', "s\n\"abc", $S, 3);
t('bare quote in the header', "ab\"c\n1\n", ['ab"c' => 'int64'], 1);

echo "-- no error at the limit byte: a row of exactly the limit\n";
t('row of 4 bytes', "s\nabcd\n", $S, 4);
echo "reference disagreements: $diffs\n";
?>
--EXPECT--
-- the framing error is on the byte right after the limit
bare quote                                  CsvException: row 1 (line 2, offset 2): bare quote in unquoted field
bare CR                                     CsvException: row 1 (line 2, offset 2): bare CR
text after closing quote                    CsvException: row 1 (line 2, offset 2): text after closing quote
delimiter opening one field too many        CsvException: row 1 (line 2, offset 4): expected 2 fields, got more
end of input inside a quoted field          CsvException: row 1 (line 2, offset 2): unterminated quoted field
bare quote in the header                    CsvException: header (line 1, offset 0): bare quote in unquoted field
-- a limit one byte smaller: the row limit comes first
bare quote                                  LimitException: row 1 (line 2, offset 2): row exceeds maxRowBytes
bare CR                                     LimitException: row 1 (line 2, offset 2): row exceeds maxRowBytes
text after closing quote                    LimitException: row 1 (line 2, offset 2): row exceeds maxRowBytes
delimiter opening one field too many        LimitException: row 1 (line 2, offset 4): row exceeds maxRowBytes
end of input inside a quoted field          LimitException: row 1 (line 2, offset 2): row exceeds maxRowBytes
bare quote in the header                    LimitException: header (line 1, offset 0): row exceeds maxRowBytes
-- no error at the limit byte: a row of exactly the limit
row of 4 bytes                              int(1)
reference disagreements: 0
