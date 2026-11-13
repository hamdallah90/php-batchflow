--TEST--
batchflow: memory_limit swept upward from 4M in 32 KiB steps through the group table growths of a scan; every limit ends in LimitException, never in PHP's memory fatal (SPEC 6.4): a growth with no room for another chunk is refused above half the average free space per chunk
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

/** Temp file of $n rows "<key>,j<i>,<i>": 16-byte string keys, or int keys when $len is 0. */
function rows(int $n, int $len): string
{
    $p = tempnam(sys_get_temp_dir(), 'batchflow_');
    $GLOBALS['__bf_tmp'][] = $p;
    $f = fopen($p, 'wb');
    fwrite($f, "k,j,v\n");
    for ($i = 0; $i < $n; $i++) {
        fwrite($f, ($len === 0 ? (string)($i * 7919 - 1000000) : str_pad(dechex($i) . '-', $len, 'y')) . ",j$i,$i\n");
    }
    fclose($f);
    return $p;
}

/**
 * Every limit from 4M to 10M in 32 KiB steps, where two or more chunks are mapped. These tables need more than 10M, so
 * each run is refused, during the scan or by the result preflight. Below 4M one chunk is mapped and the rule is the
 * JsonFuse one; there an in-process sweep on the debug build, whose blocks carry ZEND_MM_OVERHEAD, can still find
 * the free half of the chunk fragmented (SPEC 6.4, best effort). Before the per-chunk rule a slot or arena growth at about 6M to 8M passed the check
 * with its free space spread over three chunks, took a new chunk past the limit, and the output stopped at the fatal.
 */
function sweep(string $name, callable $run): void
{
    $refused = 0;
    $other = [];
    for ($kb = 4096; $kb <= 10240; $kb += 32) {
        gc_mem_caches();
        ini_set('memory_limit', "{$kb}K");
        try {
            $r = $run();
            unset($r);
            $other[] = "{$kb}K: succeeded";
        } catch (BatchFlow\LimitException $e) {
            $refused += str_ends_with($e->getMessage(), 'memory_limit would be exceeded');
        } catch (Throwable $e) {
            $other[] = "{$kb}K: " . get_class($e) . ': ' . $e->getMessage();
        }
        ini_set('memory_limit', '128M');
    }
    echo "$name: refused at all 193 limits: ", $refused === 193 ? 'yes' : "no ($refused)", '; other outcomes: ',
        $other ? implode(' | ', $other) : 'none', "\n";
}

$S = ['k' => 'string', 'j' => 'string', 'v' => 'int64'];
$I = ['k' => 'int64', 'j' => 'string', 'v' => 'int64'];
$s = rows(50000, 16);
$i = rows(50000, 0);
sweep('groupCount, 50,000 keys of 16 bytes', fn() => Csv::scan($s, $S)->groupCount('k', 100000000));
sweep('groupSum, 50,000 keys of 16 bytes', fn() => Csv::scan($s, $S)->groupSum('k', 'v', 100000000));
sweep('groupAgg, 50,000 int and string key pairs', fn() => Csv::scan($i, $I)->groupAgg(['k', 'j'], [['min', 'v'], ['max', 'v']], 100000000));
sweep('groupSum, 50,000 int keys', fn() => Csv::scan($i, $I)->groupSum('k', 'v', 100000000));
?>
--EXPECT--
groupCount, 50,000 keys of 16 bytes: refused at all 193 limits: yes; other outcomes: none
groupSum, 50,000 keys of 16 bytes: refused at all 193 limits: yes; other outcomes: none
groupAgg, 50,000 int and string key pairs: refused at all 193 limits: yes; other outcomes: none
groupSum, 50,000 int keys: refused at all 193 limits: yes; other outcomes: none
