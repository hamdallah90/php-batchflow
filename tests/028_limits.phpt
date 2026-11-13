--TEST--
batchflow: maxFieldBytes, maxRowBytes and maxInputRows exact at the limit and +1; lazy oversize row rejected within a bounded memory footprint
--EXTENSIONS--
batchflow
--SKIPIF--
<?php
// run-tests --asan disables the Zend allocator but sets USE_TRACKED_ALLOC=1, whose allocator enforces memory_limit;
// its memory_get_peak_usage() reads 0, so there the peak-growth line holds without measuring anything. Only the
// untracked system allocator enforces nothing.
if (getenv('USE_ZEND_ALLOC') === '0' && getenv('USE_TRACKED_ALLOC') !== '1') die('skip memory_limit needs the Zend or the tracked allocator');
?>
--INI--
memory_limit=256M
--FILE--
<?php
require __DIR__ . '/bf_helper.inc';

use BatchFlow\Csv;

$diffs = 0;
function l(string $label, string $csv, array $schema, array $opts = [], array $preds = [], string $term = 'count', ?string $col = null): void
{
    global $diffs;
    $x = bf_exec($csv, $schema, $opts, $preds, $term, $col);
    if ($x !== bf_ref($csv, $schema, $opts, $preds, $term, $col)) {
        $diffs++;
        $x .= '   [DIFFERS FROM REFERENCE]';
    }
    echo str_pad($label, 50), $x, "\n";
}
$S = ['s' => 'string'];
$P = fn(string $v) => [['eq', 's', $v]];

echo "-- maxFieldBytes exactly at the limit and +1 (terminators LF, CRLF, none)\n";
$o = ['maxFieldBytes' => 10];
foreach (['LF' => "\n", 'CRLF' => "\r\n", 'none' => ''] as $t => $term) {
    l("field 10 bytes, $t", "s\n" . str_repeat('a', 10) . $term, $S, $o, $P('x'));
    l("field 11 bytes, $t", "s\n" . str_repeat('a', 11) . $term, $S, $o, $P('x'));
}
l('quoted field 10 bytes', "s\n\"" . str_repeat('a', 10) . "\"\n", $S, $o, $P('x'));
l('quoted field 11 bytes', "s\n\"" . str_repeat('a', 11) . "\"\n", $S, $o, $P('x'));
l('bytes after quote removal: doubled quotes (10)', "s\n\"aa\"\"aaaaaaa\"\n", $S, $o, $P('x'));
l('bytes after quote removal: doubled quotes (11)', "s\n\"aa\"\"aaaaaaaa\"\n", $S, $o, $P('x'));
l('multibyte counts bytes (10)', "s\n" . str_repeat("\u{e9}", 5) . "\n", $S, $o, $P('x'));
l('multibyte counts bytes (12)', "s\n" . str_repeat("\u{e9}", 6) . "\n", $S, $o, $P('x'));
l('numeric field 10 bytes', "n\n1234567890\n", ['n' => 'int64'], $o, [['eq', 'n', 1]]);
l('numeric field 11 bytes', "n\n12345678901\n", ['n' => 'int64'], $o, [['eq', 'n', 1]]);
l('maxFieldBytes 1, one byte', "s\na\n", $S, ['maxFieldBytes' => 1], $P('x'));
l('maxFieldBytes 1, two bytes', "s\nab\n", $S, ['maxFieldBytes' => 1], $P('x'));
l('empty field never exceeds', "s\n\n", $S, ['maxFieldBytes' => 1], $P('x'));

echo "-- maxRowBytes exactly at the limit and +1 (terminator excluded)\n";
$S2 = ['a' => 'int64', 'b' => 'string'];
$o = ['maxRowBytes' => 12];
foreach (['LF' => "\n", 'CRLF' => "\r\n", 'none' => ''] as $t => $term) {
    l("row 12 bytes, $t", "a,b\n" . '1,' . str_repeat('x', 10) . $term, $S2, $o);
    l("row 13 bytes, $t", "a,b\n" . '1,' . str_repeat('x', 11) . $term, $S2, $o);
}
l('row of 13 bytes with quoted newline', "a,b\n1,\"" . str_repeat('x', 3) . "\n" . str_repeat('x', 5) . "\"\n", $S2, $o);
l('row of 12 bytes with quoted newline', "a,b\n1,\"" . str_repeat('x', 3) . "\n" . str_repeat('x', 4) . "\"\n", $S2, $o);
l('quotes count toward the row size (12)', "a,b\n1,\"" . str_repeat('x', 8) . "\"\n", $S2, $o);
l('quotes count toward the row size (13)', "a,b\n1,\"" . str_repeat('x', 9) . "\"\n", $S2, $o);
l('maxRowBytes applies to unreferenced columns', "a,b\n1," . str_repeat('x', 20) . "\n", $S2, $o);
l('header at the limit', "a,b\n1,x\n", $S2, ['maxRowBytes' => 3]);
l('header at the limit + 1', "a,b\n1,x\n", $S2, ['maxRowBytes' => 2]);
l('header at the limit, CRLF', "a,b\r\n1,x\r\n", $S2, ['maxRowBytes' => 3]);
l('row of 3 bytes, header of 3', "a,b\n1,x\n", $S2, ['maxRowBytes' => 3]);
l('BOM is not part of the header length', "\xEF\xBB\xBFa,b\n1,x\n", $S2, ['maxRowBytes' => 3]);
l('maxRowBytes 1 with a one-column file', "a\n1\n", ['a' => 'int64'], ['maxRowBytes' => 1]);
l('maxRowBytes 1 with a two-byte row', "a\n12\n", ['a' => 'int64'], ['maxRowBytes' => 1]);

