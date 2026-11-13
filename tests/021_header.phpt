--TEST--
batchflow: header handling - order, errors and precedence, 4096/4097 fields, extra columns, quoted names, BOM, integer-like names
--EXTENSIONS--
batchflow
--FILE--
<?php
require __DIR__ . '/bf_helper.inc';

use BatchFlow\Csv;

$diffs = 0;
// The rules do not say whether a header error after a BOM reports offset 0 or 3; normalize it.
function n(string $csv, string $r): string
{
    return str_contains($csv, "\xEF\xBB\xBF") ? preg_replace('/(header \(line 1, offset )\d+/', '$1N', $r) : $r;
}
function h(string $label, string $csv, array $schema, array $opts = [], array $preds = [], string $term = 'count', ?string $col = null): void
{
    global $diffs;
    $r = n($csv, bf_exec($csv, $schema, $opts, $preds, $term, $col));
    if ($r !== n($csv, bf_ref($csv, $schema, $opts, $preds, $term, $col))) {
        $diffs++;
        $r .= '   [DIFFERS FROM REFERENCE]';
    }
    echo str_pad($label, 44), $r, "\n";
}
$AB = ['a' => 'int64', 'b' => 'string'];

echo "-- column order in the file is free\n";
h('b,a order, sum a', "b,a\nx,1\ny,2\n", $AB, [], [], 'sum', 'a');
h('b,a order, b = y, sum a', "b,a\nx,1\ny,2\n", $AB, [], [['eq', 'b', 'y']], 'sum', 'a');
h('same order', "a,b\nx,1\n", $AB);
h('header only', "b,a", $AB);
h('header only with extra columns', "b,zz,a\n", $AB, ['allowExtraColumns' => true]);

echo "-- header errors and their precedence\n";
h('duplicate', "a,a,b\n", $AB);
h('unexpected', "a,b,c\n", $AB);
h('unexpected, first position', "c,a,b\n", $AB);
h('missing', "a\n", $AB);
h('empty header field is unexpected', "a,\n", $AB);
h('unexpected before duplicate (left to right)', "c,a,a\n", $AB);
h('duplicate before unexpected (left to right)', "a,a,c\n", $AB);
h('unexpected before missing', "c\n", $AB);
h('missing, in schema order (extras allowed)', "c\n", $AB, ['allowExtraColumns' => true]);
h('missing b only (extras allowed)', "a,c\n", $AB, ['allowExtraColumns' => true]);
h('duplicate schema column with extras allowed', "a,a,b\n", $AB, ['allowExtraColumns' => true]);
h('case-sensitive', "A,b\n", $AB);
h('framing before column checks (bare quote)', "a,a\"\n", $AB);
h('framing before column checks (bare CR)', "a,a\rb\n", $AB);
h('framing before column checks (unterminated)', "a,\"b", $AB);
h('header row size beats column checks', "a,a,b\n", $AB, ['maxRowBytes' => 3]);
h('header with spaces is not trimmed', "a, b\n", $AB);
h('header blank line', "\n", ['a' => 'int64']);
h('header blank line, extras allowed', "\n", ['a' => 'int64'], ['allowExtraColumns' => true]);
h('empty input', '', $AB);
h('BOM only', "\xEF\xBB\xBF", $AB);

echo "-- 4096 and 4097 fields\n";
$cols = static fn(int $n): string => 'a' . str_repeat(',x', $n - 1);
h('4096 fields, extras allowed', $cols(4096) . "\n", ['a' => 'int64'], ['allowExtraColumns' => true]);
h('4096 fields, extras not allowed', $cols(4096) . "\n", ['a' => 'int64']);
h('4097 fields, extras allowed', $cols(4097) . "\n", ['a' => 'int64'], ['allowExtraColumns' => true]);
h('4097 fields, extras not allowed', $cols(4097) . "\n", ['a' => 'int64']);
h('4097 fields, no terminator', $cols(4097), ['a' => 'int64'], ['allowExtraColumns' => true]);
h('4096 fields with a data row', $cols(4096) . "\n1" . str_repeat(',', 4095) . "\n", ['a' => 'int64'], ['allowExtraColumns' => true]);

