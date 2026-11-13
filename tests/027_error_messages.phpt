--TEST--
batchflow: exact reasons and row/line/offset prefixes, no record bytes in any message, <name> escaping and truncation
--EXTENSIONS--
batchflow
--FILE--
<?php
require __DIR__ . '/bf_helper.inc';

$diffs = 0;
$all = [];
function m(string $label, string $csv, array $schema, array $opts = [], array $preds = [], string $term = 'count', ?string $col = null): void
{
    global $diffs, $all;
    $x = bf_exec($csv, $schema, $opts, $preds, $term, $col);
    $all[$label] = $x;
    if ($x !== bf_ref($csv, $schema, $opts, $preds, $term, $col)) {
        $diffs++;
        $x .= '   [DIFFERS FROM REFERENCE]';
    }
    echo str_pad($label, 34), $x, "\n";
}
$S = ['id' => 'int64', 'name' => 'string'];

echo "-- every CsvException and LimitException reason\n";
m('missing header', '', $S);
m('too many columns', 'a' . str_repeat(',a', 4096) . "\n", $S);
m('duplicate column', "id,id,name\n", $S);
m('unexpected column', "id,name,zzz\n", $S);
m('missing column', "id\n", $S);
m('bare quote', "id,name\n1,a\"b\n", $S);
m('unterminated quoted field', "id,name\n1,\"ab\n", $S);
m('text after closing quote', "id,name\n1,\"a\"b\n", $S);
m('bare CR', "id,name\n1,a\rb\n", $S);
m('expected N fields, got M', "id,name\n1\n", $S);
m('expected N fields, got more', "id,name\n1,a,b\n", $S);
m('malformed value', "id,name\nx,a\n", $S, [], [['eq', 'id', 1]]);
m('quoted empty field', "id,name\n\"\",a\n", $S, [], [['eq', 'id', 1]]);
m('out-of-range number', "id,name\n99999999999999999999,a\n", $S, [], [['eq', 'id', 1]]);
m('invalid UTF-8', "id,name\n1,\xff\n", $S, [], [['eq', 'name', 'a']]);
m('row exceeds maxRowBytes', "id,name\n1,abcdefgh\n", $S, ['maxRowBytes' => 8]);
m('field exceeds maxFieldBytes', "id,name\n1,abcdefgh\n", $S, ['maxFieldBytes' => 4], [['eq', 'name', 'a']]);
m('input exceeds maxInputRows', "id,name\n1,a\n2,b\n3,c\n", $S, ['maxInputRows' => 2]);
m('int64 sum overflow', "id,name\n9223372036854775807,a\n1,b\n", $S, [], [], 'sum', 'id');
m('float64 sum not finite', "f\n1e308\n1e308\n", ['f' => 'float64'], [], [], 'sum', 'f');

echo "-- row, line and offset: LF, CRLF, mixed, multi-line quoted fields\n";
m('row 3 with LF', "id,name\n1,a\n2,b\nx,c\n", $S, [], [['eq', 'id', 1]]);
m('row 3 with CRLF', "id,name\r\n1,a\r\n2,b\r\nx,c\r\n", $S, [], [['eq', 'id', 1]]);
m('row 3 mixed', "id,name\r\n1,a\n2,b\r\nx,c\n", $S, [], [['eq', 'id', 1]]);
m('row 3 after multi-line fields', "id,name\n1,\"a\nb\nc\"\n2,\"d\ne\"\nx,c\n", $S, [], [['eq', 'id', 1]]);
m('row 2, no final terminator', "id,name\n1,a\nx,c", $S, [], [['eq', 'id', 1]]);
m('row 1000', "id,name\n" . str_repeat("1,a\n", 999) . "x,a\n", $S, [], [['eq', 'id', 1]]);
m('row 1000 offset (long rows)', "id,name\n" . str_repeat("1,abcdefghij\n", 999) . "x,a\n", $S, [], [['eq', 'id', 1]]);
m('row after a BOM', "\xEF\xBB\xBFid,name\nx,a\n", $S, [], [['eq', 'id', 1]]);