echo "-- maxInputRows exactly at the limit and +1; the header is not counted\n";
$S1 = ['a' => 'int64'];
foreach ([0, 1, 2, 3] as $n) {
    $csv = "a\n" . str_repeat("1\n", $n);
    l("$n rows, maxInputRows 2", $csv, $S1, ['maxInputRows' => 2]);
}
l('3 rows, no final terminator', "a\n1\n1\n1", $S1, ['maxInputRows' => 2]);
l('maxInputRows 1, one row', "a\n1\n", $S1, ['maxInputRows' => 1]);
l('maxInputRows 1, header only', "a\n", $S1, ['maxInputRows' => 1]);
l('framing of row limit+1 comes first', "a\n1\n1\n\"x\n", $S1, ['maxInputRows' => 2]);
l('limit+1 beats a bad value in that row', "a\n1\n1\nx\n", $S1, ['maxInputRows' => 2], [['eq', 'a', 1]]);
l('limit+1 beats a bad value (sum)', "a\n1\n1\nx\n", $S1, ['maxInputRows' => 2], [], 'sum', 'a');
l('bad value in row 2 comes before the limit', "a\n1\nx\n1\n", $S1, ['maxInputRows' => 2], [['eq', 'a', 1]]);
l('rows after limit+1 are never read', "a\n1\n1\n1\n\"never closed", $S1, ['maxInputRows' => 2]);
l('blank line rows count', "a\n\n\n\n", $S1, ['maxInputRows' => 2]);

echo "-- maxRowBytes below maxFieldBytes: the smaller limit wins\n";
$o = ['maxFieldBytes' => 100, 'maxRowBytes' => 10];
l('field of 11 bytes in a one-column file', "s\n" . str_repeat('a', 11) . "\n", $S, $o, $P('x'));
l('field of 10 bytes in a one-column file', "s\n" . str_repeat('a', 10) . "\n", $S, $o, $P('x'));
l('row of 10 bytes: "1," and an 8-byte field', "a,b\n1," . str_repeat('a', 8) . "\n", $S2, $o, [['eq', 'b', 'x']]);
l('row of 11 bytes: "1," and a 9-byte field', "a,b\n1," . str_repeat('a', 9) . "\n", $S2, $o, [['eq', 'b', 'x']]);
$o = ['maxFieldBytes' => 5, 'maxRowBytes' => 100];
l('maxFieldBytes below maxRowBytes', "s\n" . str_repeat('a', 6) . "\n", $S, $o, $P('x'));

echo "-- maxFieldBytes does not apply to header fields\n";
$long = str_repeat('h', 20);
l('20-byte header name, maxFieldBytes 5', "$long\n1\n", [$long => 'int64'], ['maxFieldBytes' => 5], [['eq', $long, 1]]);
l('20-byte header name, maxRowBytes 19', "$long\n1\n", [$long => 'int64'], ['maxRowBytes' => 19]);
l('extra header field longer than maxFieldBytes', "a,$long\n1,2\n", $S1, ['maxFieldBytes' => 5, 'allowExtraColumns' => true], [['eq', 'a', 1]]);

