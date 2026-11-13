--TEST--
batchflow: groupAgg on a table with 100,003 keys, on a fresh heap under a memory_limit sweep, ends in a result or LimitException, never in PHP's fatal
--EXTENSIONS--
batchflow
--SKIPIF--
<?php
if (getenv('USE_ZEND_ALLOC') === '0') die('skip needs the Zend allocator: its chunks and page runs');
?>
--CONFLICTS--
all
--INI--
memory_limit=256M
--FILE--
<?php
use BatchFlow\Table;

// SPEC 6.4: a table query's groupAgg arrays (hash, off, len, count, state) grow together. At 131,072 entries hash,
// off and count are 1 MiB each and ZendMM can place each in a chunk of its own: on 2b876ad one preflight of their
// 5.5 MiB sum, rounded to 6 MiB, passed with 6 MiB left, the three runs mapped three chunks and the growth ended
// in PHP's fatal at 128 of 128 points from +12,582,912 to +14,663,680 (16 KiB steps). One process per point: the
// table loads with no limit, then the limit is set just above the usage and the query runs. Whether the three runs
// find free pages depends on small allocations made before (the environment's size moves them), so each limit is
// tried after a padding string of 0 to 256 KiB.
$p = tempnam(sys_get_temp_dir(), 'bf');
$f = fopen($p, 'w');
fwrite($f, "k,v\n");
for ($i = 0; $i < 100000; $i++) {
    fwrite($f, 'key' . ($i * 7 % 100003) . ",$i\n");
}
fclose($f);
echo "groups at 256M: ", count(Table::fromCsv($p, ['k' => 'string', 'v' => 'int64'])->query()->groupAgg(['k'], [['sum', 'v']])), "\n";
$c = $p . '.php';
file_put_contents($c, '<?php
$pad = str_repeat("x", (int)$argv[3]);
$q = BatchFlow\Table::fromCsv($argv[1], ["k" => "string", "v" => "int64"])->query();
gc_collect_cycles();
gc_mem_caches();
ini_set("memory_limit", (string)(memory_get_usage(true) + (int)$argv[2]));
try {
    $q->groupAgg(["k"], [["sum", "v"]]);
    echo "result";
} catch (BatchFlow\LimitException $e) {
    echo "limit";
}
');
$php = getenv('TEST_PHP_EXECUTABLE') . ' ' . getenv('TEST_PHP_EXTRA_ARGS');
$points = $other = 0;
foreach (range(0, 256 * 1024, 16 * 1024) as $pad) {
    foreach ([12.25, 12.75, 13.25, 13.75] as $mib) {
        $extra = (int)($mib * 1048576);
        $o = trim((string)shell_exec($php . ' ' . escapeshellarg($c) . ' ' . escapeshellarg($p) . " $extra $pad 2>&1"));
        if ($o !== 'result' && $o !== 'limit') {
            $other++;
            echo "at +$extra, pad $pad: ", substr($o, 0, 60), "\n";
        }
        $points++;
    }
}
unlink($c);
unlink($p);
echo "$points points, neither result nor LimitException: $other\n";
?>
--EXPECT--
groups at 256M: 100000
68 points, neither result nor LimitException: 0
