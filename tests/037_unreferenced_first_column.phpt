--TEST--
batchflow: the first schema column is not validated when only a later column is referenced
--EXTENSIONS--
batchflow
--FILE--
<?php
require __DIR__ . '/bf_helper.inc';

$S = ['a' => 'int64', 'b' => 'int64'];
$bad = "a,b\nnot a number,1\n";
echo 'whereEq(b): ', bf_exec($bad, $S, [], [['eq', 'b', 1]]), "\n";
echo 'whereGt(b): ', bf_exec($bad, $S, [], [['gt', 'b', 0]]), "\n";
echo 'long first column, maxFieldBytes 4: ', bf_exec("a,b\n123456789,1\n", $S, ['maxFieldBytes' => 4], [['eq', 'b', 1]]), "\n";
echo 'referencing it raises: ', bf_exec($bad, $S, [], [['eq', 'a', 1]]), "\n";
?>
--EXPECT--
whereEq(b): int(1)
whereGt(b): int(1)
long first column, maxFieldBytes 4: int(1)
referencing it raises: CsvException: row 1 (line 2, offset 4): column "a": expected int64, got malformed value
