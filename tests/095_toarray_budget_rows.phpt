--TEST--
batchflow: from a plain file, rows whose estimate exceeds 1 MiB are built one by one until the row budget's check refuses one, at a later row than the reader's own preflight could; the strings count in the estimate
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

// 30 rows of two 700,000-byte fields in a temp file: no wrapper holds copies, so after the reader buffer has grown for
// the first row only the rows themselves take memory, and only the row budget can stop them. 095 serves the same rows
// through the wrapper, whose own copies make the reader's growth preflight refuse at row 1.
$F = str_repeat('x', 700000);
$p = tempnam(sys_get_temp_dir(), 'batchflow_');
$GLOBALS['__bf_tmp'][] = $p;
$f = fopen($p, 'wb');
fwrite($f, "a,b\n");
for ($i = 0; $i < 30; $i++) {
    fwrite($f, "$F,$F\n");
}
fclose($f);
unset($F);
$S = ['a' => 'string', 'b' => 'string'];

foreach (['12M', '16M', '24M'] as $limit) {
    gc_mem_caches();
    ini_set('memory_limit', $limit);
    $base = memory_get_usage();
    try {
        $r = Csv::scan($p, $S)->toArray(100);
        echo "$limit: NO EXCEPTION, rows: ", count($r), "\n";
    } catch (BatchFlow\LimitException $e) {
        $ok = preg_match('/^row (\d+) \(line (\d+), offset (\d+)\): memory_limit would be exceeded$/', $e->getMessage(), $m);
        $rows = (int)$m[1] - 1;
        $fits = intdiv((int)substr($limit, 0, -1) * 1048576, 1400100);
        echo "$limit: LimitException with row context: ", $ok ? 'yes' : 'NO: ' . $e->getMessage(),
            '; rows built before it: ', $rows >= 2 ? 'at least 2' : "ONLY $rows", ', fewer than the limit holds: ', $rows < $fits ? 'yes' : "NO ($rows of $fits)",
            ', offset is that row\'s: ', (int)$m[3] === 4 + $rows * 1400002 ? 'yes' : 'NO', "\n";
    }
    unset($r, $e);
    echo "$limit: rows freed: ", memory_get_usage() < $base + 2097152 ? 'yes' : 'NO', "\n";
    ini_set('memory_limit', '128M');
}
echo 'the whole file fits under 128M: ', count(Csv::scan($p, $S)->toArray(100)), " rows\n";
?>
--EXPECT--
12M: LimitException with row context: yes; rows built before it: at least 2, fewer than the limit holds: yes, offset is that row's: yes
12M: rows freed: yes
16M: LimitException with row context: yes; rows built before it: at least 2, fewer than the limit holds: yes, offset is that row's: yes
16M: rows freed: yes
24M: LimitException with row context: yes; rows built before it: at least 2, fewer than the limit holds: yes, offset is that row's: yes
24M: rows freed: yes
the whole file fits under 128M: 30 rows