echo "-- a lazy row far above maxRowBytes is rejected with bounded memory\n";
$cap = 1048576;
$before = memory_get_peak_usage(true);
BfWrap::$cfg = ['segments' => [["s,n\nx,", 1], ['y', 100 * 1048576]], 'max' => 65536];
$r = bf_try(fn() => Csv::scan('bfwrap://in', ['s' => 'string', 'n' => 'string'], ['maxRowBytes' => $cap])->whereEq('s', 'x')->count());
$grew = memory_get_peak_usage(true) - $before;
echo $r, "\n";
echo 'bytes served stopped near the cap: ', BfWrap::$last->served < 8 * $cap ? 'yes' : 'NO (' . BfWrap::$last->served . ')', "\n";
// Reader buffer B = maxRowBytes + 2 + 65536 + 64, its masks B/8, a field table: well under 8 x maxRowBytes.
echo 'peak growth within bound: ', $grew <= 8 * $cap ? 'yes' : "NO ($grew)", "\n";
echo "reference disagreements: $diffs\n";
?>
--EXPECT--
-- maxFieldBytes exactly at the limit and +1 (terminators LF, CRLF, none)
field 10 bytes, LF                                int(0)
field 11 bytes, LF                                LimitException: row 1 (line 2, offset 2): column "s": field exceeds maxFieldBytes
field 10 bytes, CRLF                              int(0)
field 11 bytes, CRLF                              LimitException: row 1 (line 2, offset 2): column "s": field exceeds maxFieldBytes
field 10 bytes, none                              int(0)
field 11 bytes, none                              LimitException: row 1 (line 2, offset 2): column "s": field exceeds maxFieldBytes
quoted field 10 bytes                             int(0)
quoted field 11 bytes                             LimitException: row 1 (line 2, offset 2): column "s": field exceeds maxFieldBytes
bytes after quote removal: doubled quotes (10)    int(0)
bytes after quote removal: doubled quotes (11)    LimitException: row 1 (line 2, offset 2): column "s": field exceeds maxFieldBytes
multibyte counts bytes (10)                       int(0)
multibyte counts bytes (12)                       LimitException: row 1 (line 2, offset 2): column "s": field exceeds maxFieldBytes
numeric field 10 bytes                            int(0)
numeric field 11 bytes                            LimitException: row 1 (line 2, offset 2): column "n": field exceeds maxFieldBytes
maxFieldBytes 1, one byte                         int(0)
maxFieldBytes 1, two bytes                        LimitException: row 1 (line 2, offset 2): column "s": field exceeds maxFieldBytes
empty field never exceeds                         int(0)
-- maxRowBytes exactly at the limit and +1 (terminator excluded)
row 12 bytes, LF                                  int(1)
row 13 bytes, LF                                  LimitException: row 1 (line 2, offset 4): row exceeds maxRowBytes
row 12 bytes, CRLF                                int(1)
row 13 bytes, CRLF                                LimitException: row 1 (line 2, offset 4): row exceeds maxRowBytes
row 12 bytes, none                                int(1)
row 13 bytes, none                                LimitException: row 1 (line 2, offset 4): row exceeds maxRowBytes
row of 13 bytes with quoted newline               LimitException: row 1 (line 2, offset 4): row exceeds maxRowBytes
row of 12 bytes with quoted newline               int(1)
quotes count toward the row size (12)             int(1)
quotes count toward the row size (13)             LimitException: row 1 (line 2, offset 4): row exceeds maxRowBytes
maxRowBytes applies to unreferenced columns       LimitException: row 1 (line 2, offset 4): row exceeds maxRowBytes
header at the limit                               int(1)
header at the limit + 1                           LimitException: header (line 1, offset 0): row exceeds maxRowBytes
header at the limit, CRLF                         int(1)
row of 3 bytes, header of 3                       int(1)
BOM is not part of the header length              int(1)
maxRowBytes 1 with a one-column file              int(1)
maxRowBytes 1 with a two-byte row                 LimitException: row 1 (line 2, offset 2): row exceeds maxRowBytes
-- maxInputRows exactly at the limit and +1; the header is not counted
0 rows, maxInputRows 2                            int(0)
1 rows, maxInputRows 2                            int(1)
2 rows, maxInputRows 2                            int(2)
3 rows, maxInputRows 2                            LimitException: row 3 (line 4, offset 6): input exceeds maxInputRows
3 rows, no final terminator                       LimitException: row 3 (line 4, offset 6): input exceeds maxInputRows
maxInputRows 1, one row                           int(1)
maxInputRows 1, header only                       int(0)
framing of row limit+1 comes first                CsvException: row 3 (line 4, offset 6): unterminated quoted field
limit+1 beats a bad value in that row             LimitException: row 3 (line 4, offset 6): input exceeds maxInputRows
limit+1 beats a bad value (sum)                   LimitException: row 3 (line 4, offset 6): input exceeds maxInputRows
bad value in row 2 comes before the limit         CsvException: row 2 (line 3, offset 4): column "a": expected int64, got malformed value
rows after limit+1 are never read                 LimitException: row 3 (line 4, offset 6): input exceeds maxInputRows
blank line rows count                             LimitException: row 3 (line 4, offset 4): input exceeds maxInputRows
-- maxRowBytes below maxFieldBytes: the smaller limit wins
field of 11 bytes in a one-column file            LimitException: row 1 (line 2, offset 2): row exceeds maxRowBytes
field of 10 bytes in a one-column file            int(0)
row of 10 bytes: "1," and an 8-byte field         int(0)
row of 11 bytes: "1," and a 9-byte field          LimitException: row 1 (line 2, offset 4): row exceeds maxRowBytes
maxFieldBytes below maxRowBytes                   LimitException: row 1 (line 2, offset 2): column "s": field exceeds maxFieldBytes
-- maxFieldBytes does not apply to header fields
20-byte header name, maxFieldBytes 5              int(1)
20-byte header name, maxRowBytes 19               LimitException: header (line 1, offset 0): row exceeds maxRowBytes
extra header field longer than maxFieldBytes      int(1)
-- a lazy row far above maxRowBytes is rejected with bounded memory
LimitException: row 1 (line 2, offset 4): row exceeds maxRowBytes
bytes served stopped near the cap: yes
peak growth within bound: yes
reference disagreements: 0
