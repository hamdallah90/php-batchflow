--TEST--
batchflow: memory_limit swept upward across the result list's growth to 8 MiB in 128 KiB steps; every limit ends in LimitException or success, never in PHP's memory fatal (SPEC 6.4: the row that grows the list charges its new packed data)
--EXTENSIONS--
batchflow
--SKIPIF--
<?php
// run-tests --asan disables the Zend allocator but sets USE_TRACKED_ALLOC=1, whose allocator enforces memory_limit;
// only the untracked system allocator does not.
if (getenv('USE_ZEND_ALLOC') === '0' && getenv('USE_TRACKED_ALLOC') !== '1') die('skip memory_limit needs the Zend or the tracked allocator');
?>
--INI--
memory_limit=256M
--FILE--
<?php
require __DIR__ . '/bf_helper.inc';

use BatchFlow\Csv;

// 270,000 one-column int rows: about 100 MB of rows when the list of 262,144 grows from 4 MiB to 8 MiB. Without the list
// in the estimate, a limit near 104M passes the row budget's last check with 2 to 3 MiB to spare and the list's growth
// ends in the memory fatal (measured on 8.3.36 at 106,528 KiB: "tried to allocate 8392704 bytes").
$p = tempnam(sys_get_temp_dir(), 'batchflow_');
$GLOBALS['__bf_tmp'][] = $p;
$f = fopen($p, 'wb');
fwrite($f, "i\n");
for ($i = 0; $i < 270000; $i += 1000) {
    fwrite($f, implode("\n", range($i, $i + 999)) . "\n");
}
fclose($f);

$refused = $ok = 0;
$other = [];
for ($kb = 100 * 1024; $ok < 9 && $kb < 160 * 1024; $kb += 128) {
    gc_mem_caches();
    ini_set('memory_limit', "{$kb}K");
    try {
        $r = Csv::scan($p, ['i' => 'int64'])->toArray(1000000);
        $ok += count($r) === 270000;
        unset($r);
    } catch (BatchFlow\LimitException $e) {
        if ($ok > 0 || !preg_match('/^row \d+ \(line \d+, offset \d+\): memory_limit would be exceeded$/', $e->getMessage())) {
            $other[] = "{$kb}K: " . $e->getMessage();
        }
        $refused++;
    } catch (Throwable $e) {
        $other[] = "{$kb}K: " . get_class($e) . ': ' . $e->getMessage();
    }
    unset($e);
    ini_set('memory_limit', '256M');
}
echo 'refused with row context: ', $refused > 0 ? 'yes' : 'no', '; then succeeded: ', $ok === 9 ? 'yes' : 'no',
    '; other outcomes: ', $other ? implode(' | ', $other) : 'none', "\n";
?>
--EXPECT--
refused with row context: yes; then succeeded: yes; other outcomes: none
