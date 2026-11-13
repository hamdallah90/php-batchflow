--TEST--
batchflow: a row of one 2,200,000-byte string is charged 4 MiB (a block above a chunk less a page is counted in whole chunks) and checked against its pending bytes plus 2 MiB, which rounds up to 8 MiB: with 7 MiB free at the check it is refused, with 8 MiB and 64 KiB free it is built
--EXTENSIONS--
batchflow
--SKIPIF--
<?php
// run-tests --asan disables the Zend allocator but sets USE_TRACKED_ALLOC=1, whose allocator enforces memory_limit;
// only the untracked system allocator does not.
if (getenv('USE_ZEND_ALLOC') === '0' && getenv('USE_TRACKED_ALLOC') !== '1') die('skip memory_limit needs the Zend or the tracked allocator');
?>
--INI--
memory_limit=128M
--FILE--
<?php
require __DIR__ . '/bf_helper.inc';

use BatchFlow\Csv;

/*
 * Serves a file and, on the read that finds its end, sets memory_limit to the real usage at that moment plus $free.
 * The file's only row has no line break, so the reader frames it only after that read; the reader's buffer has grown
 * before it, and nothing between the read and the row's check maps memory, so the check sees $free bytes left.
 */
final class BfFreeAtEof
{
    public $context;
    public static string $path = '';
    public static int $free = 0;
    private $fh;
    private bool $eof = false;

    public function stream_open($path, $mode, $options, &$opened)
    {
        $this->fh = fopen(self::$path, 'rb');
        return true;
    }

    public function stream_read($count)
    {
        $s = fread($this->fh, $count);
        if ($s === '') {
            ini_set('memory_limit', (string)(memory_get_usage(true) + self::$free));
            $this->eof = true;
        }
        return $s;
    }

    public function stream_eof()
    {
        return $this->eof;
    }

    public function stream_stat()
    {
        return [];
    }

    public function stream_close()
    {
        fclose($this->fh);
    }
}
stream_wrapper_register('bffree', BfFreeAtEof::class);

BfFreeAtEof::$path = bf_file("s\n" . str_repeat('x', 2200000));
$S = ['s' => 'string'];
$O = ['maxFieldBytes' => 4194304, 'maxRowBytes' => 4194304];

// The estimate is the row (zend_array and template data) plus 4 MiB for the string, so the check asks for 8 MiB.
// A headroom of 1 MiB, or the string charged at its 2,200,000 raw bytes, would ask for 6 MiB and pass at 7 MiB.
foreach ([7 * 1048576, 8 * 1048576 + 65536] as $free) {
    gc_mem_caches();
    BfFreeAtEof::$free = $free;
    try {
        $r = Csv::scan('bffree://row', $S, $O)->toArray();
        $got = count($r) === 1 && strlen($r[0]['s']) === 2200000 ? 'built' : 'a wrong result';
        unset($r);
    } catch (BatchFlow\LimitException $e) {
        $got = $e->getMessage() === 'row 1 (line 2, offset 2): memory_limit would be exceeded' ? 'refused' : $e->getMessage();
    }
    ini_set('memory_limit', '128M');
    echo "$free bytes free at the check: $got\n";
}
?>
--EXPECT--
7340032 bytes free at the check: refused
8454144 bytes free at the check: built