echo "-- no record bytes in any message\n";
$secret = 'SECRETVALUE';
$cases = [
    'int64' => ["id,name\n$secret,a\n", $S, [['eq', 'id', 1]]],
    'int64 quoted' => ["id,name\n\"$secret\",a\n", $S, [['eq', 'id', 1]]],
    'float64' => ["f\n$secret\n", ['f' => 'float64'], [['eq', 'f', 1.0]]],
    'bool' => ["b\n$secret\n", ['b' => 'bool'], [['eq', 'b', true]]],
    'utf8' => ["name\n$secret\xff\n", ['name' => 'string'], [['eq', 'name', 'x']]],
    'out of range' => ["id\n9999999999999999999$secret\n", ['id' => 'int64'], [['eq', 'id', 1]]],
    'field too long' => ["name\n$secret$secret\n", ['name' => 'string'], [['eq', 'name', 'x']]],
    'text after quote' => ["id,name\n1,\"a\"$secret\n", $S, []],
    'bare quote' => ["id,name\n1,$secret\"\n", $S, []],
    'too many fields' => ["id,name\n1,a,$secret\n", $S, []],
    'unexpected header' => ["id,$secret\n", $S, []],
    'duplicate header' => ["id,id\n", ['id' => 'int64', $secret => 'string'], []],
    'missing header column' => ["id\n", ['id' => 'int64', 'name' => 'string'], []],
    'unterminated' => ["id,name\n1,\"$secret\n", $S, []],
    'row too long' => ["id,name\n1,$secret$secret\n", $S, []],
];
foreach ($cases as $l => [$csv, $schema, $preds]) {
    $o = $l === 'field too long' ? ['maxFieldBytes' => 5] : ($l === 'row too long' ? ['maxRowBytes' => 8] : []);
    $x = bf_exec($csv, $schema, $o, $preds);
    $leak = str_contains($x, 'SECRET') || str_contains($x, 'abcdef') || preg_match('/[^\x20-\x7E]/', $x);
    echo str_pad($l, 24), $leak ? 'LEAK/NONPRINTABLE: ' . $x : 'clean', "\n";
}
$bad = 0;
foreach ($all as $l => $x) {
    if (preg_match('/[^\x20-\x7E]/', $x)) {
        $bad++;
    }
}
echo "messages with bytes outside printable ASCII: $bad\n";

