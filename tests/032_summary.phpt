--TEST--
batchflow: summary() - all fields in one pass, sum column handling, Summary is readonly, cloneable and serializable
--EXTENSIONS--
batchflow
--FILE--
<?php
require __DIR__ . '/bf_helper.inc';

use BatchFlow\{Csv, Summary};

function s(string $label, string $csv, array $S, array $preds = [], ?string $col = null): void
{
    $a = bf_exec($csv, $S, [], $preds, 'summary', $col);
    $b = bf_ref($csv, $S, [], $preds, 'summary', $col);
    echo str_pad($label, 46), $a, $a === $b ? '' : "   [DIFFERS FROM REFERENCE: $b]", "\n";
}
$S = ['i' => 'int64', 'f' => 'float64', 's' => 'string'];
$csv = "i,f,s\n1,1.5,a\n2,2.5,b\n3,,a\n,4.5,c\n5,5.5,a\n";

echo "-- fields\n";
s('no sum column: sum is null', $csv, $S);
s('sum of int64', $csv, $S, [], 'i');
s('sum of float64', $csv, $S, [], 'f');
s('predicate: matched < scanned', $csv, $S, [['eq', 's', 'a']], 'i');
s('predicate, float sum', $csv, $S, [['gt', 'i', 1]], 'f');
s('no row matches: int sum is int 0', $csv, $S, [['eq', 's', 'zz']], 'i');
s('no row matches: float sum is float 0.0', $csv, $S, [['eq', 's', 'zz']], 'f');
s('no row matches, no sum column', $csv, $S, [['eq', 's', 'zz']]);
s('header only, no sum column', "i,f,s\n", $S);
s('header only, int sum column', "i,f,s\n", $S, [], 'i');
s('header only, float sum column', "i,f,s\n", $S, [], 'f');
s('all-NULL sum column', "i,f,s\n,,\n,,\n", $S, [], 'i');
s('NULL rows are scanned and matched', "i,f,s\n,,\n,,\n", $S, [['eq', 'i', null]], 'f');
s('rowsScanned counts every data row', "i,f,s\n1,1,a\n2,2,b\n3,3,c\n4,4,d\n", $S, [['gt', 'i', 3]]);
s('summary equals count()', $csv, $S, [['gt', 'i', 1]]);

echo "-- errors\n";
s('string sum column', $csv, $S, [], 's');
s('unknown sum column', $csv, $S, [], 'zz');
s('int64 overflow', "v\n9223372036854775807\n1\n", ['v' => 'int64'], [], 'v');
s('float64 not finite', "v\n1e308\n1e308\n", ['v' => 'float64'], [], 'v');
s('malformed value', "v\nx\n", ['v' => 'int64'], [], 'v');
s('bad row after the matches', "i,f,s\n1,1,a\nx,1,a\n", $S, [], 'i');
echo 'sum column checked before I/O: ', bf_try(fn() => Csv::scan('/nonexistent/batchflow/none.csv', $S)->summary('s')), "\n";
echo 'unknown sum column before I/O: ', bf_try(fn() => Csv::scan('/nonexistent/batchflow/none.csv', $S)->summary('zz')), "\n";

echo "-- a single pass over the source\n";
BfWrap::$cfg = ['segments' => [[$csv, 1]]];
BfWrap::$opens = 0;
$r = Csv::scan('bfwrap://in', $S)->whereEq('s', 'a')->summary('i');
echo 'opens: ', BfWrap::$opens, ', bytes served: ', BfWrap::$last->served, ' of ', strlen($csv), "\n";
echo 'result: ', bf_fmt($r), "\n";
BfWrap::$cfg = ['segments' => [[$csv, 1]]];
BfWrap::$opens = 0;
$q = Csv::scan('bfwrap://in', $S)->whereEq('s', 'a');
$q->count();
$q->sum('i');
echo 'count() then sum(): opens ', BfWrap::$opens, " (each terminal reads the source again)\n";
$f = bf_wrap_stream($csv, 4);
$r = Csv::scanStream($f, $S)->summary('i');
echo 'stream, reads of 4 bytes: ', bf_fmt($r), ', reads ', BfWrap::$last->reads, "\n";

