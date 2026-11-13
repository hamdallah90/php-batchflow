--TEST--
batchflow: streaming group growth on a heap with only short free page runs ends in a result or LimitException, never in PHP's memory fatal
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
// Before the query the script fills 2,048 pages with one-page strings and frees them in runs of 3 or 7 pages, so the
// heap holds megabytes of free pages but no run longer than 7 pages, and the limit leaves no room for another chunk.
// A group growth of a large block (slots, key arena, group arrays: page runs of 2 pages up) needs a run that long or
// a fresh chunk. Half the average free space per chunk passed such growths: groupCount and groupSum reached the fatal
// at their 256 KiB growth. Points: 3 operations, 2 run lengths, 3 limits.
$p = tempnam(sys_get_temp_dir(), 'bf');
$f = fopen($p, 'w');
fwrite($f, "k,g,v\n");
for ($i = 0; $i < 100000; $i++) {
    fwrite($f, 'key' . ($i * 7 % 200003) . ',' . ($i * 3) . ",$i\n");
}
fclose($f);
$c = $p . '.php';
file_put_contents($c, '<?php
[$_, $p, $o, $x, $hole] = $argv;
$hole = (int)$hole;
$s = ["k" => "string", "g" => "int64", "v" => "int64"];
$a = [];
for ($i = 0; $i < 2048; $i++) {
    $a[] = str_repeat(chr(65 + $i % 26), 3500);
}
for ($i = 0; $i < 2048; $i++) {
    if ($i % ($hole + 1) !== $hole) {
        unset($a[$i]);
    }
}
gc_collect_cycles();
ini_set("memory_limit", (string)(memory_get_usage(true) + (int)$x));
try {
    $q = BatchFlow\Csv::scan($p, $s);
    $r = match ($o) {
        "count" => $q->groupCount("k"),
        "sum" => $q->groupSum("k", "v"),
        "agg" => $q->groupAgg(["k"], [["sum", "v"]]),
    };
    echo count($r) === 100000 ? "result" : "wrong count";
} catch (BatchFlow\LimitException $e) {
    echo "limit";
}
');
$php = getenv('TEST_PHP_EXECUTABLE') . ' ' . getenv('TEST_PHP_EXTRA_ARGS');
$points = $other = 0;
foreach (['count', 'sum', 'agg'] as $op) {
    foreach ([3, 7] as $hole) {
        foreach ([0, 1048576, 1900000] as $extra) {
            $out = [];
            exec($php . ' ' . escapeshellarg($c) . ' ' . escapeshellarg($p) . " $op $extra $hole 2>&1", $out, $rc);
            $o = trim(implode("\n", $out));
            if (!($rc === 0 && ($o === 'result' || $o === 'limit'))) {
                $other++;
                echo "$op hole $hole +$extra: exit $rc: ", substr($o, 0, 120), "\n";
            }
            $points++;
        }
    }
}
unlink($c);
unlink($p);
echo "$points points, other: $other\n";
?>
--EXPECT--
18 points, other: 0
