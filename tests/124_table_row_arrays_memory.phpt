--TEST--
batchflow: a load of eight int64 columns, on a fresh heap under a memory_limit sweep, ends in a table or LimitException, never in PHP's fatal
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

// SPEC 6.4: a table's row arrays grow together. At 131,072 rows each int64 column's values are 1 MiB and ZendMM
// can place each in a chunk of its own: on 6fa476a the growth's one preflight of the 9 MiB sum, rounded to 10 MiB,
// passed and the arrays then took more, ending in PHP's fatal ("tried to allocate 1048576 bytes") at limits of +16
// to +17.5 MiB after a 32 or 128 KiB padding string. One process per point; whether the 1 MiB runs find free pages
// depends on small allocations made before, so each limit is tried after a padding string of 0 to 512 KiB.
$p = tempnam(sys_get_temp_dir(), 'bf');
$f = fopen($p, 'w');
fwrite($f, "a,b,c,d,e,f,g,h\n");
for ($i = 0; $i < 100000; $i++) {
    fwrite($f, implode(',', array_fill(0, 8, $i)) . "\n");
}
fclose($f);
$s = array_fill_keys(['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h'], 'int64');
echo "rows at 256M: ", Table::fromCsv($p, $s)->rowCount(), "\n";
$c = $p . '.php';
file_put_contents($c, '<?php
$pad = str_repeat("x", (int)$argv[3]);
gc_collect_cycles();
gc_mem_caches();
ini_set("memory_limit", (string)(memory_get_usage(true) + (int)$argv[2]));
try {
    BatchFlow\Table::fromCsv($argv[1], array_fill_keys(["a", "b", "c", "d", "e", "f", "g", "h"], "int64"));
    echo "table";
} catch (BatchFlow\LimitException $e) {
    echo "limit";
}
');
$php = getenv('TEST_PHP_EXECUTABLE') . ' ' . getenv('TEST_PHP_EXTRA_ARGS');
$points = $other = 0;
foreach (range(0, 512 * 1024, 16 * 1024) as $pad) {
    foreach ([16, 16.5, 17, 17.5] as $mib) {
        $extra = (int)($mib * 1048576);
        $o = trim((string)shell_exec($php . ' ' . escapeshellarg($c) . ' ' . escapeshellarg($p) . " $extra $pad 2>&1"));
        if ($o !== 'table' && $o !== 'limit') {
            $other++;
            echo "at +$extra, pad $pad: ", substr($o, 0, 60), "\n";
        }
        $points++;
    }
}
unlink($c);
unlink($p);
echo "$points points, neither table nor LimitException: $other\n";
?>
--EXPECT--
rows at 256M: 100000
132 points, neither table nor LimitException: 0
