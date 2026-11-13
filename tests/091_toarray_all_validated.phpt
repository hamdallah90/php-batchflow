--TEST--
batchflow: toArray references every schema column on every row, so a bad value that count, sum and groupCount never look at now raises
--EXTENSIONS--
batchflow
--FILE--
<?php
require __DIR__ . "/bf_helper.inc";

use BatchFlow\Csv;

function t(string $label, string $csv, array $S, array $preds = [], array $opts = [], string $term = "toArray", array $x = []): void
{
    $a = bf_gexec($csv, $S, $opts, $preds, $term, $x);
    $b = bf_gref($csv, $S, $opts, $preds, $term, $x);
    echo str_pad($label, 56), str_replace("\n", " | ", $a), $a === $b ? "" : "   [DIFFERS FROM REFERENCE: $b]", "\n";
}

$S = ['id' => 'int64', 'name' => 'string', 'amt' => 'float64', 'ok' => 'bool'];
$good = "id,name,amt,ok\n1,a,1.5,true\n";
echo "-- the same bad value: silent on a streaming terminal that does not reference it, an error on toArray\n";
foreach ([
    'int64 column' => "id,name,amt,ok\n1,a,1.5,true\nx,b,2.5,false\n",
    'float64 column' => "id,name,amt,ok\n1,a,1.5,true\n2,b,1e400,false\n",
    'bool column' => "id,name,amt,ok\n1,a,1.5,true\n2,b,2.5,TRUE\n",
    'string column' => "id,name,amt,ok\n1,a,1.5,true\n2,\xFF,2.5,false\n",
    'quoted empty in int64' => "id,name,amt,ok\n1,a,1.5,true\n\"\",b,2.5,false\n",
] as $label => $csv) {
    $col = ['int64 column' => 'id', 'float64 column' => 'amt', 'bool column' => 'ok', 'string column' => 'name', 'quoted empty in int64' => 'id'][$label];
    $other = $col === 'amt' ? 'id' : 'amt';
    $q = bf_q($csv, $S);
    echo str_pad("$label: count()", 40), bf_try(fn() => $q->count()), "\n";
    if ($col !== 'id') {
        echo str_pad("$label: sum('id')", 40), bf_try(fn() => $q->sum('id')), "\n";
    }
    echo str_pad("$label: toArray()", 40), bf_try(fn() => $q->toArray()), "\n";
}
echo "-- a row that no predicate matches is still validated\n";
t('bad value in a non-matching row', "id,name,amt,ok\n1,a,1.5,true\n2,b,zz,false\n3,c,3.5,true\n", $S, [['eq', 'id', 1]]);
$a = bf_exec("id,name,amt,ok\n1,a,1.5,true\n2,b,zz,false\n", $S, [], [['eq', 'id', 1]], 'sum', 'id');
$b = bf_ref("id,name,amt,ok\n1,a,1.5,true\n2,b,zz,false\n", $S, [], [['eq', 'id', 1]], 'sum', 'id');
echo str_pad('same data, streaming sum(id) with whereEq(id, 1)', 56), $a, $a === $b ? '' : "   [DIFFERS FROM REFERENCE: $b]", "\n";
echo "-- maxFieldBytes applies to every column on toArray\n";
t('oversize unreferenced string column', "id,name\n1,abcdefghij\n", ['id' => 'int64', 'name' => 'string'], [], ['maxFieldBytes' => 5]);
t('groupCount on id does not look at it', "id,name\n1,abcdefghij\n", ['id' => 'int64', 'name' => 'string'], [], ['maxFieldBytes' => 5], 'groupCount', ['key' => 'id']);
t('exactly at maxFieldBytes is fine', "id,name\n1,abcde\n", ['id' => 'int64', 'name' => 'string'], [], ['maxFieldBytes' => 5]);
echo "-- validation follows schema order, not file order, within one row\n";
t('two bad columns, schema order id then amt', "amt,id\nzz,yy\n", ['id' => 'int64', 'amt' => 'float64']);
t('two bad columns, schema order amt then id', "amt,id\nzz,yy\n", ['amt' => 'float64', 'id' => 'int64']);
t('oversize field before malformed value in schema order', "a,b\nzzzzzz,x\n", ['a' => 'string', 'b' => 'int64'], [], ['maxFieldBytes' => 3]);
t('malformed value before oversize field in schema order', "a,b\nxxxxxx,y\n", ['b' => 'int64', 'a' => 'string'], [], ['maxFieldBytes' => 3]);
echo "-- extra columns are framed but never validated\n";
t('bad UTF-8 and size in an extra column', "id,junk\n1,\xFF\xFF\xFF\xFF\xFF\xFF\xFF\n", ['id' => 'int64'], [], ['allowExtraColumns' => true, 'maxFieldBytes' => 3]);
t('a bare quote in an extra column is a framing error', "id,junk\n1,a\"b\n", ['id' => 'int64'], [], ['allowExtraColumns' => true]);
t('wrong field count still fails', "id,junk\n1\n", ['id' => 'int64'], [], ['allowExtraColumns' => true]);
echo "-- error context: row, line and offset of the failing row\n";
t('third row, after a quoted LF', "id,name\n1,\"a\nb\"\n2,ok\nx,y\n", ['id' => 'int64', 'name' => 'string']);
t('CRLF file', "id\r\n1\r\nx\r\n", ['id' => 'int64']);
t('record bytes never appear in the message', "id\nSECRET-PAYLOAD\n", ['id' => 'int64']);
t('...nor for a string column', "name\n\xFF\xFESECRET\n", ['name' => 'string']);
echo "-- a good file for contrast\n";
t('all columns valid', $good, $S);
?>
--EXPECT--
-- the same bad value: silent on a streaming terminal that does not reference it, an error on toArray
int64 column: count()                   int(2)
int64 column: toArray()                 CsvException: row 2 (line 3, offset 28): column "id": expected int64, got malformed value
float64 column: count()                 int(2)
float64 column: sum('id')               int(3)
float64 column: toArray()               CsvException: row 2 (line 3, offset 28): column "amt": expected float64, got out-of-range number
bool column: count()                    int(2)
bool column: sum('id')                  int(3)
bool column: toArray()                  CsvException: row 2 (line 3, offset 28): column "ok": expected bool, got malformed value
string column: count()                  int(2)
string column: sum('id')                int(3)
string column: toArray()                CsvException: row 2 (line 3, offset 28): column "name": invalid UTF-8
quoted empty in int64: count()          int(2)
quoted empty in int64: toArray()        CsvException: row 2 (line 3, offset 28): column "id": expected int64, got quoted empty field
-- a row that no predicate matches is still validated
bad value in a non-matching row                         CsvException: row 2 (line 3, offset 28): column "amt": expected float64, got malformed value
same data, streaming sum(id) with whereEq(id, 1)        int(1)
-- maxFieldBytes applies to every column on toArray
oversize unreferenced string column                     LimitException: row 1 (line 2, offset 8): column "name": field exceeds maxFieldBytes
groupCount on id does not look at it                    [int(1),int(1)]
exactly at maxFieldBytes is fine                        {id=int(1), name=s"abcde"}
-- validation follows schema order, not file order, within one row
two bad columns, schema order id then amt               CsvException: row 1 (line 2, offset 7): column "id": expected int64, got malformed value
two bad columns, schema order amt then id               CsvException: row 1 (line 2, offset 7): column "amt": expected float64, got malformed value
oversize field before malformed value in schema order   LimitException: row 1 (line 2, offset 4): column "a": field exceeds maxFieldBytes
malformed value before oversize field in schema order   CsvException: row 1 (line 2, offset 4): column "b": expected int64, got malformed value
-- extra columns are framed but never validated
bad UTF-8 and size in an extra column                   {id=int(1)}
a bare quote in an extra column is a framing error      CsvException: row 1 (line 2, offset 8): bare quote in unquoted field
wrong field count still fails                           CsvException: row 1 (line 2, offset 8): expected 2 fields, got 1
-- error context: row, line and offset of the failing row
third row, after a quoted LF                            CsvException: row 3 (line 5, offset 21): column "id": expected int64, got malformed value
CRLF file                                               CsvException: row 2 (line 3, offset 7): column "id": expected int64, got malformed value
record bytes never appear in the message                CsvException: row 1 (line 2, offset 3): column "id": expected int64, got malformed value
...nor for a string column                              CsvException: row 1 (line 2, offset 5): column "name": invalid UTF-8
-- a good file for contrast
all columns valid                                       {id=int(1), name=s"a", amt=float(1.5), ok=bool(true)}
