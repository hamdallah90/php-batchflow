--TEST--
batchflow: quoted fields keep every byte, line counting over quoted LFs, each quote error at start, middle and end of file
--EXTENSIONS--
batchflow
--FILE--
<?php
require __DIR__ . '/bf_helper.inc';

$diffs = 0;
function q(string $label, string $csv, array $schema, array $preds = [], string $term = 'count', ?string $col = null): void
{
    global $diffs;
    $r = bf_exec($csv, $schema, [], $preds, $term, $col);
    if ($r !== bf_ref($csv, $schema, [], $preds, $term, $col)) {
        $diffs++;
        $r .= '   [DIFFERS FROM REFERENCE]';
    }
    echo str_pad($label, 44), $r, "\n";
}
$S = ['s' => 'string'];
$m = fn(string $v): array => [['eq', 's', $v]];

echo "-- every byte inside quotes is data\n";
q('delimiter', "s\n\"a,b\"\n", $S, $m('a,b'));
q('LF preserved', "s\n\"a\nb\"\n", $S, $m("a\nb"));
q('LF not normalized to CRLF', "s\n\"a\nb\"\n", $S, $m("a\r\nb"));
q('CR preserved', "s\n\"a\rb\"\n", $S, $m("a\rb"));
q('CRLF preserved', "s\n\"a\r\nb\"\n", $S, $m("a\r\nb"));
q('CRLF not normalized to LF', "s\n\"a\r\nb\"\n", $S, $m("a\nb"));
q('doubled quote', "s\n\"a\"\"b\"\n", $S, $m('a"b'));
q('only a doubled quote', "s\n\"\"\"\"\n", $S, $m('"'));
q('three quotes then close', "s\n\"\"\"\"\"\"\n", $S, $m('""'));
q('quoted equals unquoted', "s\n\"abc\"\nabc\n", $S, $m('abc'));
q('quoted empty is the empty string', "s\n\"\"\nx\n", $S, $m(''));
q('quoted NUL byte', "s\n\"a\0b\"\n", $S, $m("a\0b"));
q('mixed quoted and plain fields', "a,s,b\n1,\"x\ny\",2\n", ['a' => 'int64', 's' => 'string', 'b' => 'int64'], $m("x\ny"));
q('trailing delimiter after a quoted field', "a,s\n1,\"x\",\n", ['a' => 'int64', 's' => 'string']);

echo "-- line numbers count every LF once, including those inside quotes\n";
$S2 = ['s' => 'string', 'n' => 'int64'];
q('LF inside quotes, then bad row', "s,n\n\"x\ny\nz\",1\nq,bad\n", $S2, [], 'sum', 'n');
q('CRLF inside quotes counts one line', "s,n\n\"x\r\ny\",1\nq,bad\n", $S2, [], 'sum', 'n');
q('bare CR inside quotes counts none', "s,n\n\"x\ry\",1\nq,bad\n", $S2, [], 'sum', 'n');
q('CRLF record terminators', "s,n\r\nx,1\r\ny,2\r\nq,bad\r\n", $S2, [], 'sum', 'n');
q('header with LF in name, then bad row', "\"s\n\",n\nx,bad\n", ["s\n" => 'string', 'n' => 'int64'], [], 'sum', 'n');
q('error on the row that follows two quoted rows', "s,n\n\"1\n2\",1\n\"3\n4\",2\nq,bad\n", $S2, [], 'sum', 'n');
q('field count error after quoted LFs', "s,n\n\"a\nb\"\nz,1\n", $S2);

