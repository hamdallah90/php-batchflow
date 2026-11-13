--TEST--
batchflow: in 1-byte reads a row with no terminator is rejected at the first rescan with more than maxRowBytes + 2 bytes buffered (SPEC 6.2, 6.4)
--EXTENSIONS--
batchflow
--FILE--
<?php
require __DIR__ . '/bf_helper.inc';

use BatchFlow\Csv;

// Header "a", then 40 bytes with no terminator, served one byte per read. The first fill stops at 3
// bytes (BOM test); after that each refill reads until the buffered row has doubled (1, 2, 4, 8, 16
// bytes) and only then rescans. The row is rejected at the first rescan with more than
// maxRowBytes + 2 bytes, so the bytes served are 2 (header) + that buffered size.
foreach ([1 => 6, 2 => 10, 3 => 10, 6 => 18] as $max => $want) {
    BfWrap::$cfg = ['segments' => [["a\n", 1], ['x', 40]], 'max' => 1];
    $r = bf_try(fn() => Csv::scan('bfwrap://in', ['a' => 'string'], ['maxRowBytes' => $max])->count());
    $served = BfWrap::$last->served;
    echo "maxRowBytes $max: $r; bytes served $served", $served === $want ? '' : " (expected $want)", "\n";
}
?>
--EXPECT--
maxRowBytes 1: LimitException: row 1 (line 2, offset 2): row exceeds maxRowBytes; bytes served 6
maxRowBytes 2: LimitException: row 1 (line 2, offset 2): row exceeds maxRowBytes; bytes served 10
maxRowBytes 3: LimitException: row 1 (line 2, offset 2): row exceeds maxRowBytes; bytes served 10
maxRowBytes 6: LimitException: row 1 (line 2, offset 2): row exceeds maxRowBytes; bytes served 18