echo "-- <name> escaping: bytes outside 0x20..0x7E, quote and backslash become \\xHH (uppercase)\n";
$names = [
    'plain' => 'plain', 'space and tilde' => 'a ~b', 'double quote' => 'a"b', 'backslash' => 'a\\b', 'both' => '"\\',
    'LF' => "a\nb", 'tab' => "a\tb", 'DEL' => "a\x7Fb", 'NUL' => "a\0b", 'e acute' => "caf\u{e9}",
    'emoji' => "\u{1F600}", 'slash' => 'a/b', 'percent' => 'a%b',
];
foreach ($names as $l => $n) {
    // The schema column is missing from the header: the message carries the schema name.
    $csv = "zzz\n1\n";
    echo str_pad($l, 18), bf_exec($csv, [$n => 'int64'], ['allowExtraColumns' => true]), "\n";
}
echo "-- truncation after 64 source bytes, '...' only when bytes were cut\n";
foreach ([63, 64, 65, 100] as $len) {
    $n = str_repeat('n', $len);
    echo str_pad("plain $len bytes", 18), bf_exec("zzz\n1\n", [$n => 'int64'], ['allowExtraColumns' => true]), "\n";
}
foreach ([63, 64, 65] as $len) {
    $n = str_repeat('"', $len);
    echo str_pad("quotes $len bytes", 18), bf_exec("zzz\n1\n", [$n => 'int64'], ['allowExtraColumns' => true]), "\n";
}
echo str_pad('multibyte cut', 18), bf_exec("zzz\n1\n", [str_repeat("\u{e9}", 40) => 'int64'], ['allowExtraColumns' => true]), "\n";
echo str_pad('name in value error', 18), bf_exec("\"q\"\"w\"\nx\n", ['q"w' => 'int64'], [], [['eq', 'q"w', 1]]), "\n";
echo str_pad('name in utf8 error', 18), bf_exec("n\n\xff\n", ["n" => 'string'], [], [['eq', 'n', 'x']]), "\n";
echo str_pad('long name, value error', 18), bf_exec(str_repeat('n', 70) . "\nx\n", [str_repeat('n', 70) => 'int64'], [], [['eq', str_repeat('n', 70), 1]]), "\n";
echo "reference disagreements: $diffs\n";
?>
--EXPECT--
-- every CsvException and LimitException reason
missing header                    CsvException: missing header
too many columns                  CsvException: header (line 1, offset 0): too many columns (limit 4096)
duplicate column                  CsvException: header (line 1, offset 0): duplicate column "id"
unexpected column                 CsvException: header (line 1, offset 0): unexpected column at position 3
missing column                    CsvException: header (line 1, offset 0): missing column "name"
bare quote                        CsvException: row 1 (line 2, offset 8): bare quote in unquoted field
unterminated quoted field         CsvException: row 1 (line 2, offset 8): unterminated quoted field
text after closing quote          CsvException: row 1 (line 2, offset 8): text after closing quote
bare CR                           CsvException: row 1 (line 2, offset 8): bare CR
expected N fields, got M          CsvException: row 1 (line 2, offset 8): expected 2 fields, got 1
expected N fields, got more       CsvException: row 1 (line 2, offset 8): expected 2 fields, got more
malformed value                   CsvException: row 1 (line 2, offset 8): column "id": expected int64, got malformed value
quoted empty field                CsvException: row 1 (line 2, offset 8): column "id": expected int64, got quoted empty field
out-of-range number               CsvException: row 1 (line 2, offset 8): column "id": expected int64, got out-of-range number
invalid UTF-8                     CsvException: row 1 (line 2, offset 8): column "name": invalid UTF-8
row exceeds maxRowBytes           LimitException: row 1 (line 2, offset 8): row exceeds maxRowBytes
field exceeds maxFieldBytes       LimitException: row 1 (line 2, offset 8): column "name": field exceeds maxFieldBytes
input exceeds maxInputRows        LimitException: row 3 (line 4, offset 16): input exceeds maxInputRows
int64 sum overflow                SumOverflowException: row 2 (line 3, offset 30): int64 sum overflow in column "id"
float64 sum not finite            SumOverflowException: float64 sum of column "f" is not finite
-- row, line and offset: LF, CRLF, mixed, multi-line quoted fields
row 3 with LF                     CsvException: row 3 (line 4, offset 16): column "id": expected int64, got malformed value
row 3 with CRLF                   CsvException: row 3 (line 4, offset 19): column "id": expected int64, got malformed value
row 3 mixed                       CsvException: row 3 (line 4, offset 18): column "id": expected int64, got malformed value
row 3 after multi-line fields     CsvException: row 3 (line 7, offset 26): column "id": expected int64, got malformed value
row 2, no final terminator        CsvException: row 2 (line 3, offset 12): column "id": expected int64, got malformed value
row 1000                          CsvException: row 1000 (line 1001, offset 4004): column "id": expected int64, got malformed value
row 1000 offset (long rows)       CsvException: row 1000 (line 1001, offset 12995): column "id": expected int64, got malformed value
row after a BOM                   CsvException: row 1 (line 2, offset 11): column "id": expected int64, got malformed value
-- no record bytes in any message
int64                   clean
int64 quoted            clean
float64                 clean
bool                    clean
utf8                    clean
out of range            clean
field too long          clean
text after quote        clean
bare quote              clean
too many fields         clean
unexpected header       clean
duplicate header        clean
missing header column   clean
unterminated            clean
row too long            clean
messages with bytes outside printable ASCII: 0
-- <name> escaping: bytes outside 0x20..0x7E, quote and backslash become \xHH (uppercase)
plain             CsvException: header (line 1, offset 0): missing column "plain"
space and tilde   CsvException: header (line 1, offset 0): missing column "a ~b"
double quote      CsvException: header (line 1, offset 0): missing column "a\x22b"
backslash         CsvException: header (line 1, offset 0): missing column "a\x5Cb"
both              CsvException: header (line 1, offset 0): missing column "\x22\x5C"
LF                CsvException: header (line 1, offset 0): missing column "a\x0Ab"
tab               CsvException: header (line 1, offset 0): missing column "a\x09b"
DEL               CsvException: header (line 1, offset 0): missing column "a\x7Fb"
NUL               CsvException: header (line 1, offset 0): missing column "a\x00b"
e acute           CsvException: header (line 1, offset 0): missing column "caf\xC3\xA9"
emoji             CsvException: header (line 1, offset 0): missing column "\xF0\x9F\x98\x80"
slash             CsvException: header (line 1, offset 0): missing column "a/b"
percent           CsvException: header (line 1, offset 0): missing column "a%b"
-- truncation after 64 source bytes, '...' only when bytes were cut
plain 63 bytes    CsvException: header (line 1, offset 0): missing column "nnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnn"
plain 64 bytes    CsvException: header (line 1, offset 0): missing column "nnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnn"
plain 65 bytes    CsvException: header (line 1, offset 0): missing column "nnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnn..."
plain 100 bytes   CsvException: header (line 1, offset 0): missing column "nnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnn..."
quotes 63 bytes   CsvException: header (line 1, offset 0): missing column "\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22"
quotes 64 bytes   CsvException: header (line 1, offset 0): missing column "\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22"
quotes 65 bytes   CsvException: header (line 1, offset 0): missing column "\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22..."
multibyte cut     CsvException: header (line 1, offset 0): missing column "\xC3\xA9\xC3\xA9\xC3\xA9\xC3\xA9\xC3\xA9\xC3\xA9\xC3\xA9\xC3\xA9\xC3\xA9\xC3\xA9\xC3\xA9\xC3\xA9\xC3\xA9\xC3\xA9\xC3\xA9\xC3\xA9\xC3\xA9\xC3\xA9\xC3\xA9\xC3\xA9\xC3\xA9\xC3\xA9\xC3\xA9\xC3\xA9\xC3\xA9\xC3\xA9\xC3\xA9\xC3\xA9\xC3\xA9\xC3\xA9\xC3\xA9\xC3\xA9..."
name in value errorCsvException: row 1 (line 2, offset 7): column "q\x22w": expected int64, got malformed value
name in utf8 errorCsvException: row 1 (line 2, offset 2): column "n": invalid UTF-8
long name, value errorCsvException: row 1 (line 2, offset 71): column "nnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnn...": expected int64, got malformed value
reference disagreements: 0
