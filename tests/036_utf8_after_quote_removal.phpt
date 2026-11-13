--TEST--
batchflow: a UTF-8 sequence cut by the end of a field is invalid even when quote removal leaves continuation bytes after the field
--EXTENSIONS--
batchflow
--FILE--
<?php
require __DIR__ . '/bf_helper.inc';

$S = ['s' => 'string'];

// Doubled quotes shift the field left in the buffer, so the two bytes after its new end are the old
// A9 C3: a two-byte sequence cut at the field's end is followed by what looks like its continuation.
$cut = "s\n\"\"\"\"\"\xC3\xA9\xC3\"\n";
echo 'cut sequence: ', bf_exec($cut, $S, [], [['eq', 's', 'zz']]), "\n";
echo 'against the reference: ', bf_cmp($cut, $S, [], [['eq', 's', 'zz']]), "\n";

$whole = "s\n\"\"\"\"\"\xC3\xA9\xC3\xA9\"\n";
echo 'complete sequence: ', bf_exec($whole, $S, [], [['eq', 's', 'zz']]), "\n";
echo 'against the reference: ', bf_cmp($whole, $S, [], [['eq', 's', 'zz']]), "\n";
?>
--EXPECT--
cut sequence: CsvException: row 1 (line 2, offset 2): column "s": invalid UTF-8
against the reference: same
complete sequence: int(0)
against the reference: same
