--TEST--
batchflow: rows whose own estimate exceeds 1 MiB hit the memory check at every row, so a file of such rows ends in LimitException under a low memory_limit, not in a fatal
--EXTENSIONS--
batchflow
--SKIPIF--
<?php
// The expected output depends on the Zend allocator's accounting of chunks and pages; run-tests --asan replaces it
// with the tracked allocator, which enforces memory_limit but counts differently.
if (getenv('USE_ZEND_ALLOC') === '0') die('skip needs the Zend allocator');
?>
--INI--
memory_limit=12M
--FILE--
<?php
require __DIR__ . '/bf_helper.inc';

use BatchFlow\Csv;

register_shutdown_function(static function () {
    echo "shutdown function ran\n";
});

/** Refusal summary: class, optional row number, reason. */
function refusal(callable $fn): string
{
    try {
        $r = $fn();
        return 'NO EXCEPTION, rows: ' . count($r);
    } catch (Throwable $e) {
        if (preg_match('/^row (\d+) \(line (\d+), offset (\d+)\): (.*)$/s', $e->getMessage(), $m)) {
            return get_class($e) . ' | row context: yes | ' . $m[4] . ' | row ' . ($m[1] >= 1 && $m[1] <= 30 ? 'in 1..30' : "OUT OF RANGE {$m[1]}");
        }
        return get_class($e) . ' | row context: no | ' . $e->getMessage();
    }
}

$S = ['a' => 'string', 'b' => 'string'];
$F = str_repeat('x', 700000); // two of these make a row whose estimate is about 1.4 MB, above the 1 MiB trigger
$G = str_repeat('y', 1048576); // the largest field maxFieldBytes allows
echo 'memory_limit: ', ini_get('memory_limit'), "\n";
$base = memory_get_usage();

echo "-- 30 rows of 1.4 MB each (42 MB of fields) under 12M\n";
$p = bf_wrap_path(['segments' => [["a,b\n", 1], ["$F,$F\n", 30]], 'max' => 65536]);
echo refusal(fn() => Csv::scan($p, $S)->toArray(100)), "\n";
$read = BfWrap::$last->served;
echo 'the run stopped reading early: ', $read < 30 * 1400001 ? 'yes' : "NO ($read)", "\n";
echo 'the wrapper stream was closed: ', BfWrap::$closes, "\n";

echo "-- one 1,048,576-byte field per row (the largest allowed), 30 rows\n";
$p = bf_wrap_path(['segments' => [["a\n", 1], ["$G\n", 30]], 'max' => 65536]);
echo refusal(fn() => Csv::scan($p, ['a' => 'string'])->toArray(100)), "\n";

echo "-- a single such row still fits: the check refuses only what cannot fit\n";
// Calibrated at 32M: under 12M this row cannot pass its own check. That check asks for its 1.4 MB estimate plus 2 MiB,
// rounded to 4 MiB, beside about 8 MiB of real usage (the reader buffer grown to 2 MiB for the row, the earlier cases'
// chunks); and the wrapper builds three copies of its 1.4 MB segment on every read (refused at 12M and 14M, the
// wrapper's own memory fatal at 16M, measured on 8.3.36).
ini_set('memory_limit', '32M');
$p = bf_wrap_path(['segments' => [["a,b\n", 1], ["$F,$F\n", 1]], 'max' => 65536]);
$r = Csv::scan($p, $S)->toArray(100);
echo 'rows: ', count($r), ', field lengths: ', strlen($r[0]['a']), ',', strlen($r[0]['b']), "\n";
unset($r);
ini_set('memory_limit', '12M');

echo "-- partial rows are freed and the process continues\n";
echo 'back within 2 MiB of the baseline: ', memory_get_usage() < $base + 2097152 ? 'yes' : 'NO (' . (memory_get_usage() - $base) . ' above)', "\n";
echo bf_fmt_rows(bf_q("a,b\n1,2\n", $S)->toArray()), "\n";
echo "end of script\n";
?>
--EXPECT--
memory_limit: 12M
-- 30 rows of 1.4 MB each (42 MB of fields) under 12M
BatchFlow\LimitException | row context: yes | memory_limit would be exceeded | row in 1..30
the run stopped reading early: yes
the wrapper stream was closed: 1
-- one 1,048,576-byte field per row (the largest allowed), 30 rows
BatchFlow\LimitException | row context: yes | memory_limit would be exceeded | row in 1..30
-- a single such row still fits: the check refuses only what cannot fit
rows: 1, field lengths: 700000,700000
-- partial rows are freed and the process continues
back within 2 MiB of the baseline: yes
{a=s"1", b=s"2"}
end of script
shutdown function ran