echo "-- Summary object\n";
$r = bf_q($csv, $S)->summary('f');
var_dump($r instanceof Summary);
$p = (new ReflectionClass($r))->getProperties();
echo implode(',', array_map(fn($x) => $x->getName(), $p)), "\n";
foreach (['rowsScanned' => 9, 'rowsMatched' => 9, 'sum' => 9.0] as $k => $v) {
    try {
        $r->$k = $v;
        echo "$k: WRITABLE\n";
    } catch (Error $e) {
        echo get_class($e), ': ', $e->getMessage(), "\n";
    }
}
try {
    $r->extra = 1;
    echo "dynamic property allowed\n";
} catch (Error $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
$c = clone $r;
echo 'clone: ', bf_fmt($c), ' ', $c == $r ? 'equal' : 'different', ' ', $c !== $r ? 'distinct' : 'same', "\n";
$ser = serialize($r);
$u = unserialize($ser);
echo 'unserialize: ', bf_fmt($u), ' ', $u == $r ? 'equal' : 'different', "\n";
$r2 = bf_q($csv, $S)->summary();
echo 'sum null: ', var_export($r2->sum, true), ' ', bf_fmt(unserialize(serialize($r2))), "\n";
?>
--EXPECT--
-- fields
no sum column: sum is null                    Summary{scanned=5;matched=5;sum=NULL}
sum of int64                                  Summary{scanned=5;matched=5;sum=int(11)}
sum of float64                                Summary{scanned=5;matched=5;sum=float(14.0)}
predicate: matched < scanned                  Summary{scanned=5;matched=3;sum=int(9)}
predicate, float sum                          Summary{scanned=5;matched=3;sum=float(8.0)}
no row matches: int sum is int 0              Summary{scanned=5;matched=0;sum=int(0)}
no row matches: float sum is float 0.0        Summary{scanned=5;matched=0;sum=float(0.0)}
no row matches, no sum column                 Summary{scanned=5;matched=0;sum=NULL}
header only, no sum column                    Summary{scanned=0;matched=0;sum=NULL}
header only, int sum column                   Summary{scanned=0;matched=0;sum=int(0)}
header only, float sum column                 Summary{scanned=0;matched=0;sum=float(0.0)}
all-NULL sum column                           Summary{scanned=2;matched=2;sum=int(0)}
NULL rows are scanned and matched             Summary{scanned=2;matched=2;sum=float(0.0)}
rowsScanned counts every data row             Summary{scanned=4;matched=1;sum=NULL}
summary equals count()                        Summary{scanned=5;matched=3;sum=NULL}
-- errors
string sum column                             SchemaException: column "s": sum requires a numeric column
unknown sum column                            SchemaException: unknown column "zz"
int64 overflow                                SumOverflowException: row 2 (line 3, offset 22): int64 sum overflow in column "v"
float64 not finite                            SumOverflowException: float64 sum of column "v" is not finite
malformed value                               CsvException: row 1 (line 2, offset 2): column "v": expected int64, got malformed value
bad row after the matches                     CsvException: row 2 (line 3, offset 12): column "i": expected int64, got malformed value
sum column checked before I/O: SchemaException: column "s": sum requires a numeric column
unknown sum column before I/O: SchemaException: unknown column "zz"
-- a single pass over the source
opens: 1, bytes served: 42 of 42
result: Summary{scanned=5;matched=3;sum=int(9)}
count() then sum(): opens 2 (each terminal reads the source again)
stream, reads of 4 bytes: Summary{scanned=5;matched=5;sum=int(11)}, reads 12
-- Summary object
bool(true)
rowsScanned,rowsMatched,sum
Error: Cannot modify readonly property BatchFlow\Summary::$rowsScanned
Error: Cannot modify readonly property BatchFlow\Summary::$rowsMatched
Error: Cannot modify readonly property BatchFlow\Summary::$sum
Error: Cannot create dynamic property BatchFlow\Summary::$extra
clone: Summary{scanned=5;matched=5;sum=float(14.0)} equal distinct
unserialize: Summary{scanned=5;matched=5;sum=float(14.0)} equal
sum null: NULL Summary{scanned=5;matched=5;sum=NULL}