echo "-- quote errors at the start, middle and end of the file\n";
foreach ([
    'bare quote' => ['x"y', 'x"y'],
    'text after closing quote' => ['"x"y', '"x"y'],
    'text after closing quote (space)' => ['"x" ', '"x" '],
    'quote after a space' => [' "x"', ' "x"'],
    'quote after closing quote pair' => ['"x""y"z', '"x""y"z'],
    'unterminated' => ['"xyz', '"xyz'],
    'unterminated, only a quote' => ['"', '"'],
    'closing quote then bare CR' => ['"x"' . "\rz", ''],
] as $l => [$bad]) {
    // start: in the header; middle: in the middle row; end: in the last row without a terminator
    q("$l / start", $bad . "\n1\n", ['s' => 'string']);
    q("$l / middle", "s\n1\n$bad\n2\n", ['s' => 'string']);
    q("$l / end", "s\n1\n$bad", ['s' => 'string']);
}
q('unterminated across newlines', "s\n\"abc\ndef\n", $S);
q('unterminated header', "\"s\n", $S);
q('quote opens a later field', "a,s\n1,\"x\"\n", ['a' => 'int64', 's' => 'string']);
q('quote after delimiter, unterminated', "a,s\n1,\"x\n", ['a' => 'int64', 's' => 'string']);
q('closing quote at end of input', "s\n\"x\"", $S);
q('closing quote then CRLF', "s\n\"x\"\r\n", $S);
q('closing quote then delimiter', "a,s\n\"1\",\"x\"\n", ['a' => 'int64', 's' => 'string']);
echo "reference disagreements: $diffs\n";
?>
--EXPECT--
-- every byte inside quotes is data
delimiter                                   int(1)
LF preserved                                int(1)
LF not normalized to CRLF                   int(0)
CR preserved                                int(1)
CRLF preserved                              int(1)
CRLF not normalized to LF                   int(0)
doubled quote                               int(1)
only a doubled quote                        int(1)
three quotes then close                     int(1)
quoted equals unquoted                      int(2)
quoted empty is the empty string            int(1)
quoted NUL byte                             int(1)
mixed quoted and plain fields               int(1)
trailing delimiter after a quoted field     CsvException: row 1 (line 2, offset 4): expected 2 fields, got more
-- line numbers count every LF once, including those inside quotes
LF inside quotes, then bad row              CsvException: row 2 (line 5, offset 14): column "n": expected int64, got malformed value
CRLF inside quotes counts one line          CsvException: row 2 (line 4, offset 13): column "n": expected int64, got malformed value
bare CR inside quotes counts none           CsvException: row 2 (line 3, offset 12): column "n": expected int64, got malformed value
CRLF record terminators                     CsvException: row 3 (line 4, offset 15): column "n": expected int64, got malformed value
header with LF in name, then bad row        CsvException: row 1 (line 3, offset 7): column "n": expected int64, got malformed value
error on the row that follows two quoted rowsCsvException: row 3 (line 6, offset 20): column "n": expected int64, got malformed value
field count error after quoted LFs          CsvException: row 1 (line 2, offset 4): expected 2 fields, got 1
-- quote errors at the start, middle and end of the file
bare quote / start                          CsvException: header (line 1, offset 0): bare quote in unquoted field
bare quote / middle                         CsvException: row 2 (line 3, offset 4): bare quote in unquoted field
bare quote / end                            CsvException: row 2 (line 3, offset 4): bare quote in unquoted field
text after closing quote / start            CsvException: header (line 1, offset 0): text after closing quote
text after closing quote / middle           CsvException: row 2 (line 3, offset 4): text after closing quote
text after closing quote / end              CsvException: row 2 (line 3, offset 4): text after closing quote
text after closing quote (space) / start    CsvException: header (line 1, offset 0): text after closing quote
text after closing quote (space) / middle   CsvException: row 2 (line 3, offset 4): text after closing quote
text after closing quote (space) / end      CsvException: row 2 (line 3, offset 4): text after closing quote
quote after a space / start                 CsvException: header (line 1, offset 0): bare quote in unquoted field
quote after a space / middle                CsvException: row 2 (line 3, offset 4): bare quote in unquoted field
quote after a space / end                   CsvException: row 2 (line 3, offset 4): bare quote in unquoted field
quote after closing quote pair / start      CsvException: header (line 1, offset 0): text after closing quote
quote after closing quote pair / middle     CsvException: row 2 (line 3, offset 4): text after closing quote
quote after closing quote pair / end        CsvException: row 2 (line 3, offset 4): text after closing quote
unterminated / start                        CsvException: header (line 1, offset 0): unterminated quoted field
unterminated / middle                       CsvException: row 2 (line 3, offset 4): unterminated quoted field
unterminated / end                          CsvException: row 2 (line 3, offset 4): unterminated quoted field
unterminated, only a quote / start          CsvException: header (line 1, offset 0): unterminated quoted field
unterminated, only a quote / middle         CsvException: row 2 (line 3, offset 4): unterminated quoted field
unterminated, only a quote / end            CsvException: row 2 (line 3, offset 4): unterminated quoted field
closing quote then bare CR / start          CsvException: header (line 1, offset 0): bare CR
closing quote then bare CR / middle         CsvException: row 2 (line 3, offset 4): bare CR
closing quote then bare CR / end            CsvException: row 2 (line 3, offset 4): bare CR
unterminated across newlines                CsvException: row 1 (line 2, offset 2): unterminated quoted field
unterminated header                         CsvException: header (line 1, offset 0): unterminated quoted field
quote opens a later field                   int(1)
quote after delimiter, unterminated         CsvException: row 1 (line 2, offset 4): unterminated quoted field
closing quote at end of input               int(1)
closing quote then CRLF                     int(1)
closing quote then delimiter                int(1)
reference disagreements: 0
