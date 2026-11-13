--TEST--
batchflow: the groupAgg result preflight keeps the margin of SPEC 6.3 over the result's real cost: a limit with room for the result plus 32 bytes per group and a chunk, or for groups * (row + 88) without the 32 * N term, but not for groups * (row + 88 + 32 * N), is refused without row context; room for the full estimate succeeds
--EXTENSIONS--
batchflow
--SKIPIF--
<?php
// The expected output depends on the Zend allocator's accounting of chunks and pages; run-tests --asan replaces it
// with the tracked allocator, which enforces memory_limit but counts differently.
if (getenv('USE_ZEND_ALLOC') === '0') die('skip needs the Zend allocator');
?>
--INI--
memory_limit=-1
--FILE--
<?php
require __DIR__ . '/bf_helper.inc';

use BatchFlow\Csv;

// 2^17 one-int-key groups (N = 1, M = 0, P = 8): the outer list's packed capacity is exactly G, 16 bytes per group.
const G = 131072;
const CHUNK = 2097152;
const S = ['b' => 'int64'];

$b = "b\n";
for ($i = 0; $i < G; $i++) {
    $b .= "$i\n";
}
$p = bf_file($b);
unset($b);

/** One groupAgg(['b'], []) under $limit bytes above the current real usage: "ok", "result" (refused without row context) or "scan". */
function run(string $p, ?int $above, ?array &$r = null): string
{
    gc_mem_caches();
    if ($above !== null) {
        ini_set('memory_limit', (string)(memory_get_usage(true) + $above));
    }
    try {
        $r = Csv::scan($p, S)->groupAgg(['b'], []);
        return 'ok';
    } catch (BatchFlow\LimitException $e) {
        return preg_match('/^row \d+ \(line \d+, offset \d+\): memory_limit would be exceeded$/', $e->getMessage()) ? 'scan'
            : ($e->getMessage() === 'memory_limit would be exceeded' ? 'result' : 'other: ' . $e->getMessage());
    } finally {
        ini_set('memory_limit', '-1');
    }
}

// A: the result's own cost (rows, their packed data and the outer list), measured with no limit.
$u0 = memory_get_usage();
echo "-- no limit: ", run($p, null, $r), ', ', is_array($r) ? count($r) : 'no', " groups\n";
$A = memory_get_usage() - $u0;
unset($r);

// The scan's real usage at the result preflight: the peak of a run whose result is refused.
foreach ([4, 8, 16, 32] as $mb) {
    $base = memory_get_usage(true);
    memory_reset_peak_usage();
    $o = run($p, $mb * 1048576);
    if ($o !== 'scan') {
        break;
    }
}
echo "-- probe: ", $o, "\n";
$scan = memory_get_peak_usage(true) - $base;

// The estimate is row * G + 120 * G with row * G below A, so it is at least A + 88 * G - 16 * G; the limit below leaves
// A + 32 * G and a chunk, enough for an estimate without the 88-byte term, and 56 * G - CHUNK (5.3 MB) short of the estimate.
echo "-- result plus 32 bytes per group and a chunk: ", run($p, $scan + $A + 32 * G + CHUNK), "\n";
// row * G is A less the outer list's 16 bytes per group and a few KB, so the estimate is A + 104 * G less those KB.
// Without its 32 * N term it would be A + 72 * G less those KB: 19 whole chunks on release builds, which a limit of
// A + 72 * G holds, while the full estimate is 4 MiB more.
echo "-- room for groups * (row + 88) but not for the 32 * N term: ", run($p, $scan + $A + 72 * G), "\n";
echo "-- the full estimate and a chunk: ", run($p, $scan + $A + 120 * G + CHUNK, $r), ', ', is_array($r) ? count($r) : 'no', " groups\n";
?>
--EXPECT--
-- no limit: ok, 131072 groups
-- probe: result
-- result plus 32 bytes per group and a chunk: result
-- room for groups * (row + 88) but not for the 32 * N term: result
-- the full estimate and a chunk: ok, 131072 groups
