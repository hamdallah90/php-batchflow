--TEST--
batchflow: a memory fatal inside a dictionary load's group growth ends the request without a crash in the load's cleanup
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

// A load that ends in PHP's memory fatal unwinds through the load's zend_catch, which frees the table. A fatal inside
// the dictionary's group growth must leave no group array freed twice: the cleanup then crashed the process. The
// input and band are the D2 case of the memory sweeps (300,000 rows, 200,003 keys, one process per point on a fresh heap),
// where the group arrays' summed preflight lets the growth to 131,072 entries reach the fatal on PHP versions whose
// memory fatal bails out inside the allocator (8.3.21 does). A point may end in a table, LimitException or that
// fatal; none may crash.
$p = tempnam(sys_get_temp_dir(), 'bf');
$f = fopen($p, 'w');
fwrite($f, "k,g,v\n");
for ($i = 0; $i < 300000; $i++) {
    fwrite($f, 'key' . ($i * 7 % 200003) . ',' . ($i * 3) . ",$i\n");
}
fclose($f);
$s = ['k' => 'string', 'g' => 'int64', 'v' => 'int64'];
echo "rows at 256M: ", Table::fromCsv($p, $s, ['dictionary' => ['k']])->rowCount(), "\n";
$c = $p . '.php';
file_put_contents($c, '<?php
$s = ["k" => "string", "g" => "int64", "v" => "int64"];
$q = BatchFlow\Csv::scan($argv[1], $s);
gc_collect_cycles();
gc_mem_caches();
ini_set("memory_limit", (string)(memory_get_usage(true) + (int)$argv[2]));
try {
    BatchFlow\Table::fromCsv($argv[1], $s, ["dictionary" => ["k"]]);
    echo "table";
} catch (BatchFlow\LimitException $e) {
    echo "limit";
}
');
$php = getenv('TEST_PHP_EXECUTABLE') . ' ' . getenv('TEST_PHP_EXTRA_ARGS');
$points = $crashed = 0;
foreach (range(14 * 1048576, 16 * 1048576 + 128 * 1024, 64 * 1024) as $extra) {
    $out = [];
    exec($php . ' ' . escapeshellarg($c) . ' ' . escapeshellarg($p) . ' ' . $extra . ' 2>&1', $out, $rc);
    $o = trim(implode("\n", $out));
    $fatal = $rc === 255 && str_contains($o, 'Allowed memory size of');
    if (!($rc === 0 && ($o === 'table' || $o === 'limit')) && !$fatal) {
        $crashed++;
        echo "crash at +$extra: exit $rc\n";
    }
    $points++;
}
unlink($c);
unlink($p);
echo "$points points, crashed: $crashed\n";
?>
--EXPECT--
rows at 256M: 300000
35 points, crashed: 0