echo "-- allowExtraColumns\n";
$E = ['a' => 'int64', 'b' => 'int64'];
h('repeated and empty-named extras', "a,x,x,,b\n1,p,q,r,2\n", $E, ['allowExtraColumns' => true], [], 'sum', 'b');
h('extras never appear in results', "x,a,y,b\n\"q\",1,\"\xff\",2\n", $E, ['allowExtraColumns' => true], [], 'sum', 'a');
h('extras still get framing checks', "a,x,b\n1,\"q\"z,2\n", $E, ['allowExtraColumns' => true]);
h('extras get exact field count', "a,x,b\n1,2\n", $E, ['allowExtraColumns' => true]);

echo "-- quoted and doubled-quote names\n";
h('quoted name', "\"a\",\"b\"\n1,2\n", $E, [], [], 'sum', 'b');
h('doubled-quote name', "\"say \"\"hi\"\"\"\n5\n", ['say "hi"' => 'int64'], [], [], 'sum', 'say "hi"');
h('name with comma', "\"a,b\"\n5\n", ['a,b' => 'int64'], [], [], 'sum', 'a,b');
h('name with newline', "\"a\nb\"\n5\n", ["a\nb" => 'int64'], [], [], 'sum', "a\nb");
h('name with newline, error line count', "\"a\nb\"\nx\n", ["a\nb" => 'int64'], [], [], 'sum', "a\nb");
h('quoted and unquoted are the same name', "\"a\",a\n", $E);

echo "-- BOM\n";
$bom = "\xEF\xBB\xBF";
h('BOM skipped', $bom . "a\n1\n", ['a' => 'int64'], [], [], 'sum', 'a');
h('BOM counts toward offsets', $bom . "a\nx\n", ['a' => 'int64'], [], [], 'sum', 'a');
h('second BOM is data (name mismatch)', $bom . $bom . "a\n1\n", ['a' => 'int64']);
h('second BOM is data (name matches)', $bom . $bom . "a\n1\n", ["\u{FEFF}a" => 'int64'], [], [], 'sum', "\u{FEFF}a");
h('BOM not at byte 0 is data', "a\n" . $bom . "1\n", ['a' => 'int64'], [], [], 'sum', 'a');
h('BOM before quoted header', $bom . "\"a\"\n1\n", ['a' => 'int64'], [], [], 'sum', 'a');
h('BOM then header at row-size limit', $bom . "abc\n", ['abc' => 'int64'], ['maxRowBytes' => 3]);
foreach ([1, 2, 3, 5] as $max) {
    foreach ([[$bom . "a,b\n1,x\n2,y\n", 'BOM split'], [$bom . $bom . "a\n", 'two BOMs split']] as [$csv, $l]) {
        $schema = $l === 'BOM split' ? $AB : ['a' => 'int64'];
        $q = Csv::scanStream(bf_wrap_stream($csv, $max), $schema);
        $got = n($csv, bf_try(fn() => $q->count()));
        $want = n($csv, bf_ref($csv, $schema));
        echo str_pad("$l, reads of $max byte(s)", 44), $got, $got === $want ? '' : "   [DIFFERS FROM REFERENCE: $want]", "\n";
    }
}

