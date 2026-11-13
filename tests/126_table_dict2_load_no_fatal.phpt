--TEST--
batchflow: a load with two dictionaries just above the usage ends in a table or LimitException, never in PHP's memory fatal
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

// Two dictionaries (k and g) on 300,000 rows. The second dictionary's slot table grows to 128 KiB, a run of 32 pages,
// while the first one's arrays have left free pages scattered over the chunks. Half the average free space per chunk
// passed that request with no such run free and no room for another chunk: the growth reached the fatal.
$p = tempnam(sys_get_temp_dir(), 'bf');
$f = fopen($p, 'w');
fwrite($f, "k,g,v\n");
for ($i = 0; $i < 300000; $i++) {
    fwrite($f, 'key' . ($i * 7 % 200003) . ',' . ($i * 3) . ",$i\n");
}
fclose($f);
$c = $p . '.php';
file_put_contents($c, '<?php
$s = ["k" => "string", "g" => "int64", "v" => "int64"];
$q = BatchFlow\Csv::scan($argv[1], $s);
gc_collect_cycles();
gc_mem_caches();
ini_set("memory_limit", (string)(memory_get_usage(true) + (int)$argv[2]));
try {
    BatchFlow\Table::fromCsv($argv[1], $s, ["dictionary" => ["k", "g"]]);
    echo "table";
} catch (BatchFlow\LimitException $e) {
    echo "limit";
}
');
$php = getenv('TEST_PHP_EXECUTABLE') . ' ' . getenv('TEST_PHP_EXTRA_ARGS');
$points = $other = 0;
foreach (range(0, 2 * 1048576, 64 * 1024) as $extra) {
    $out = [];
    exec($php . ' ' . escapeshellarg($c) . ' ' . escapeshellarg($p) . ' ' . $extra . ' 2>&1', $out, $rc);
    $o = trim(implode("\n", $out));
    if (!($rc === 0 && ($o === 'table' || $o === 'limit'))) {
        $other++;
        echo "+$extra: exit $rc: ", substr($o, 0, 120), "\n";
    }
    $points++;
}
unlink($c);
unlink($p);
echo "$points points, other: $other\n";
?>
--EXPECT--
33 points, other: 0
