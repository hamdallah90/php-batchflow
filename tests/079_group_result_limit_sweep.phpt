--TEST--
batchflow: memory_limit swept upward across the group result boundary in 256 KiB steps; every limit ends in LimitException or success, never in PHP's memory fatal (SPEC 6.4), for groupCount, groupSum and groupAgg over long string keys, int keys and a 32-slot groupAgg row
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

/** Temp file of $n rows "<key of $len bytes>,j<i>,1". */
function keys(int $n, int $len): string
{
    $p = tempnam(sys_get_temp_dir(), 'batchflow_');
    $GLOBALS['__bf_tmp'][] = $p;
    $f = fopen($p, 'wb');
    fwrite($f, "k,j,v\n");
    for ($i = 0; $i < $n; $i++) {
        fwrite($f, str_pad(dechex($i) . '-', $len, 'x') . ",j$i,1\n");
    }
    fclose($f);
    return $p;
}

/**
 * Raises memory_limit from $fromKb in $stepKb KiB steps until $run succeeds, then 8 steps more. The lowest limits are
 * refused during the scan (with row context), the next ones by the result preflight (without). An estimate below what
 * the result takes passes the preflight at some limit the result cannot fit, and the test output stops at a fatal.
 */
function sweep(string $name, callable $run, int $fromKb, int $stepKb = 256): void
{
    $result = $ok = 0;
    $other = [];
    for ($kb = $fromKb; $ok < 9 && $kb < $fromKb + 400 * $stepKb; $kb += $stepKb) {
        gc_mem_caches();
        ini_set('memory_limit', "{$kb}K");
        try {
            $r = $run();
            unset($r);
            $ok++;
        } catch (BatchFlow\LimitException $e) {
            if ($ok > 0 || !str_ends_with($e->getMessage(), 'memory_limit would be exceeded')) {
                $other[] = "{$kb}K: " . $e->getMessage();
            }
            $result += $e->getMessage() === 'memory_limit would be exceeded';
        } catch (Throwable $e) {
            $other[] = "{$kb}K: " . get_class($e) . ': ' . $e->getMessage();
        }
        ini_set('memory_limit', '128M');
    }
    echo "$name: refused by the result preflight: ", $result > 0 ? 'yes' : 'no', '; then succeeded: ', $ok === 9 ? 'yes' : 'no',
        '; other outcomes: ', $other ? implode(' | ', $other) : 'none', "\n";
}

$S = ['k' => 'string', 'j' => 'string', 'v' => 'int64'];
// 3,000 keys of 4,072 bytes: two pages per key string. 4,000 keys of 1,000 bytes: the 1,280-byte bin.
$a = keys(3000, 4072);
$b = keys(4000, 1000);
sweep('groupCount, 3,000 keys of 4,072 bytes', fn() => Csv::scan($a, $S)->groupCount('k'), 24 * 1024);
sweep('groupAgg, 3,000 keys of 4,072 bytes', fn() => Csv::scan($a, $S)->groupAgg('k', [['sum', 'v']]), 24 * 1024);
sweep('groupSum, 4,000 keys of 1,000 bytes', fn() => Csv::scan($b, $S)->groupSum('k', 'v'), 6 * 1024);
sweep('groupAgg, 4,000 pairs of a 1,000-byte and a short key', fn() => Csv::scan($b, $S)->groupAgg(['k', 'j'], [['min', 'v']]), 6 * 1024);
// An int key before the long string: the walk over each encoded tuple must step over the int's 8 bytes to charge it.
$c = tempnam(sys_get_temp_dir(), 'batchflow_');
$GLOBALS['__bf_tmp'][] = $c;
$f = fopen($c, 'wb');
fwrite($f, "n,k,v\n");
for ($i = 0; $i < 4000; $i++) {
    fwrite($f, ($i * 7919) . ',' . str_pad(dechex($i) . '-', 1000, 'x') . ",1\n");
}
fclose($f);
sweep('groupAgg, 4,000 pairs of an int and a 1,000-byte key', fn() => Csv::scan($c, ['n' => 'int64', 'k' => 'string', 'v' => 'int64'])->groupAgg(['n', 'k'], [['min', 'v']]), 6 * 1024);
// Int keys: the rows alone. On a debug build each block carries ZEND_MM_OVERHEAD and lands in a larger bin.
// 18 elements a row: packed data for 32, 520 bytes in the 640-byte bin.
$p = tempnam(sys_get_temp_dir(), 'batchflow_');
$GLOBALS['__bf_tmp'][] = $p;
$f = fopen($p, 'wb');
fwrite($f, "k,j,v\n");
for ($i = 0; $i < 100000; $i += 1000) {
    $c = '';
    for ($j = $i; $j < $i + 1000; $j++) {
        $c .= ($j * 7919) . ",x,$j\n";
    }
    fwrite($f, $c);
}
fclose($f);
$I = ['k' => 'int64', 'j' => 'string', 'v' => 'int64'];
sweep('groupCount, 100,000 int keys', fn() => Csv::scan($p, $I)->groupCount('k'), 16 * 1024, 512);
sweep('groupAgg, 25,000 int keys, 16 pairs', fn() => Csv::scan($p, $I)->whereLt('k', 25000 * 7919)->groupAgg('k', array_fill(0, 16, ['min', 'v'])), 24 * 1024, 512);
?>
--EXPECT--
groupCount, 3,000 keys of 4,072 bytes: refused by the result preflight: yes; then succeeded: yes; other outcomes: none
groupAgg, 3,000 keys of 4,072 bytes: refused by the result preflight: yes; then succeeded: yes; other outcomes: none
groupSum, 4,000 keys of 1,000 bytes: refused by the result preflight: yes; then succeeded: yes; other outcomes: none
groupAgg, 4,000 pairs of a 1,000-byte and a short key: refused by the result preflight: yes; then succeeded: yes; other outcomes: none
groupAgg, 4,000 pairs of an int and a 1,000-byte key: refused by the result preflight: yes; then succeeded: yes; other outcomes: none
groupCount, 100,000 int keys: refused by the result preflight: yes; then succeeded: yes; other outcomes: none
groupAgg, 25,000 int keys, 16 pairs: refused by the result preflight: yes; then succeeded: yes; other outcomes: none