echo "-- integer-like names\n";
h('2024 and 5 headers', "2024,5\n1.5,7\n2.5,8\n", ['2024' => 'float64', 5 => 'int64'], [], [], 'sum', '2024');
h('integer key, sum by string name', "2024,5\n1.5,7\n", ['2024' => 'float64', 5 => 'int64'], [], [['eq', '5', 7]], 'sum', '2024');
h('negative integer name', "-5\n3\n", [-5 => 'int64'], [], [], 'sum', '-5');
h('leading zero name is text', "05\n3\n", ['05' => 'int64'], [], [], 'sum', '05');
echo "reference disagreements: $diffs\n";
?>
--EXPECT--
-- column order in the file is free
b,a order, sum a                            int(3)
b,a order, b = y, sum a                     int(2)
same order                                  int(1)
header only                                 int(0)
header only with extra columns              int(0)
-- header errors and their precedence
duplicate                                   CsvException: header (line 1, offset 0): duplicate column "a"
unexpected                                  CsvException: header (line 1, offset 0): unexpected column at position 3
unexpected, first position                  CsvException: header (line 1, offset 0): unexpected column at position 1
missing                                     CsvException: header (line 1, offset 0): missing column "b"
empty header field is unexpected            CsvException: header (line 1, offset 0): unexpected column at position 2
unexpected before duplicate (left to right) CsvException: header (line 1, offset 0): unexpected column at position 1
duplicate before unexpected (left to right) CsvException: header (line 1, offset 0): duplicate column "a"
unexpected before missing                   CsvException: header (line 1, offset 0): unexpected column at position 1
missing, in schema order (extras allowed)   CsvException: header (line 1, offset 0): missing column "a"
missing b only (extras allowed)             CsvException: header (line 1, offset 0): missing column "b"
duplicate schema column with extras allowed CsvException: header (line 1, offset 0): duplicate column "a"
case-sensitive                              CsvException: header (line 1, offset 0): unexpected column at position 1
framing before column checks (bare quote)   CsvException: header (line 1, offset 0): bare quote in unquoted field
framing before column checks (bare CR)      CsvException: header (line 1, offset 0): bare CR
framing before column checks (unterminated) CsvException: header (line 1, offset 0): unterminated quoted field
header row size beats column checks         LimitException: header (line 1, offset 0): row exceeds maxRowBytes
header with spaces is not trimmed           CsvException: header (line 1, offset 0): unexpected column at position 2
header blank line                           CsvException: header (line 1, offset 0): unexpected column at position 1
header blank line, extras allowed           CsvException: header (line 1, offset 0): missing column "a"
empty input                                 CsvException: missing header
BOM only                                    CsvException: missing header
-- 4096 and 4097 fields
4096 fields, extras allowed                 int(0)
4096 fields, extras not allowed             CsvException: header (line 1, offset 0): unexpected column at position 2
4097 fields, extras allowed                 CsvException: header (line 1, offset 0): too many columns (limit 4096)
4097 fields, extras not allowed             CsvException: header (line 1, offset 0): too many columns (limit 4096)
4097 fields, no terminator                  CsvException: header (line 1, offset 0): too many columns (limit 4096)
4096 fields with a data row                 int(1)
-- allowExtraColumns
repeated and empty-named extras             int(2)
extras never appear in results              int(1)
extras still get framing checks             CsvException: row 1 (line 2, offset 6): text after closing quote
extras get exact field count                CsvException: row 1 (line 2, offset 6): expected 3 fields, got 2
-- quoted and doubled-quote names
quoted name                                 int(2)
doubled-quote name                          int(5)
name with comma                             int(5)
name with newline                           int(5)
name with newline, error line count         CsvException: row 1 (line 3, offset 6): column "a\x0Ab": expected int64, got malformed value
quoted and unquoted are the same name       CsvException: header (line 1, offset 0): duplicate column "a"
-- BOM
BOM skipped                                 int(1)
BOM counts toward offsets                   CsvException: row 1 (line 2, offset 5): column "a": expected int64, got malformed value
second BOM is data (name mismatch)          CsvException: header (line 1, offset N): unexpected column at position 1
second BOM is data (name matches)           int(1)
BOM not at byte 0 is data                   CsvException: row 1 (line 2, offset 2): column "a": expected int64, got malformed value
BOM before quoted header                    int(1)
BOM then header at row-size limit           int(0)
BOM split, reads of 1 byte(s)               int(2)
two BOMs split, reads of 1 byte(s)          CsvException: header (line 1, offset N): unexpected column at position 1
BOM split, reads of 2 byte(s)               int(2)
two BOMs split, reads of 2 byte(s)          CsvException: header (line 1, offset N): unexpected column at position 1
BOM split, reads of 3 byte(s)               int(2)
two BOMs split, reads of 3 byte(s)          CsvException: header (line 1, offset N): unexpected column at position 1
BOM split, reads of 5 byte(s)               int(2)
two BOMs split, reads of 5 byte(s)          CsvException: header (line 1, offset N): unexpected column at position 1
-- integer-like names
2024 and 5 headers                          float(4.0)
integer key, sum by string name             float(1.5)
negative integer name                       int(3)
leading zero name is text                   int(3)
reference disagreements: 0
