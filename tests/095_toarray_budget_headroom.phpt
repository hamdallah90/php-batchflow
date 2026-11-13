--TEST--
batchflow: memory_limit swept from 12M to 36M in 64 KiB steps over rows that alternate one row of two 950,000-byte strings with 700 small rows; every limit ends in LimitException or the whole result, never in PHP's memory fatal: the row budget's check asks for room for another chunk on top of what is pending
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
 * A big row is checked with about 2.0 MB pending (its own 1.9 MB and the small rows since the last check), below a
 * chunk, so without the headroom the rule only needs those bytes to fit and room for one more chunk. Its two strings
 * take 464 of that chunk's 511 pages, and the next six hundred or so small rows (just under the 1 MiB budget) are built
 * before the next check. They need a second chunk, and mapping it past the limit was PHP's memory fatal at 16.0M to
 * 18.0M, 22.0M to 24.0M and 28.0M to 30.0M (nts-8.3 arm64, one process per limit, 16 KiB steps).
 */
$B = str_repeat('x', 950000);
$s = str_repeat('y', 1000);
$p = tempnam(sys_get_temp_dir(), 'batchflow_');
$GLOBALS['__bf_tmp'][] = $p;
$f = fopen($p, 'wb');
fwrite($f, "a,b\n");
for ($c = 0; $c < 8; $c++) {
    fwrite($f, "$B,$B\n");
    for ($i = 0; $i < 700; $i++) {
        fwrite($f, "$s,\n");
    }
}
fclose($f);
unset($B);
$S = ['a' => 'string', 'b' => 'string'];

$n = 0;
$other = [];
for ($kb = 12288; $kb <= 36864; $kb += 64) {
    gc_mem_caches();
    ini_set('memory_limit', "{$kb}K");
    try {
        $r = Csv::scan($p, $S)->toArray();
        $c = count($r);
        unset($r);
        $c === 5608 ? $n++ : $other[] = "{$kb}K: $c rows";
    } catch (BatchFlow\LimitException $e) {
        str_ends_with($e->getMessage(), 'memory_limit would be exceeded') ? $n++ : $other[] = "{$kb}K: " . $e->getMessage();
    } catch (Throwable $e) {
        $other[] = "{$kb}K: " . get_class($e) . ': ' . $e->getMessage();
    }
    ini_set('memory_limit', '128M');
}
echo 'every one of the 385 limits ends in LimitException or all 5,608 rows: ', $n === 385 ? 'yes' : "no ($n)",
    '; other outcomes: ', $other ? implode(' | ', $other) : 'none', "\n";
echo 'the whole file fits under 128M: ', count(Csv::scan($p, $S)->toArray()), " rows\n";
?>
--EXPECT--
every one of the 385 limits ends in LimitException or all 5,608 rows: yes; other outcomes: none
the whole file fits under 128M: 5608 rows
