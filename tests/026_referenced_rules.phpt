--TEST--
batchflow: only referenced columns are validated, non-matching rows are validated too, schema-order error selection
--EXTENSIONS--
batchflow
--FILE--
<?php
require __DIR__ . '/bf_helper.inc';

$diffs = 0;
function r(string $label, string $csv, array $schema, array $opts = [], array $preds = [], string $term = 'count', ?string $col = null): void
{
    global $diffs;
    $x = bf_exec($csv, $schema, $opts, $preds, $term, $col);
    if ($x !== bf_ref($csv, $schema, $opts, $preds, $term, $col)) {
        $diffs++;
        $x .= '   [DIFFERS FROM REFERENCE]';
    }
    echo str_pad($label, 52), $x, "\n";
}
$S = ['id' => 'int64', 's' => 'string', 'f' => 'float64'];
$csv = "id,s,f\n1,a,1.5\n2,b,notafloat\n3,\xff,2.5\n";

echo "-- a bad value in an unreferenced column raises nothing\n";
r('count()', $csv, $S);
r('sum(id)', $csv, $S, [], [], 'sum', 'id');
r('whereEq(id)->count()', $csv, $S, [], [['eq', 'id', 2]]);
r('whereGt(id)->sum(id)', $csv, $S, [], [['gt', 'id', 0]], 'sum', 'id');
r('summary(id) rows scanned', $csv, $S, [], [], 'summary', 'id');
r('summary() rows scanned', $csv, $S, [], [], 'summary');

echo "-- the same value raises as soon as the column is referenced\n";
r('sum(f)', $csv, $S, [], [], 'sum', 'f');
r('whereEq(f, 1.5)->count()', $csv, $S, [], [['eq', 'f', 1.5]]);
r('whereGt(f, 100.0)->count()', $csv, $S, [], [['gt', 'f', 100.0]]);
r('whereEq(s, a)->count()', $csv, $S, [], [['eq', 's', 'a']]);
r('whereEq(s, null)->sum(id)', $csv, $S, [], [['eq', 's', null]], 'sum', 'id');

echo "-- a row that does not match is still validated\n";
r('no row matches id=999, f is bad in row 2', $csv, $S, [], [['eq', 'id', 999]], 'sum', 'f');
r('earlier predicate fails, later column is bad', "id,f\n1,2.5\n2,bad\n", ['id' => 'int64', 'f' => 'float64'], [], [['eq', 'id', 99], ['gt', 'f', 0]]);
r('later row that cannot match is validated', "id,f\n1,2.5\n99,bad\n", ['id' => 'int64', 'f' => 'float64'], [], [['eq', 'id', 1], ['gt', 'f', 0]]);

echo "-- errors are selected in schema order, not predicate or reference order\n";
$T = "id,s,f\nbad,\xff,bad\n";
r('id and f bad, f referenced first', $T, $S, [], [['gt', 'f', 0.0], ['gt', 'id', 0]]);
r('id and f bad, id referenced first', $T, $S, [], [['gt', 'id', 0], ['gt', 'f', 0.0]]);
r('s and f bad, sum(f) with whereEq(s)', "id,s,f\n1,\xff,bad\n", $S, [], [['eq', 's', 'x']], 'sum', 'f');
r('s and f bad, f first then s', "id,s,f\n1,\xff,bad\n", $S, [], [['gt', 'f', 0.0], ['eq', 's', 'x']]);
r('predicate order does not change the error (1)', "id,f\nx,y\n", ['id' => 'int64', 'f' => 'float64'], [], [['eq', 'f', 1.0], ['eq', 'id', 1]]);
r('predicate order does not change the error (2)', "id,f\nx,y\n", ['id' => 'int64', 'f' => 'float64'], [], [['eq', 'id', 1], ['eq', 'f', 1.0]]);
r('column order in the file does not matter', "f,id\ny,x\n", ['id' => 'int64', 'f' => 'float64'], [], [['eq', 'f', 1.0], ['eq', 'id', 1]]);

echo "-- field size, then value, per column in schema order\n";
$O = ['maxFieldBytes' => 4];
$S2 = ['a' => 'int64', 'b' => 'string'];
r('too long and invalid: size is reported', "a,b\n123456789x,x\n", $S2, $O, [], 'sum', 'a');
r('earlier column invalid beats later column too long', "a,b\nx,toolongvalue\n", $S2, $O, [['eq', 'b', 'q']], 'sum', 'a');
r('earlier column too long beats later invalid value', "a,b\n123456,\n", ['a' => 'int64', 'b' => 'int64'], $O, [['eq', 'b', 1]], 'sum', 'a');
r('maxFieldBytes only for referenced columns', "a,b\n1,toolongvalue\n", $S2, $O, [], 'sum', 'a');
r('maxFieldBytes for a referenced column', "a,b\n1,toolongvalue\n", $S2, $O, [['eq', 'b', 'x']], 'sum', 'a');
r('maxFieldBytes for a referenced column (quoted)', "a,b\n1,\"toolongvalue\"\n", $S2, $O, [['eq', 'b', 'x']], 'sum', 'a');
r('long field in an extra column', "a,b,x\n1,ok,toolongvalue\n", $S2, $O + ['allowExtraColumns' => true], [['eq', 'b', 'ok']], 'sum', 'a');
r('invalid UTF-8 in an extra column', "a,b,x\n1,ok,\xff\n", $S2, ['allowExtraColumns' => true], [['eq', 'b', 'ok']], 'sum', 'a');

