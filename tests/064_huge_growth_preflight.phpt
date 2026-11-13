--TEST--
batchflow: a reader growth of 2 MiB or more is rounded up to whole chunks and compared with the limit minus real usage
--EXTENSIONS--
batchflow
--SKIPIF--
<?php
// run-tests --asan disables the Zend allocator but sets USE_TRACKED_ALLOC=1, whose allocator enforces memory_limit;
// only the untracked system allocator does not.
if (getenv('USE_ZEND_ALLOC') === '0' && getenv('USE_TRACKED_ALLOC') !== '1') die('skip memory_limit needs the Zend or the tracked allocator');
?>
--FILE--
<?php
require __DIR__ . '/bf_helper.inc';

use BatchFlow\Csv;

// The row needs the reader to grow from 1 MiB to 2 MiB (+ 256 KiB of masks): a request of 2,359,296
// bytes, charged as 4 MiB (SPEC 6.4). With only 3 MiB of headroom it is refused; with 4 MiB it fits.
// Headroom is set relative to real usage on every read, so the 1 MiB growth before it always passes.
function run(int $headroom): string
{
    $cfg = [
        'segments' => [["a,s\n1,", 1], ['x', 900000], ["\n", 1]],
        'max' => 65536,
        'on_read' => function () use ($headroom) {
            ini_set('memory_limit', (string)(memory_get_usage(true) + $headroom));
        },
    ];
    BfWrap::$cfg = $cfg;
    return bf_try(fn() => Csv::scan('bfwrap://in', ['a' => 'int64', 's' => 'string'])->whereEq('s', 'q')->count());
}

echo '3 MiB headroom: ', run(3 * 1048576), "\n";
ini_set('memory_limit', '-1');
echo '4 MiB headroom: ', run(4 * 1048576), "\n";
?>
--EXPECT--
3 MiB headroom: LimitException: row 1 (line 2, offset 4): memory_limit would be exceeded
4 MiB headroom: int(0)
