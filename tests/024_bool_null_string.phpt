--TEST--
batchflow: bool grammar, NULL for every type, quoted empty field per type, NUL bytes in string and numeric columns
--EXTENSIONS--
batchflow
--FILE--
<?php
require __DIR__ . '/bf_helper.inc';

$diffs = 0;
function g(string $label, string $csv, array $schema, array $preds = [], string $term = 'count', ?string $col = null): void
{
    global $diffs;
    $r = bf_exec($csv, $schema, [], $preds, $term, $col);
    if ($r !== bf_ref($csv, $schema, [], $preds, $term, $col)) {
        $diffs++;
        $r .= '   [DIFFERS FROM REFERENCE]';
    }
    echo str_pad($label, 44), $r, "\n";
}
$B = ['b' => 'bool'];

echo "-- bool accepted: counted as true / false\n";
foreach (['true', 'false', '"true"', '"false"'] as $v) {
    g("bool $v = true", "b\n$v\n", $B, [['eq', 'b', true]]);
    g("bool $v = false", "b\n$v\n", $B, [['eq', 'b', false]]);
}
echo "-- bool rejected\n";
foreach (['TRUE', 'True', 'FALSE', '1', '0', 't', 'f', 'yes', 'no', 'on', ' true', 'true ', 'tru', 'truee', 'null', '-1', '"1"', '"TRUE"', '"tr""ue"', '"true"""'] as $v) {
    g("bool $v", "b\n$v\n", $B, [['eq', 'b', null]]);
}
g('bool quoted empty', "b\n\"\"\n", $B, [['eq', 'b', null]]);

echo "-- NULL: an unquoted empty field, for every type\n";
$all = ['i' => 'int64', 'f' => 'float64', 'b' => 'bool', 's' => 'string'];
foreach ($all as $c => $t) {
    g("one column $t: blank line", "$c\n\n", [$c => $t], [['eq', $c, null]]);
    g("one column $t: blank line is not equal", "$c\n\n", [$c => $t], [['eq', $c, $t === 'string' ? '' : ($t === 'bool' ? false : ($t === 'int64' ? 0 : 0.0))]]);
}
g('all four NULL in a row', "i,f,b,s\n,,,\n", $all, [['eq', 'i', null], ['eq', 'f', null], ['eq', 'b', null], ['eq', 's', null]]);
g('NULL next to values', "i,f,b,s\n1,,true,\n,2.5,,x\n", $all, [['eq', 'f', null]]);
g('sum ignores NULL (int64)', "i,s\n1,a\n,b\n3,c\n", ['i' => 'int64', 's' => 'string'], [], 'sum', 'i');
g('sum ignores NULL (float64)', "f\n1.5\n\n2.5\n", ['f' => 'float64'], [], 'sum', 'f');
g('NULL last field, no terminator', "i,s\n1,", ['i' => 'int64', 's' => 'string'], [['eq', 's', null]]);
g('NULL first field', "s,i\n,1\n", ['i' => 'int64', 's' => 'string'], [['eq', 's', null]]);

echo "-- quoted empty field per type\n";
foreach ($all as $c => $t) {
    g("quoted empty $t", "$c\n\"\"\n", [$c => $t], [['eq', $c, null]]);
}
g('quoted empty string is the empty string', "s\n\"\"\n", ['s' => 'string'], [['eq', 's', '']]);
g('quoted empty string is not NULL', "s\n\"\"\n", ['s' => 'string'], [['eq', 's', null]]);
g('NULL is not the empty string', "s\n\n", ['s' => 'string'], [['eq', 's', '']]);
g('mixed NULL and empty strings', "s\n\n\"\"\nx\n\n", ['s' => 'string'], [['eq', 's', '']]);
g('mixed NULL and empty strings (IS NULL)', "s\n\n\"\"\nx\n\n", ['s' => 'string'], [['eq', 's', null]]);