echo "-- framing and maxRowBytes apply to every column\n";
r('quote error in an unreferenced column', "id,s,f\n1,a\"x,2\n", $S, [], [], 'sum', 'id');
r('quote error in an extra column', "a,x\n1,\"q\"z\n", ['a' => 'int64'], ['allowExtraColumns' => true]);
r('field count in an unreferenced column', "id,s,f\n1,a\n", $S, [], [], 'sum', 'id');
r('maxRowBytes counts unreferenced columns', "id,s\n1,abcdefghij\n", ['id' => 'int64', 's' => 'string'], ['maxRowBytes' => 6], [], 'sum', 'id');
r('maxRowBytes counts extra columns', "a,x\n1,abcdefghij\n", ['a' => 'int64'], ['maxRowBytes' => 6, 'allowExtraColumns' => true]);
echo "reference disagreements: $diffs\n";
?>
--EXPECT--
-- a bad value in an unreferenced column raises nothing
count()                                             int(3)
sum(id)                                             int(6)
whereEq(id)->count()                                int(1)
whereGt(id)->sum(id)                                int(6)
summary(id) rows scanned                            Summary{scanned=3;matched=3;sum=int(6)}
summary() rows scanned                              Summary{scanned=3;matched=3;sum=NULL}
-- the same value raises as soon as the column is referenced
sum(f)                                              CsvException: row 2 (line 3, offset 15): column "f": expected float64, got malformed value
whereEq(f, 1.5)->count()                            CsvException: row 2 (line 3, offset 15): column "f": expected float64, got malformed value
whereGt(f, 100.0)->count()                          CsvException: row 2 (line 3, offset 15): column "f": expected float64, got malformed value
whereEq(s, a)->count()                              CsvException: row 3 (line 4, offset 29): column "s": invalid UTF-8
whereEq(s, null)->sum(id)                           CsvException: row 3 (line 4, offset 29): column "s": invalid UTF-8
-- a row that does not match is still validated
no row matches id=999, f is bad in row 2            CsvException: row 2 (line 3, offset 15): column "f": expected float64, got malformed value
earlier predicate fails, later column is bad        CsvException: row 2 (line 3, offset 11): column "f": expected float64, got malformed value
later row that cannot match is validated            CsvException: row 2 (line 3, offset 11): column "f": expected float64, got malformed value
-- errors are selected in schema order, not predicate or reference order
id and f bad, f referenced first                    CsvException: row 1 (line 2, offset 7): column "id": expected int64, got malformed value
id and f bad, id referenced first                   CsvException: row 1 (line 2, offset 7): column "id": expected int64, got malformed value
s and f bad, sum(f) with whereEq(s)                 CsvException: row 1 (line 2, offset 7): column "s": invalid UTF-8
s and f bad, f first then s                         CsvException: row 1 (line 2, offset 7): column "s": invalid UTF-8
predicate order does not change the error (1)       CsvException: row 1 (line 2, offset 5): column "id": expected int64, got malformed value
predicate order does not change the error (2)       CsvException: row 1 (line 2, offset 5): column "id": expected int64, got malformed value
column order in the file does not matter            CsvException: row 1 (line 2, offset 5): column "id": expected int64, got malformed value
-- field size, then value, per column in schema order
too long and invalid: size is reported              LimitException: row 1 (line 2, offset 4): column "a": field exceeds maxFieldBytes
earlier column invalid beats later column too long  CsvException: row 1 (line 2, offset 4): column "a": expected int64, got malformed value
earlier column too long beats later invalid value   LimitException: row 1 (line 2, offset 4): column "a": field exceeds maxFieldBytes
maxFieldBytes only for referenced columns           int(1)
maxFieldBytes for a referenced column               LimitException: row 1 (line 2, offset 4): column "b": field exceeds maxFieldBytes
maxFieldBytes for a referenced column (quoted)      LimitException: row 1 (line 2, offset 4): column "b": field exceeds maxFieldBytes
long field in an extra column                       int(1)
invalid UTF-8 in an extra column                    int(1)
-- framing and maxRowBytes apply to every column
quote error in an unreferenced column               CsvException: row 1 (line 2, offset 7): bare quote in unquoted field
quote error in an extra column                      CsvException: row 1 (line 2, offset 4): text after closing quote
field count in an unreferenced column               CsvException: row 1 (line 2, offset 7): expected 3 fields, got 2
maxRowBytes counts unreferenced columns             LimitException: row 1 (line 2, offset 5): row exceeds maxRowBytes
maxRowBytes counts extra columns                    LimitException: row 1 (line 2, offset 4): row exceeds maxRowBytes
reference disagreements: 0