echo "-- NUL bytes\n";
g('NUL in a string value', "s\n\"a\0b\"\n", ['s' => 'string'], [['eq', 's', "a\0b"]]);
g('NUL in an unquoted string value', "s\na\0b\n", ['s' => 'string'], [['eq', 's', "a\0b"]]);
g('NUL does not equal the prefix', "s\na\0b\n", ['s' => 'string'], [['eq', 's', 'a']]);
g('NUL-only string', "s\n\0\n", ['s' => 'string'], [['eq', 's', "\0"]]);
g('NUL in an unreferenced string column', "s,n\n\0,1\n", ['s' => 'string', 'n' => 'int64'], [], 'sum', 'n');
g('NUL in an int64', "i\n1\0\n", ['i' => 'int64'], [], 'sum', 'i');
g('NUL only, int64', "i\n\0\n", ['i' => 'int64'], [], 'sum', 'i');
g('NUL in a float64', "f\n1\0\n", ['f' => 'float64'], [], 'sum', 'f');
g('NUL in the middle of a float64', "f\n1\0.5\n", ['f' => 'float64'], [], 'sum', 'f');
g('NUL in a bool', "b\ntrue\0\n", $B, [['eq', 'b', null]]);
g('NUL in a quoted int64', "i\n\"1\0\"\n", ['i' => 'int64'], [], 'sum', 'i');
g('NUL in a header name', "a\0b\n7\n", ["a\0b" => 'int64'], [], 'sum', "a\0b");
echo "reference disagreements: $diffs\n";
?>
--EXPECT--
-- bool accepted: counted as true / false
bool true = true                            int(1)
bool true = false                           int(0)
bool false = true                           int(0)
bool false = false                          int(1)
bool "true" = true                          int(1)
bool "true" = false                         int(0)
bool "false" = true                         int(0)
bool "false" = false                        int(1)
-- bool rejected
bool TRUE                                   CsvException: row 1 (line 2, offset 2): column "b": expected bool, got malformed value
bool True                                   CsvException: row 1 (line 2, offset 2): column "b": expected bool, got malformed value
bool FALSE                                  CsvException: row 1 (line 2, offset 2): column "b": expected bool, got malformed value
bool 1                                      CsvException: row 1 (line 2, offset 2): column "b": expected bool, got malformed value
bool 0                                      CsvException: row 1 (line 2, offset 2): column "b": expected bool, got malformed value
bool t                                      CsvException: row 1 (line 2, offset 2): column "b": expected bool, got malformed value
bool f                                      CsvException: row 1 (line 2, offset 2): column "b": expected bool, got malformed value
bool yes                                    CsvException: row 1 (line 2, offset 2): column "b": expected bool, got malformed value
bool no                                     CsvException: row 1 (line 2, offset 2): column "b": expected bool, got malformed value
bool on                                     CsvException: row 1 (line 2, offset 2): column "b": expected bool, got malformed value
bool  true                                  CsvException: row 1 (line 2, offset 2): column "b": expected bool, got malformed value
bool true                                   CsvException: row 1 (line 2, offset 2): column "b": expected bool, got malformed value
bool tru                                    CsvException: row 1 (line 2, offset 2): column "b": expected bool, got malformed value
bool truee                                  CsvException: row 1 (line 2, offset 2): column "b": expected bool, got malformed value
bool null                                   CsvException: row 1 (line 2, offset 2): column "b": expected bool, got malformed value
bool -1                                     CsvException: row 1 (line 2, offset 2): column "b": expected bool, got malformed value
bool "1"                                    CsvException: row 1 (line 2, offset 2): column "b": expected bool, got malformed value
bool "TRUE"                                 CsvException: row 1 (line 2, offset 2): column "b": expected bool, got malformed value
bool "tr""ue"                               CsvException: row 1 (line 2, offset 2): column "b": expected bool, got malformed value
bool "true"""                               CsvException: row 1 (line 2, offset 2): column "b": expected bool, got malformed value
bool quoted empty                           CsvException: row 1 (line 2, offset 2): column "b": expected bool, got quoted empty field
-- NULL: an unquoted empty field, for every type
one column int64: blank line                int(1)
one column int64: blank line is not equal   int(0)
one column float64: blank line              int(1)
one column float64: blank line is not equal int(0)
one column bool: blank line                 int(1)
one column bool: blank line is not equal    int(0)
one column string: blank line               int(1)
one column string: blank line is not equal  int(0)
all four NULL in a row                      int(1)
NULL next to values                         int(1)
sum ignores NULL (int64)                    int(4)
sum ignores NULL (float64)                  float(4.0)
NULL last field, no terminator              int(1)
NULL first field                            int(1)
-- quoted empty field per type
quoted empty int64                          CsvException: row 1 (line 2, offset 2): column "i": expected int64, got quoted empty field
quoted empty float64                        CsvException: row 1 (line 2, offset 2): column "f": expected float64, got quoted empty field
quoted empty bool                           CsvException: row 1 (line 2, offset 2): column "b": expected bool, got quoted empty field
quoted empty string                         int(0)
quoted empty string is the empty string     int(1)
quoted empty string is not NULL             int(0)
NULL is not the empty string                int(0)
mixed NULL and empty strings                int(1)
mixed NULL and empty strings (IS NULL)      int(2)
-- NUL bytes
NUL in a string value                       int(1)
NUL in an unquoted string value             int(1)
NUL does not equal the prefix               int(0)
NUL-only string                             int(1)
NUL in an unreferenced string column        int(1)
NUL in an int64                             CsvException: row 1 (line 2, offset 2): column "i": expected int64, got malformed value
NUL only, int64                             CsvException: row 1 (line 2, offset 2): column "i": expected int64, got malformed value
NUL in a float64                            CsvException: row 1 (line 2, offset 2): column "f": expected float64, got malformed value
NUL in the middle of a float64              CsvException: row 1 (line 2, offset 2): column "f": expected float64, got malformed value
NUL in a bool                               CsvException: row 1 (line 2, offset 2): column "b": expected bool, got malformed value
NUL in a quoted int64                       CsvException: row 1 (line 2, offset 2): column "i": expected int64, got malformed value
NUL in a header name                        int(7)
reference disagreements: 0
